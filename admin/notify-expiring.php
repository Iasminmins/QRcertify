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
            e.id AS emp_id, e.name AS emp_name, e.email AS emp_email,
            COALESCE(e.department, "") AS emp_dept
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
$byEmployee  = [];
$allTypes    = [];
$allDepts    = [];
foreach ($rows as $r) {
    $byEmployee[$r['emp_id']]['name']  = $r['emp_name'];
    $byEmployee[$r['emp_id']]['email'] = $r['emp_email'];
    $byEmployee[$r['emp_id']]['dept']  = $r['emp_dept'];
    $byEmployee[$r['emp_id']]['certs'][] = $r;
    if ($r['type'] !== '') $allTypes[$r['type']] = true;
    if ($r['emp_dept'] !== '') $allDepts[$r['emp_dept']] = true;
}
ksort($allTypes);
ksort($allDepts);

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

// ── ASO: busca vencidos e vencendo ───────────────────────────────────────────
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

$stmtAso = $db->prepare(
    'SELECT a.id, a.aso_type, a.expiration_date, a.document_status,
            e.id AS emp_id, e.name AS emp_name
     FROM employee_asos a
     JOIN employees e ON e.id = a.employee_id
     WHERE e.company_id = ? AND e.status = "active"
       AND a.document_status IN ("vencendo","vencido")
     ORDER BY a.expiration_date ASC'
);
$stmtAso->execute([$companyId]);
$asoAlerts = $stmtAso->fetchAll();

$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];

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
            <span id="contador-filtro" class="text-gray-400"></span>
        </p>
        <div class="flex gap-2">
            <button type="button" onclick="toggleAll(true)"
                    class="text-xs text-industrial-blue hover:underline">Selecionar todos</button>
            <span class="text-gray-300">|</span>
            <button type="button" onclick="toggleAll(false)"
                    class="text-xs text-gray-500 hover:underline">Desmarcar todos</button>
        </div>
    </div>

    <!-- ── FILTROS ─────────────────────────────────────────────────── -->
    <div class="bg-gray-50 border rounded-lg p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Buscar nome ou e-mail</label>
            <input id="f-busca" type="text" placeholder="Ex: João Silva"
                   oninput="aplicarFiltros()"
                   class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-industrial-blue focus:border-industrial-blue px-3 py-1.5">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Tipo de certificado</label>
            <select id="f-tipo" onchange="aplicarFiltros()"
                    class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-industrial-blue focus:border-industrial-blue px-3 py-1.5">
                <option value="">Todos</option>
                <?php foreach (array_keys($allTypes) as $t): ?>
                    <option value="<?= e($t) ?>"><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <select id="f-status" onchange="aplicarFiltros()"
                    class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-industrial-blue focus:border-industrial-blue px-3 py-1.5">
                <option value="">Todos</option>
                <option value="expired">Expirado (urgente)</option>
                <option value="expiring">Expirando (a vencer)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Departamento</label>
            <select id="f-dept" onchange="aplicarFiltros()"
                    class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-industrial-blue focus:border-industrial-blue px-3 py-1.5">
                <option value="">Todos</option>
                <?php foreach (array_keys($allDepts) as $d): ?>
                    <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <!-- ── /FILTROS ────────────────────────────────────────────────── -->

    <div class="space-y-3 mb-5" id="lista-funcionarios">
        <?php foreach ($byEmployee as $empId => $emp):
            $empTypes   = array_unique(array_column($emp['certs'], 'type'));
            $empStatuses = array_unique(array_column($emp['certs'], 'status'));
        ?>
            <div class="bg-white rounded-lg shadow-sm border p-4 emp-card"
                 data-nome="<?= strtolower(e($emp['name'])) ?>"
                 data-email="<?= strtolower(e($emp['email'])) ?>"
                 data-tipos="<?= e(implode('|', $empTypes)) ?>"
                 data-statuses="<?= e(implode('|', $empStatuses)) ?>"
                 data-dept="<?= strtolower(e($emp['dept'] ?? '')) ?>">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="targets[]" value="<?= (int)$empId ?>"
                           class="emp-check mt-0.5 h-4 w-4 rounded border-gray-300 text-industrial-blue"
                           checked>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-medium text-gray-900 text-sm"><?= e($emp['name']) ?></span>
                            <span class="text-xs text-gray-400"><?= e($emp['email']) ?></span>
                            <?php if (!empty($emp['dept'])): ?>
                                <span class="text-xs bg-gray-100 text-gray-500 border border-gray-200 rounded-full px-2 py-0.5"><?= e($emp['dept']) ?></span>
                            <?php endif; ?>
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

    <button type="button" id="btn-enviar" onclick="enviarEmLotes()"
            class="inline-flex items-center gap-2 bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2.5 rounded-lg font-medium transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        Enviar E-mails Selecionados
    </button>

    <div id="progresso" class="hidden mt-5 bg-white border rounded-lg p-5 space-y-3">
        <p class="text-sm font-medium text-gray-700">
            Enviando lote <span id="prog-lote">1</span>…
        </p>
        <p class="text-sm text-gray-600">
            <span id="prog-enviados">0</span> / <span id="prog-total">0</span> enviados
        </p>
        <div class="w-full bg-gray-200 rounded-full h-2.5">
            <div id="prog-barra" class="bg-industrial-blue h-2.5 rounded-full transition-all duration-300" style="width:0%"></div>
        </div>
        <div id="prog-erros" class="text-xs text-red-600 space-y-0.5"></div>
        <p id="prog-fim" class="hidden text-sm font-semibold text-green-700">✓ Todos os lotes foram processados!</p>
    </div>
</form>
<?php endif; ?>

<script>
function toggleAll(v) {
    document.querySelectorAll('.emp-card:not(.hidden) .emp-check').forEach(c => c.checked = v);
}

function aplicarFiltros() {
    const busca  = document.getElementById('f-busca').value.toLowerCase().trim();
    const tipo   = document.getElementById('f-tipo').value;
    const status = document.getElementById('f-status').value;
    const dept   = document.getElementById('f-dept').value.toLowerCase();

    let visiveis = 0;

    document.querySelectorAll('.emp-card').forEach(card => {
        const nome     = card.dataset.nome     || '';
        const email    = card.dataset.email    || '';
        const tipos    = card.dataset.tipos    || '';
        const statuses = card.dataset.statuses || '';
        const cardDept = card.dataset.dept     || '';

        const okBusca  = !busca  || nome.includes(busca)  || email.includes(busca);
        const okTipo   = !tipo   || tipos.split('|').includes(tipo);
        const okStatus = !status || statuses.split('|').includes(status);
        const okDept   = !dept   || cardDept === dept;

        const visivel = okBusca && okTipo && okStatus && okDept;
        card.classList.toggle('hidden', !visivel);
        if (visivel) visiveis++;
    });

    const contador = document.getElementById('contador-filtro');
    if (contador) {
        const total = document.querySelectorAll('.emp-card').length;
        contador.textContent = visiveis < total ? `— ${visiveis} exibido(s)` : '';
    }
}

async function enviarEmLotes() {
    const checks  = [...document.querySelectorAll('.emp-check:checked')];
    const targets = checks.map(c => c.value);

    if (targets.length === 0) {
        alert('Selecione ao menos um funcionário.');
        return;
    }

    const LOTE     = 20;
    const total    = targets.length;
    const btn      = document.getElementById('btn-enviar');
    const divProg  = document.getElementById('progresso');
    const elLote   = document.getElementById('prog-lote');
    const elEnv    = document.getElementById('prog-enviados');
    const elTotal  = document.getElementById('prog-total');
    const elBarra  = document.getElementById('prog-barra');
    const elErros  = document.getElementById('prog-erros');
    const elFim    = document.getElementById('prog-fim');
    const csrf     = document.querySelector('input[name="csrf_token"]').value;

    btn.disabled     = true;
    divProg.classList.remove('hidden');
    elTotal.textContent = total;
    elErros.innerHTML   = '';
    elFim.classList.add('hidden');

    let enviados = 0;
    let numLote  = 0;

    for (let offset = 0; offset < total; offset += LOTE) {
        numLote++;
        const lote = targets.slice(offset, offset + LOTE);
        elLote.textContent = numLote;

        const fd = new FormData();
        fd.append('send',       '1');
        fd.append('csrf_token', csrf);
        fd.append('offset',     offset);
        lote.forEach(id => fd.append('targets[]', id));

        try {
            const resp = await fetch(window.location.href, { method: 'POST', body: fd });
            const html = await resp.text();

            const sucessos = (html.match(/✓/g) || []).length;
            const falhas   = (html.match(/✗/g) || []).length;
            enviados += sucessos;

            if (falhas > 0) {
                const p = document.createElement('p');
                p.textContent = `Lote ${numLote}: ${falhas} falha(s) de envio.`;
                elErros.appendChild(p);
            }
        } catch (err) {
            const p = document.createElement('p');
            p.textContent = `Lote ${numLote}: erro de rede — ${err.message}`;
            elErros.appendChild(p);
        }

        elEnv.textContent  = enviados;
        elBarra.style.width = Math.round(((offset + lote.length) / total) * 100) + '%';

        if (offset + LOTE < total) {
            await new Promise(r => setTimeout(r, 500));
        }
    }

    elLote.closest('p').textContent = 'Processamento concluído.';
    elFim.classList.remove('hidden');
    btn.disabled = true;
}
</script>

<?php endif; ?>

<!-- ── ALERTAS DE ASO ─────────────────────────────────────────────────────── -->
<?php if (!empty($asoAlerts)): ?>
<div class="mt-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
        <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Alertas de ASO (<?= count($asoAlerts) ?>)
    </h2>
    <div class="space-y-3">
        <?php foreach ($asoAlerts as $a):
            $isVencido = $a['document_status'] === 'vencido';
            $days = (int) round((strtotime($a['expiration_date']) - time()) / 86400);
            $bgClass = $isVencido ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50';
            $badge   = $isVencido
                ? '<span class="text-xs font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700 border border-red-200">Vencido</span>'
                : '<span class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 border border-amber-200">Vencendo</span>';
            $prazo = $isVencido
                ? 'Venceu há ' . abs($days) . ' dia' . (abs($days) === 1 ? '' : 's')
                : 'Vence em ' . $days . ' dia' . ($days === 1 ? '' : 's');
        ?>
        <div class="flex items-center justify-between gap-4 border rounded-lg px-4 py-3 <?= $bgClass ?>">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900"><?= e($a['emp_name']) ?></p>
                <p class="text-xs text-gray-600 mt-0.5">
                    ASO <?= e($asoTypeLabels[$a['aso_type']] ?? $a['aso_type']) ?>
                    · <?= e($prazo) ?> · Validade: <?= formatDate($a['expiration_date']) ?>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <?= $badge ?>
                <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= $a['emp_id'] ?>"
                   class="text-xs text-industrial-blue hover:underline font-medium">Ver →</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
