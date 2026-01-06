<?php
declare(strict_types=1);

/**
 * Central Unsubscribe API
 * - Expects POST: email, site (optional)
 * - Auto-creates table on first run
 * - Uses SSL if CA cert exists
 */

function respond(int $code, string $message): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $message;
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, 'Method Not Allowed');
}

$email = trim($_POST['email'] ?? '');
$site  = trim($_POST['site'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(400, 'Invalid email address');
}

/* Load environment variables */
$dbHost = getenv('DB_HOST') ?: '';
$dbPort = getenv('DB_PORT') ?: '25060';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';
$dbCa   = getenv('DB_SSL_CA_PATH') ?: '';

if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
  respond(500, 'Server misconfiguration: database environment variables missing');
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_EMULATE_PREPARES => false,
];

/* Enable SSL if CA cert exists */
if ($dbCa !== '' && file_exists($dbCa)) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = $dbCa;
  $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
  $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

  /* Create table if it does not exist */
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS unsubscribes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(320) NOT NULL,
      site VARCHAR(100) NULL,
      unsubscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  /* Insert or update */
  $stmt = $pdo->prepare("
    INSERT INTO unsubscribes (email, site)
    VALUES (:email, :site)
    ON DUPLICATE KEY UPDATE
      unsubscribed_at = CURRENT_TIMESTAMP,
      site = VALUES(site)
  ");

  $stmt->execute([
    ':email' => $email,
    ':site'  => $site,
  ]);

  respond(200, 'You have been unsubscribed.');
} catch (Throwable $e) {
  error_log('Unsubscribe API error: ' . $e->getMessage());
  respond(500, 'Internal Server Error');
}
