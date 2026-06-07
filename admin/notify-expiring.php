<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();
$days      = CERT_ALERT_DAYS;
$canEmail  = companyHasFeature($companyId, 'email_alerts');

// Verifica se colunas manager_email/phone existem (migration 015)
$hasMgrEmail = false;
try {
    $db->query('SELECT manager_email FROM departments LIMIT 0');
    $hasMgrEmail = true;
} catch (Exception $e) {
    $hasMgrEmail = false;
}

$mgrFields = $hasMgrEmail
    ? 'd.manager_name AS mgr_name, d.manager_email AS mgr_email'
    : 'd.manager_name AS mgr_name, NULL AS mgr_email';

// Busca certificados vencendo ou vencidos, agrupados por funcionário
$stmt = $db->prepare(
    "SELECT c.id, c.title, c.type, c.expiry_date, c.status,
            e.id AS emp_id, e.name AS emp_name, e.email AS emp_email,
            COALESCE(d.name, e.department, '') AS emp_dept,
            COALESCE(d2.manager_name, d.manager_name) AS mgr_name,
            COALESCE(d2.manager_email, d.manager_email) AS mgr_email
     FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     LEFT JOIN departments d  ON d.id   = e.department_id
     LEFT JOIN departments d2 ON d2.name = e.department AND d2.company_id = e.company_id
     WHERE e.company_id = ?
       AND e.status = 'active'
       AND c.status IN ('expiring','expired')
       AND e.email IS NOT NULL AND e.email != ''
     ORDER BY e.name ASC, c.expiry_date ASC"
);
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();

// Agrupa por funcionário
$byEmployee  = [];
$allTypes    = [];
$allDepts    = [];
foreach ($rows as $r) {
    $byEmployee[$r['emp_id']]['name']      = $r['emp_name'];
    $byEmployee[$r['emp_id']]['email']     = $r['emp_email'];
    $byEmployee[$r['emp_id']]['dept']      = $r['emp_dept'];
    $byEmployee[$r['emp_id']]['mgr_name']  = $r['mgr_name'];
    $byEmployee[$r['emp_id']]['mgr_email'] = $r['mgr_email'];
    $byEmployee[$r['emp_id']]['certs'][]   = $r;
    if ($r['type'] !== '')    $allTypes[$r['type']] = true;
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

    $targets     = $_POST['targets'] ?? [];
    $companyName = '';
    $stmtCo      = $db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $stmtCo->execute([$companyId]);
    $coRow       = $stmtCo->fetch();
    if ($coRow) $companyName = $coRow['name'];

    // Agrupa por gestor para enviar e-mail consolidado ao gestor
    $byManager = []; // mgr_email => ['name'=>, 'employees'=>[]]

    foreach ($byEmployee as $empId => $emp) {
        if (!empty($targets) && !in_array((string)$empId, $targets)) continue;

        // ── E-mail para o COLABORADOR ────────────────────────────────
        $subject = '[' . SITE_NAME . '] ⚠️ Seus certificados precisam de atenção';
        $html    = buildCertAlertHtmlEmployee($emp['name'], $emp['certs'], $companyName);
        $ok      = sendMail($emp['email'], $subject, $html);

        if ($ok) {
            $sent++;
            auditLog('notify.sent', "employee #{$empId}",
                json_encode(['email' => $emp['email'], 'certs' => count($emp['certs'])]));
        } else {
            $failed++;
        }
        $results[] = [
            'name'  => $emp['name'],
            'email' => $emp['email'],
            'ok'    => $ok,
            'type'  => 'colaborador',
        ];

        // ── Acumula para e-mail do GESTOR ────────────────────────────
        if (!empty($emp['mgr_email'])) {
            $mgrKey = $emp['mgr_email'];
            if (!isset($byManager[$mgrKey])) {
                $byManager[$mgrKey] = [
                    'name'      => $emp['mgr_name'] ?? 'Gestor',
                    'email'     => $emp['mgr_email'],
                    'employees' => [],
                ];
            }
            $byManager[$mgrKey]['employees'][] = $emp;
        }
    }

    // ── E-mail consolidado para cada GESTOR ──────────────────────────
    foreach ($byManager as $mgr) {
        $subject = '[' . SITE_NAME . '] 📋 Certificados pendentes na sua equipe';
        $html    = buildCertAlertHtmlManager($mgr['name'], $mgr['employees'], $companyName);
        $ok      = sendMail($mgr['email'], $subject, $html);

        $empCount = count($mgr['employees']);
        if ($ok) {
            auditLog('notify.manager.sent', "manager {$mgr['email']}",
                json_encode(['employees' => $empCount]));
        }
        $results[] = [
            'name'  => $mgr['name'] . ' (Gestor — ' . $empCount . ' colaborador(es))',
            'email' => $mgr['email'],
            'ok'    => $ok,
            'type'  => 'gestor',
        ];
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

<?php if (!$hasMgrEmail): ?>
<div class="mb-4 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900 flex items-start gap-3">
    <svg class="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <div>
        <p class="font-semibold">Migration pendente — e-mail de gestor não disponível</p>
        <p class="mt-1 text-amber-800">
            Execute o arquivo <code class="bg-amber-100 px-1 rounded font-mono text-xs">sql/015_department_manager_contact.sql</code>
            no seu banco de dados para habilitar notificações para gestores.
            Após isso, cadastre o e-mail do gestor em
            <a href="<?= BASE_URL ?>/admin/departments.php" class="underline font-medium">Áreas e Setores</a>.
        </p>
    </div>
</div>
<?php endif; ?>

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
        <p class="font-medium <?= $failed === 0 ? 'text-green-800' : 'text-amber-800' ?> mb-3">
            <?= $sent ?> e-mail(s) enviado(s)<?= $failed > 0 ? ", {$failed} falha(s)" : ' com sucesso!' ?>
        </p>
        <?php
        $cols = ['colaborador' => [], 'gestor' => []];
        foreach ($results as $r) $cols[$r['type'] ?? 'colaborador'][] = $r;
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
            <span id="contador-selecionados" class="text-industrial-blue font-medium"></span>
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
            <div class="bg-white rounded-xl shadow-sm border hover:border-gray-300 transition p-4 emp-card"
                 data-nome="<?= strtolower(e($emp['name'])) ?>"
                 data-email="<?= strtolower(e($emp['email'])) ?>"
                 data-tipos="<?= e(implode('|', $empTypes)) ?>"
                 data-statuses="<?= e(implode('|', $empStatuses)) ?>"
                 data-dept="<?= strtolower(e($emp['dept'] ?? '')) ?>">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="targets[]" value="<?= (int)$empId ?>"
                           class="emp-check mt-1 h-4 w-4 rounded border-gray-300 text-industrial-blue"
                           checked>
                    <div class="flex-1 min-w-0">

                        <!-- Linha 1: nome + e-mail + setor -->
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <!-- Avatar -->
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

                        <!-- Linha 2: certificados -->
                        <div class="flex flex-wrap gap-1.5 mb-2.5">
                            <?php foreach ($emp['certs'] as $c):
                                $isExp    = $c['status'] === 'expired';
                                $badgeCls = $isExp
                                    ? 'bg-red-50 text-red-700 border-red-200'
                                    : 'bg-amber-50 text-amber-700 border-amber-200';
                                $dot = $isExp ? '🔴' : '🟡';
                            ?>
                                <span class="inline-flex items-center gap-1 text-xs border rounded-full px-2.5 py-0.5 font-medium <?= $badgeCls ?>">
                                    <?= $dot ?> <?= e($c['type']) ?> · <?= date('d/m/Y', strtotime($c['expiry_date'])) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <!-- Linha 3: info do gestor -->
                        <?php if (!empty($emp['mgr_email'])): ?>
                        <div class="flex items-center gap-2 mt-1 pt-2 border-t border-gray-100">
                            <svg class="h-3.5 w-3.5 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            <span class="text-xs text-gray-500">
                                Gestor: <strong class="text-gray-700"><?= e($emp['mgr_name'] ?? 'Gestor') ?></strong>
                                <span class="text-gray-400 ml-1"><?= e($emp['mgr_email']) ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1 text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded-full px-2 py-0.5 font-medium">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                Gestor será notificado
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center gap-1.5 mt-1 pt-2 border-t border-gray-100">
                            <svg class="h-3.5 w-3.5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            <span class="text-xs text-gray-400">
                                Sem gestor cadastrado —
                                <a href="<?= BASE_URL ?>/admin/departments.php"
                                   class="text-industrial-blue hover:underline" target="_blank">
                                    cadastrar e-mail do gestor
                                </a>
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
function atualizarSelecionados() {
    const total = document.querySelectorAll('.emp-card:not(.hidden) .emp-check:checked').length;
    const el    = document.getElementById('contador-selecionados');
    if (el) {
        el.textContent = total > 0 ? `· ${total} selecionado(s)` : '';
    }
}

function toggleAll(v) {
    document.querySelectorAll('.emp-card:not(.hidden) .emp-check').forEach(c => c.checked = v);
    atualizarSelecionados();
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
        const temFiltro = busca !== '' || tipo !== '' || status !== '' || dept !== '';
        if (temFiltro) {
            contador.textContent = `— ${visiveis} resultado(s) encontrado(s)`;
            contador.style.color = visiveis === 0 ? '#ef4444' : '#64748b';
        } else {
            contador.textContent = '';
        }
    }
    atualizarSelecionados();
}

async function enviarEmLotes() {
    const checks  = [...document.querySelectorAll('.emp-card:not(.hidden) .emp-check:checked')];
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

// Atualiza contador ao clicar em checkbox individual
document.addEventListener('change', e => {
    if (e.target.classList.contains('emp-check')) atualizarSelecionados();
});

// Inicializa contador
atualizarSelecionados();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
