<?php
require_once __DIR__ . '/../config/config.php';

$hash      = trim($_GET['hash'] ?? '');
$legacyId  = (int) ($_GET['id'] ?? 0);
$db        = getDB();

$employee     = null;
$company      = null;
$certificates = [];
$employeeId   = 0;

if ($hash !== '') {
    $stmt = $db->prepare(
        'SELECT e.*, c.name AS company_name
         FROM employees e
         JOIN companies c ON c.id = e.company_id
         WHERE e.public_hash = ? AND e.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $employee = $stmt->fetch();
} elseif ($legacyId > 0) {
    $stmt = $db->prepare(
        'SELECT e.*, c.name AS company_name
         FROM employees e
         JOIN companies c ON c.id = e.company_id
         WHERE e.id = ? AND e.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$legacyId]);
    $employee = $stmt->fetch();
    if ($employee) {
        $walletHash = ensureEmployeePublicHash((int) $employee['id']);
        if ($walletHash) {
            header('Location: ' . BASE_URL . '/public/employee.php?hash=' . urlencode($walletHash), true, 301);
            exit;
        }
    }
}

if ($employee) {
    $employeeId = (int) $employee['id'];

    $db->prepare(
        'UPDATE certificates
         SET status = CASE
            WHEN expiry_date < CURDATE() THEN "expired"
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
            ELSE "valid"
         END
         WHERE employee_id = ?'
    )->execute([$employeeId]);

    $stmt = $db->prepare(
        'SELECT * FROM certificates
         WHERE employee_id = ?
         ORDER BY expiry_date DESC'
    );
    $stmt->execute([$employeeId]);
    $certificates = $stmt->fetchAll();
}

$validCount    = 0;
$expiringCount = 0;
$expiredCount  = 0;

foreach ($certificates as $cert) {
    if ($cert['status'] === 'valid')    $validCount++;
    if ($cert['status'] === 'expiring') $expiringCount++;
    if ($cert['status'] === 'expired')  $expiredCount++;
}

$pageUrl    = $employee ? getEmployeePublicUrl($employeeId) : '';
$selfQrUrl  = $pageUrl ? generateQRCodeUrl($pageUrl, 200) : '';
$verifiedAt = date('d/m/Y \à\s H:i');

$backUrl = BASE_URL . '/';
if (isLoggedIn()) {
    $role = currentUser()['role'] ?? '';
    $backUrl = match ($role) {
        'admin'      => BASE_URL . '/admin/employees.php',
        'superadmin' => BASE_URL . '/superadmin/dashboard.php',
        'employee'   => BASE_URL . '/employee/certificates.php',
        default      => BASE_URL . '/admin/dashboard.php',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carteira Digital | <?= e(SITE_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                'industrial-blue': '#1e3a8a',
                'industrial-yellow': '#facc15',
            } } }
        }
    </script>
    <style>
        @media print { .no-print { display: none !important; } body { background: white !important; } }
    </style>
</head>
<body class="min-h-screen bg-gray-50 flex flex-col">

<?php if (!$employee): ?>
    <div class="flex-1 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-lg p-8 max-w-md w-full text-center">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Carteira não encontrada</h1>
            <p class="text-gray-600 mb-6">O colaborador solicitado não existe ou está inativo.</p>
            <a href="<?= BASE_URL ?>/" class="inline-block bg-industrial-blue hover:bg-blue-800 text-white font-medium px-6 py-2 rounded-md">
                Voltar ao início
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="flex-1 flex flex-col items-center p-4">
        <div class="w-full max-w-lg flex justify-between items-center mb-4 no-print">
            <a href="<?= $backUrl ?>" class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Voltar
            </a>
            <button type="button" onclick="window.print()" class="text-sm bg-white border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded-md">
                Imprimir
            </button>
        </div>

        <div class="bg-white rounded-xl shadow-xl border max-w-lg w-full overflow-hidden">
            <div class="bg-industrial-blue text-white p-5">
                <div class="flex items-center gap-4">
                    <div class="h-14 w-14 rounded-xl bg-white/20 flex items-center justify-center overflow-hidden flex-shrink-0">
                        <?php if (!empty($employee['photo'])): ?>
                            <img src="<?= e(BASE_URL . '/' . ltrim($employee['photo'], '/')) ?>" alt="" class="h-full w-full object-cover">
                        <?php else: ?>
                            <span class="text-xl font-bold"><?= e(strtoupper(mb_substr($employee['name'], 0, 1))) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-bold truncate"><?= e($employee['name']) ?></h1>
                        <p class="text-sm text-white/80"><?= e($employee['role']) ?></p>
                        <p class="text-xs text-white/60 mt-0.5"><?= e($employee['company_name']) ?></p>
                    </div>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-3 gap-2 text-center mb-5">
                    <div class="p-2 rounded-lg bg-green-50 border border-green-100">
                        <div class="text-lg font-bold text-green-700"><?= $validCount ?></div>
                        <div class="text-xs text-green-600">Válidos</div>
                    </div>
                    <div class="p-2 rounded-lg bg-amber-50 border border-amber-100">
                        <div class="text-lg font-bold text-amber-700"><?= $expiringCount ?></div>
                        <div class="text-xs text-amber-600">Expirando</div>
                    </div>
                    <div class="p-2 rounded-lg bg-red-50 border border-red-100">
                        <div class="text-lg font-bold text-red-700"><?= $expiredCount ?></div>
                        <div class="text-xs text-red-600">Expirados</div>
                    </div>
                </div>

                <div class="flex flex-col items-center mb-5 pb-5 border-b border-gray-100">
                    <img src="<?= e($selfQrUrl) ?>" alt="QR Code da carteira" class="w-36 h-36 object-contain border-2 border-gray-200 rounded-lg p-2">
                    <p class="text-xs text-gray-500 mt-2 text-center">Escaneie para acessar esta carteira digital</p>
                    <p class="text-xs text-gray-400 mt-1">Verificado em <?= e($verifiedAt) ?></p>
                </div>

                <?php if (empty($certificates)): ?>
                    <p class="text-sm text-gray-500 text-center py-4">Nenhum certificado cadastrado.</p>
                <?php else: ?>
                    <h2 class="text-sm font-semibold text-gray-700 mb-3">Certificados</h2>
                    <div class="space-y-3">
                        <?php foreach ($certificates as $cert):
                            $badge = getStatusBadge($cert['status']);
                        ?>
                        <a href="<?= e(getCertificatePublicUrl($cert['hash'])) ?>"
                           class="block border border-gray-200 rounded-lg p-3 hover:border-industrial-blue hover:bg-blue-50/30 transition no-print">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-industrial-blue"><?= e($cert['type']) ?></p>
                                    <p class="text-sm font-semibold text-gray-900 truncate"><?= e($cert['title']) ?></p>
                                    <p class="text-xs text-gray-500 mt-1">Validade: <?= formatDate($cert['expiry_date']) ?></p>
                                </div>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium border flex-shrink-0 <?= $badge['class'] ?>">
                                    <?= e($badge['label']) ?>
                                </span>
                            </div>
                            <p class="text-xs text-industrial-blue font-medium mt-2">Ver validação por QR →</p>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <p class="mt-6 text-xs text-gray-500 text-center max-w-lg no-print">
            Carteira digital emitida por <?= e($employee['company_name']) ?>.
            Cada certificado possui QR Code individual para validação pelo fiscal.
        </p>
    </div>
<?php endif; ?>

</body>
</html>
