<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$canExport = companyHasFeature($companyId, 'export_reports');

$type   = $_GET['type']   ?? '';
$format = $_GET['format'] ?? '';

// Bloqueia exportação real se não tiver plano
if (!$canExport && ($format === 'csv' || $format === 'print')) {
    setFlash('error', 'Faça upgrade para exportar relatórios.');
    redirect('/admin/export.php');
}

// ── EXPORT CSV ───────────────────────────────────────────────
if ($format === 'csv' && in_array($type, ['employees','certificates','expired','expiring','conformity','aso'])) {

    if ($type === 'employees') {
        $stmt = $db->prepare(
            'SELECT e.name, e.email, e.cpf, e.role, e.department, e.phone, e.status,
                    e.cep, e.address, e.number, e.neighborhood, e.city, e.state,
                    COUNT(c.id) AS total_certs,
                    SUM(c.status="valid")    AS certs_validos,
                    SUM(c.status="expiring") AS certs_expirando,
                    SUM(c.status="expired")  AS certs_expirados,
                    e.created_at
             FROM employees e
             LEFT JOIN certificates c ON c.employee_id = e.id
             WHERE e.company_id = ?
             GROUP BY e.id
             ORDER BY e.name ASC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $headers = ['Nome','E-mail','CPF','Cargo','Setor/Departamento','Telefone','Status',
                    'CEP','Logradouro','Número','Bairro','Cidade','UF',
                    'Total Certificados','Válidos','Expirando','Expirados','Cadastrado em'];
        $filename = 'funcionarios_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r): array {
            return [
                $r['name'], $r['email'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $r['cpf'] ?? ''),
                $r['role'],
                $r['department'] ?? '',
                $r['phone'] ? '(' . substr($r['phone'],0,2) . ') ' . substr($r['phone'],2) : '',
                $r['status'] === 'active' ? 'Ativo' : 'Inativo',
                $r['cep'] ? substr($r['cep'],0,5).'-'.substr($r['cep'],5) : '',
                $r['address'] ?? '', $r['number'] ?? '',
                $r['neighborhood'] ?? '', $r['city'] ?? '', $r['state'] ?? '',
                $r['total_certs'], $r['certs_validos'], $r['certs_expirando'], $r['certs_expirados'],
                date('d/m/Y', strtotime($r['created_at'])),
            ];
        };
    } elseif ($type === 'expired') {
        $stmt = $db->prepare(
            'SELECT e.name AS emp_name, e.cpf AS emp_cpf, e.role AS emp_role, e.department,
                    c.title, c.type, c.expiry_date,
                    DATEDIFF(CURDATE(), c.expiry_date) AS dias_vencido
             FROM certificates c
             JOIN employees e ON e.id = c.employee_id
             WHERE e.company_id = ? AND c.status = "expired"
             ORDER BY c.expiry_date ASC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $headers  = ['Funcionário','CPF','Cargo','Setor','Certificado','Tipo','Venceu em','Dias vencido'];
        $filename = 'certificados_vencidos_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r): array {
            return [
                $r['emp_name'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',$r['emp_cpf']??''),
                $r['emp_role'], $r['department'] ?? '',
                $r['title'], $r['type'],
                date('d/m/Y', strtotime($r['expiry_date'])),
                $r['dias_vencido'],
            ];
        };

    } elseif ($type === 'expiring') {
        $stmt = $db->prepare(
            'SELECT e.name AS emp_name, e.cpf AS emp_cpf, e.role AS emp_role, e.department,
                    c.title, c.type, c.expiry_date,
                    DATEDIFF(c.expiry_date, CURDATE()) AS dias_restantes
             FROM certificates c
             JOIN employees e ON e.id = c.employee_id
             WHERE e.company_id = ? AND c.status = "expiring"
             ORDER BY c.expiry_date ASC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $headers  = ['Funcionário','CPF','Cargo','Setor','Certificado','Tipo','Vence em','Dias restantes'];
        $filename = 'vencendo_em_breve_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r): array {
            return [
                $r['emp_name'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',$r['emp_cpf']??''),
                $r['emp_role'], $r['department'] ?? '',
                $r['title'], $r['type'],
                date('d/m/Y', strtotime($r['expiry_date'])),
                $r['dias_restantes'],
            ];
        };

    } elseif ($type === 'conformity') {
        $stmt = $db->prepare(
            'SELECT e.name, e.cpf, e.role, e.department,
                    SUM(c.status="valid")    AS validos,
                    SUM(c.status="expiring") AS expirando,
                    SUM(c.status="expired")  AS expirados,
                    COUNT(c.id)              AS total,
                    CASE
                        WHEN SUM(c.status="expired")  > 0 THEN "Irregular"
                        WHEN SUM(c.status="expiring") > 0 THEN "Atenção"
                        WHEN COUNT(c.id) = 0              THEN "Sem certificados"
                        ELSE "Regular"
                    END AS situacao
             FROM employees e
             LEFT JOIN certificates c ON c.employee_id = e.id
             WHERE e.company_id = ? AND e.status = "active"
             GROUP BY e.id
             ORDER BY situacao ASC, e.name ASC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $headers  = ['Funcionário','CPF','Cargo','Setor','Situação','Total','Válidos','Expirando','Expirados'];
        $filename = 'conformidade_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r): array {
            return [
                $r['name'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',$r['cpf']??''),
                $r['role'], $r['department'] ?? '',
                $r['situacao'], $r['total'],
                $r['validos'], $r['expirando'], $r['expirados'],
            ];
        };

    } elseif ($type === 'aso') {
        $stmt = $db->prepare(
            'SELECT e.name AS emp_name, e.role AS emp_role,
                    CONCAT("***.", SUBSTR(REPLACE(REPLACE(e.cpf,".","-"),"-",""),4,3),".",SUBSTR(REPLACE(REPLACE(e.cpf,".","-"),"-",""),7,3),"-**") AS cpf_mask,
                    a.aso_type, a.result_status, a.exam_date, a.expiration_date, a.document_status
             FROM employee_asos a
             JOIN employees e ON e.id = a.employee_id
             WHERE e.company_id = ?
             ORDER BY e.name ASC, a.exam_date DESC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $asoTypeMap = [
            'admissional'      => 'Admissional',
            'periodico'        => 'Periódico',
            'retorno_trabalho' => 'Retorno ao Trabalho',
            'mudanca_risco'    => 'Mudança de Risco',
            'demissional'      => 'Demissional',
        ];
        $resultMap = ['apto' => 'Apto', 'inapto' => 'Inapto', 'pendente' => 'Pendente'];
        $docMap    = ['valido' => 'Válido', 'vencendo' => 'Vencendo', 'vencido' => 'Vencido'];

        $headers  = ['Colaborador','CPF (parcial)','Função','Tipo de ASO','Resultado','Data do Exame','Validade','Status'];
        $filename = 'aso_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r) use ($asoTypeMap, $resultMap, $docMap): array {
            return [
                $r['emp_name'], $r['cpf_mask'], $r['emp_role'],
                $asoTypeMap[$r['aso_type']]      ?? $r['aso_type'],
                $resultMap[$r['result_status']]  ?? $r['result_status'],
                date('d/m/Y', strtotime($r['exam_date'])),
                date('d/m/Y', strtotime($r['expiration_date'])),
                $docMap[$r['document_status']]   ?? $r['document_status'],
            ];
        };

    } else {
        // certificates
        $stmt = $db->prepare(
            'SELECT c.title, c.type, c.issued_date, c.expiry_date, c.status,
                    e.name AS emp_name, e.cpf AS emp_cpf, e.role AS emp_role
             FROM certificates c
             JOIN employees e ON e.id = c.employee_id
             WHERE e.company_id = ?
             ORDER BY e.name ASC, c.expiry_date ASC'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $headers  = ['Funcionário','CPF','Cargo','Certificado','Tipo','Emissão','Validade','Status'];
        $filename = 'certificados_' . date('Y-m-d') . '.csv';
        $statusMap = ['valid'=>'Válido','expiring'=>'Expirando','expired'=>'Expirado'];
        $mapRow = function(array $r) use ($statusMap): array {
            return [
                $r['emp_name'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $r['emp_cpf'] ?? ''),
                $r['emp_role'],
                $r['title'], $r['type'],
                date('d/m/Y', strtotime($r['issued_date'])),
                date('d/m/Y', strtotime($r['expiry_date'])),
                $statusMap[$r['status']] ?? $r['status'],
            ];
        };
    }

    auditLog('export.csv', "company #{$companyId}", json_encode(['type' => $type, 'rows' => count($rows)]));

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $r) fputcsv($out, $mapRow($r), ';');
    fclose($out);
    exit;
}

// ── TELA PRINCIPAL ────────────────────────────────────────────
$counts = [];
$stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE company_id=?");
$stmt->execute([$companyId]); $counts['employees'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id=c.employee_id WHERE e.company_id=?");
$stmt->execute([$companyId]); $counts['certificates'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id=c.employee_id WHERE e.company_id=? AND c.status='expired'");
$stmt->execute([$companyId]); $counts['expired'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id=c.employee_id WHERE e.company_id=? AND c.status='expiring'");
$stmt->execute([$companyId]); $counts['expiring'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM employee_asos a JOIN employees e ON e.id=a.employee_id WHERE e.company_id=?");
$stmt->execute([$companyId]); $counts['aso'] = (int)$stmt->fetchColumn();

$iconAso = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>';

$pageTitle = 'Relatórios e Exportações';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Relatórios e Exportações</h1>
    <p class="text-gray-500 text-sm mt-1">Gere relatórios para controle, auditoria e acompanhamento de vencimentos.</p>
</div>

<?php renderFlashes(); ?>

<?php if (!$canExport): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-6 flex flex-col sm:flex-row items-start sm:items-center gap-4">
    <div class="h-12 w-12 flex-shrink-0 bg-amber-100 rounded-xl flex items-center justify-center">
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
    </div>
    <div class="flex-1">
        <p class="font-semibold text-amber-900">Exportação disponível nos planos Pro, Business e Enterprise</p>
        <p class="text-sm text-amber-700 mt-1">
            Faça upgrade para baixar planilhas CSV dos seus funcionários e certificados,
            ou gerar relatórios para impressão e PDF.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/plano.php"
       class="flex-shrink-0 inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white
              font-semibold px-5 py-2.5 rounded-lg transition text-sm whitespace-nowrap">
        Ver planos e fazer upgrade →
    </a>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">

<?php
// Helper para renderizar cada card de relatório
function reportCard(string $type, string $icon_color, string $icon_path, string $title, string $desc, int $count, string $count_label, bool $canExport, string $base_url, string $badge_color = ''): void {
?>
    <div class="bg-white rounded-lg shadow-sm border p-6 flex flex-col gap-4">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 <?= $icon_color ?> rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $icon_path ?>
                    </svg>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900"><?= $title ?></h2>
                    <p class="text-xs text-gray-400"><?= $count ?> <?= $count_label ?></p>
                </div>
            </div>
            <?php if ($badge_color && $count > 0): ?>
                <span class="text-xs font-bold px-2 py-1 rounded-full <?= $badge_color ?>"><?= $count ?></span>
            <?php endif; ?>
        </div>
        <p class="text-sm text-gray-600"><?= $desc ?></p>
        <div class="flex gap-2 mt-auto">
            <?php if ($canExport): ?>
            <a href="?type=<?= $type ?>&format=csv"
               class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar CSV / Excel
            </a>
            <a href="?type=<?= $type ?>&format=print" target="_blank"
               class="inline-flex items-center gap-1.5 border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir / PDF
            </a>
            <?php else: ?>
            <a href="<?= $base_url ?>/admin/plano.php"
               class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-md text-sm font-medium transition">
                🔒 Fazer upgrade para exportar
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php } ?>

<?php
$iconEmp   = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>';
$iconCert  = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>';
$iconAlert = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>';
$iconClock = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>';
$iconChart = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>';

reportCard('employees',  'bg-blue-100 text-blue-600',   $iconEmp,   'Colaboradores',        'Exporta nome, e-mail, CPF, cargo, setor, telefone, endereço e contagem de certificados.',                          $counts['employees'],    'registros',   $canExport, BASE_URL);
reportCard('certificates','bg-amber-100 text-amber-600', $iconCert,  'Certificados',          'Exporta funcionário, CPF, certificado, tipo, emissão, validade e status.',                                          $counts['certificates'], 'registros',   $canExport, BASE_URL);
reportCard('expired',    'bg-red-100 text-red-600',      $iconAlert, 'Certificados vencidos', 'Lista de colaboradores com certificados expirados que precisam de regularização imediata.',                          $counts['expired'],      'vencidos',    $canExport, BASE_URL, 'bg-red-100 text-red-700');
reportCard('expiring',   'bg-orange-100 text-orange-600',$iconClock, 'Vencendo em breve',     'Certificados que vencem nos próximos 30 dias. Ideal para planejar renovações com antecedência.',                    $counts['expiring'],     'expirando',   $canExport, BASE_URL, 'bg-orange-100 text-orange-700');
reportCard('conformity', 'bg-indigo-100 text-indigo-600',$iconChart, 'Conformidade',          'Visão geral de colaboradores regulares, em atenção e irregulares. Útil para auditoria e prestação de contas.',      $counts['employees'],    'colaboradores',$canExport, BASE_URL);
reportCard('aso',        'bg-teal-100 text-teal-600',    $iconAso,   'ASO',                   'Exporta status de ASO, tipo, resultado operacional, data do exame, validade e situação. CPF parcialmente mascarado.', $counts['aso'],          'registros',   $canExport, BASE_URL);
?>

</div>

<?php if ($format === 'print' && in_array($type, ['employees','certificates'])): ?>
<!-- ── MODO IMPRESSÃO ─────────────────────────────────────── -->
<?php
    $stmtCo = $db->prepare('SELECT name FROM companies WHERE id=?');
    $stmtCo->execute([$companyId]);
    $coName = $stmtCo->fetchColumn();

    if ($type === 'employees') {
        $stmt = $db->prepare(
            'SELECT e.name, e.cpf, e.role, e.department, e.phone, e.status,
                    SUM(c.status="valid") AS v, SUM(c.status="expiring") AS x, SUM(c.status="expired") AS e2
             FROM employees e LEFT JOIN certificates c ON c.employee_id=e.id
             WHERE e.company_id=? GROUP BY e.id ORDER BY e.name'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT e.name AS emp_name, c.title, c.type, c.issued_date, c.expiry_date, c.status
             FROM certificates c JOIN employees e ON e.id=c.employee_id
             WHERE e.company_id=? ORDER BY e.name, c.expiry_date'
        );
    }
    $stmt->execute([$companyId]);
    $printRows = $stmt->fetchAll();
    auditLog('export.print', "company #{$companyId}", json_encode(['type'=>$type]));
?>
<div id="printArea" class="mt-8 bg-white rounded-lg border p-6">
    <div class="flex items-center justify-between mb-4 print:hidden">
        <h2 class="text-lg font-semibold text-gray-900">
            Pré-visualização — <?= $type === 'employees' ? 'Funcionários' : 'Certificados' ?>
        </h2>
        <button onclick="window.print()"
                class="inline-flex items-center gap-2 bg-industrial-blue text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-blue-800">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Imprimir / Salvar PDF
        </button>
    </div>
    <p class="text-sm text-gray-500 mb-4"><?= e($coName) ?> · Gerado em <?= date('d/m/Y H:i') ?></p>

    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="bg-industrial-blue text-white">
                    <?php if ($type === 'employees'): ?>
                        <th class="px-3 py-2 text-left">Nome</th>
                        <th class="px-3 py-2 text-left">CPF</th>
                        <th class="px-3 py-2 text-left">Cargo</th>
                        <th class="px-3 py-2 text-left">Setor</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-center">Válidos</th>
                        <th class="px-3 py-2 text-center">Expirando</th>
                        <th class="px-3 py-2 text-center">Expirados</th>
                    <?php else: ?>
                        <th class="px-3 py-2 text-left">Funcionário</th>
                        <th class="px-3 py-2 text-left">Certificado</th>
                        <th class="px-3 py-2 text-left">Tipo</th>
                        <th class="px-3 py-2 text-left">Emissão</th>
                        <th class="px-3 py-2 text-left">Validade</th>
                        <th class="px-3 py-2 text-left">Status</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($printRows as $i => $r):
                    $bg = $i % 2 === 0 ? '' : 'background:#f9fafb';
                ?>
                <tr style="<?= $bg ?>; border-bottom:1px solid #e5e7eb">
                    <?php if ($type === 'employees'): ?>
                        <td class="px-3 py-2"><?= e($r['name']) ?></td>
                        <td class="px-3 py-2 font-mono text-xs"><?= e(preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',$r['cpf']??'')) ?></td>
                        <td class="px-3 py-2"><?= e($r['role']) ?></td>
                        <td class="px-3 py-2 text-xs text-gray-600"><?= e($r['department'] ?? '—') ?></td>
                        <td class="px-3 py-2"><?= $r['status']==='active'?'Ativo':'Inativo' ?></td>
                        <td class="px-3 py-2 text-center text-green-700 font-medium"><?= (int)$r['v'] ?></td>
                        <td class="px-3 py-2 text-center text-amber-600 font-medium"><?= (int)$r['x'] ?></td>
                        <td class="px-3 py-2 text-center text-red-600 font-medium"><?= (int)$r['e2'] ?></td>
                    <?php else:
                        $sc = ['valid'=>'color:#15803d','expiring'=>'color:#d97706','expired'=>'color:#dc2626'];
                        $sl = ['valid'=>'Válido','expiring'=>'Expirando','expired'=>'Expirado'];
                    ?>
                        <td class="px-3 py-2"><?= e($r['emp_name']) ?></td>
                        <td class="px-3 py-2"><?= e($r['title']) ?></td>
                        <td class="px-3 py-2 font-medium"><?= e($r['type']) ?></td>
                        <td class="px-3 py-2"><?= date('d/m/Y',strtotime($r['issued_date'])) ?></td>
                        <td class="px-3 py-2"><?= date('d/m/Y',strtotime($r['expiry_date'])) ?></td>
                        <td class="px-3 py-2 font-medium" style="<?= $sc[$r['status']]??'' ?>"><?= $sl[$r['status']]??$r['status'] ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<style>@media print{.print\:hidden{display:none!important}#sidebar,#main-content>div:first-child{display:none}}</style>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
