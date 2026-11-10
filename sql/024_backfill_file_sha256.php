<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$db = getDB();
$root = realpath(__DIR__ . '/..');
$tables = [
    'certificates' => 'document',
    'employee_asos' => 'file_path',
    'employee_documents' => 'file_path',
];
$updated = 0;
$missing = 0;

foreach ($tables as $table => $pathColumn) {
    $stmt = $db->query("SELECT id, {$pathColumn} AS file_path FROM {$table} WHERE {$pathColumn} IS NOT NULL AND {$pathColumn} <> '' AND file_sha256 IS NULL");
    $update = $db->prepare("UPDATE {$table} SET file_sha256 = ? WHERE id = ? AND file_sha256 IS NULL");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['file_path']));
        if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            $missing++;
            continue;
        }

        $sha256 = hash_file('sha256', $path);
        if ($sha256 !== false) {
            $update->execute([$sha256, $row['id']]);
            $updated += $update->rowCount();
        } else {
            $missing++;
        }
    }
}

echo "Arquivos atualizados: {$updated}" . PHP_EOL;
echo "Arquivos ausentes ou ilegíveis: {$missing}" . PHP_EOL;
