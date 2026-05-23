<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();
$days      = CERT_ALERT_DAYS;
$canEmail  = companyHasFeature($companyId, 'email_alerts');

// Busca certificados vencendo ou vencidos, agrupados por funcionário
$stmt = $db->prepare(
    'SELECT c.id, c.title, c.type, c.expiry_date, c.status,
            e.id AS emp_id, e.name AS emp_name, e.email AS emp_email
     FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     WHERE e.company_id = ?
       AND e.status = "active"
       AND c.status IN ("expiring","expired")
       AND e.email IS NOT NULL AND e.email != ""
     ORDER BY e.name ASC, c.expiry_date ASC'
);
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();

// Agrupa por funcionário
$byEmployee = [];
foreach ($rows as $r) {
    $byEmployee[$r['emp_id']]['name']  = $r['emp_name'];
    $byEmployee[$r['emp_id']]['email'] = $r['emp_email'];
    $byEmployee[$r['emp_id']]['certs'][] = $r;
}

$sent    = 0;
$failed  = 0;
$results = [];

// Envio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    if (!csrfCheck()) {
        setFlash('error', 'Token inválido.');
        redirect('/admin/notify-expiring.php');
    }
    if (!$canEmail) {
        setFlash('error', 'Alertas por e-mail estão disponíveis a partir do plano Pro. Faça upgrade em Meu Plano.');
        redirect('/admin/notify-expiring.php');
    }

    $targets = $_POST['targets'] ?? [];  // IDs dos funcionários selecionados

    foreach ($byEmployee as $empId => $emp) {
        if (!empty($targets) && !in_array((string)$empId, $targets)) continue;

        $subject = '[' . SITE_NAME . '] Atenção: certificado(s) com vencimento próximo';
        $html    = buildCertAlertHtml($emp['name'], $emp['certs']);
        $ok      = sendMail($emp['email'], $subject, $html);

        if ($ok) {
            $sent++;
            auditLog('notify.sent', "employee #{$empId}",
                json_encode(['email' => $emp['email'], 'certs' => count($emp['certs'])]));
        } else {
            $failed++;
        }
        $results[] = ['name' => $emp['name'], 'email' => $emp['email'], 'ok' => $ok];
    }
}

$pageTitle = 'Notificações de Vencimento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/dashboard.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Notificações de Vencimento</h1>
    <p class="text-gray-500 text-sm mt-1">
        Funcionários com certificados vencidos ou vencendo nos próximos <?= CERT_ALERT_DAYS ?> dias.
    </p>
</div>

<?php renderFlashes(); ?>

<?php if (!$canEmail): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
    <p class="font-semibold mb-1">Alertas por e-mail não incluídos no seu plano</p>
    <p>O plano Starter não envia notificações automáticas. Faça upgrade para Pro ou superior em
        <a href="<?= BASE_URL ?>/admin/plano.php" class="text-industrial-blue underline font-medium">Meu Plano</a>.
    </p>
</div>
<?php endif; ?>

<!-- Resultado do envio -->
<?php if (!empty($results)): ?>
    <div class="mb-6 <?= $failed === 0 ? 'bg-green-50 border-green-200' : 'bg-amber-50 border-amber-200' ?> border rounded-lg p-4">
        <p class="font-medium <?= $failed === 0 ? 'text-green-800' : 'text-amber-800' ?> mb-2">
            <?= $sent ?> e-mail(s) enviado(s)<?= $failed > 0 ? ", {$failed} falha(s)" : ' com sucesso!' ?>
        </p>
        <ul class="space-y-1">
            <?php foreach ($results as $r): ?>
                <li class="text-sm flex items-center gap-2 <?= $r['ok'] ? 'text-green-700' : 'text-red-600' ?>">
                    <?= $r['ok'] ? '✓' : '✗' ?>
                    <?= e($r['name']) ?> — <?= e($r['email']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (empty($byEmployee)): ?>
    <div class="bg-white rounded-lg shadow-sm border p-10 text-center text-gray-500">
        <svg class="h-12 w-12 text-green-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="font-medium">Nenhum certificado vencido ou a vencer! 🎉</p>
        <p class="text-sm mt-1">Todos os certificados estão em dia.</p>
    </div>
<?php else: ?>

<?php if ($canEmail): ?>
<form method="POST">
    <?= csrfField() ?>

    <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-600">
            <strong><?= count($byEmployee) ?></strong> funcionário(s) precisam de notificação.
        </p>
        <div class="flex gap-2">
            <button type="button" onclick="toggleAll(true)"
                    class="text-xs text-industrial-blue hover:underline">Selecionar todos</button>
            <span class="text-gray-300">|</span>
            <button type="button" onclick="toggleAll(false)"
                    class="text-xs text-gray-500 hover:underline">Desmarcar todos</button>
        </div>
    </div>

    <div class="space-y-3 mb-5">
        <?php foreach ($byEmployee as $empId => $emp): ?>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="targets[]" value="<?= (int)$empId ?>"
                           class="emp-check mt-0.5 h-4 w-4 rounded border-gray-300 text-industrial-blue"
                           checked>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-medium text-gray-900 text-sm"><?= e($emp['name']) ?></span>
                            <span class="text-xs text-gray-400"><?= e($emp['email']) ?></span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($emp['certs'] as $c):
                                $isExp = $c['status'] === 'expired';
                                $badgeCls = $isExp
                                    ? 'bg-red-100 text-red-700 border-red-200'
                                    : 'bg-amber-100 text-amber-700 border-amber-200';
                            ?>
                                <span class="inline-flex items-center gap-1 text-xs border rounded-full px-2.5 py-0.5 <?= $badgeCls ?>">
                                    <?= e($c['type']) ?> — <?= e($isExp ? 'Expirado' : 'Expirando') ?>
                                    · <?= date('d/m/Y', strtotime($c['expiry_date'])) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </label>
            </div>
        <?php endforeach; ?>
    </div>

    <button type="submit" name="send" value="1"
            class="inline-flex items-center gap-2 bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2.5 rounded-lg font-medium transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        Enviar E-mails Selecionados
    </button>
</form>
<?php endif; ?>

<script>
function toggleAll(v) {
    document.querySelectorAll('.emp-check').forEach(c => c.checked = v);
}
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
