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
$data = $payload['payment'] ?? $payload['subscription'] ?? $payload['checkout'] ?? [];

error_log("[Asaas Webhook] Event: {$event}");

$db = getDB();

$extractId = static function ($value): ?string {
    if (is_array($value)) {
        return isset($value['id']) ? (string) $value['id'] : null;
    }
    return $value ? (string) $value : null;
};

$subscriptionId = $extractId($data['subscription'] ?? null);
$customerId = $extractId($data['customer'] ?? null);
$externalReference = $data['externalReference']
    ?? ($payload['checkout']['externalReference'] ?? null)
    ?? ($payload['payment']['externalReference'] ?? null)
    ?? ($payload['subscription']['externalReference'] ?? null)
    ?? null;

$companyIdFromRef = null;
$planFromRef = null;
if (is_string($externalReference) && preg_match('/^company_(\d+)_plan_([a-z0-9_-]+)$/i', $externalReference, $m)) {
    $companyIdFromRef = (int) $m[1];
    $planFromRef = strtolower($m[2]);
}

$statusMap = [
    'PAYMENT_RECEIVED'         => 'active',
    'PAYMENT_CONFIRMED'        => 'active',
    'PAYMENT_OVERDUE'          => 'overdue',
    'PAYMENT_DELETED'          => 'canceled',
    'CHECKOUT_PAID'            => 'trialing',
    'CHECKOUT_CANCELED'        => 'canceled',
    'CHECKOUT_EXPIRED'         => 'canceled',
    'SUBSCRIPTION_INACTIVATED' => 'canceled',
    'SUBSCRIPTION_DELETED'     => 'canceled',
];

$newStatus = $statusMap[$event] ?? null;

if ($newStatus) {
    $updatedCompanyId = null;

    if ($subscriptionId) {
        $stmt = $db->prepare(
            'UPDATE companies
                SET subscription_status = ?,
                    asaas_customer_id = COALESCE(asaas_customer_id, ?)
              WHERE asaas_subscription_id = ?'
        );
        $stmt->execute([$newStatus, $customerId, $subscriptionId]);

        if ($stmt->rowCount() > 0) {
            $stmtCo = $db->prepare(
                'SELECT id FROM companies WHERE asaas_subscription_id = ?'
            );
            $stmtCo->execute([$subscriptionId]);
            $company = $stmtCo->fetch();
            if ($company) {
                $updatedCompanyId = (int) $company['id'];
            }
        }
    }

    if (!$updatedCompanyId && $companyIdFromRef) {
        $stmt = $db->prepare(
            'UPDATE companies
                SET subscription_status = ?,
                    plan = COALESCE(?, plan),
                    asaas_customer_id = COALESCE(asaas_customer_id, ?),
                    asaas_subscription_id = COALESCE(asaas_subscription_id, ?)
              WHERE id = ?'
        );
        $stmt->execute([$newStatus, $planFromRef, $customerId, $subscriptionId, $companyIdFromRef]);
        if ($stmt->rowCount() > 0) {
            $updatedCompanyId = $companyIdFromRef;
        }
    }

    if ($updatedCompanyId && in_array($newStatus, ['canceled', 'overdue', 'trialing', 'active'], true)) {
        $stmt2 = $db->prepare(
            'INSERT INTO audit_log (company_id, action, target, detail, ip)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt2->execute([
            $updatedCompanyId,
            'subscription.' . $newStatus,
            'asaas_webhook',
            json_encode([
                'event' => $event,
                'subscription' => $subscriptionId,
                'externalReference' => $externalReference,
            ]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

http_response_code(200);
echo 'OK';
