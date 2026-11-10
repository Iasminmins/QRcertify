<?php
require_once __DIR__ . '/../config/config.php';

$hash     = trim($_GET['hash'] ?? '');
$db       = getDB();
$employee = null;
$company  = null;

if ($hash !== '') {
    $stmt = $db->prepare(
        'SELECT e.*, c.name AS company_name, c.logo AS company_logo,
                c.phone AS company_phone,
                c.emergency_sesmt, c.emergency_bombeiro,
                c.emergency_samu, c.emergency_policia,
                c.sesmt_elaborado, c.sesmt_aprovado
         FROM employees e
         JOIN companies c ON c.id = e.company_id
         WHERE e.public_hash = ? AND e.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $employee = $stmt->fetch();
}

if (!$employee) {
    // Página de erro
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?php renderGoogleTagManagerHead(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carteira não encontrada | <?= e(SITE_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
<?php renderGoogleTagManagerBody(); ?>
    <div class="bg-white rounded-xl shadow-lg p-8 max-w-md w-full text-center">
        <div class="h-16 w-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
            <svg class="h-8 w-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-gray-900 mb-2">Carteira não encontrada</h1>
        <p class="text-gray-500 text-sm">O colaborador solicitado não existe ou está inativo.</p>
    </div>
</body>
</html><?php
    exit;
}

$employeeId = (int) $employee['id'];
recordPublicView($db, 'authorization', $employeeId, (int)$employee['company_id']);

// Atualiza status dos certificados
$db->prepare(
    'UPDATE certificates SET status = CASE
        WHEN expiry_date < CURDATE() THEN "expired"
        WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
        ELSE "valid"
     END WHERE employee_id = ?'
)->execute([$employeeId]);

// Busca certificados
$stmt = $db->prepare(
    'SELECT * FROM certificates WHERE employee_id = ? ORDER BY expiry_date DESC'
);
$stmt->execute([$employeeId]);
$certificates = $stmt->fetchAll();

// Atualiza e busca ASO mais recente
$db->prepare(
    'UPDATE employee_asos SET document_status = CASE
        WHEN expiration_date < CURDATE() THEN "vencido"
        WHEN expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END WHERE employee_id = ?'
)->execute([$employeeId]);

$stmtAso = $db->prepare(
    'SELECT * FROM employee_asos WHERE employee_id = ? ORDER BY exam_date DESC LIMIT 1'
);
$stmtAso->execute([$employeeId]);
$aso = $stmtAso->fetch();

// Mascara CPF
function maskCpfAuth(?string $cpf): string {
    $d = preg_replace('/\D/', '', $cpf ?? '');
    if (strlen($d) !== 11) return '***.***.***-**';
    return '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**';
}

// URL desta página (para o QR)
$pageUrl = BASE_URL . '/public/authorization.php?hash=' . urlencode($hash);
$qrDataUri = generateQRCodeDataUri($pageUrl, 120);

// Logo da empresa
$logoUrl = '';
if (!empty($employee['company_logo'])) {
    $logoUrl = BASE_URL . '/' . ltrim($employee['company_logo'], '/');
}

// Ramais de emergência
$sesmt    = $employee['emergency_sesmt']    ?: ($employee['company_phone'] ?? '—');
$bombeiro = $employee['emergency_bombeiro'] ?: '193';
$samu     = $employee['emergency_samu']     ?: '192';
$policia  = $employee['emergency_policia']  ?: '190';

// Responsáveis SESMT — fallback seguro caso coluna ainda não exista em produção
$sesmtElab  = $employee['sesmt_elaborado'] ?? '';
$sesmtAprov = $employee['sesmt_aprovado']  ?? '';

// Operações autorizadas — fallback seguro caso coluna ainda não exista em produção
$authorizedOps = [];
if (!empty($employee['authorized_ops'])) {
    $decoded = json_decode($employee['authorized_ops'], true);
    if (is_array($decoded)) {
        $authorizedOps = $decoded;
    }
}

// ASO labels
$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];

// Status geral: pior status dos certificados
$hasExpired  = false;
$hasExpiring = false;
foreach ($certificates as $c) {
    if ($c['status'] === 'expired')  $hasExpired  = true;
    if ($c['status'] === 'expiring') $hasExpiring = true;
}
$overallStatus = $hasExpired ? 'expired' : ($hasExpiring ? 'expiring' : 'valid');

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

$verifiedAt = date('d/m/Y \à\s H:i');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?php renderGoogleTagManagerHead(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carteira de Autorização | <?= e($employee['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                'industrial-blue': '#1e3a8a',
                'industrial-yellow': '#facc15',
            }}}
        }
    </script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .card-authorization { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }
        body { font-family: Arial, sans-serif; }

        /* Tamanhos mínimos para leitura no celular */
        .auth-label  { font-size: 9px;  font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .auth-value  { font-size: 11px; }
        .auth-type   { font-size: 11px; font-weight: 700; }
        .auth-date   { font-size: 10px; font-weight: 600; white-space: nowrap; }
        .auth-op     { font-size: 10px; line-height: 1.35; }
        .auth-header { font-size: 9px;  font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .auth-aso    { font-size: 11px; }
        .auth-sesmt  { font-size: 9px;  }
        .auth-ramais { font-size: 10px; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 flex flex-col">
<?php renderGoogleTagManagerBody(); ?>

<div class="flex-1 flex flex-col items-center py-6 px-4">

    <!-- Barra superior -->
    <div class="w-full max-w-lg flex justify-between items-center mb-4 no-print">
        <a href="<?= $backUrl ?>"
           class="text-sm text-gray-500 hover:text-industrial-blue inline-flex items-center gap-1">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Voltar
        </a>
        <button onclick="window.print()"
                class="text-sm bg-white border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded-md inline-flex items-center gap-1">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Imprimir
        </button>
    </div>

    <!-- CARTEIRA -->
    <div class="card-authorization bg-white rounded-xl shadow-xl border border-gray-200 w-full max-w-lg overflow-hidden">

        <!-- ══ CABEÇALHO AZUL ══ -->
        <div class="bg-industrial-blue text-white" style="-webkit-print-color-adjust:exact;print-color-adjust:exact;background-color:#1e3a8a!important;color:#fff!important">
            <!-- Topo: título centralizado -->
            <div class="flex items-center justify-center gap-2 px-4 pt-4 pb-3">
                <div class="w-2.5 h-2.5 rounded-full bg-industrial-yellow flex-shrink-0" style="background-color:#facc15!important"></div>
                <span class="text-xs font-bold tracking-widest uppercase">Carteira de Autorização</span>
            </div>
        </div>

        <!-- ══ DADOS DO COLABORADOR (fundo branco) ══ -->
        <div class="flex items-start gap-3 px-4 py-3 border-b border-gray-200 bg-white">

                <!-- Logo da empresa -->
                <div class="flex-shrink-0 w-14 h-14 rounded-lg border border-gray-200 bg-gray-50
                            flex items-center justify-center overflow-hidden">
                    <?php if ($logoUrl): ?>
                        <img src="<?= e($logoUrl) ?>" alt="Logo" class="w-full h-full object-contain p-1">
                    <?php else: ?>
                        <span class="text-xs text-gray-400 text-center leading-tight px-1">LOGO</span>
                    <?php endif; ?>
                </div>

                <!-- Placeholder foto doc -->
                <div class="flex-shrink-0 w-14 h-16 rounded-lg border border-dashed border-gray-300
                            bg-gray-50 flex items-center justify-center">
                    <?php if (!empty($employee['photo'])): ?>
                        <img src="<?= e(BASE_URL . '/' . ltrim($employee['photo'], '/')) ?>"
                             alt="" class="w-full h-full object-cover rounded-lg">
                    <?php else: ?>
                        <span class="text-[9px] text-gray-400 text-center leading-tight px-1">Portar<br>doc.<br>foto</span>
                    <?php endif; ?>
                </div>

                <!-- Dados -->
                <div class="flex-1 min-w-0 text-[11px] space-y-0.5">
                    <div class="border-b border-gray-100 pb-0.5">
                        <span class="text-gray-500">Nome: </span>
                        <span class="font-semibold text-gray-900"><?= e($employee['name']) ?></span>
                    </div>
                    <div class="border-b border-gray-100 pb-0.5">
                        <span class="text-gray-500">Matrícula: </span>
                        <span class="font-semibold text-gray-900"><?= e(str_pad((string)$employeeId, 4, '0', STR_PAD_LEFT)) ?></span>
                    </div>
                    <div class="border-b border-gray-100 pb-0.5">
                        <span class="text-gray-500">Função: </span>
                        <span class="font-semibold text-gray-900"><?= e($employee['role']) ?></span>
                    </div>
                    <?php if (!empty($employee['manager_name'])): ?>
                    <div class="border-b border-gray-100 pb-0.5">
                        <span class="text-gray-500">Gestor: </span>
                        <span class="font-semibold text-gray-900"><?= e($employee['manager_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="border-b border-gray-100 pb-0.5">
                        <span class="text-gray-500">Empresa: </span>
                        <span class="font-semibold text-gray-900"><?= e($employee['company_name']) ?></span>
                    </div>
                    <?php if (!empty($employee['cpf'])): ?>
                    <div>
                        <span class="text-gray-500">CPF: </span>
                        <span class="font-semibold text-gray-900"><?= e(maskCpfAuth($employee['cpf'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ══ TABELA NRs + OPERAÇÕES ══ -->
        <div class="grid grid-cols-2 border-t border-gray-200 border-b border-gray-200">

            <!-- Coluna: NRs -->
            <div class="border-r border-gray-200">
                <div class="bg-industrial-blue text-white text-[10px] font-bold text-center py-1.5 px-2 tracking-wide uppercase" style="background-color:#1e3a8a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact">
                    Trabalhador Autorizado / Validade
                </div>
                <div class="p-2 min-h-[80px]">
                    <?php if (empty($certificates)): ?>
                        <p class="text-[10px] text-gray-400 text-center py-3">Nenhum certificado</p>
                    <?php else: ?>
                        <?php foreach ($certificates as $cert):
                            $dotColor = match($cert['status']) {
                                'expired'  => '#ef4444',
                                'expiring' => '#f59e0b',
                                default    => '#22c55e',
                            };
                            $dateColor = match($cert['status']) {
                                'expired'  => 'text-red-600',
                                'expiring' => 'text-amber-600',
                                default    => 'text-green-700',
                            };
                        ?>
                        <div class="flex items-center gap-1.5 py-1 border-b border-gray-100 last:border-0">
                            <span class="w-2.5 h-2.5 rounded-sm flex-shrink-0"
                                  style="background:<?= $dotColor ?>"></span>
                            <span class="text-[11px] font-bold text-industrial-blue flex-1">
                                <?= e($cert['type']) ?>
                            </span>
                            <span class="text-[10px] font-semibold <?= $dateColor ?>">
                                <?= formatDate($cert['expiry_date']) ?>
                            </span>
                        </div>
                        <?php if (empty($cert['document'])): ?><p class="text-[9px] text-amber-700 px-4 pb-1">Sem comprovante anexado</p><?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Coluna: Operações -->
            <div>
                <div class="bg-industrial-blue text-white text-[10px] font-bold text-center py-1.5 px-2 tracking-wide uppercase" style="background-color:#1e3a8a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact">
                    Operação / Execução
                </div>
                <div class="p-2 min-h-[60px]">
                    <?php
                    $opsToShow = !empty($authorizedOps) ? $authorizedOps : [
                        'Direção Defensiva',
                        'Veículo Grande Porte',
                        'Trabalho a Quente (Solda / Corte)',
                        'Espaço Confinado (NR-33)',
                        'Trabalho em Altura (NR-35)',
                    ];
                    $isPlaceholder = empty($authorizedOps);
                    foreach ($opsToShow as $op): ?>
                    <div class="flex items-center gap-1.5 py-0.5 border-b border-gray-50 last:border-0">
                        <?php if ($isPlaceholder): ?>
                            <span class="w-3 h-3 border border-gray-300 rounded-sm flex-shrink-0"></span>
                            <span class="text-[10px] text-gray-400"><?= e($op) ?></span>
                        <?php else: ?>
                            <span class="w-3 h-3 bg-industrial-blue rounded-sm flex-shrink-0 flex items-center justify-center">
                                <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                            <span class="text-[10px] text-industrial-blue font-semibold"><?= e($op) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ══ ASO ══ -->
        <div class="border-b border-gray-200">
            <div class="bg-industrial-blue text-white text-[10px] font-bold text-center py-1.5 px-2 tracking-wide uppercase" style="background-color:#1e3a8a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact">
                Atestado de Saúde Ocupacional (ASO)
            </div>
            <div class="px-3 py-2 min-h-[36px] flex items-center">
                <?php if ($aso): ?>
                    <?php
                    $dotColor = match($aso['document_status']) {
                        'vencido'  => '#ef4444',
                        'vencendo' => '#f59e0b',
                        default    => '#22c55e',
                    };
                    $resultLabel = match($aso['result_status'] ?? '') {
                        'apto'     => 'Apto',
                        'inapto'   => 'Inapto',
                        default    => 'Pendente',
                    };
                    $resultColor = match($aso['result_status'] ?? '') {
                        'apto'   => 'text-green-700',
                        'inapto' => 'text-red-600',
                        default  => 'text-amber-600',
                    };
                    $tipoLabel = $asoTypeLabels[$aso['aso_type']] ?? $aso['aso_type'];
                    ?>
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 mr-2"
                          style="background:<?= $dotColor ?>"></span>
                    <span class="text-[11px] text-gray-700 flex-1">
                        <?= e($tipoLabel) ?> · Validade:
                        <strong><?= formatDate($aso['expiration_date']) ?></strong>
                    </span>
                    <span class="text-[11px] font-bold <?= $resultColor ?>">
                        <?= e($resultLabel) ?>
                    </span>
                <?php else: ?>
                    <span class="text-[10px] text-gray-400">Nenhum ASO registrado</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ SESMT ══ -->
        <div class="grid grid-cols-2 border-b border-gray-200">
            <div class="border-r border-gray-200 px-3 py-2">
                <p class="text-[9px] font-bold text-industrial-blue mb-1">SESMT · ELABORADO POR:</p>
                <?php if ($sesmtElab !== ''): ?>
                    <p class="text-[10px] font-medium text-gray-800 mb-1"><?= e($sesmtElab) ?></p>
                <?php endif; ?>
                <div class="h-5 border-b border-gray-300"></div>
            </div>
            <div class="px-3 py-2">
                <p class="text-[9px] font-bold text-industrial-blue mb-1">SESMT · APROVADO POR:</p>
                <?php if ($sesmtAprov !== ''): ?>
                    <p class="text-[10px] font-medium text-gray-800 mb-1"><?= e($sesmtAprov) ?></p>
                <?php endif; ?>
                <div class="h-5 border-b border-gray-300"></div>
            </div>
        </div>

        <!-- ══ QR CODE + RAMAIS ══ -->
        <div class="flex items-center gap-3 px-4 py-3">

            <!-- QR Code -->
            <div class="flex flex-col items-center gap-1 flex-shrink-0">
                <img src="<?= e($qrDataUri) ?>" alt="QR Code"
                     class="w-24 h-24 object-contain border border-gray-200 rounded-md p-1">
                <span class="text-[9px] text-gray-400">Escaneie para verificar</span>
                <span class="text-[9px] text-gray-300">Verificado em <?= e($verifiedAt) ?></span>
            </div>

            <!-- Ramais de emergência -->
            <div class="flex-1 bg-yellow-50 border border-yellow-300 rounded-md px-3 py-2 text-center" style="background-color:#fefce8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact">
                <p class="text-[10px] font-bold text-amber-700 mb-1">RAMAIS DE EMERGÊNCIA</p>
                <p class="text-[10px] text-amber-900">SESMT: <?= e($sesmt) ?></p>
                <p class="text-[10px] text-amber-900 mt-0.5">
                    Bombeiro: <strong><?= e($bombeiro) ?></strong>
                    &nbsp; SAMU: <strong><?= e($samu) ?></strong>
                    &nbsp; Polícia: <strong><?= e($policia) ?></strong>
                </p>
            </div>
        </div>

    </div><!-- /card-authorization -->

    <!-- Rodapé -->
    <p class="mt-5 text-xs text-gray-400 text-center max-w-lg no-print">
        Carteira de autorização emitida por <?= e($employee['company_name']) ?>.
        Verifique autenticidade escaneando o QR Code acima.
    </p>

</div><!-- /flex-1 -->

</body>
</html>
