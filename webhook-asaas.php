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

// Para SUBSCRIPTION_CREATED o dado principal fica em $payload['subscription'].
// Para PAYMENT_* fica em $payload['payment'].
// Para CHECKOUT_* fica em $payload['checkout'].
// Priorizamos pelo tipo de evento para evitar pegar o objeto errado.
if (str_starts_with($event, 'SUBSCRIPTION_')) {
    $data = $payload['subscription'] ?? $payload['payment'] ?? $payload['checkout'] ?? [];
} elseif (str_starts_with($event, 'CHECKOUT_')) {
    $data = $payload['checkout'] ?? $payload['payment'] ?? $payload['subscription'] ?? [];
} else {
    $data = $payload['payment'] ?? $payload['subscription'] ?? $payload['checkout'] ?? [];
}

error_log("[Asaas Webhook] Event: {$event}");

$db = getDB();

$extractId = static function ($value): ?string {
    if (is_array($value)) {
        return isset($value['id']) ? (string) $value['id'] : null;
    }
    return $value ? (string) $value : null;
};

// Para SUBSCRIPTION_*, $data JÁ É o objeto da assinatura → ID em $data['id']
// Para PAYMENT_*, a assinatura é uma referência dentro do pagamento → $data['subscription']
if (str_starts_with($event, 'SUBSCRIPTION_')) {
    $subscriptionId = $extractId($data['id'] ?? null);
} else {
    $subscriptionId = $extractId($data['subscription'] ?? null);
}

$customerId = $extractId($data['customer'] ?? null);

$externalReference = $data['externalReference']
    ?? ($payload['checkout']['externalReference'] ?? null)
    ?? ($payload['payment']['externalReference'] ?? null)
    ?? ($payload['subscription']['externalReference'] ?? null)
    ?? null;

// Log completo para debug — remover após confirmar funcionamento
error_log('[Asaas Webhook] payload=' . json_encode([
    'event'             => $event,
    'subscriptionId'    => $subscriptionId,
    'customerId'        => $customerId,
    'externalReference' => $externalReference,
]));

$companyIdFromRef = null;
$planFromRef = null;
if (is_string($externalReference) && preg_match('/^company_(\d+)_plan_([a-z0-9_-]+)$/i', $externalReference, $m)) {
    $companyIdFromRef = (int) $m[1];
    $planFromRef = strtolower($m[2]);
}

$statusMap = [
    // Pagamento confirmado → ativa a assinatura
    'PAYMENT_RECEIVED'         => 'active',
    'PAYMENT_CONFIRMED'        => 'active',
    // Inadimplência / cancelamentos
    'PAYMENT_OVERDUE'          => 'overdue',
    'PAYMENT_DELETED'          => 'canceled',
    // Checkout hospedado no Asaas concluído (sem cobrança imediata = trial)
    'CHECKOUT_PAID'            => 'trialing',
    'CHECKOUT_CANCELED'        => 'canceled',
    'CHECKOUT_EXPIRED'         => 'canceled',
    // Assinatura criada via checkout recorrente (trial de 30 dias, sem pagamento imediato)
    // Este é o evento enviado pelo Asaas quando o usuário cadastra o cartão no checkout.
    'SUBSCRIPTION_CREATED'     => 'trialing',
    // Outros estados da assinatura
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
