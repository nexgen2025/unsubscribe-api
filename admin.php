<?php
declare(strict_types=1);

/**
 * admin.php
 * - Shows a tiny admin page with a "Download CSV" button
 * - Requires an ADMIN_TOKEN (set in App Platform env vars)
 *
 * Usage:
 *   https://your-app.ondigitalocean.app/admin.php?token=YOUR_TOKEN
 *   Then click "Download CSV"
 */

function deny(string $msg = 'Forbidden'): void {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

$requiredToken = getenv('ADMIN_TOKEN') ?: '';
$providedToken = $_GET['token'] ?? '';

if ($requiredToken === '') {
  deny('Server misconfiguration: ADMIN_TOKEN not set');
}
if (!is_string($providedToken) || !hash_equals($requiredToken, $providedToken)) {
  deny('Forbidden');
}

$dbHost = getenv('DB_HOST') ?: '';
$dbPort = getenv('DB_PORT') ?: '25060';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';
$dbCa   = getenv('DB_SSL_CA_PATH') ?: '';

if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Server misconfiguration: database env vars missing';
  exit;
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

try {
  $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (Throwable $e) {
  error_log('Admin DB connection error: ' . $e->getMessage());
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'DB connection failed';
  exit;
}

$download = ($_GET['download'] ?? '') === '1';

if ($download) {
  // Stream CSV download
  $filename = 'unsubscribes_' . gmdate('Y-m-d_H-i-s') . '_UTC.csv';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = fopen('php://output', 'w');
  if ($out === false) {
    http_response_code(500);
    exit;
  }

  // CSV header row
  fputcsv($out, ['email', 'site', 'unsubscribed_at']);

  // Stream rows
  $stmt = $pdo->prepare("
    SELECT email, site, unsubscribed_at
    FROM unsubscribes
    ORDER BY unsubscribed_at DESC
  ");
  $stmt->execute();

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

// Admin page HTML
$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$self = htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tokenEsc = htmlspecialchars($providedToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$downloadUrl = $self . '?token=' . rawurlencode($providedToken) . '&download=1';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unsubscribe Admin</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 40px; }
    .card { max-width: 560px; padding: 18px; border: 1px solid #ddd; border-radius: 10px; }
    button { padding: 10px 14px; font-size: 16px; cursor: pointer; }
    code { background: #f6f6f6; padding: 2px 6px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Unsubscribe Admin</h1>
    <p>Token accepted.</p>

    <p>
      <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <button type="button">Download CSV</button>
      </a>
    </p>

    <p style="color:#666">
      Keep this URL private. Your token is passed in the query string:
      <code>?token=<?= $tokenEsc ?></code>
    </p>
  </div>
</body>
</html>
