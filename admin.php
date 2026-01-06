<?php
declare(strict_types=1);

/**
 * admin.php
 * - Protected by ADMIN_TOKEN (?token=...)
 * - Filter by unsubscribed_at between start/end dates (DD/MM/YYYY)
 * - Display results
 * - Download filtered CSV
 *
 * URLs:
 *   View page:
 *     /admin.php?token=YOURTOKEN
 *   Filter:
 *     /admin.php?token=YOURTOKEN&start=12/12/2025&end=20/12/2025
 *   Download filtered CSV:
 *     /admin.php?token=YOURTOKEN&start=12/12/2025&end=20/12/2025&download=1
 */

function plain(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requireToken(): string {
  $requiredToken = getenv('ADMIN_TOKEN') ?: '';
  $providedToken = $_GET['token'] ?? '';

  if ($requiredToken === '') {
    plain(500, 'Server misconfiguration: ADMIN_TOKEN not set');
  }
  if (!is_string($providedToken) || $providedToken === '' || !hash_equals($requiredToken, $providedToken)) {
    plain(403, 'Forbidden');
  }
  return $providedToken;
}

/**
 * Parse DD/MM/YYYY into a UTC datetime range.
 * Returns [startDateTimeString, endDateTimeString] for SQL, or [null, null] if no dates provided.
 * Throws on invalid input.
 */
function parseDateRange(?string $startRaw, ?string $endRaw): array {
  $startRaw = is_string($startRaw) ? trim($startRaw) : '';
  $endRaw   = is_string($endRaw) ? trim($endRaw) : '';

  if ($startRaw === '' && $endRaw === '') {
    return [null, null];
  }
  if ($startRaw === '' || $endRaw === '') {
    throw new RuntimeException('Please provide both start and end dates (DD/MM/YYYY).');
  }

  $tz = new DateTimeZone('UTC');

  $start = DateTime::createFromFormat('d/m/Y', $startRaw, $tz);
  $end   = DateTime::createFromFormat('d/m/Y', $endRaw, $tz);

  $startErrors = DateTime::getLastErrors();
  if ($start === false || ($startErrors['warning_count'] ?? 0) > 0 || ($startErrors['error_count'] ?? 0) > 0) {
    throw new RuntimeException('Invalid start date. Use DD/MM/YYYY (e.g. 12/12/2025).');
  }

  $endErrors = DateTime::getLastErrors();
  if ($end === false || ($endErrors['warning_count'] ?? 0) > 0 || ($endErrors['error_count'] ?? 0) > 0) {
    throw new RuntimeException('Invalid end date. Use DD/MM/YYYY (e.g. 20/12/2025).');
  }

  // Inclusive range: start 00:00:00 to end 23:59:59 (UTC)
  $start->setTime(0, 0, 0);
  $end->setTime(23, 59, 59);

  if ($end < $start) {
    throw new RuntimeException('End date must be on or after start date.');
  }

  return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function dbConnect(): PDO {
  $dbHost = getenv('DB_HOST') ?: '';
  $dbPort = getenv('DB_PORT') ?: '25060';
  $dbName = getenv('DB_NAME') ?: '';
  $dbUser = getenv('DB_USER') ?: '';
  $dbPass = getenv('DB_PASS') ?: '';
  $dbCa   = getenv('DB_SSL_CA_PATH') ?: '';

  if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
    plain(500, 'Server misconfiguration: database env vars missing');
  }

  $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];

  if ($dbCa !== '' && file_exists($dbCa)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $dbCa;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
  }

  return new PDO($dsn, $dbUser, $dbPass, $options);
}

$token = requireToken();

$startInput = isset($_GET['start']) ? (string)$_GET['start'] : '';
$endInput   = isset($_GET['end']) ? (string)$_GET['end'] : '';
$download   = (isset($_GET['download']) && (string)$_GET['download'] === '1');

try {
  [$startSql, $endSql] = parseDateRange($startInput, $endInput);
} catch (Throwable $e) {
  // Render page with error (no download)
  $startSql = $endSql = null;
  $errorMsg = $e->getMessage();
  $download = false;
}

try {
  $pdo = dbConnect();
} catch (Throwable $e) {
  error_log('Admin DB connection error: ' . $e->getMessage());
  plain(500, 'DB connection failed');
}

// Ensure table exists (safe to run repeatedly)
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS unsubscribes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(320) NOT NULL,
      site VARCHAR(100) NULL,
      unsubscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  error_log('Admin table ensure error: ' . $e->getMessage());
  plain(500, 'Failed to ensure table exists');
}

/**
 * Build WHERE clause for optional date filtering
 */
$where = '';
$params = [];
if ($startSql !== null && $endSql !== null) {
  $where = "WHERE unsubscribed_at BETWEEN :start_dt AND :end_dt";
  $params[':start_dt'] = $startSql;
  $params[':end_dt'] = $endSql;
}

if ($download) {
  // Stream CSV download (filtered if dates provided)
  $filenameSuffix = ($startSql && $endSql)
    ? ('_' . str_replace([':', ' '], ['-', '_'], $startSql) . '_to_' . str_replace([':', ' '], ['-', '_'], $endSql))
    : '_all';

  $filename = 'unsubscribes' . $filenameSuffix . '_UTC.csv';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = fopen('php://output', 'w');
  if ($out === false) {
    plain(500, 'Failed to open output stream');
  }

  fputcsv($out, ['email', 'site', 'unsubscribed_at']);

  $sql = "
    SELECT email, site, unsubscribed_at
    FROM unsubscribes
    $where
    ORDER BY unsubscribed_at DESC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      (string)($row['email'] ?? ''),
      (string)($row['site'] ?? ''),
      (string)($row['unsubscribed_at'] ?? ''),
    ]);
  }

  fclose($out);
  exit;
}

// For display: show up to N rows (keeps page fast)
$limit = 500;

// Count total matching rows
$countSql = "SELECT COUNT(*) AS cnt FROM unsubscribes $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

// Fetch sample rows for display
$listSql = "
  SELECT email, site, unsubscribed_at
  FROM unsubscribes
  $where
  ORDER BY unsubscribed_at DESC
  LIMIT {$limit}
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Build URLs
$basePath = strtok($_SERVER['REQUEST_URI'], '?');
$self = $basePath;

$queryBase = [
  'token' => $token,
  'start' => $startInput,
  'end'   => $endInput,
];

$downloadQuery = $queryBase;
$downloadQuery['download'] = '1';

function buildUrl(string $path, array $query): string {
  // Remove empty start/end to keep URL clean
  foreach (['start','end'] as $k) {
    if (!isset($query[$k]) || trim((string)$query[$k]) === '') {
      unset($query[$k]);
    }
  }
  return $path . '?' . http_build_query($query);
}

$downloadUrl = buildUrl($self, $downloadQuery);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unsubscribe Admin</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 40px; }
    .wrap { max-width: 980px; }
    .card { padding: 18px; border: 1px solid #ddd; border-radius: 12px; }
    label { display:block; margin-top: 10px; font-weight: 600; }
    input { padding: 10px 12px; font-size: 16px; width: 260px; max-width: 100%; }
    .row { display:flex; gap: 14px; flex-wrap: wrap; align-items: end; }
    button { padding: 10px 14px; font-size: 16px; cursor: pointer; }
    .muted { color: #666; }
    .error { color: #b00020; margin-top: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border-bottom: 1px solid #eee; text-align: left; padding: 10px; vertical-align: top; }
    th { background: #fafafa; position: sticky; top: 0; }
    .pill { display:inline-block; padding: 2px 8px; border: 1px solid #ddd; border-radius: 999px; font-size: 12px; }
    .actions { margin-top: 14px; display:flex; gap: 10px; flex-wrap: wrap; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Unsubscribe Admin</h1>

    <div class="card">
      <form method="get" action="<?= h($self) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="row">
          <div>
            <label for="start">Start date (DD/MM/YYYY)</label>
            <input id="start" name="start" value="<?= h($startInput) ?>" placeholder="12/12/2025">
          </div>

          <div>
            <label for="end">End date (DD/MM/YYYY)</label>
            <input id="end" name="end" value="<?= h($endInput) ?>" placeholder="20/12/2025">
          </div>

          <div>
            <button type="submit">Search</button>
          </div>
        </div>

        <p class="muted" style="margin-top:10px;">
          Dates are treated as UTC day boundaries (start 00:00:00 → end 23:59:59).
        </p>

        <?php if (isset($errorMsg) && $errorMsg !== ''): ?>
          <p class="error"><?= h($errorMsg) ?></p>
        <?php endif; ?>
      </form>

      <div class="actions">
        <a href="<?= h($downloadUrl) ?>">
          <button type="button">Download CSV (current filter)</button>
        </a>
        <span class="pill"><?= h((string)$totalCount) ?> matching rows</span>
        <span class="muted">
          Showing up to <?= h((string)$limit) ?> rows on screen.
        </span>
      </div>

      <?php if ($totalCount === 0): ?>
        <p class="muted" style="margin-top:16px;">No results found.</p>
      <?php else: ?>
        <div style="overflow:auto; max-height: 520px; margin-top: 10px;">
          <table>
            <thead>
              <tr>
                <th>Email</th>
                <th>Site</th>
                <th>Unsubscribed at (DB timestamp)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= h((string)($r['email'] ?? '')) ?></td>
                  <td><?= h((string)($r['site'] ?? '')) ?></td>
                  <td><?= h((string)($r['unsubscribed_at'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <p class="muted" style="margin-top:14px;">
      Tip: Keep this URL private (it contains your token).
    </p>
  </div>
</body>
</html>
