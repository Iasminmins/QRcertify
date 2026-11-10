<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$db = getDB();
$stmt = $db->query(
    "SELECT id, public_hash FROM employees
     WHERE public_hash = LOWER(SHA2(CONCAT('emp-wallet-', id, '-', UNIX_TIMESTAMP(created_at)), 256))"
);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;

foreach ($employees as $employee) {
    $newHash = generateHash();
    $update = $db->prepare('UPDATE employees SET public_hash = ? WHERE id = ? AND public_hash = ?');
    $update->execute([$newHash, $employee['id'], $employee['public_hash']]);
    $updated += $update->rowCount();
}

echo "Hashes públicos atualizados: {$updated}" . PHP_EOL;
