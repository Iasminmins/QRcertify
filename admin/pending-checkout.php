<?php
/**
 * Página exibida quando a empresa está com subscription_status = pending_checkout.
 * O usuário está logado mas ainda não concluiu o checkout no Asaas.
 */
require_once __DIR__ . '/../config/config.php';

// Precisa estar logado como admin
if (!isLoggedIn() || currentUser()['role'] !== 'admin') {
    redirect('/login.php');
}

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$stmt = $db->prepare('SELECT plan, subscription_status FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Se o status já foi atualizado pelo webhook, redireciona pro dashboard
if ($company && $company['subscription_status'] !== 'pending_checkout') {
    redirect('/admin/dashboard.php');
}

$plan = $company['plan'] ?? 'pro';
// URL para reiniciar o checkout caso necessário
$checkoutUrl = BASE_URL . '/checkout.php?plan=' . urlencode($plan);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout Pendente | <?= e(SITE_NAME) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { theme: { extend: { colors: { 'industrial-blue': '#1e3a8a', 'industrial-yellow': '#facc15' } } } }</script>
  <meta http-equiv="refresh" content="15;url=<?= BASE_URL ?>/admin/pending-checkout.php">
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">

    <!-- Ícone -->
    <div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mx-auto mb-5">
      <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>

    <h1 class="text-xl font-bold text-gray-900 mb-2">Aguardando confirmação do pagamento</h1>
    <p class="text-sm text-gray-500 mb-6">
      Você cadastrou seu cartão no Asaas. Estamos aguardando a confirmação da assinatura.<br>
      Isso pode levar alguns segundos. Esta página atualiza automaticamente.
    </p>

    <!-- Spinner de atualização -->
    <div class="flex items-center justify-center gap-2 text-sm text-gray-400 mb-8">
      <svg class="animate-spin h-4 w-4 text-industrial-blue" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
      Verificando a cada 15 segundos…
    </div>

    <div class="space-y-3">
      <!-- Atualizar agora -->
      <a href="<?= BASE_URL ?>/admin/pending-checkout.php"
         class="block w-full bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">
        Verificar agora
      </a>

      <!-- Refazer checkout se necessário -->
      <a href="<?= e($checkoutUrl) ?>"
         class="block w-full border border-gray-200 text-gray-600 font-medium py-2.5 rounded-xl hover:bg-gray-50 transition-colors text-sm">
        Não recebi o link — refazer checkout
      </a>

      <!-- Sair -->
      <a href="<?= BASE_URL ?>/logout.php"
         class="block text-xs text-gray-400 hover:text-gray-600 pt-1">
        Sair da conta
      </a>
    </div>
  </div>

  <script>
    // Também tenta verificar via fetch sem recarregar a página inteira
    setInterval(async () => {
      try {
        const r = await fetch('<?= BASE_URL ?>/admin/pending-checkout.php', { method: 'HEAD' });
        // Se retornar redirect (3xx) significa que o status mudou
        if (r.redirected) window.location.href = r.url;
      } catch {}
    }, 8000);
  </script>
</body>
</html>
