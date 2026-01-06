<?php
// Protect this with a password or IP allowlist in real usage!

$pdo = new PDO(
  "mysql:host=" . getenv('DB_HOST') .
  ";port=" . getenv('DB_PORT') .
  ";dbname=" . getenv('DB_NAME') .
  ";charset=utf8mb4",
  getenv('DB_USER'),
  getenv('DB_PASS'),
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_SSL_CA => getenv('DB_SSL_CA_PATH'),
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
  ]
);

$rows = $pdo->query("
  SELECT email, site, unsubscribed_at
  FROM unsubscribes
  ORDER BY unsubscribed_at DESC
")->fetchAll();
?>
<!doctype html>
<html>
<body>
  <h1>Unsubscribes</h1>
  <table border="1" cellpadding="6">
    <tr><th>Email</th><th>Site</th><th>Date</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['site']) ?></td>
        <td><?= htmlspecialchars($r['unsubscribed_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
