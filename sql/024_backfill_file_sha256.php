<?php
// Execução única pelo terminal: php sql/024_backfill_file_sha256.php
// Sem SSH? Use a tela Superadmin > Manutenção.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/maintenance.php';

$result = backfillFileSha256(getDB());
echo "Arquivos atualizados: {$result['updated']}" . PHP_EOL;
echo "Arquivos ausentes ou ilegíveis: {$result['missing']}" . PHP_EOL;
