<?php
require_once __DIR__ . '/../config/config.php';

$hash = trim($_GET['hash'] ?? '');
$db = getDB();

$certificate = null;
$employee = null;
$company = null;

if ($hash !== '') {
    $stmt = $db->prepare(
        'SELECT c.*, e.name AS employee_name, e.cpf AS employee_cpf,
                e.photo AS employee_photo, e.role AS employee_role,
                e.id AS emp_id, e.company_id
         FROM certificates c
         JOIN employees e ON e.id = c.employee_id
         WHERE c.hash = ?
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $certificate = $stmt->fetch();

    if ($certificate) {
        // Atualiza status baseado na data
        $newStatus = getCertStatus($certificate['expiry_date']);
        if ($newStatus !== $certificate['status']) {
            $upd = $db->prepare('UPDATE certificates SET status = ? WHERE id = ?');
            $upd->execute([$newStatus, $certificate['id']]);
            $certificate['status'] = $newStatus;
        }

        $stmt = $db->prepare('SELECT name FROM companies WHERE id = ?');
        $stmt->execute([$certificate['company_id']]);
        $company = $stmt->fetch();
    }
}

$badge = $certificate ? getStatusBadge($certificate['status']) : null;
$qrUrl = $certificate ? generateQRCodeUrl(getCertificatePublicUrl($certificate['hash'])) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validação de Certificado | <?= e(SITE_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
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
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 flex flex-col">

<?php if (!$certificate): ?>
    <!-- Estado: não encontrado -->
    <div class="flex-1 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-lg p-8 max-w-md w-full text-center">
            <div class="h-16 w-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="h-10 w-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Certificado não encontrado</h1>
            <p class="text-gray-600 mb-6">O certificado solicitado não existe ou foi removido.</p>
            <a href="<?= BASE_URL ?>/" class="inline-block bg-industrial-blue hover:bg-blue-800 text-white font-medium px-6 py-2 rounded-md">
                Voltar ao início
            </a>
        </div>
    </div>
<?php else: ?>
    <!-- Estado: carteira digital -->
    <div class="flex-1 flex flex-col items-center p-4">
        <!-- Barra superior (oculta na impressão) -->
        <div class="w-full max-w-md flex justify-between items-center mb-4 no-print">
            <?php
            $backUrl = BASE_URL . '/';
            if (isLoggedIn()) {
                $role = currentUser()['role'] ?? '';
                $backUrl = match($role) {
                    'admin'      => BASE_URL . '/admin/certificates.php',
                    'superadmin' => BASE_URL . '/superadmin/dashboard.php',
                    'employee'   => BASE_URL . '/employee/certificates.php',
                    default      => BASE_URL . '/admin/dashboard.php',
                };
            }
            ?>
            <a href="<?= $backUrl ?>" class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Voltar
            </a>
            <button onclick="window.print()" class="text-sm bg-white border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded-md inline-flex items-center gap-1">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Imprimir
            </button>
        </div>

        <!-- Carteira -->
        <div class="bg-white rounded-xl shadow-xl border max-w-md w-full overflow-hidden">
            <!-- Header -->
            <div class="bg-industrial-blue text-white p-4">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-white flex items-center justify-center flex-shrink-0">
                        <div class="h-6 w-6 rounded-full bg-industrial-yellow"></div>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold">Carteira Digital</h1>
                        <p class="text-xs opacity-90">Verificação de Certificado</p>
                    </div>
                </div>
            </div>

            <!-- Conteúdo -->
            <div class="p-5 space-y-4">
                <!-- Badges -->
                <div class="flex justify-between items-center">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?= $badge['class'] ?>">
                        <?= e($badge['label']) ?>
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-industrial-blue border border-blue-200">
                        <?= e($certificate['type']) ?>
                    </span>
                </div>

                <!-- Info do colaborador -->
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                    <div class="h-16 w-16 rounded-lg overflow-hidden bg-gray-200 flex-shrink-0 flex items-center justify-center">
                        <?php if (!empty($certificate['employee_photo'])): ?>
                            <img src="<?= e($certificate['employee_photo']) ?>" alt="" class="h-full w-full object-cover">
                        <?php else: ?>
                            <span class="text-2xl font-bold text-gray-500">
                                <?= e(strtoupper(substr($certificate['employee_name'], 0, 1))) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="font-semibold text-base truncate"><?= e($certificate['employee_name']) ?></h2>
                        <p class="text-sm text-gray-600"><?= e($certificate['employee_role']) ?></p>
                        <?php
                        $cpf = preg_replace('/\D/', '', $certificate['employee_cpf'] ?? '');
                        $cpfMask = (strlen($cpf) === 11)
                            ? '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**'
                            : '***.***.***-**';
                        ?>
                        <p class="text-xs text-gray-500 mt-0.5">CPF: <?= e($cpfMask) ?></p>
                    </div>
                </div>

                <!-- Empresa -->
                <?php if ($company): ?>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-xs text-gray-500 mb-0.5">Empresa</p>
                    <p class="text-sm font-medium"><?= e($company['name']) ?></p>
                </div>
                <?php endif; ?>

                <!-- Título do certificado -->
                <div class="p-3 bg-blue-50 rounded-lg border border-blue-100">
                    <p class="text-xs text-industrial-blue font-medium mb-1">Certificado:</p>
                    <p class="text-sm font-semibold text-gray-900"><?= e($certificate['title']) ?></p>
                </div>

                <!-- Datas -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Data de Emissão</p>
                        <p class="text-sm font-semibold"><?= formatDate($certificate['issued_date']) ?></p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Validade</p>
                        <p class="text-sm font-semibold <?= $certificate['status'] === 'expired' ? 'text-red-600' : ($certificate['status'] === 'expiring' ? 'text-amber-600' : 'text-green-600') ?>">
                            <?= formatDate($certificate['expiry_date']) ?>
                        </p>
                    </div>
                </div>

                <!-- QR Code -->
                <div class="flex justify-center py-3">
                    <div class="p-3 bg-white border-2 border-gray-200 rounded-lg">
                        <img src="<?= e($qrUrl) ?>" alt="QR Code" class="w-32 h-32 object-contain">
                    </div>
                </div>

                <!-- Rodapé -->
                <div class="text-center space-y-1 pt-2 border-t">
                    <p class="text-xs text-gray-600">Certificado autenticado via QR Code</p>
                    <p class="text-xs text-gray-400 font-mono break-all">
                        ID: <?= e(substr($certificate['hash'], 0, 16)) ?>...
                    </p>
                </div>
            </div>
        </div>

        <p class="mt-6 text-xs text-gray-500 text-center max-w-md no-print">
            Este certificado foi emitido por <?= e($company['name'] ?? 'a empresa') ?> e pode ser
            verificado a qualquer momento através do QR Code acima.
        </p>
    </div>
<?php endif; ?>

</body>
</html>
