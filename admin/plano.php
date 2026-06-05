<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$currentPlan   = $company['plan']                ?? 'starter';
$subStatus     = $company['subscription_status'] ?? 'trialing';
$asaasSubId    = $company['asaas_subscription_id'] ?? null;

$allPlans       = getPlansCatalog();
$plans          = array_intersect_key($allPlans, array_flip(['starter', 'pro', 'business']));
$upgradePlans   = $plans;
$currentPlan    = normalizePlanKey($currentPlan);

$statusLabels = [
    'pending_checkout' => ['label' => 'Checkout pendente', 'color' => 'gray'],
    'trialing' => ['label' => 'Trial gratuito', 'color' => 'blue'],
    'active'   => ['label' => 'Ativo',          'color' => 'green'],
    'overdue'  => ['label' => 'Inadimplente',   'color' => 'red'],
    'canceled' => ['label' => 'Cancelado',      'color' => 'gray'],
];
$statusInfo = $statusLabels[$subStatus] ?? $statusLabels['trialing'];

$errors  = [];
$success = '';

// ── Cancelar assinatura ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrfCheck()) { $errors[] = 'Token inválido.'; }
    else {
        $action = $_POST['action'];

        if ($action === 'cancel' && $asaasSubId) {
            $resp = asaasRequest('DELETE', '/subscriptions/' . $asaasSubId);
            $db->prepare('UPDATE companies SET subscription_status = "canceled" WHERE id = ?')
               ->execute([$companyId]);
            auditLog('subscription.cancel', "company#{$companyId}", json_encode(['plan' => $currentPlan]));
            $success = 'Assinatura cancelada. Você pode continuar usando até o fim do período pago.';
        }

        if ($action === 'upgrade') {
            $newPlan = $_POST['new_plan'] ?? '';
            if (!isset($plans[$newPlan]) || $newPlan === $currentPlan) {
                $errors[] = 'Plano inválido.';
            } else {
                if ($asaasSubId) {
                    $resp = asaasRequest('PUT', '/subscriptions/' . $asaasSubId, [
                        'value' => $plans[$newPlan]['price'],
                        'description' => 'Plano ' . $plans[$newPlan]['name'] . ' — QRCertify',
                    ]);
                    if (!empty($resp['id'])) {
                        $db->prepare('UPDATE companies SET plan = ? WHERE id = ?')
                           ->execute([$newPlan, $companyId]);
                        auditLog('subscription.upgrade', "company#{$companyId}", json_encode(['from' => $currentPlan, 'to' => $newPlan]));
                        $currentPlan = $newPlan;
                        $success = 'Plano atualizado para ' . $plans[$newPlan]['name'] . ' com sucesso!';
                    } else {
                        $errors[] = 'Erro ao atualizar plano no gateway. Tente novamente.';
                    }
                } else {
                    // Sem assinatura ativa — atualiza só no banco
                    $db->prepare('UPDATE companies SET plan = ? WHERE id = ?')
                       ->execute([$newPlan, $companyId]);
                    $currentPlan = $newPlan;
                    $success = 'Plano atualizado para ' . $plans[$newPlan]['name'] . '!';
                }
            }
        }
    }
}

$pageTitle = 'Meu Plano';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Conta funcionários ativos
$empCount = (int)$db->prepare('SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active"')->execute([$companyId]) ? 
    $db->prepare('SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active"') : 0;
$stmtEmp = $db->prepare('SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active"');
$stmtEmp->execute([$companyId]);
$empCount = (int)$stmtEmp->fetchColumn();
$empLimit = getPlanEmployeeLimit($currentPlan);
$empPct   = $empLimit > 0 ? min(100, round($empCount / $empLimit * 100)) : 0;
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Meu Plano</h1>
    <p class="page-subtitle">Gerencie sua assinatura e faça upgrade quando precisar</p>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success mb-6"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div class="alert alert-error mb-6"><?php foreach ($errors as $e) echo '<div>' . e($e) . '</div>'; ?></div>
<?php endif; ?>

<div class="grid gap-6 mb-8" style="grid-template-columns: 1fr 1fr;">

  <!-- Card plano atual -->
  <div class="card p-6">
    <div class="flex items-start justify-between mb-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-1">Plano atual</p>
        <?php $planDef = getPlanDefinition($currentPlan); ?>
        <h2 class="text-2xl font-bold text-gray-900"><?= e($planDef['name']) ?></h2>
        <p class="text-gray-500 text-sm mt-1">R$ <?= number_format($planDef['price'], 0, ',', '.') ?>/mês</p>
      </div>
      <?php $c = $statusInfo['color']; ?>
      <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold
        <?= $c === 'green' ? 'bg-green-100 text-green-700' : ($c === 'blue' ? 'bg-blue-100 text-blue-700' : ($c === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600')) ?>">
        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
        <?= $statusInfo['label'] ?>
      </span>
    </div>

    <!-- Uso de funcionários -->
    <div class="mb-2 flex items-center justify-between text-sm">
      <span class="text-gray-600">Funcionários</span>
      <span class="font-semibold text-gray-900"><?= $empCount ?> / <?= $empLimit ?></span>
    </div>
    <div class="w-full bg-gray-100 rounded-full h-2 mb-4">
      <div class="h-2 rounded-full transition-all <?= $empPct >= 90 ? 'bg-red-500' : ($empPct >= 70 ? 'bg-yellow-400' : 'bg-green-500') ?>"
           style="width:<?= $empPct ?>%"></div>
    </div>
    <?php if ($empPct >= 90): ?>
      <p class="text-xs text-red-600 font-medium">⚠️ Quase no limite! Faça upgrade para continuar crescendo.</p>
    <?php endif; ?>

    <?php if ($subStatus !== 'canceled'): ?>
      <form method="POST" onsubmit="return confirm('Tem certeza que deseja cancelar? Você perderá acesso ao fim do período pago.')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="mt-4 text-xs text-red-500 hover:text-red-700 hover:underline transition-colors">
          Cancelar assinatura
        </button>
      </form>
    <?php else: ?>
      <p class="mt-4 text-xs text-gray-400">Assinatura cancelada. <a href="<?= BASE_URL ?>/checkout.php" class="text-industrial-blue underline">Reativar</a></p>
    <?php endif; ?>
  </div>

  <!-- Card Enterprise -->
  <div class="card p-6 border-2 border-dashed border-gray-200 flex flex-col justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-1">Precisa de mais?</p>
      <h2 class="text-xl font-bold text-gray-900 mb-1">Enterprise</h2>
      <p class="text-2xl font-extrabold text-gray-900 mb-1">R$ 997<span class="text-xs font-normal text-gray-400">/mês</span></p>
      <p class="text-xs text-gray-500 mb-1">+ R$ 2.000 de implantação <span class="text-gray-400">(único)</span></p>
      <p class="text-sm text-gray-500 mb-4 mt-2">Até 800 colaboradores, suporte dedicado e onboarding completo.</p>
    </div>
    <a href="mailto:qrcertify@gmail.com?subject=Interesse+Enterprise" class="btn btn-secondary text-sm text-center">Falar com vendas</a>
  </div>
</div>

<!-- Upgrade de plano -->
<?php if ($subStatus !== 'canceled'): ?>
<div class="card p-6">
  <h3 class="font-bold text-gray-900 mb-1">Trocar de plano</h3>
  <p class="text-sm text-gray-500 mb-6">A mudança é aplicada imediatamente na sua próxima cobrança.</p>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upgrade">
    <div class="grid gap-4" style="grid-template-columns: repeat(3, 1fr);">
      <?php foreach ($plans as $key => $p): ?>
        <label class="relative cursor-pointer border-2 rounded-xl p-4 transition-all hover:border-industrial-blue
          <?= $key === $currentPlan ? 'border-industrial-blue bg-blue-50 cursor-not-allowed' : 'border-gray-200' ?>">
          <input type="radio" name="new_plan" value="<?= $key ?>" class="sr-only"
            <?= $key === $currentPlan ? 'disabled' : '' ?>>
          <?php if ($key === $currentPlan): ?>
            <span class="absolute top-3 right-3 text-xs bg-industrial-blue text-white px-2 py-0.5 rounded-full font-medium">Atual</span>
          <?php endif; ?>
          <p class="font-bold text-gray-900 mb-1"><?= $p['name'] ?></p>
          <p class="text-xs text-gray-500 mb-2">Até <?= $p['employees'] ?> funcionários</p>
          <p class="text-lg font-extrabold text-gray-900">R$ <?= number_format($p['price'], 0, ',', '.') ?><span class="text-xs font-normal text-gray-400">/mês</span></p>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary mt-5">Confirmar troca de plano</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
