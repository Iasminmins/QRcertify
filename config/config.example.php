<?php
/**
 * Copie este arquivo para config/config.php e ajuste os valores.
 * O arquivo config.php real nao deve ser commitado.
 */

function envValue(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host && strpos($host, 'localhost') !== false) {
    define('BASE_URL', envValue('BASE_URL', 'http://localhost/site-carteira-php'));
} else {
    define('BASE_URL', envValue('BASE_URL', 'https://seudominio.com.br'));
}

define('SITE_NAME', envValue('SITE_NAME', 'QRCertify'));

define('MAIL_FROM',      envValue('MAIL_FROM', 'noreply@seudominio.com.br'));
define('MAIL_FROM_NAME', envValue('MAIL_FROM_NAME', 'QRCertify'));
define('CERT_ALERT_DAYS', (int) envValue('CERT_ALERT_DAYS', '30'));

define('CRON_SECRET', envValue('CRON_SECRET', 'altere_para_um_secret_aleatorio'));

define('ASAAS_ENV',           envValue('ASAAS_ENV', 'sandbox'));
define('ASAAS_KEY',           envValue('ASAAS_KEY', 'sua_chave_sandbox_asaas'));
define('ASAAS_KEY_PROD',      envValue('ASAAS_KEY_PROD', 'sua_chave_producao_asaas'));
define('ASAAS_WEBHOOK_TOKEN', envValue('ASAAS_WEBHOOK_TOKEN', 'token_webhook_asaas_com_32_ou_mais_caracteres'));
define('ASAAS_URL', ASAAS_ENV === 'production'
    ? 'https://api.asaas.com/v3'
    : 'https://sandbox.asaas.com/api/v3'
);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/auth.php';
