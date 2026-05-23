<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

if (!companyHasFeature($companyId, 'export_reports')) {
    setFlash('error', 'Exportação de relatórios está disponível nos planos Pro, Business e Enterprise.');
    redirect('/admin/dashboard.php');
}

$type   = $_GET['type']   ?? '';   // 'employees' | 'certificates'
$format = $_GET['format'] ?? '';   // 'csv' | 'print'

// ── EXPORT CSV ───────────────────────────────────────────────
if ($format === 'csv' && in_array($type, ['employees','certificates'])) {

    if ($type === 'employees') {
        $stmt = $db->prepare(
            'SELECT e.name, e.email, e.cpf, e.role, e.phone, e.status,
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

        $headers = ['Nome','E-mail','CPF','Cargo','Telefone','Status',
                    'CEP','Logradouro','Número','Bairro','Cidade','UF',
                    'Total Certificados','Válidos','Expirando','Expirados','Cadastrado em'];
        $filename = 'funcionarios_' . date('Y-m-d') . '.csv';
        $mapRow = function(array $r): array {
            return [
                $r['name'], $r['email'],
                preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $r['cpf'] ?? ''),
                $r['role'],
                $r['phone'] ? '(' . substr($r['phone'],0,2) . ') ' . substr($r['phone'],2) : '',
                $r['status'] === 'active' ? 'Ativo' : 'Inativo',
                $r['cep'] ? substr($r['cep'],0,5).'-'.substr($r['cep'],5) : '',
                $r['address'] ?? '', $r['number'] ?? '',
                $r['neighborhood'] ?? '', $r['city'] ?? '', $r['state'] ?? '',
                $r['total_certs'], $r['certs_validos'], $r['certs_expirando'], $r['certs_expirados'],
                date('d/m/Y', strtotime($r['created_at'])),
            ];
        };
    } else {
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
// Resumo rápido para mostrar na tela
$counts = [];
foreach (['employees','certificates'] as $t) {
    $tbl = $t === 'employees' ? 'employees e' : 'certificates c JOIN employees e ON e.id=c.employee_id';
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$tbl} WHERE e.company_id=?");
    $stmt->execute([$companyId]);
    $counts[$t] = (int)$stmt->fetchColumn();
}

$pageTitle = 'Exportar Relatórios';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Exportar Relatórios</h1>
    <p class="text-gray-500 text-sm mt-1">Baixe os dados em planilha ou gere uma visualização para impressão.</p>
</div>

<?php renderFlashes(); ?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">

    <!-- FUNCIONÁRIOS -->
    <div class="bg-white rounded-lg shadow-sm border p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="h-10 w-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-gray-900">Funcionários</h2>
                <p class="text-xs text-gray-400"><?= $counts['employees'] ?> registros</p>
            </div>
        </div>
        <p class="text-sm text-gray-600 mb-4">
            Exporta nome, e-mail, CPF, cargo, telefone, endereço e contagem de certificados.
        </p>
        <div class="flex gap-2">
            <a href="?type=employees&format=csv"
               class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar CSV / Excel
            </a>
            <a href="?type=employees&format=print" target="_blank"
               class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir / PDF
            </a>
        </div>
    </div>

    <!-- CERTIFICADOS -->
    <div class="bg-white rounded-lg shadow-sm border p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="h-10 w-10 bg-amber-100 rounded-lg flex items-center justify-center">
                <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-gray-900">Certificados</h2>
                <p class="text-xs text-gray-400"><?= $counts['certificates'] ?> registros</p>
            </div>
        </div>
        <p class="text-sm text-gray-600 mb-4">
            Exporta funcionário, CPF, certificado, tipo, emissão, validade e status.
        </p>
        <div class="flex gap-2">
            <a href="?type=certificates&format=csv"
               class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar CSV / Excel
            </a>
            <a href="?type=certificates&format=print" target="_blank"
               class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-md text-sm font-medium transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir / PDF
            </a>
        </div>
    </div>

</div>

<?php if ($format === 'print' && in_array($type, ['employees','certificates'])): ?>
<!-- ── MODO IMPRESSÃO ─────────────────────────────────────── -->
<?php
    $stmtCo = $db->prepare('SELECT name FROM companies WHERE id=?');
    $stmtCo->execute([$companyId]);
    $coName = $stmtCo->fetchColumn();

    if ($type === 'employees') {
        $stmt = $db->prepare(
            'SELECT e.name, e.cpf, e.role, e.phone, e.status,
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
