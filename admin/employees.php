<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user         = currentUser();
$companyId    = $user['company_id'];
$db           = getDB();
$canImportCsv = companyHasFeature($companyId, 'csv_import');

// Busca empresa
$stmtCo = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmtCo->execute([$companyId]);
$company = $stmtCo->fetch();

$search       = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql    = 'SELECT e.*,
            COUNT(c.id)                    AS cert_count,
            SUM(c.status = "valid")        AS cert_valid,
            SUM(c.status = "expiring")     AS cert_expiring,
            SUM(c.status = "expired")      AS cert_expired
           FROM employees e
           LEFT JOIN certificates c ON c.employee_id = e.id
           WHERE e.company_id = ?';
$params = [$companyId];

if ($search !== '') {
    $sql .= ' AND (e.name LIKE ? OR e.email LIKE ? OR e.cpf LIKE ? OR e.role LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $sql .= ' AND e.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' GROUP BY e.id ORDER BY e.name ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allEmployees = $stmt->fetchAll();

$pag       = paginate($allEmployees, (int)($_GET['page'] ?? 1), 20);
$employees = $pag['items'];

// Para cada funcionário, busca os certificados (para a carteirinha)
$empCertMap = [];
if (!empty($employees)) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $allCerts = $db->query(
        "SELECT * FROM certificates WHERE employee_id IN ($ids) ORDER BY type ASC"
    )->fetchAll();
    foreach ($allCerts as $c) {
        $empCertMap[$c['employee_id']][] = $c;
    }
}

// Monta JSON para o JS usar na carteirinha
$empData = [];
foreach ($employees as $emp) {
    $certs = $empCertMap[$emp['id']] ?? [];
    $empData[$emp['id']] = [
        'id'      => $emp['id'],
        'name'    => $emp['name'],
        'role'    => $emp['role'],
        'cpf'     => $emp['cpf'],
        'email'   => $emp['email'],
        'photo'   => $emp['photo'] ?? '',
        'company' => $company['name'] ?? '',
        'certs'   => array_map(fn($c) => [
            'title'       => $c['title'],
            'type'        => $c['type'],
            'issued_date' => $c['issued_date'],
            'expiry_date' => $c['expiry_date'],
            'status'      => $c['status'],
            'hash'        => $c['hash'],
        ], $certs),
        'qr_url'  => getEmployeePublicUrl((int) $emp['id']),
    ];
}

$pageTitle = 'Colaboradores';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Colaboradores</h1>
        <p class="page-subtitle">Total de <?= $pag['total'] ?> colaboradores</p>
    </div>
    <div class="flex gap-2">
        <?php if ($canImportCsv): ?>
        <a href="<?= BASE_URL ?>/admin/employee-import.php"
           class="inline-flex items-center gap-2 border border-industrial-blue text-industrial-blue hover:bg-blue-50 px-4 py-2 rounded-lg font-medium transition text-sm">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Importar Planilha
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/employee-form.php" class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Colaborador
        </a>
    </div>
</div>

<?php renderFlashes(); ?>

<!-- Filtros -->
<form method="GET" class="section-card mb-5">
    <div class="section-card-body">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="<?= e($search) ?>"
                       placeholder="Buscar por nome, email, CPF ou cargo..."
                       class="form-input pl-10">
            </div>
            <select name="status" class="form-input sm:w-44">
                <option value="">Todos os status</option>
                <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Ativos</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inativos</option>
            </select>
            <button type="submit" class="btn btn-primary whitespace-nowrap">Filtrar</button>
            <?php if ($search || $statusFilter): ?>
                <a href="<?= BASE_URL ?>/admin/employees.php" class="btn btn-secondary whitespace-nowrap">Limpar</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Lista de cards de colaboradores -->
<?php if (empty($employees)): ?>
    <div class="section-card">
        <div class="py-16 text-center text-gray-400">
            <svg class="h-12 w-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <?php if ($search || $statusFilter): ?>
                <p class="font-medium text-gray-500">Nenhum colaborador encontrado para os filtros aplicados</p>
                <p class="text-sm mt-1">
                    <a href="<?= BASE_URL ?>/admin/employees.php" class="text-industrial-blue hover:underline">
                        Limpar filtros
                    </a>
                </p>
            <?php else: ?>
                <p class="font-medium text-gray-500">Nenhum colaborador cadastrado ainda</p>
                <p class="text-sm mt-1">
                    <a href="<?= BASE_URL ?>/admin/employee-form.php" class="text-industrial-blue hover:underline">
                        Cadastre o primeiro
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
<div class="space-y-4">
<?php foreach ($employees as $emp):
    $initials  = strtoupper(mb_substr($emp['name'], 0, 2));
    $cValid    = (int)($emp['cert_valid']    ?? 0);
    $cExpiring = (int)($emp['cert_expiring'] ?? 0);
    $cExpired  = (int)($emp['cert_expired']  ?? 0);
    $cTotal    = (int)($emp['cert_count']    ?? 0);
    $qrEmpUrl  = generateQRCodeUrl(getEmployeePublicUrl((int) $emp['id']), 160);
?>
<div class="section-card overflow-hidden">

    <!-- Topo do card: avatar + nome + ID -->
    <div style="padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;border-bottom:1px solid #f1f5f9">
        <div style="width:48px;height:48px;border-radius:50%;background:#1e3a8a;
                    display:flex;align-items:center;justify-content:center;
                    flex-shrink:0;overflow:hidden;border:2px solid #bfdbfe">
            <?php if (!empty($emp['photo'])): ?>
                <img src="<?= e($emp['photo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
                <span style="font-size:.85rem;font-weight:700;color:#fff"><?= $initials ?></span>
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;color:#0f172a;font-size:1rem;truncate"><?= e($emp['name']) ?></div>
            <div style="font-size:.8rem;color:#94a3b8">
                ID: <?= (int)$emp['id'] ?> · <?= e($emp['role']) ?>
                <?php if (!empty($emp['department'])): ?>
                    <span style="color:#cbd5e1"> · </span>
                    <span style="color:#64748b"><?= e($emp['department']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($emp['status'] === 'inactive'): ?>
            <span class="badge badge-inactive">Inativo</span>
        <?php endif; ?>
    </div>

    <!-- Stats: Certificados + Status badges -->
    <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f1f5f9">
        <!-- Certificados -->
        <div style="padding:.875rem 1.25rem;border-right:1px solid #f1f5f9;background:#fafafa">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem">
                <span style="font-size:.75rem;color:#64748b;font-weight:500">Certificados</span>
                <svg style="width:1rem;height:1rem;color:#cbd5e1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <span style="font-size:.8rem;font-weight:600;padding:.2rem .6rem;border-radius:99px;
                         background:#f1f5f9;color:#475569;border:1px solid #e2e8f0">
                Total: <?= $cTotal ?>
            </span>
        </div>

        <!-- Status bubbles -->
        <div style="padding:.875rem 1.25rem;background:#fafafa">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem">
                <span style="font-size:.75rem;color:#64748b;font-weight:500">Status</span>
                <svg style="width:1rem;height:1rem;color:#cbd5e1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div style="display:flex;gap:.4rem">
                <span style="display:inline-flex;align-items:center;justify-content:center;
                             width:1.6rem;height:1.6rem;border-radius:50%;font-size:.75rem;font-weight:700;
                             background:#16a34a;color:#fff"><?= $cValid ?></span>
                <span style="display:inline-flex;align-items:center;justify-content:center;
                             width:1.6rem;height:1.6rem;border-radius:50%;font-size:.75rem;font-weight:700;
                             background:#f59e0b;color:#fff"><?= $cExpiring ?></span>
                <span style="display:inline-flex;align-items:center;justify-content:center;
                             width:1.6rem;height:1.6rem;border-radius:50%;font-size:.75rem;font-weight:700;
                             background:#ef4444;color:#fff"><?= $cExpired ?></span>
            </div>
        </div>
    </div>

    <!-- Ações -->
    <div style="padding:.875rem 1.25rem;display:flex;flex-direction:column;gap:.5rem">

        <!-- Linha 1: Ver Detalhes + QR download -->
        <div style="display:flex;gap:.5rem">
            <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= (int)$emp['id'] ?>"
               class="btn btn-secondary btn-sm" style="flex:1;justify-content:center">
                Ver Detalhes
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            <button onclick="showQrModal(<?= (int)$emp['id'] ?>, '<?= e(addslashes($emp['name'])) ?>')"
                    class="btn btn-secondary btn-sm btn-icon" title="Ver QR Code da carteira">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
            </button>
        </div>

        <!-- Linha 2: Gerar Carteirinha -->
        <button onclick="openCarteirinha(<?= (int)$emp['id'] ?>)"
                class="btn btn-secondary btn-sm" style="justify-content:center;width:100%">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0M9 12h6"/>
            </svg>
            Gerar Carteirinha
        </button>

    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     MODAL: QR Code do colaborador
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="modal-qr" style="display:none;position:fixed;inset:0;z-index:9000;
     background:rgba(0,0,0,.5);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:1rem;padding:1.5rem;max-width:360px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;font-weight:700;color:#0f172a">QR Code do Colaborador</h3>
            <button onclick="closeModal('modal-qr')"
                    style="background:none;border:none;cursor:pointer;padding:.25rem;color:#94a3b8">
                <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <p id="qr-modal-name" style="font-size:.875rem;color:#64748b;margin-bottom:1rem"></p>
        <div style="text-align:center;margin-bottom:1rem">
            <div style="display:inline-block;padding:.75rem;border:2px solid #e2e8f0;border-radius:.75rem;background:#fff">
                <img id="qr-modal-img" src="" alt="QR Code" style="width:160px;height:160px;object-fit:contain">
            </div>
        </div>
        <div id="qr-modal-url" style="font-size:.7rem;color:#94a3b8;word-break:break-all;
             text-align:center;padding:.5rem;background:#f8fafc;border-radius:.5rem;margin-bottom:1rem"></div>
        <div style="display:flex;gap:.5rem">
            <button onclick="copyQrUrl()" class="btn btn-secondary" style="flex:1;justify-content:center;font-size:.8rem">
                Copiar URL
            </button>
            <button onclick="downloadQrCode()" class="btn btn-secondary" style="flex:1;justify-content:center;font-size:.8rem">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar QR
            </button>
            <a id="qr-open-link" href="#" target="_blank" class="btn btn-primary" style="flex:1;justify-content:center;font-size:.8rem">
                Ver Carteira
            </a>
        </div>
    </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     MODAL: Carteirinha de Autorização
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div id="modal-carteirinha" style="display:none;position:fixed;inset:0;z-index:9000;
     background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
     overflow:hidden;padding:1.25rem;align-items:flex-start;justify-content:center">
    <div style="max-width:620px;width:100%;background:#fff;border-radius:1rem;
                box-shadow:0 24px 64px rgba(0,0,0,.3);
                display:flex;flex-direction:column;
                max-height:calc(100vh - 2.5rem);overflow:hidden">

        <!-- Header fixo -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;flex-shrink:0">
            <h3 style="font-size:1rem;font-weight:700;color:#0f172a">Carteira de Autorização</h3>
            <button onclick="closeModal('modal-carteirinha')"
                    style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:.25rem;line-height:1">
                <svg style="width:1.1rem;height:1.1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Corpo com scroll -->
        <div style="flex:1;overflow-y:auto;padding:1rem;background:#f8fafc">
            <div id="carteirinha-preview"
                 style="background:#fff;border-radius:.5rem;overflow:hidden;
                        width:100%;max-width:520px;margin:0 auto;
                        font-family:Arial,sans-serif;font-size:.7rem;
                        box-shadow:0 2px 12px rgba(0,0,0,.08);border:1px solid #e2e8f0">

                <!-- Topo azul -->
                <div style="background:#1e3a8a;color:#fff;padding:.5rem .75rem;
                            display:flex;align-items:center;justify-content:center;gap:.5rem">
                    <div style="width:.8rem;height:.8rem;border-radius:50%;background:#facc15;flex-shrink:0"></div>
                    <span style="font-size:.75rem;font-weight:700;letter-spacing:.06em">CARTEIRA DE AUTORIZAÇÃO</span>
                </div>

                <!-- Dados pessoais -->
                <div style="display:flex;gap:.75rem;padding:.75rem;border-bottom:1px solid #e2e8f0;align-items:flex-start">
                    <div id="cart-logo-wrap"
                         style="border:1.5px dashed #cbd5e1;border-radius:.25rem;
                                width:58px;height:58px;flex-shrink:0;overflow:hidden;
                                background:#f8fafc;display:flex;align-items:center;justify-content:center">
                        <img id="cart-logo-img" src="" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain;display:none">
                        <span id="cart-logo-placeholder" style="font-size:.5rem;color:#94a3b8;text-align:center;line-height:1.4">LOGO<br>EMPRESA</span>
                    </div>
                    <div id="cart-photo-wrap"
                         style="border:1.5px dashed #cbd5e1;border-radius:.25rem;
                                width:58px;height:68px;flex-shrink:0;overflow:hidden;
                                background:#f8fafc;display:flex;align-items:center;justify-content:center">
                        <span style="font-size:.5rem;color:#94a3b8;text-align:center;line-height:1.4">Portar<br>doc.<br>foto</span>
                    </div>
                    <div style="flex:1;display:grid;gap:.15rem">
                        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:.15rem">
                            <strong>Nome:</strong> <span id="cart-name"></span>
                        </div>
                        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:.15rem">
                            <strong>Matrícula:</strong> <span id="cart-id"></span>
                        </div>
                        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:.15rem">
                            <strong>Função:</strong> <span id="cart-role"></span>
                        </div>
                        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:.15rem">
                            <strong>Empresa:</strong> <span id="cart-company"></span>
                        </div>
                        <div><strong>CPF:</strong> <span id="cart-cpf"></span></div>
                    </div>
                </div>

                <!-- NRs + Operações (lado a lado) -->
                <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e2e8f0">
                    <div style="border-right:1px solid #e2e8f0">
                        <div style="background:#1e3a8a;color:#fff;font-size:.6rem;font-weight:700;
                                    padding:.3rem .5rem;text-align:center">
                            TRABALHADOR AUTORIZADO / VALIDADE
                        </div>
                        <div id="cart-nrs" style="padding:.35rem;min-height:80px"></div>
                    </div>
                    <div>
                        <div style="background:#1e3a8a;color:#fff;font-size:.6rem;font-weight:700;
                                    padding:.3rem .5rem;text-align:center">
                            OPERAÇÃO / EXECUÇÃO
                        </div>
                        <div style="padding:.35rem;font-size:.6rem">
                            <?php
                            $ops = ['Direção Defensiva','Veículo Grande Porte','Trabalho a Quente',
                                    'Espaço Confinado','Altura (NR-35)','Eletricidade (NR-10)'];
                            foreach ($ops as $op): ?>
                            <div style="display:flex;align-items:center;gap:.3rem;padding:.1rem 0;border-bottom:1px solid #f8fafc">
                                <div style="width:9px;height:9px;border:1px solid #94a3b8;border-radius:2px;flex-shrink:0"></div>
                                <span><?= $op ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- SESMT -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid #e2e8f0">
                    <div style="padding:.4rem .6rem;border-right:1px solid #e2e8f0;font-size:.6rem">
                        <div style="font-weight:700;color:#1e3a8a;margin-bottom:.2rem">SESMT · ELABORADO POR:</div>
                        <div style="height:1.2rem;border-bottom:1px solid #e2e8f0"></div>
                    </div>
                    <div style="padding:.4rem .6rem;font-size:.6rem">
                        <div style="font-weight:700;color:#1e3a8a;margin-bottom:.2rem">SESMT · APROVADO POR:</div>
                        <div style="height:1.2rem;border-bottom:1px solid #e2e8f0"></div>
                    </div>
                </div>

                <!-- QR Code + Ramais lado a lado -->
                <div style="display:grid;grid-template-columns:auto 1fr;align-items:center;gap:.75rem;padding:.75rem">
                    <div style="display:flex;flex-direction:column;align-items:center;gap:.2rem">
                        <img id="cart-qr-img" src="" alt="QR Code"
                             style="width:80px;height:80px;object-fit:contain;display:block;border:1px solid #e2e8f0;border-radius:4px">
                        <span style="font-size:.5rem;color:#64748b">Escaneie para verificar</span>
                    </div>
                    <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:.25rem;
                                padding:.5rem .6rem;font-size:.6rem;text-align:center;height:100%">
                        <div style="font-weight:700;color:#b45309;margin-bottom:.25rem">RAMAIS DE EMERGÊNCIA</div>
                        <div style="color:#78350f;line-height:1.8">
                            SESMT: <?= e($company['emergency_sesmt'] ?: ($company['phone'] ?? 'â€”')) ?><br>
                            Bombeiro: <strong><?= e($company['emergency_bombeiro'] ?? '193') ?></strong>
                            &nbsp;&nbsp; SAMU: <strong><?= e($company['emergency_samu'] ?? '192') ?></strong>
                            &nbsp;&nbsp; Polícia: <strong><?= e($company['emergency_policia'] ?? '190') ?></strong>
                        </div>
                    </div>
                </div>

            </div><!-- /carteirinha-preview -->
        </div>

        <!-- Botões SEMPRE visÃ­veis (flex-shrink:0) -->
        <div style="display:flex;gap:.5rem;padding:1rem 1.25rem;border-top:1px solid #f1f5f9;
                    flex-shrink:0;flex-wrap:wrap;background:#fff">
            <button onclick="closeModal('modal-carteirinha')" class="btn btn-secondary" style="font-size:.8rem">Fechar</button>
            <button onclick="showCarteirinhaQr()" class="btn btn-secondary" style="flex:1;justify-content:center;font-size:.8rem">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5a.5.5 0 11-1 0 .5.5 0 011 0z"/>
                </svg>
                QR Code
            </button>
            <button onclick="downloadCarteirinhaAsPdf()" id="btn-pdf" class="btn btn-secondary" style="flex:1;justify-content:center;font-size:.8rem">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar PDF
            </button>
            <button onclick="printCarteirinha()" class="btn btn-primary" style="flex:1;justify-content:center;font-size:.8rem">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir
            </button>
        </div>
    </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     PAGINAÇÃO
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<?= $pag['html'] ?>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     JAVASCRIPT
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<script>
// Dados de todos os funcionários (PHP â†’ JS)
const EMP_DATA = <?= json_encode($empData, JSON_UNESCAPED_UNICODE) ?>;
const COMPANY_LOGO = <?= json_encode(!empty($company['logo']) ? BASE_URL . '/' . ltrim($company['logo'], '/') : '') ?>;

let _currentQrUrl = '';

/* â”€â”€ Utilitários de modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function openModal(id) {
    const el = document.getElementById(id);
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}
// Fecha ao clicar fora
['modal-qr','modal-carteirinha'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('modal-qr');
        closeModal('modal-carteirinha');
    }
});

/* â”€â”€ Modal QR Code â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
let _currentQrEmpName = '';

function showQrModal(empId, name) {
    const emp = EMP_DATA[empId];
    if (!emp) return;

    const url = emp.qr_url;
    _currentQrUrl     = url;
    _currentQrEmpName = name;

    document.getElementById('qr-modal-name').textContent = 'Escaneie para ver os certificados de ' + name;
    document.getElementById('qr-modal-url').textContent  = url;
    document.getElementById('qr-open-link').href         = url;

    const qrSrc = '<?= BASE_URL ?>/public/qr.php?size=160&data=' + encodeURIComponent(url);
    document.getElementById('qr-modal-img').src = qrSrc;

    openModal('modal-qr');
}

function copyQrUrl() {
    navigator.clipboard.writeText(_currentQrUrl).then(() => {
        alert('URL copiada!');
    }).catch(() => {
        prompt('Copie a URL:', _currentQrUrl);
    });
}

async function downloadQrCode() {
    const img  = document.getElementById('qr-modal-img');
    const name = (_currentQrEmpName || 'colaborador').replace(/\s+/g, '-').toLowerCase();
    // Versão maior para download
    const largeSrc = '<?= BASE_URL ?>/public/qr.php?size=400&data=' + encodeURIComponent(_currentQrUrl);
    try {
        const resp = await fetch(largeSrc);
        const blob = await resp.blob();
        const blobUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href     = blobUrl;
        a.download = 'qrcode-' + name + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
    } catch(e) {
        window.open(largeSrc, '_blank');
    }
}

/* â”€â”€ Modal Carteirinha â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function openCarteirinha(empId) {
    const emp = EMP_DATA[empId];
    if (!emp) return;

    // Dados do funcionário
    document.getElementById('cart-name').textContent    = emp.name;
    document.getElementById('cart-id').textContent      = String(emp.id).padStart(4, '0');
    document.getElementById('cart-role').textContent    = emp.role;
    document.getElementById('cart-company').textContent = emp.company;
    document.getElementById('cart-cpf').textContent     = emp.cpf;

    // Logo da empresa
    const logoImg = document.getElementById('cart-logo-img');
    const logoPh  = document.getElementById('cart-logo-placeholder');
    if (COMPANY_LOGO) {
        logoImg.src = COMPANY_LOGO;
        logoImg.style.display = 'block';
        if (logoPh) logoPh.style.display = 'none';
    } else {
        logoImg.style.display = 'none';
        if (logoPh) logoPh.style.display = '';
    }

    // Foto
    const photoWrap = document.getElementById('cart-photo-wrap');
    if (emp.photo) {
        photoWrap.innerHTML = '<img src="' + emp.photo + '" style="width:100%;height:100%;object-fit:cover">';
    } else {
        photoWrap.innerHTML = '<span style="font-size:.6rem;color:#94a3b8;text-align:center;line-height:1.3">Portar<br>documento<br>com foto</span>';
    }

    // NRs/Certificados
    const nrContainer = document.getElementById('cart-nrs');
    if (emp.certs.length === 0) {
        nrContainer.innerHTML = '<div style="font-size:.65rem;color:#94a3b8;padding:.25rem;text-align:center">Nenhum certificado</div>';
    } else {
        nrContainer.innerHTML = emp.certs.map(c => {
            const dateStr = c.expiry_date ? formatDateBR(c.expiry_date) : 'â€”';
            const color = c.status === 'expired' ? '#ef4444' : c.status === 'expiring' ? '#f59e0b' : '#16a34a';
            return '<div style="display:flex;align-items:center;gap:.3rem;padding:.15rem .25rem;'
                 + 'border-bottom:1px solid #f1f5f9;font-size:.65rem">'
                 + '<div style="width:10px;height:10px;border:1px solid #94a3b8;border-radius:2px;flex-shrink:0;'
                 + 'background:' + color + '"></div>'
                 + '<span style="flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">' + escHtml(c.type) + '</span>'
                 + '<span style="color:' + color + ';font-weight:600;white-space:nowrap">' + dateStr + '</span>'
                 + '</div>';
        }).join('');
    }

    // QR Code da carteira
    const qrUrl = '<?= BASE_URL ?>/public/qr.php?size=90&data=' + encodeURIComponent(emp.qr_url);
    document.getElementById('cart-qr-img').src = qrUrl;

    openModal('modal-carteirinha');
}

function showCarteirinhaQr() {
    // Abre o modal de QR com a URL já carregada na carteirinha
    const img = document.getElementById('cart-qr-img');
    if (!img || !img.src) return;
    const params  = new URL(img.src).searchParams;
    const dataUrl = params.get('data') || '';
    const empName = document.getElementById('cart-name').textContent || 'colaborador';

    _currentQrUrl     = dataUrl;
    _currentQrEmpName = empName;

    document.getElementById('qr-modal-name').textContent = 'QR Code de ' + empName;
    document.getElementById('qr-modal-url').textContent  = dataUrl;
    document.getElementById('qr-open-link').href         = dataUrl;

    const qrSrc = '<?= BASE_URL ?>/public/qr.php?size=160&data=' + encodeURIComponent(dataUrl);
    document.getElementById('qr-modal-img').src = qrSrc;

    closeModal('modal-carteirinha');
    openModal('modal-qr');
}

async function downloadCarteirinhaAsPdf() {
    const btn = document.getElementById('btn-pdf');
    const origText = btn.innerHTML;
    btn.innerHTML  = 'â³ Gerando PDF...';
    btn.disabled   = true;

    try {
        const element = document.getElementById('carteirinha-preview');
        const canvas  = await html2canvas(element, {
            scale: 2, useCORS: true, backgroundColor: '#ffffff',
            logging: false
        });

        const imgData  = canvas.toDataURL('image/jpeg', 0.92);
        const { jsPDF } = window.jspdf;
        const pdf       = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        const pageW  = pdf.internal.pageSize.getWidth();
        const pageH  = pdf.internal.pageSize.getHeight();
        const imgW   = pageW - 20; // 10mm margem cada lado
        const imgH   = (canvas.height * imgW) / canvas.width;
        const yPos   = imgH < pageH ? (pageH - imgH) / 2 : 10;

        pdf.addImage(imgData, 'JPEG', 10, yPos, imgW, Math.min(imgH, pageH - 20));

        const empName = (document.getElementById('cart-name').textContent || 'colaborador')
                        .replace(/\s+/g, '-').toLowerCase();
        pdf.save('carteirinha-' + empName + '.pdf');

    } catch(err) {
        alert('Erro ao gerar PDF. Tente usar o botão Imprimir e salve como PDF.');
        console.error(err);
    }

    btn.innerHTML = origText;
    btn.disabled  = false;
}

function printCarteirinha() {
    const content = document.getElementById('carteirinha-preview').innerHTML;
    const win = window.open('', '_blank', 'width=700,height=900');
    win.document.write(`<!DOCTYPE html><html lang="pt-BR"><head>
        <meta charset="UTF-8"><title>Carteirinha de Autorização</title>
        <style>
            body { margin: 20px; font-family: Arial, sans-serif; }
            @media print { body { margin: 0; } }
        </style>
    </head><body>` + content + `
    <script>window.onload = function() { window.print(); window.close(); }<\/script>
    </body></html>`);
    win.document.close();
}

/* â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€  */
function formatDateBR(str) {
    if (!str) return 'â€”';
    const [y, m, d] = str.split('-');
    return d + '/' + m + '/' + y;
}
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<!-- Libs para PDF da carteirinha -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
