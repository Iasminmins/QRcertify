<?php
require_once __DIR__ . '/../config/config.php';
requireRole('employee');

$user = currentUser();
$db   = getDB();

$stmt = $db->prepare('SELECT * FROM employees WHERE email = ? LIMIT 1');
$stmt->execute([$user['email']]);
$employee = $stmt->fetch();

$certificates = [];
if ($employee) {
    $db->prepare(
        'UPDATE certificates SET status = CASE
            WHEN expiry_date < CURDATE() THEN "expired"
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
            ELSE "valid" END
         WHERE employee_id = ?'
    )->execute([$employee['id']]);

    $stmt = $db->prepare('SELECT * FROM certificates WHERE employee_id = ? ORDER BY expiry_date DESC');
    $stmt->execute([$employee['id']]);
    $certificates = $stmt->fetchAll();
}

$validCount    = count(array_filter($certificates, fn($c) => $c['status'] === 'valid'));
$expiringCount = count(array_filter($certificates, fn($c) => $c['status'] === 'expiring'));
$expiredCount  = count(array_filter($certificates, fn($c) => $c['status'] === 'expired'));

// QR Code da carteira pessoal
$employeeQrSrc = $employee
    ? BASE_URL . '/public/qr.php?size=112&data=' . urlencode(getEmployeePublicUrl((int)$employee['id']))
    : '';

// URL e QR da Carteira de Autorização
$authUrl   = $employee ? getAuthorizationPublicUrl((int)$employee['id']) : '';
$authQrSrc = $employee
    ? BASE_URL . '/public/qr.php?size=112&data=' . urlencode($authUrl)
    : '';

$pageTitle = 'Meus Certificados';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Meus Certificados</h1>
        <p class="page-subtitle">Sua carteira digital de certificados e treinamentos</p>
    </div>
</div>

<?php renderFlashes(); ?>

<?php if (!$employee): ?>
    <div class="section-card">
        <div class="section-card-body text-center py-10 text-gray-500">
            <svg class="h-12 w-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <p class="font-medium">Cadastro não encontrado</p>
            <p class="text-sm mt-1">Entre em contato com o administrador da sua empresa.</p>
        </div>
    </div>
<?php else: ?>

    <!-- Card do perfil + resumo + QR -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">

        <!-- Perfil -->
        <div class="section-card lg:col-span-2">
            <div class="bg-gradient-to-r from-industrial-blue to-blue-700 rounded-t-[.75rem] text-white p-5">
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-xl bg-white/20 flex items-center justify-center
                                overflow-hidden flex-shrink-0 shadow-md">
                        <?php if (!empty($employee['photo'])): ?>
                            <img src="<?= e($employee['photo']) ?>" alt="" class="h-full w-full object-cover">
                        <?php else: ?>
                            <span class="text-2xl font-bold">
                                <?= e(strtoupper(mb_substr($employee['name'], 0, 1))) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold"><?= e($employee['name']) ?></h2>
                        <p class="text-white/80 text-sm"><?= e($employee['role']) ?></p>
                        <p class="text-white/60 text-xs mt-0.5"><?= e($employee['email']) ?></p>
                    </div>
                </div>
            </div>
            <div class="section-card-body grid grid-cols-3 gap-3 text-center">
                <div class="p-3 rounded-lg bg-green-50 border border-green-100">
                    <div class="text-2xl font-bold text-green-700"><?= $validCount ?></div>
                    <div class="text-xs text-green-600 mt-0.5">Válidos</div>
                </div>
                <div class="p-3 rounded-lg bg-amber-50 border border-amber-100">
                    <div class="text-2xl font-bold text-amber-700"><?= $expiringCount ?></div>
                    <div class="text-xs text-amber-600 mt-0.5">Expirando</div>
                </div>
                <div class="p-3 rounded-lg bg-red-50 border border-red-100">
                    <div class="text-2xl font-bold text-red-700"><?= $expiredCount ?></div>
                    <div class="text-xs text-red-600 mt-0.5">Expirados</div>
                </div>
            </div>
        </div>

        <!-- Carteiras digitais: QR Código + Autorização -->
        <div class="section-card flex flex-col p-5 gap-4">
            <p class="text-sm font-semibold text-gray-700 text-center">Minhas Carteiras</p>

            <!-- Carteira Digital (certificados) -->
            <div class="flex flex-col items-center text-center border border-gray-100 rounded-xl p-4 bg-gray-50">
                <p class="text-xs font-semibold text-industrial-blue mb-2">📋 Carteira Digital</p>
                <div class="p-2 border-2 border-gray-200 rounded-xl bg-white shadow-sm inline-block mb-2">
                    <img src="<?= e($employeeQrSrc) ?>" alt="QR Code" width="96" height="96" style="display:block">
                </div>
                <p class="text-xs text-gray-400 mb-2">Todos os seus certificados</p>
                <a href="<?= getEmployeePublicUrl((int)$employee['id']) ?>" target="_blank"
                   class="btn btn-secondary btn-sm w-full justify-content:center">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Ver online
                </a>
            </div>

            <!-- Carteira de Autorização -->
            <div class="flex flex-col items-center text-center border border-blue-100 rounded-xl p-4 bg-blue-50">
                <p class="text-xs font-semibold text-industrial-blue mb-2">🪪 Carteira de Autorização</p>
                <div class="p-2 border-2 border-blue-200 rounded-xl bg-white shadow-sm inline-block mb-2">
                    <img src="<?= e($authQrSrc) ?>" alt="QR Code Autorização" width="96" height="96" style="display:block">
                </div>
                <p class="text-xs text-gray-400 mb-2">NRs, ASO e ramais de emergência</p>
                <a href="<?= e($authUrl) ?>" target="_blank"
                   class="btn btn-primary btn-sm w-full justify-content:center">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Ver online
                </a>
            </div>

            <!-- Alterar senha -->
            <a href="<?= BASE_URL ?>/employee/change-password.php"
               class="btn btn-secondary btn-sm w-full justify-center">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                Alterar senha
            </a>
        </div>
    </div>

    <!-- Grade de certificados -->
    <?php if (empty($certificates)): ?>
        <div class="section-card">
            <div class="section-card-body py-12 text-center text-gray-400">
                <p class="font-medium">Você ainda não tem certificados cadastrados.</p>
            </div>
        </div>
    <?php else: ?>
        <h2 class="text-base font-semibold text-gray-700 mb-3">Todos os Certificados</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($certificates as $cert):
                $badge = getStatusBadge($cert['status']);
                $topColor = match($cert['status']) {
                    'valid'    => '#10b981',
                    'expiring' => '#f59e0b',
                    'expired'  => '#ef4444',
                    default    => '#94a3b8',
                };
                $dateClass = match($cert['status']) {
                    'expired'  => 'color:#dc2626',
                    'expiring' => 'color:#b45309',
                    default    => 'color:#16a34a',
                };
            ?>
            <a href="<?= getCertificatePublicUrl($cert['hash']) ?>" target="_blank"
               class="section-card block hover:shadow-md transition overflow-hidden"
               style="border-top:3px solid <?= $topColor ?>; text-decoration:none">
                <div style="background:#1e3a8a;color:#fff;padding:.75rem 1rem;
                            display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:.7rem;opacity:.85">Carteira Digital</span>
                    <span class="badge badge-<?= $cert['status'] ?>"><?= e($badge['label']) ?></span>
                </div>
                <div class="section-card-body">
                    <p style="font-size:.72rem;font-weight:700;color:#1e3a8a;margin-bottom:.35rem">
                        <?= e($cert['type']) ?>
                    </p>
                    <p style="font-weight:600;color:#0f172a;font-size:.9rem;line-height:1.3;margin-bottom:.75rem">
                        <?= e($cert['title']) ?>
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.75rem">
                        <div>
                            <div style="color:#94a3b8;margin-bottom:.1rem">Emissão</div>
                            <div style="font-weight:600;color:#334155"><?= formatDate($cert['issued_date']) ?></div>
                        </div>
                        <div>
                            <div style="color:#94a3b8;margin-bottom:.1rem">Validade</div>
                            <div style="font-weight:600;<?= $dateClass ?>"><?= formatDate($cert['expiry_date']) ?></div>
                        </div>
                    </div>
                    <div style="margin-top:.875rem;padding-top:.875rem;border-top:1px solid #f1f5f9;
                                font-size:.75rem;color:#1e3a8a;font-weight:600;text-align:center">
                        Ver QR Code →
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
