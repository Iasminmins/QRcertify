<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$id   = (int)($_GET['id'] ?? 0);
$tab  = $_GET['tab'] ?? 'certificados'; // certificados | aso | documentos
$user = currentUser();
$db   = getDB();

$stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?');
$stmt->execute([$id, $user['company_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    setFlash('error', 'Funcionário não encontrado.');
    redirect('/admin/employees.php');
}

// ── Certificados ─────────────────────────────────────────────────────────────
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

// ── ASO ──────────────────────────────────────────────────────────────────────
$db->prepare(
    'UPDATE employee_asos
     SET document_status = CASE
        WHEN expiration_date < CURDATE() THEN "vencido"
        WHEN expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END
     WHERE employee_id = ?'
)->execute([$id]);

$stmtAso = $db->prepare(
    'SELECT * FROM employee_asos WHERE employee_id = ? ORDER BY exam_date DESC LIMIT 1'
);
$stmtAso->execute([$id]);
$aso = $stmtAso->fetch();

$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];
$asoResultBadge = [
    'apto'     => ['label' => 'Apto',     'class' => 'bg-green-100 text-green-800 border-green-200'],
    'inapto'   => ['label' => 'Inapto',   'class' => 'bg-red-100 text-red-800 border-red-200'],
    'pendente' => ['label' => 'Pendente', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
];
$asoDocBadge = [
    'valido'   => ['label' => 'Válido',   'class' => 'bg-green-100 text-green-800 border-green-200'],
    'vencendo' => ['label' => 'Vencendo', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
    'vencido'  => ['label' => 'Vencido',  'class' => 'bg-red-100 text-red-800 border-red-200'],
];

// ── Documentos ───────────────────────────────────────────────────────────────
// Atualiza status de documentos do colaborador
$db->prepare(
    'UPDATE employee_documents
     SET status = CASE
        WHEN expiry_date IS NULL THEN "sem_validade"
        WHEN expiry_date < CURDATE() THEN "vencido"
        WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END
     WHERE employee_id = ? AND company_id = ?'
)->execute([$id, $user['company_id']]);

$docTypeFilter = $_GET['doc_type'] ?? '';
$docSql = 'SELECT * FROM employee_documents WHERE employee_id = ? AND company_id = ?';
$docParams = [$id, $user['company_id']];
if ($docTypeFilter !== '') {
    $docSql   .= ' AND doc_type = ?';
    $docParams[] = $docTypeFilter;
}
$docSql .= ' ORDER BY created_at DESC';
$stmtDocs = $db->prepare($docSql);
$stmtDocs->execute($docParams);
$documents = $stmtDocs->fetchAll();

$docTypes = [
    'aso'                  => ['label' => 'ASO',                  'icon' => '🏥', 'color' => 'blue'],
    'nr_certificado'       => ['label' => 'NR / Certificado',     'icon' => '📋', 'color' => 'orange'],
    'certificado_assinado' => ['label' => 'Cert. Assinado',       'icon' => '✅', 'color' => 'green'],
    'foto'                 => ['label' => 'Foto',                 'icon' => '🖼️', 'color' => 'pink'],
    'pdf_geral'            => ['label' => 'PDF',                  'icon' => '📄', 'color' => 'red'],
    'doc_word'             => ['label' => 'Word',                 'icon' => '📝', 'color' => 'indigo'],
    'outro'                => ['label' => 'Outro',                'icon' => '📎', 'color' => 'gray'],
];
$statusColors = [
    'valido'       => 'green',
    'vencendo'     => 'amber',
    'vencido'      => 'red',
    'sem_validade' => 'gray',
];
$statusLabels = [
    'valido'       => 'Válido',
    'vencendo'     => 'Vencendo',
    'vencido'      => 'Vencido',
    'sem_validade' => 'Sem validade',
];

// Contagem de docs por tipo
$docTypeCounts = [];
foreach ($documents as $d) {
    $docTypeCounts[$d['doc_type']] = ($docTypeCounts[$d['doc_type']] ?? 0) + 1;
}

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

<!-- ══════════════════════════════════════════════════════════
     CARD DO FUNCIONÁRIO
═══════════════════════════════════════════════════════════ -->
<div class="section-card mb-5 overflow-hidden">
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
            <!-- Contadores -->
            <div class="flex gap-3 flex-shrink-0 flex-wrap">
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-green-300"><?= $validCount ?></div>
                    <div class="text-xs text-white/70">Certs. Válidos</div>
                </div>
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-yellow-300"><?= $expiringCount ?></div>
                    <div class="text-xs text-white/70">Expirando</div>
                </div>
                <div class="text-center px-3 py-2 bg-white/10 rounded-lg">
                    <div class="text-xl font-bold text-white"><?= count($documents) ?></div>
                    <div class="text-xs text-white/70">Documentos</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Botões de ação rápida -->
    <div class="section-card-body flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/admin/employee-form.php?id=<?= (int)$employee['id'] ?>"
           class="btn btn-secondary btn-sm">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Editar dados
        </a>
        <a href="<?= BASE_URL ?>/admin/certificate-form.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-primary btn-sm">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            + Certificado
        </a>
        <a href="<?= BASE_URL ?>/admin/aso-form.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-secondary btn-sm">
            + ASO
        </a>
        <a href="<?= BASE_URL ?>/admin/document-upload.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-secondary btn-sm">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            + Documento
        </a>
        <a href="<?= getEmployeePublicUrl((int)$employee['id']) ?>" target="_blank"
           class="btn btn-secondary btn-sm ml-auto">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            Carteira Digital
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TABS DE NAVEGAÇÃO
═══════════════════════════════════════════════════════════ -->
<?php
function tabUrl(int $empId, string $tabName, string $extra = ''): string {
    return BASE_URL . '/admin/employee-details.php?id=' . $empId . '&tab=' . $tabName . ($extra ? '&' . $extra : '');
}
?>
<div class="mb-4 border-b border-gray-200">
    <nav class="flex gap-1 -mb-px overflow-x-auto">

        <!-- Aba: Certificados -->
        <a href="<?= tabUrl($id, 'certificados') ?>"
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition
                  <?= $tab === 'certificados'
                      ? 'border-industrial-blue text-industrial-blue'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            Certificados
            <span class="px-1.5 py-0.5 rounded-full text-xs font-bold
                         <?= $tab === 'certificados' ? 'bg-industrial-blue text-white' : 'bg-gray-100 text-gray-600' ?>">
                <?= count($certificates) ?>
            </span>
        </a>

        <!-- Aba: ASO -->
        <a href="<?= tabUrl($id, 'aso') ?>"
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition
                  <?= $tab === 'aso'
                      ? 'border-industrial-blue text-industrial-blue'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
            </svg>
            Saúde Ocupacional
            <?php if ($aso): ?>
            <span class="px-1.5 py-0.5 rounded-full text-xs font-bold
                         <?= $tab === 'aso' ? 'bg-industrial-blue text-white' : 'bg-gray-100 text-gray-600' ?>">1</span>
            <?php endif; ?>
        </a>

        <!-- Aba: Documentos -->
        <a href="<?= tabUrl($id, 'documentos') ?>"
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition
                  <?= $tab === 'documentos'
                      ? 'border-industrial-blue text-industrial-blue'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/>
            </svg>
            Documentos
            <span class="px-1.5 py-0.5 rounded-full text-xs font-bold
                         <?= $tab === 'documentos' ? 'bg-industrial-blue text-white' : 'bg-gray-100 text-gray-600' ?>">
                <?= count($documents) ?>
            </span>
        </a>

    </nav>
</div>

<!-- ══════════════════════════════════════════════════════════
     ABA: CERTIFICADOS
═══════════════════════════════════════════════════════════ -->
<?php if ($tab === 'certificados'): ?>
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title">Certificados (<?= count($certificates) ?>)</h2>
        <a href="<?= BASE_URL ?>/admin/certificate-form.php?employee_id=<?= (int)$employee['id'] ?>"
           class="btn btn-primary btn-sm">+ Adicionar</a>
    </div>

    <?php if (empty($certificates)): ?>
        <div class="py-14 text-center">
            <div class="text-5xl mb-3">📜</div>
            <p class="text-gray-500 font-medium">Nenhum certificado cadastrado.</p>
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
<?php endif; // fim aba certificados ?>


<!-- ══════════════════════════════════════════════════════════
     ABA: ASO / SAÚDE OCUPACIONAL
═══════════════════════════════════════════════════════════ -->
<?php if ($tab === 'aso'): ?>
<div class="section-card">
    <div class="section-card-header flex items-center justify-between">
        <div class="flex items-center gap-2">
            <svg class="h-5 w-5 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h2 class="section-card-title">Saúde Ocupacional (ASO)</h2>
        </div>
        <a href="<?= BASE_URL ?>/admin/aso-form.php?employee_id=<?= $id ?>"
           class="btn btn-primary btn-sm">
            + <?= $aso ? 'Novo ASO' : 'Adicionar ASO' ?>
        </a>
    </div>
    <div class="section-card-body">
        <?php if (!$aso): ?>
            <div class="py-14 text-center">
                <div class="text-5xl mb-3">🏥</div>
                <p class="text-gray-500 font-medium">Nenhum ASO cadastrado.</p>
                <p class="text-sm mt-1">
                    <a href="<?= BASE_URL ?>/admin/aso-form.php?employee_id=<?= $id ?>"
                       class="text-industrial-blue hover:underline">Adicionar ASO</a>
                </p>
            </div>
        <?php else:
            $rb  = $asoResultBadge[$aso['result_status']] ?? $asoResultBadge['pendente'];
            $db2 = $asoDocBadge[$aso['document_status']]  ?? $asoDocBadge['valido'];
        ?>
        <div class="border border-gray-200 rounded-lg p-4 space-y-3">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div>
                    <p class="text-xs font-bold text-industrial-blue uppercase tracking-wider">
                        <?= e($asoTypeLabels[$aso['aso_type']] ?? $aso['aso_type']) ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Exame: <?= formatDate($aso['exam_date']) ?>
                        · Válido até: <strong><?= formatDate($aso['expiration_date']) ?></strong>
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?= $rb['class'] ?>">
                        <?= $rb['label'] ?>
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?= $db2['class'] ?>">
                        <?= $db2['label'] ?>
                    </span>
                </div>
            </div>
            <?php if (!empty($aso['doctor_name'])): ?>
            <p class="text-xs text-gray-500">
                Médico: <?= e($aso['doctor_name']) ?>
                <?php if (!empty($aso['doctor_crm'])): ?> · CRM: <?= e($aso['doctor_crm']) ?><?php endif; ?>
            </p>
            <?php endif; ?>
            <div class="flex items-center gap-2 pt-1 border-t border-gray-100">
                <a href="<?= BASE_URL ?>/admin/aso-form.php?id=<?= $aso['id'] ?>"
                   class="btn btn-secondary btn-sm">Editar</a>
                <?php if (!empty($aso['file_path'])): ?>
                <a href="<?= BASE_URL ?>/admin/aso-download.php?id=<?= $aso['id'] ?>"
                   target="_blank" class="btn btn-secondary btn-sm">Ver Documento</a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/admin/aso-delete.php?id=<?= $aso['id'] ?>"
                   onclick="return confirm('Excluir este ASO?')"
                   class="btn btn-danger btn-sm ml-auto">Excluir</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; // fim aba aso ?>

<!-- ══════════════════════════════════════════════════════════
     ABA: DOCUMENTOS
═══════════════════════════════════════════════════════════ -->
<?php if ($tab === 'documentos'): ?>

<!-- Filtro por tipo (chips) -->
<div class="flex flex-wrap gap-2 mb-4">
    <a href="<?= tabUrl($id, 'documentos') ?>"
       class="px-3 py-1 rounded-full text-sm font-medium border transition
              <?= $docTypeFilter === '' ? 'bg-industrial-blue text-white border-industrial-blue' : 'bg-white text-gray-600 border-gray-200 hover:border-industrial-blue/40' ?>">
        Todos (<?= count($documents) ?>)
    </a>
    <?php foreach ($docTypes as $key => $info): if (!isset($docTypeCounts[$key])) continue; ?>
    <a href="<?= tabUrl($id, 'documentos', 'doc_type=' . $key) ?>"
       class="px-3 py-1 rounded-full text-sm font-medium border transition
              <?= $docTypeFilter === $key ? 'bg-industrial-blue text-white border-industrial-blue' : 'bg-white text-gray-600 border-gray-200 hover:border-industrial-blue/40' ?>">
        <?= $info['icon'] ?> <?= $info['label'] ?> (<?= $docTypeCounts[$key] ?>)
    </a>
    <?php endforeach; ?>

    <a href="<?= BASE_URL ?>/admin/document-upload.php?employee_id=<?= $id ?>"
       class="ml-auto px-3 py-1 rounded-full text-sm font-semibold border border-dashed border-industrial-blue
              text-industrial-blue hover:bg-blue-50 transition flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Adicionar
    </a>
</div>

<?php if (empty($documents)): ?>
<div class="section-card">
    <div class="py-16 text-center">
        <div class="text-6xl mb-4">📂</div>
        <p class="text-gray-500 font-medium">Nenhum documento encontrado.</p>
        <p class="text-sm text-gray-400 mt-1">
            <a href="<?= BASE_URL ?>/admin/document-upload.php?employee_id=<?= $id ?>"
               class="text-industrial-blue hover:underline">Adicione o primeiro documento</a>
        </p>
    </div>
</div>
<?php else: ?>

<!-- Grid de documentos -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="docs-grid">
    <?php foreach ($documents as $doc):
        $type  = $docTypes[$doc['doc_type']] ?? $docTypes['outro'];
        $sc    = $statusColors[$doc['status']] ?? 'gray';
        $sl    = $statusLabels[$doc['status']] ?? $doc['status'];
        $ext   = strtolower($doc['file_ext'] ?? '');
        $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
        $isPdf = $ext === 'pdf';
    ?>
    <div class="section-card overflow-hidden hover:shadow-lg hover:border-industrial-blue/30 transition group cursor-pointer"
         onclick="openDocViewer(<?= $doc['id'] ?>, '<?= addslashes(htmlspecialchars($doc['title'])) ?>', '<?= $ext ?>', <?= $isImg ? 'true' : 'false' ?>, <?= $isPdf ? 'true' : 'false' ?>)">

        <!-- Preview -->
        <div class="bg-gray-50 h-40 flex items-center justify-center relative overflow-hidden border-b border-gray-100">
            <?php if ($isImg): ?>
                <img src="<?= BASE_URL ?>/admin/document-view.php?id=<?= $doc['id'] ?>&thumb=1"
                     alt="<?= htmlspecialchars($doc['title']) ?>"
                     class="h-full w-full object-cover group-hover:scale-105 transition-transform duration-300"
                     loading="lazy"
                     onerror="this.parentNode.innerHTML='<div class=\'flex flex-col items-center\'><span class=\'text-5xl\'>🖼️</span></div>'">
            <?php elseif ($isPdf): ?>
                <div class="text-center">
                    <div class="text-5xl mb-1 group-hover:scale-110 transition-transform">📄</div>
                    <span class="text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded-full border border-red-200">PDF</span>
                </div>
            <?php elseif (in_array($ext, ['doc','docx'])): ?>
                <div class="text-center">
                    <div class="text-5xl mb-1 group-hover:scale-110 transition-transform">📝</div>
                    <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full border border-indigo-200">WORD</span>
                </div>
            <?php else: ?>
                <div class="text-5xl group-hover:scale-110 transition-transform"><?= $type['icon'] ?></div>
            <?php endif; ?>

            <!-- Badge tipo -->
            <div class="absolute top-2 left-2">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold
                             bg-white/90 border border-gray-200 shadow-sm text-gray-700">
                    <?= $type['icon'] ?> <?= $type['label'] ?>
                </span>
            </div>

            <!-- Badge status -->
            <?php if ($doc['status'] !== 'sem_validade'): ?>
            <div class="absolute top-2 right-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                             bg-<?= $sc ?>-100 text-<?= $sc ?>-800 border border-<?= $sc ?>-200">
                    <?= $sl ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Overlay ao hover -->
            <div class="absolute inset-0 bg-industrial-blue/0 group-hover:bg-industrial-blue/10 transition-colors flex items-center justify-center">
                <div class="opacity-0 group-hover:opacity-100 transition-opacity bg-white rounded-full p-2 shadow-lg">
                    <svg class="h-5 w-5 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Info -->
        <div class="p-4" onclick="event.stopPropagation()">
            <h3 class="font-semibold text-gray-900 truncate text-sm" title="<?= htmlspecialchars($doc['title']) ?>">
                <?= htmlspecialchars($doc['title']) ?>
            </h3>
            <div class="mt-1 flex items-center gap-3 text-xs text-gray-400">
                <?php if (!empty($doc['issue_date'])): ?>
                <span>📅 <?= date('d/m/Y', strtotime($doc['issue_date'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($doc['expiry_date'])): ?>
                <span class="text-<?= $sc ?>-600 font-medium">
                    ⏱ <?= date('d/m/Y', strtotime($doc['expiry_date'])) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($doc['description'])): ?>
            <p class="text-xs text-gray-400 mt-1 line-clamp-1"><?= htmlspecialchars($doc['description']) ?></p>
            <?php endif; ?>

            <!-- Ações -->
            <div class="flex gap-1.5 mt-3 pt-3 border-t border-gray-100">
                <button onclick="openDocViewer(<?= $doc['id'] ?>, '<?= addslashes(htmlspecialchars($doc['title'])) ?>', '<?= $ext ?>', <?= $isImg ? 'true' : 'false' ?>, <?= $isPdf ? 'true' : 'false' ?>)"
                        class="btn btn-secondary btn-sm flex-1 justify-center text-xs">
                    👁 Ver
                </button>
                <a href="<?= BASE_URL ?>/admin/document-upload.php?id=<?= $doc['id'] ?>"
                   class="btn btn-secondary btn-sm text-xs px-2" title="Editar">✏️</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/document-delete.php"
                      onsubmit="return confirm('Excluir este documento?')" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm text-xs px-2" title="Excluir">🗑</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; // empty docs ?>
<?php endif; // tab documentos ?>

<!-- ══════════════════════════════════════════════════════════
     MODAL VISUALIZADOR DE DOCUMENTO
═══════════════════════════════════════════════════════════ -->
<div id="doc-viewer-modal"
     class="fixed inset-0 z-50 hidden"
     role="dialog" aria-modal="true">

    <!-- Overlay -->
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeDocViewer()"></div>

    <!-- Painel -->
    <div class="absolute inset-4 sm:inset-8 bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        <!-- Header do modal -->
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200 bg-gray-50 flex-shrink-0">
            <h3 id="doc-viewer-title" class="font-semibold text-gray-900 truncate mr-4 flex items-center gap-2">
                <svg class="h-5 w-5 text-industrial-blue flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span id="doc-viewer-title-text">Documento</span>
            </h3>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a id="doc-viewer-download" href="#" target="_blank"
                   class="btn btn-secondary btn-sm hidden">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Abrir / Baixar
                </a>
                <button onclick="closeDocViewer()"
                        class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Conteúdo do modal -->
        <div id="doc-viewer-body" class="flex-1 overflow-auto flex items-center justify-center bg-gray-100 p-4">
            <!-- Preenchido pelo JS -->
        </div>
    </div>
</div>

<script>
function openDocViewer(docId, title, ext, isImg, isPdf) {
    const modal     = document.getElementById('doc-viewer-modal');
    const titleEl   = document.getElementById('doc-viewer-title-text');
    const body      = document.getElementById('doc-viewer-body');
    const dlBtn     = document.getElementById('doc-viewer-download');
    const viewUrl   = '<?= BASE_URL ?>/admin/document-view.php?id=' + docId;

    titleEl.textContent = title;
    dlBtn.href = viewUrl;
    dlBtn.classList.remove('hidden');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    if (isImg) {
        body.innerHTML = `
            <div class="flex items-center justify-center w-full h-full">
                <img src="${viewUrl}" alt="${title}"
                     class="max-w-full max-h-full object-contain rounded-lg shadow-lg"
                     style="max-height: calc(100vh - 160px)">
            </div>`;
    } else if (isPdf) {
        body.innerHTML = `
            <iframe src="${viewUrl}" class="w-full h-full rounded-lg" style="min-height:500px" frameborder="0">
                <p>Seu navegador não suporta visualização de PDF.
                   <a href="${viewUrl}" target="_blank" class="text-industrial-blue underline">Clique aqui para abrir</a>.</p>
            </iframe>`;
    } else {
        const icons = {doc:'📝', docx:'📝', xls:'📊', xlsx:'📊', txt:'📄'};
        body.innerHTML = `
            <div class="text-center">
                <div class="text-7xl mb-4">${icons[ext] || '📎'}</div>
                <p class="text-gray-600 font-medium mb-2">${title}</p>
                <p class="text-sm text-gray-400 mb-6">Este tipo de arquivo (${ext.toUpperCase()}) não pode ser<br>visualizado diretamente no navegador.</p>
                <a href="${viewUrl}" target="_blank"
                   class="btn btn-primary">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Baixar arquivo
                </a>
            </div>`;
    }
}

function closeDocViewer() {
    const modal = document.getElementById('doc-viewer-modal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('doc-viewer-body').innerHTML = '';
}

// Fecha com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDocViewer();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
