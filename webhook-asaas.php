<?php
/**
 * Webhook Asaas - recebe notificacoes de pagamento.
 *
 * Configure no painel do Asaas:
 * URL: https://qrcertify.com.br/webhook-asaas.php
 * Token de autenticacao: mesmo valor de ASAAS_WEBHOOK_TOKEN.
 */
require_once __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// O Asaas envia o token configurado no header "asaas-access-token".
$expectedToken = defined('ASAAS_WEBHOOK_TOKEN') ? ASAAS_WEBHOOK_TOKEN : '';
$receivedToken = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';

if ($expectedToken === '') {
    error_log('[Asaas Webhook] ASAAS_WEBHOOK_TOKEN nao configurado.');
    http_response_code(500);
    exit('Webhook token not configured');
}

if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    error_log('[Asaas Webhook] Token invalido.');
    http_response_code(401);
    exit('Unauthorized');
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !isset($payload['event'])) {
    http_response_code(400);
    exit('Bad Request');
}

$event = $payload['event'];
$data = $payload['payment'] ?? $payload['subscription'] ?? [];

error_log("[Asaas Webhook] Event: {$event}");

$db = getDB();

$statusMap = [
    'PAYMENT_RECEIVED'         => 'active',
    'PAYMENT_CONFIRMED'        => 'active',
    'PAYMENT_OVERDUE'          => 'overdue',
    'PAYMENT_DELETED'          => 'canceled',
    'SUBSCRIPTION_INACTIVATED' => 'canceled',
    'SUBSCRIPTION_DELETED'     => 'canceled',
];

$newStatus = $statusMap[$event] ?? null;

if ($newStatus && !empty($data['subscription'])) {
    $subscriptionId = is_array($data['subscription'])
        ? ($data['subscription']['id'] ?? null)
        : $data['subscription'];

    if ($subscriptionId) {
        $stmt = $db->prepare(
            'UPDATE companies SET subscription_status = ? WHERE asaas_subscription_id = ?'
        );
        $stmt->execute([$newStatus, $subscriptionId]);

        if (in_array($newStatus, ['canceled', 'overdue'], true)) {
            $stmtCo = $db->prepare(
                'SELECT id FROM companies WHERE asaas_subscription_id = ?'
            );
            $stmtCo->execute([$subscriptionId]);
            $company = $stmtCo->fetch();

            if ($company) {
                $stmt2 = $db->prepare(
                    'INSERT INTO audit_log (company_id, action, target, detail, ip)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt2->execute([
                    $company['id'],
                    'subscription.' . $newStatus,
                    'asaas_subscription',
                    json_encode(['event' => $event, 'subscription' => $subscriptionId]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            }
        }
    }
}

http_response_code(200);
echo 'OK';
