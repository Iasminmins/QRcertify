<?php
/**
 * Página de assinatura pendente.
 * Aparece quando a empresa precisa pagar para (re)ativar o acesso:
 *  - trial terminou
 *  - assinatura venceu (overdue) e a carência estourou
 *  - status pending_checkout
 *
 * Aqui a pessoa GERA o checkout do Asaas (Pix/boleto/cartão) e paga.
 * Nenhum dado é apagado em nenhum momento.
 */
require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn() || currentUser()['role'] !== 'admin') {
    redirect('/login.php');
}

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$stmt = $db->prepare(
    'SELECT id, name, cnpj, phone, email, plan, subscription_status, asaas_customer_id
       FROM companies WHERE id = ?'
);
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Se já está ativo, não precisa estar aqui.
if ($company && $company['subscription_status'] === 'active') {
    redirect('/admin/dashboard.php');
}

$plan      = $company['plan'] ?? 'pro';
$planDef   = getPlanDefinition($plan);
$planName  = $planDef['name'];
$planPrice = (int) $planDef['price'];

$error      = null;
$checkoutUrl = null;

// POST: gerar o checkout do Asaas e redirecionar para o pagamento.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_checkout') {
    if (!csrfCheck()) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        try {
            $externalReference = "company_{$companyId}_plan_{$plan}";

            // Garante um cliente no Asaas (reusa o já salvo, se houver).
            $customerId = $company['asaas_customer_id'] ?: null;
            if (!$customerId) {
                $customerPayload = array_filter([
                    'name'              => $company['name'] ?: $user['name'],
                    'email'             => $company['email'] ?: $user['email'],
                    'cpfCnpj'           => $company['cnpj'] ?: null,
                    'phone'             => $company['phone'] ?: null,
                    'externalReference' => $externalReference,
                ], static fn($v) => $v !== null && $v !== '');
                $custResp = asaasRequest('POST', '/customers', $customerPayload);
                if (!empty($custResp['id'])) {
                    $customerId = $custResp['id'];
                    $db->prepare('UPDATE companies SET asaas_customer_id = ? WHERE id = ?')
                       ->execute([$customerId, $companyId]);
                }
            }

            // Primeira cobrança vence hoje (sem novo trial: aqui é hora de pagar).
            $nextDueDate = (new \DateTime('today'))->format('Y-m-d');

            $checkoutPayload = [
                'billingTypes'      => ['CREDIT_CARD', 'BOLETO', 'PIX'],
                'chargeTypes'       => ['RECURRENT'],
                'minutesToExpire'   => 1440,
                'externalReference' => $externalReference,
                'callback'          => [
                    'successUrl' => BASE_URL . '/admin/pending-checkout.php?paid=1',
                    'cancelUrl'  => BASE_URL . '/admin/pending-checkout.php?status=cancelado',
                    'expiredUrl' => BASE_URL . '/admin/pending-checkout.php?status=expirado',
                ],
                'items' => [[
                    'name'        => 'Plano ' . $planName . ' - QRCertify',
                    'description' => 'Assinatura mensal QRCertify',
                    'quantity'    => 1,
                    'value'       => $planPrice,
                ]],
                'subscription' => [
                    'cycle'       => 'MONTHLY',
                    'nextDueDate' => $nextDueDate,
                ],
            ];
            if ($customerId) {
                $checkoutPayload['customer'] = $customerId;
            }

            $resp = asaasRequest('POST', '/checkouts', $checkoutPayload);
            if (empty($resp['id'])) {
                $msg = $resp['errors'][0]['description'] ?? 'Erro desconhecido';
                throw new \Exception($msg);
            }

            $db->prepare('UPDATE companies SET subscription_status = "pending_checkout" WHERE id = ?')
               ->execute([$companyId]);

            $checkoutUrl = $resp['link'] ?? $resp['url'] ?? buildAsaasCheckoutUrl($resp['id']);
            header('Location: ' . $checkoutUrl);
            exit;
        } catch (\Exception $e) {
            $error = 'Não foi possível gerar o pagamento: ' . $e->getMessage();
        }
    }
}

$priceLabel = 'R$ ' . number_format($planPrice, 0, ',', '.') . '/mês';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assinar plano | <?= e(SITE_NAME) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { theme: { extend: { colors: { 'industrial-blue': '#1e3a8a', 'industrial-yellow': '#facc15' } } } }</script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">

    <div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mx-auto mb-5">
      <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>

    <h1 class="text-xl font-bold text-gray-900 mb-2">Ative sua assinatura</h1>
    <p class="text-sm text-gray-500 mb-6">
      Seu período de teste terminou. Para continuar usando o QRCertify,
      assine o plano abaixo. <strong>Seus dados continuam guardados</strong> —
      tudo volta assim que o pagamento for confirmado.
    </p>

    <?php if (!empty($_GET['status']) && $_GET['status'] === 'cancelado'): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm text-amber-700 mb-4">
        Pagamento não concluído. Você pode tentar novamente quando quiser.
      </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700 mb-4"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Resumo do plano -->
    <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 mb-5 text-left">
      <div class="flex items-center justify-between text-sm">
        <span class="font-medium text-gray-700">Plano <?= e($planName) ?></span>
        <span class="font-bold text-gray-900"><?= e($priceLabel) ?></span>
      </div>
      <div class="text-xs text-gray-500 mt-1">Cobrança mensal · Pix, boleto ou cartão · cancele quando quiser</div>
    </div>

    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="start_checkout">
      <button type="submit"
        class="block w-full bg-industrial-blue text-white font-bold py-3 rounded-xl hover:bg-blue-900 transition-colors text-sm">
        Assinar e pagar →
      </button>
    </form>

    <div class="mt-3 space-y-2">
      <a href="<?= BASE_URL ?>/admin/pending-checkout.php"
         class="block w-full border border-gray-200 text-gray-600 font-medium py-2.5 rounded-xl hover:bg-gray-50 transition-colors text-sm">
        Já paguei — verificar agora
      </a>
      <a href="<?= BASE_URL ?>/logout.php" class="block text-xs text-gray-400 hover:text-gray-600 pt-1">
        Sair da conta
      </a>
    </div>

    <p class="text-xs text-gray-400 mt-5">
      Pagamento processado com segurança pelo Asaas.
    </p>
  </div>
</body>
</html>
