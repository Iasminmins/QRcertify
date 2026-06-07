<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();
$canEmail  = companyHasFeature($companyId, 'email_alerts');

// Atualiza document_status
$db->prepare(
    'UPDATE employee_asos a
     JOIN employees e ON e.id = a.employee_id
     SET a.document_status = CASE
        WHEN a.expiration_date < CURDATE() THEN "vencido"
        WHEN a.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END
     WHERE e.company_id = ?'
)->execute([$companyId]);

// Verifica se colunas manager_email existem (migration 015)
$hasMgrEmail = false;
try {
    $db->query('SELECT manager_email FROM departments LIMIT 0');
    $hasMgrEmail = true;
} catch (Exception $e) { $hasMgrEmail = false; }

$mgrFields = $hasMgrEmail
    ? 'COALESCE(d2.manager_name, d.manager_name) AS mgr_name,
       COALESCE(d2.manager_email, d.manager_email) AS mgr_email'
    : 'd.manager_name AS mgr_name, NULL AS mgr_email';

// Busca ASOs com problema
$stmtAso = $db->prepare(
    "SELECT a.id, a.aso_type, a.expiration_date, a.document_status,
            e.id AS emp_id, e.name AS emp_name, e.email AS emp_email,
            COALESCE(d.name, e.department, '') AS emp_dept,
            {$mgrFields}
     FROM employee_asos a
     JOIN employees e ON e.id = a.employee_id
     LEFT JOIN departments d  ON d.id   = e.department_id
     LEFT JOIN departments d2 ON d2.name = e.department AND d2.company_id = e.company_id
     WHERE e.company_id = ? AND e.status = 'active'
       AND a.document_status IN ('vencendo','vencido')
       AND e.email IS NOT NULL AND e.email != ''
     ORDER BY e.name ASC, a.expiration_date ASC"
);
$stmtAso->execute([$companyId]);
$rows = $stmtAso->fetchAll();

$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];

// Agrupa por funcionário
$byEmployee = [];
$allDepts   = [];
foreach ($rows as $r) {
    $byEmployee[$r['emp_id']]['name']      = $r['emp_name'];
    $byEmployee[$r['emp_id']]['email']     = $r['emp_email'];
    $byEmployee[$r['emp_id']]['dept']      = $r['emp_dept'];
    $byEmployee[$r['emp_id']]['mgr_name']  = $r['mgr_name'];
    $byEmployee[$r['emp_id']]['mgr_email'] = $r['mgr_email'];
    $byEmployee[$r['emp_id']]['asos'][]    = $r;
    if ($r['emp_dept'] !== '') $allDepts[$r['emp_dept']] = true;
}
ksort($allDepts);

$sent = 0; $failed = 0; $results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    if (!csrfCheck()) { setFlash('error', 'Token inválido.'); redirect('/admin/aso-notify.php'); }
    if (!$canEmail)   { setFlash('error', 'Alertas por e-mail estão disponíveis a partir do plano Pro.'); redirect('/admin/aso-notify.php'); }

    $targets     = $_POST['targets'] ?? [];
    $companyName = '';
    $stmtCo      = $db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $stmtCo->execute([$companyId]);
    $coRow = $stmtCo->fetch();
    if ($coRow) $companyName = $coRow['name'];

    $byManager = [];

    foreach ($byEmployee as $empId => $emp) {
        if (!empty($targets) && !in_array((string)$empId, $targets)) continue;

        // ── E-mail rico para o COLABORADOR ──────────────────────────
        $hasVencido  = count(array_filter($emp['asos'], fn($a) => $a['document_status'] === 'vencido')) > 0;
        $urgencia = $hasVencido
            ? '<div style="background:#fef2f2;border-left:4px solid #dc2626;border-radius:4px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-size:14px;"><strong>⚠️ Ação urgente!</strong> Você possui ASO <strong>vencido</strong>. Regularize imediatamente.</div>'
            : '<div style="background:#fffbeb;border-left:4px solid #d97706;border-radius:4px;padding:12px 16px;margin-bottom:16px;color:#92400e;font-size:14px;"><strong>📅 Atenção ao prazo!</strong> Seu ASO está próximo do vencimento.</div>';

        $rows_html = '';
        foreach ($emp['asos'] as $a) {
            $tipo   = $asoTypeLabels[$a['aso_type']] ?? $a['aso_type'];
            $isVenc = $a['document_status'] === 'vencido';
            $color  = $isVenc ? '#dc2626' : '#d97706';
            $label  = $isVenc ? '🔴 VENCIDO' : '🟡 VENCENDO';
            $bg     = $isVenc ? '#fef2f2' : '#fffbeb';
            $border = $isVenc ? '#fecaca' : '#fde68a';
            $data   = date('d/m/Y', strtotime($a['expiration_date']));
            $rows_html .= '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:8px;padding:14px 16px;margin-bottom:10px;">'
                . '<div style="font-size:11px;font-weight:700;color:'.$color.';text-transform:uppercase;">'.$label.'</div>'
                . '<div style="font-size:15px;font-weight:600;color:#111827;margin:4px 0 2px;">ASO '.$tipo.'</div>'
                . '<div style="font-size:13px;color:'.$color.';font-weight:600;">Vencimento: '.$data.'</div>'
                . '</div>';
        }

        $passos = '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin:20px 0;">'
            . '<p style="margin:0 0 10px;font-weight:600;color:#0369a1;font-size:14px;">📋 O que você precisa fazer:</p>'
            . '<ol style="margin:0;padding-left:20px;color:#374151;font-size:14px;line-height:2;">'
            . '<li>Dirija-se ao <strong>setor de RH</strong> ou fale com seu <strong>gestor</strong></li>'
            . '<li>Informe sobre este aviso de vencimento do ASO</li>'
            . '<li>Agende o exame médico ocupacional para renovação</li>'
            . '<li>Após o exame, o RH atualizará seu registro no sistema</li>'
            . '</ol></div>';

        $baseUrl = BASE_URL;
        $nome    = htmlspecialchars($emp['name']);
        $html = '<h2 style="font-size:18px;color:#1e3a8a;margin:0 0 6px;">Olá, '.$nome.'!</h2>'
              . '<p style="color:#6b7280;font-size:14px;margin:0 0 20px;">'.($companyName ? htmlspecialchars($companyName).' — ' : '').'Sistema de Gestão de Saúde Ocupacional</p>'
              . $urgencia
              . '<p style="font-size:15px;color:#374151;margin-bottom:14px;">O(s) seguinte(s) ASO(s) requer(em) sua atenção:</p>'
              . $rows_html . $passos
              . '<p style="font-size:13px;color:#6b7280;margin-top:16px;">Em caso de dúvidas, entre em contato com o RH da empresa.</p>';

        $subject = '[' . SITE_NAME . '] ⚠️ Seu ASO precisa de atenção';
        $ok      = sendMail($emp['email'], $subject, $html);

        if ($ok) { $sent++; auditLog('aso_notify.sent', "employee #{$empId}", json_encode(['email'=>$emp['email']])); }
        else      { $failed++; }
        $results[] = ['name'=>$emp['name'], 'email'=>$emp['email'], 'ok'=>$ok, 'type'=>'colaborador'];

        // ── Acumula para gestor ──────────────────────────────────────
        if (!empty($emp['mgr_email'])) {
            $mk = $emp['mgr_email'];
            if (!isset($byManager[$mk])) {
                $byManager[$mk] = ['name'=>$emp['mgr_name']??'Gestor','email'=>$emp['mgr_email'],'employees'=>[]];
            }
            $byManager[$mk]['employees'][] = $emp;
        }
    }

    // ── E-mail consolidado para cada GESTOR ─────────────────────────
    foreach ($byManager as $mgr) {
        $total   = count($mgr['employees']);
        $vencido = 0; $vencendo = 0;
        foreach ($mgr['employees'] as $emp) {
            foreach ($emp['asos'] as $a) {
                if ($a['document_status'] === 'vencido') $vencido++;
                else $vencendo++;
            }
        }

        $resumo = '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">'
            . '<div style="flex:1;min-width:100px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#dc2626;">'.$vencido.'</div><div style="font-size:12px;color:#991b1b;font-weight:600;">ASO(s) Vencido(s)</div></div>'
            . '<div style="flex:1;min-width:100px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#d97706;">'.$vencendo.'</div><div style="font-size:12px;color:#92400e;font-weight:600;">A Vencer</div></div>'
            . '<div style="flex:1;min-width:100px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#1e3a8a;">'.$total.'</div><div style="font-size:12px;color:#1e40af;font-weight:600;">Colaborador(es)</div></div>'
            . '</div>';

        $rows_mgr = '';
        foreach ($mgr['employees'] as $emp) {
            $hasVenc = count(array_filter($emp['asos'], fn($a) => $a['document_status'] === 'vencido')) > 0;
            $bg = $hasVenc ? '#fef2f2' : '#fffbeb';
            $border = $hasVenc ? '#fecaca' : '#fde68a';
            $dot = $hasVenc ? '🔴' : '🟡';
            $tags = '';
            foreach ($emp['asos'] as $a) {
                $tipo  = $asoTypeLabels[$a['aso_type']] ?? $a['aso_type'];
                $lbl   = $a['document_status'] === 'vencido' ? 'Vencido' : 'Vencendo';
                $cor   = $a['document_status'] === 'vencido' ? '#dc2626' : '#d97706';
                $data  = date('d/m/Y', strtotime($a['expiration_date']));
                $tags .= '<span style="display:inline-block;font-size:11px;background:#fff;border:1px solid '.$cor.';color:'.$cor.';border-radius:20px;padding:2px 10px;margin:2px;">ASO '.$tipo.' — '.$lbl.' · '.$data.'</span>';
            }
            $rows_mgr .= '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:8px;padding:14px 16px;margin-bottom:10px;">'
                . '<div style="font-weight:600;color:#111827;font-size:15px;">'.$dot.' '.htmlspecialchars($emp['name']).'</div>'
                . '<div style="font-size:12px;color:#6b7280;margin-bottom:8px;">'.htmlspecialchars($emp['email']).'</div>'
                . '<div>'.$tags.'</div></div>';
        }

        $html_mgr = '<h2 style="font-size:18px;color:#1e3a8a;margin:0 0 6px;">Olá, '.htmlspecialchars($mgr['name']).'!</h2>'
            . '<p style="color:#6b7280;font-size:14px;margin:0 0 20px;">'.($companyName ? htmlspecialchars($companyName).' — ' : '').'Relatório de Saúde Ocupacional da sua Equipe</p>'
            . '<p style="font-size:15px;color:#374151;margin-bottom:16px;">Os colaboradores abaixo possuem ASO vencido ou próximo do vencimento:</p>'
            . $resumo . $rows_mgr
            . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-top:16px;">'
            . '<p style="margin:0;font-size:14px;color:#166534;"><strong>📋 Próximo passo:</strong> Oriente os colaboradores a procurarem o RH para agendar o exame médico de renovação do ASO. ASOs vencidos podem gerar restrições legais e de segurança.</p>'
            . '</div>'
            . '<a style="display:inline-block;background:#1e3a8a;color:#fff;padding:11px 24px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;margin-top:16px;" href="'.BASE_URL.'/admin/aso-notify.php">Ver no sistema</a>';

        $ok = sendMail($mgr['email'], '['.SITE_NAME.'] 📋 ASOs pendentes na sua equipe', $html_mgr);
        if ($ok) auditLog('aso_notify.manager.sent', "manager {$mgr['email']}", json_encode(['employees'=>$total]));
        $results[] = ['name'=>$mgr['name'].' (Gestor — '.$total.' colab.)','email'=>$mgr['email'],'ok'=>$ok,'type'=>'gestor'];
    }
}

$pageTitle = 'Alertas de ASO';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Alertas de ASO</h1>
        <p class="page-subtitle">Colaboradores com ASO vencido ou vencendo nos próximos 30 dias.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/asos.php" class="btn btn-secondary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Todos os ASOs
    </a>
</div>

<?php renderFlashes(); ?>

<?php if (!$hasMgrEmail): ?>
<div class="mb-4 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900 flex items-start gap-3">
    <svg class="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <div>
        <p class="font-semibold">Migration pendente — e-mail de gestor não disponível</p>
        <p class="mt-1 text-amber-800">Execute <code class="bg-amber-100 px-1 rounded font-mono text-xs">sql/015_department_manager_contact.sql</code>
        e cadastre o e-mail do gestor em <a href="<?= BASE_URL ?>/admin/departments.php" class="underline font-medium">Áreas e Setores</a>.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!$canEmail): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
    <p class="font-semibold mb-1">Alertas por e-mail não incluídos no seu plano</p>
    <p>Faça upgrade em <a href="<?= BASE_URL ?>/admin/plano.php" class="text-industrial-blue underline font-medium">Meu Plano</a>.</p>
</div>
<?php endif; ?>

<!-- Resultado do envio -->
<?php if (!empty($results)): ?>
<div class="mb-6 <?= $failed === 0 ? 'bg-green-50 border-green-200' : 'bg-amber-50 border-amber-200' ?> border rounded-xl p-4">
    <p class="font-medium <?= $failed === 0 ? 'text-green-800' : 'text-amber-800' ?> mb-3">
        <?= $sent ?> e-mail(s) enviado(s)<?= $failed > 0 ? ", {$failed} falha(s)" : ' com sucesso!' ?>
    </p>
    <?php
    $cols = ['colaborador'=>[],'gestor'=>[]];
    foreach ($results as $r) $cols[$r['type']??'colaborador'][] = $r;
    ?>
    <?php if (!empty($cols['colaborador'])): ?>
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Colaboradores</p>
    <ul class="space-y-0.5 mb-3">
        <?php foreach ($cols['colaborador'] as $r): ?>
        <li class="text-sm flex items-center gap-2 <?= $r['ok'] ? 'text-green-700' : 'text-red-600' ?>">
            <?= $r['ok'] ? '✓' : '✗' ?> <?= e($r['name']) ?> — <?= e($r['email']) ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($cols['gestor'])): ?>
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Gestores notificados</p>
    <ul class="space-y-0.5">
        <?php foreach ($cols['gestor'] as $r): ?>
        <li class="text-sm flex items-center gap-2 <?= $r['ok'] ? 'text-blue-700' : 'text-red-600' ?>">
            <?= $r['ok'] ? '✓' : '✗' ?>
            <span class="px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 rounded font-medium">Gestor</span>
            <?= e($r['name']) ?> — <?= e($r['email']) ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($byEmployee)): ?>
<div class="section-card">
    <div class="py-16 text-center text-gray-400">
        <svg class="h-12 w-12 text-green-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="font-medium text-gray-600">Nenhum ASO vencido ou a vencer! 🎉</p>
        <p class="text-sm mt-1 text-gray-400">Todos os ASOs estão em dia.</p>
    </div>
</div>
<?php else: ?>

<?php if ($canEmail): ?>
<form method="POST">
    <?= csrfField() ?>
    <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-600">
            <strong><?= count($byEmployee) ?></strong> colaborador(es) com ASO a vencer ou vencido.
            <span id="contador-filtro" class="text-gray-400"></span>
            <span id="contador-selecionados" class="text-industrial-blue font-medium"></span>
        </p>
        <div class="flex gap-2">
            <button type="button" onclick="toggleAll(true)" class="text-xs text-industrial-blue hover:underline">Selecionar todos</button>
            <span class="text-gray-300">|</span>
            <button type="button" onclick="toggleAll(false)" class="text-xs text-gray-500 hover:underline">Desmarcar todos</button>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-gray-50 border rounded-xl p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Buscar nome ou e-mail</label>
            <input id="f-busca" type="text" placeholder="Ex: João Silva" oninput="aplicarFiltros()"
                   class="w-full text-sm border-gray-300 rounded-md shadow-sm px-3 py-1.5 focus:ring-industrial-blue focus:border-industrial-blue">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status do ASO</label>
            <select id="f-status" onchange="aplicarFiltros()"
                    class="w-full text-sm border-gray-300 rounded-md shadow-sm px-3 py-1.5 focus:ring-industrial-blue focus:border-industrial-blue">
                <option value="">Todos</option>
                <option value="vencido">Vencido (urgente)</option>
                <option value="vencendo">Vencendo (a vencer)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Departamento</label>
            <select id="f-dept" onchange="aplicarFiltros()"
                    class="w-full text-sm border-gray-300 rounded-md shadow-sm px-3 py-1.5 focus:ring-industrial-blue focus:border-industrial-blue">
                <option value="">Todos</option>
                <?php foreach (array_keys($allDepts) as $d): ?>
                <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Cards dos colaboradores -->
    <div class="space-y-3 mb-5" id="lista-funcionarios">
        <?php foreach ($byEmployee as $empId => $emp):
            $empStatuses = array_unique(array_column($emp['asos'], 'document_status'));
        ?>
        <div class="bg-white rounded-xl shadow-sm border hover:border-gray-300 transition p-4 emp-card"
             data-nome="<?= strtolower(e($emp['name'])) ?>"
             data-email="<?= strtolower(e($emp['email'])) ?>"
             data-statuses="<?= e(implode('|', $empStatuses)) ?>"
             data-dept="<?= strtolower(e($emp['dept'] ?? '')) ?>">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="targets[]" value="<?= (int)$empId ?>"
                       class="emp-check mt-1 h-4 w-4 rounded border-gray-300 text-industrial-blue" checked>
                <div class="flex-1 min-w-0">

                    <!-- Linha 1: avatar + nome + e-mail + setor -->
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <div class="h-7 w-7 rounded-full bg-industrial-blue/10 flex items-center justify-center text-industrial-blue text-xs font-bold flex-shrink-0">
                            <?= strtoupper(mb_substr($emp['name'], 0, 1)) ?>
                        </div>
                        <span class="font-semibold text-gray-900 text-sm"><?= e($emp['name']) ?></span>
                        <span class="text-xs text-gray-400"><?= e($emp['email']) ?></span>
                        <?php if (!empty($emp['dept'])): ?>
                        <span class="text-xs bg-gray-100 text-gray-600 border border-gray-200 rounded-full px-2 py-0.5">
                            <?= e($emp['dept']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Linha 2: badges dos ASOs -->
                    <div class="flex flex-wrap gap-1.5 mb-2.5">
                        <?php foreach ($emp['asos'] as $a):
                            $isVencido = $a['document_status'] === 'vencido';
                            $badgeCls  = $isVencido ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200';
                            $dot       = $isVencido ? '🔴' : '🟡';
                            $tipo      = $asoTypeLabels[$a['aso_type']] ?? $a['aso_type'];
                            $data      = date('d/m/Y', strtotime($a['expiration_date']));
                        ?>
                        <span class="inline-flex items-center gap-1 text-xs border rounded-full px-2.5 py-0.5 font-medium <?= $badgeCls ?>">
                            <?= $dot ?> <?= e($tipo) ?> · <?= $data ?>
                        </span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Linha 3: gestor -->
                    <?php if (!empty($emp['mgr_email'])): ?>
                    <div class="flex items-center gap-2 pt-2 border-t border-gray-100">
                        <svg class="h-3.5 w-3.5 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span class="text-xs text-gray-500">
                            Gestor: <strong class="text-gray-700"><?= e($emp['mgr_name'] ?? 'Gestor') ?></strong>
                            <span class="text-gray-400 ml-1"><?= e($emp['mgr_email']) ?></span>
                        </span>
                        <span class="inline-flex items-center gap-1 text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded-full px-2 py-0.5 font-medium">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Gestor será notificado
                        </span>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-1.5 pt-2 border-t border-gray-100">
                        <svg class="h-3.5 w-3.5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span class="text-xs text-gray-400">
                            Sem gestor cadastrado —
                            <a href="<?= BASE_URL ?>/admin/departments.php" class="text-industrial-blue hover:underline" target="_blank">cadastrar e-mail do gestor</a>
                        </span>
                    </div>
                    <?php endif; ?>

                </div>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    <button type="button" id="btn-enviar" onclick="enviarEmLotes()"
            class="inline-flex items-center gap-2 bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2.5 rounded-lg font-medium transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        Enviar E-mails Selecionados
    </button>

    <div id="progresso" class="hidden mt-5 bg-white border rounded-xl p-5 space-y-3">
        <p class="text-sm font-medium text-gray-700">Enviando lote <span id="prog-lote">1</span>…</p>
        <p class="text-sm text-gray-600"><span id="prog-enviados">0</span> / <span id="prog-total">0</span> enviados</p>
        <div class="w-full bg-gray-200 rounded-full h-2.5">
            <div id="prog-barra" class="bg-industrial-blue h-2.5 rounded-full transition-all duration-300" style="width:0%"></div>
        </div>
        <div id="prog-erros" class="text-xs text-red-600 space-y-0.5"></div>
        <p id="prog-fim" class="hidden text-sm font-semibold text-green-700">✓ Todos os lotes foram processados!</p>
    </div>
</form>
<?php endif; ?>
<?php endif; ?>

<script>
function atualizarSelecionados() {
    const total = document.querySelectorAll('.emp-card:not(.hidden) .emp-check:checked').length;
    const el    = document.getElementById('contador-selecionados');
    if (el) el.textContent = total > 0 ? `· ${total} selecionado(s)` : '';
}
function toggleAll(v) {
    document.querySelectorAll('.emp-card:not(.hidden) .emp-check').forEach(c => c.checked = v);
    atualizarSelecionados();
}
function aplicarFiltros() {
    const busca  = document.getElementById('f-busca')?.value.toLowerCase().trim() ?? '';
    const status = document.getElementById('f-status')?.value ?? '';
    const dept   = document.getElementById('f-dept')?.value.toLowerCase() ?? '';
    let visiveis = 0;
    document.querySelectorAll('.emp-card').forEach(card => {
        const ok = (!busca  || card.dataset.nome.includes(busca) || card.dataset.email.includes(busca))
                && (!status || card.dataset.statuses.split('|').includes(status))
                && (!dept   || card.dataset.dept === dept);
        card.classList.toggle('hidden', !ok);
        if (ok) visiveis++;
    });
    const el = document.getElementById('contador-filtro');
    if (el) {
        const tem = busca || status || dept;
        el.textContent = tem ? `— ${visiveis} resultado(s)` : '';
        el.style.color = (tem && visiveis === 0) ? '#ef4444' : '#64748b';
    }
    atualizarSelecionados();
}
async function enviarEmLotes() {
    const checks  = [...document.querySelectorAll('.emp-card:not(.hidden) .emp-check:checked')];
    const targets = checks.map(c => c.value);
    if (!targets.length) { alert('Selecione ao menos um colaborador.'); return; }
    const LOTE=20, total=targets.length;
    const btn=document.getElementById('btn-enviar');
    const div=document.getElementById('progresso');
    const csrf=document.querySelector('input[name="csrf_token"]').value;
    btn.disabled=true; div.classList.remove('hidden');
    document.getElementById('prog-total').textContent=total;
    document.getElementById('prog-erros').innerHTML='';
    document.getElementById('prog-fim').classList.add('hidden');
    let enviados=0, numLote=0;
    for (let offset=0; offset<total; offset+=LOTE) {
        numLote++;
        const lote=targets.slice(offset,offset+LOTE);
        document.getElementById('prog-lote').textContent=numLote;
        const fd=new FormData();
        fd.append('send','1'); fd.append('csrf_token',csrf);
        lote.forEach(id=>fd.append('targets[]',id));
        try {
            const resp=await fetch(window.location.href,{method:'POST',body:fd});
            const html=await resp.text();
            enviados+=(html.match(/✓/g)||[]).length;
            const f=(html.match(/✗/g)||[]).length;
            if (f>0) { const p=document.createElement('p'); p.textContent=`Lote ${numLote}: ${f} falha(s).`; document.getElementById('prog-erros').appendChild(p); }
        } catch(err) {
            const p=document.createElement('p'); p.textContent=`Lote ${numLote}: erro — ${err.message}`; document.getElementById('prog-erros').appendChild(p);
        }
        document.getElementById('prog-enviados').textContent=enviados;
        document.getElementById('prog-barra').style.width=Math.round(((offset+lote.length)/total)*100)+'%';
        if (offset+LOTE<total) await new Promise(r=>setTimeout(r,500));
    }
    document.getElementById('prog-lote').closest('p').textContent='Processamento concluído.';
    document.getElementById('prog-fim').classList.remove('hidden');
    btn.disabled=true;
}
document.addEventListener('change',e=>{ if(e.target.classList.contains('emp-check')) atualizarSelecionados(); });
atualizarSelecionados();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
