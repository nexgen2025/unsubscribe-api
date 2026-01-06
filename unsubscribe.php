<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$email = trim($_POST['email'] ?? '');
$site  = trim($_POST['site'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  exit('Invalid email address');
}

$dsn = sprintf(
  'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
  getenv('DB_HOST'),
  getenv('DB_PORT'),
  getenv('DB_NAME')
);

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::MYSQL_ATTR_SSL_CA => getenv('DB_SSL_CA_PATH'),
];

$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), $options);

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

echo 'You have been unsubscribed.';
