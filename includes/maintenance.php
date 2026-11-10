<?php
/**
 * Rotinas de manutenção de execução única.
 * Usadas pelos scripts de linha de comando (sql/020 e sql/024)
 * e pela tela superadmin/manutencao.php (para hospedagem sem SSH).
 */

/**
 * Troca os public_hash previsíveis gerados pela migração 005
 * por hashes aleatórios. Hashes que já são aleatórios não mudam.
 * Retorna quantos colaboradores foram atualizados.
 */
function regeneratePredictablePublicHashes(PDO $db): int {
    $stmt = $db->query(
        "SELECT id, public_hash FROM employees
         WHERE public_hash = LOWER(SHA2(CONCAT('emp-wallet-', id, '-', UNIX_TIMESTAMP(created_at)), 256))"
    );
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE employees SET public_hash = ? WHERE id = ? AND public_hash = ?');
    $updated = 0;

    foreach ($employees as $employee) {
        $update->execute([generateHash(), $employee['id'], $employee['public_hash']]);
        $updated += $update->rowCount();
    }

    return $updated;
}

/**
 * Calcula o SHA-256 dos arquivos que já existiam antes da migração 021.
 * Retorna ['updated' => int, 'missing' => int].
 */
function backfillFileSha256(PDO $db): array {
    $root = realpath(__DIR__ . '/..');
    $tables = [
        'certificates'       => 'document',
        'employee_asos'      => 'file_path',
        'employee_documents' => 'file_path',
    ];
    $updated = 0;
    $missing = 0;

    foreach ($tables as $table => $pathColumn) {
        $rows = $db->query(
            "SELECT id, {$pathColumn} AS file_path FROM {$table}
             WHERE {$pathColumn} IS NOT NULL AND {$pathColumn} <> '' AND file_sha256 IS NULL"
        )->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare("UPDATE {$table} SET file_sha256 = ? WHERE id = ? AND file_sha256 IS NULL");

        foreach ($rows as $row) {
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['file_path']);
            $path = realpath($root . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR));
            if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
                $missing++;
                continue;
            }

            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                $missing++;
                continue;
            }
            $update->execute([$sha256, $row['id']]);
            $updated += $update->rowCount();
        }
    }

    return ['updated' => $updated, 'missing' => $missing];
}
