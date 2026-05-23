<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$id   = (int)($_GET['id'] ?? 0);
$user = currentUser();
$db   = getDB();

$stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?');
$stmt->execute([$id, $user['company_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    setFlash('error', 'Funcionário não encontrado.');
    redirect('/admin/employees.php');
}

// Atualiza status e busca certs
$db->prepare(
    'UPDATE certificates SET status = CASE
        WHEN expiry_date < CURDATE() THEN "expired"
        WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
        ELSE "valid" END
     WHERE employee_id = ?'
)->execute([$id]);

$stmt = $db->prepare('SELECT * FROM certificates WHERE employee_id = ? ORDER BY expiry_date DESC');
$stmt->execute([$id]);
$certificates = $stmt->fetchAll();

$validCount    = count(array_filter($certificates, fn($c) => $c['status'] === 'valid'));
$expiringCount = count(array_filter($certificates, fn($c) => $c['status'] === 'expiring'));
$expiredCount  = count(array_filter($certificates, fn($c) => $c['status'] === 'expired'));

$pageTitle = $employee['name'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Voltar -->
<div class="mb-5">
    <a href="<?= BASE_URL ?>/admin/employees.php"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Funcionários
    </a>
</div>

<?php renderFlashes(); ?>

<!-- Card do funcionário -->
<div class="section-card mb-6 overflow-hidden">
    <!-- Header azul -->
    <div class="bg-gradient-to-r from-industrial-blue to-blue-700 text-white p-6">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="h-20 w-20 rounded-xl overflow-hidden bg-white/20 flex-shrink-0
                        flex items-center justify-center shadow-lg">
                <?php if (!empty($employee['photo'])): ?>
                    <img src="<?= e($employee['photo']) ?>" alt="" class="h-full w-full object-cover">
                <?php else: ?>
                    <span class="text-3xl font-bold">
                        <?= e(strtoupper(mb_substr($employee['name'], 0, 1))) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold"><?= e($employee['name']) ?></h1>
                <p class="text-white/80 mt-0.5"><?= e($employee['role']) ?></p>
                <p class="text-white/60 text-sm mt-1">
                    <?= e($employee['email']) ?> · CPF: <?= e($employee['cpf']) ?>
                </p>
            </div>
            <!-- Resumo de certs -->
            <div class="flex gap-3 flex-shrink-0">
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-green-300"><?= $validCount ?></div>
                    <div class="text-xs text-white/70">Válidos</div>
                </div>
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-yellow-300"><?= $expiringCount ?></div>
                    <div class="text-xs text-white/70">Expirando</div>
                </div>
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-red-300"><?= $expiredCount ?></div>
                    <div class="text-xs text-white/70">Expirados</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Ações -->
    <div class="section-card-body flex flex-wrap gap-3">
        <a href="<?= BASE_URL ?>/admin/employee-form.php?id=<?= (int)$employee['id'] ?>"
           class="btn btn-secondary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Editar dados
        </a>
        <a href="<?= BASE_URL ?>/admin/certificate-form.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Adicionar Certificado
        </a>
        <a href="<?= getEmployeePublicUrl((int)$employee['id']) ?>" target="_blank"
           class="btn btn-secondary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5a.5.5 0 11-1 0 .5.5 0 011 0zM6.5 19.5a.5.5 0 11-1 0 .5.5 0 011 0zM6.5 6.5a.5.5 0 11-1 0 .5.5 0 011 0z"/>
            </svg>
            Ver Carteira Digital
        </a>
    </div>
</div>

<!-- Lista de certificados -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title">Certificados (<?= count($certificates) ?>)</h2>
        <a href="<?= BASE_URL ?>/admin/certificate-form.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-primary btn-sm">+ Adicionar</a>
    </div>

    <?php if (empty($certificates)): ?>
        <div class="py-12 text-center text-gray-400">
            <p class="font-medium">Nenhum certificado cadastrado.</p>
            <p class="text-sm mt-1">
                <a href="<?= BASE_URL ?>/admin/certificate-form.php?employee_id=<?= (int)$employee['id'] ?>"
                   class="text-industrial-blue hover:underline">Adicione o primeiro</a>
            </p>
        </div>
    <?php else: ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($certificates as $cert):
                $badge = getStatusBadge($cert['status']);
                $dateClass = match($cert['status']) {
                    'expired'  => 'text-red-600',
                    'expiring' => 'text-amber-600',
                    default    => 'text-green-700',
                };
            ?>
            <div class="p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-gray-50 transition">
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <span class="badge badge-<?= $cert['status'] ?>"><?= e($badge['label']) ?></span>
                        <span style="font-size:.72rem;font-weight:600;color:#64748b"><?= e($cert['type']) ?></span>
                    </div>
                    <h3 class="font-semibold text-gray-900"><?= e($cert['title']) ?></h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Emitido em <?= formatDate($cert['issued_date']) ?>
                        · Validade:
                        <span class="<?= $dateClass ?> font-medium"><?= formatDate($cert['expiry_date']) ?></span>
                    </p>
                </div>
                <div class="flex gap-2 flex-shrink-0">
                    <a href="<?= getCertificatePublicUrl($cert['hash']) ?>" target="_blank"
                       class="btn btn-secondary btn-sm">Ver QR</a>
                    <a href="<?= BASE_URL ?>/admin/certificate-form.php?id=<?= (int)$cert['id'] ?>"
                       class="btn btn-secondary btn-sm">Editar</a>
                    <form method="POST" action="<?= BASE_URL ?>/admin/certificate-delete.php" class="inline"
                          onsubmit="return confirm('Excluir este certificado?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
