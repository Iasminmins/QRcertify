<?php
// Execução única pelo terminal: php sql/020_regenerate_public_hash.php
// Sem SSH? Use a tela Superadmin > Manutenção.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/maintenance.php';

$updated = regeneratePredictablePublicHashes(getDB());
echo "Hashes públicos atualizados: {$updated}" . PHP_EOL;
