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

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !isset($payload['event'])) {
    http_response_code(400);
    exit('Bad Request');
}

$event = $payload['event'];

// Seleciona o objeto principal pelo tipo de evento
if (str_starts_with($event, 'SUBSCRIPTION_')) {
    $data = $payload['subscription'] ?? [];
} elseif (str_starts_with($event, 'CHECKOUT_')) {
    $data = $payload['checkout'] ?? [];
} else {
    $data = $payload['payment'] ?? $payload['subscription'] ?? $payload['checkout'] ?? [];
}

$db = getDB();

$extractId = static function ($value): ?string {
    if (is_array($value)) return isset($value['id']) ? (string) $value['id'] : null;
    return $value ? (string) $value : null;
};

// Para SUBSCRIPTION_*, o ID da assinatura e $data['id']
// Para outros eventos, a assinatura e uma referencia: $data['subscription']
if (str_starts_with($event, 'SUBSCRIPTION_')) {
    $subscriptionId = $extractId($data['id'] ?? null);
} else {
    $subscriptionId = $extractId($data['subscription'] ?? null);
}

$customerId = $extractId($data['customer'] ?? null);

// Tenta encontrar externalReference no payload
$externalReference = $data['externalReference']
    ?? ($payload['checkout']['externalReference']     ?? null)
    ?? ($payload['payment']['externalReference']      ?? null)
    ?? ($payload['subscription']['externalReference'] ?? null)
    ?? null;

// Se ainda nulo, consulta a API do Asaas pela assinatura
if (!$externalReference && $subscriptionId) {
    $subDetails        = asaasRequest('GET', '/subscriptions/' . $subscriptionId);
    $externalReference = $subDetails['externalReference'] ?? null;
}

// Log para debug
$debugLine = date('Y-m-d H:i:s')
    . ' event='  . $event
    . ' sub='    . ($subscriptionId    ?? 'NULL')
    . ' cus='    . ($customerId        ?? 'NULL')
    . ' ref='    . ($externalReference ?? 'NULL')
    . "\n";
@file_put_contents(__DIR__ . '/logs/webhook-debug.log', $debugLine, FILE_APPEND);

// Tenta resolver company pelo externalReference
$companyIdFromRef = null;
$planFromRef      = null;
if (is_string($externalReference)
    && preg_match('/^company_(\d+)_plan_([a-z0-9_-]+)$/i', $externalReference, $m)) {
    $companyIdFromRef = (int) $m[1];
    $planFromRef      = strtolower($m[2]);
}

// Ultimo recurso: busca pelo asaas_customer_id ja salvo no banco
if (!$companyIdFromRef && $customerId) {
    $stmtCus = $db->prepare(
        'SELECT id, plan FROM companies WHERE asaas_customer_id = ? LIMIT 1'
    );
    $stmtCus->execute([$customerId]);
    $coByCustomer = $stmtCus->fetch();
    if ($coByCustomer) {
        $companyIdFromRef = (int) $coByCustomer['id'];
        $planFromRef      = $coByCustomer['plan'];
    }
}

$statusMap = [
    'PAYMENT_RECEIVED'         => 'active',
    'PAYMENT_CONFIRMED'        => 'active',
    'PAYMENT_OVERDUE'          => 'overdue',
    'PAYMENT_DELETED'          => 'canceled',
    'CHECKOUT_PAID'            => 'trialing',
    'CHECKOUT_CANCELED'        => 'canceled',
    'CHECKOUT_EXPIRED'         => 'canceled',
    'SUBSCRIPTION_CREATED'     => 'trialing',
    'SUBSCRIPTION_INACTIVATED' => 'canceled',
    'SUBSCRIPTION_DELETED'     => 'canceled',
];

$newStatus = $statusMap[$event] ?? null;

// Régua de carência: define como a coluna overdue_since deve mudar conforme o status.
//  - overdue  -> grava a data de hoje SE ainda não houver (início da contagem)
//  - active   -> limpa (pagou, zera a carência)
//  - demais   -> não mexe
$overdueSql = '';        // fragmento extra no SET
if ($newStatus === 'overdue') {
    $overdueSql = ', overdue_since = COALESCE(overdue_since, CURDATE())';
} elseif ($newStatus === 'active') {
    $overdueSql = ', overdue_since = NULL';
}

if ($newStatus) {
    $updatedCompanyId = null;

    // 1) Tenta pelo asaas_subscription_id ja cadastrado no banco
    if ($subscriptionId) {
        $stmt = $db->prepare(
            'UPDATE companies
                SET subscription_status = ?,
                    asaas_customer_id   = COALESCE(asaas_customer_id, ?)' . $overdueSql . '
              WHERE asaas_subscription_id = ?'
        );
        $stmt->execute([$newStatus, $customerId, $subscriptionId]);

        if ($stmt->rowCount() > 0) {
            $stmtCo = $db->prepare('SELECT id FROM companies WHERE asaas_subscription_id = ?');
            $stmtCo->execute([$subscriptionId]);
            $co = $stmtCo->fetch();
            if ($co) $updatedCompanyId = (int) $co['id'];
        }
    }

    // 2) Tenta pelo externalReference ou customer_id
    if (!$updatedCompanyId && $companyIdFromRef) {
        $stmt = $db->prepare(
            'UPDATE companies
                SET subscription_status   = ?,
                    plan                  = COALESCE(?, plan),
                    asaas_customer_id     = COALESCE(asaas_customer_id, ?),
                    asaas_subscription_id = COALESCE(asaas_subscription_id, ?)' . $overdueSql . '
              WHERE id = ?'
        );
        $stmt->execute([$newStatus, $planFromRef, $customerId, $subscriptionId, $companyIdFromRef]);
        if ($stmt->rowCount() > 0) $updatedCompanyId = $companyIdFromRef;
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
                'event'             => $event,
                'subscription'      => $subscriptionId,
                'externalReference' => $externalReference,
            ]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

http_response_code(200);
echo 'OK';
