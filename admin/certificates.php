<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$db->prepare(
    'UPDATE certificates c JOIN employees e ON e.id = c.employee_id
     SET c.status = CASE
        WHEN c.expiry_date < CURDATE() THEN "expired"
        WHEN c.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
        ELSE "valid" END
     WHERE e.company_id = ?'
)->execute([$companyId]);

$search       = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$typeFilter   = $_GET['type']   ?? '';
$empFilter    = $_GET['emp']    ?? '';

$sql    = 'SELECT c.*, e.name AS employee_name, e.role AS employee_role,
                  e.photo AS employee_photo, e.id AS emp_id
           FROM certificates c
           JOIN employees e ON e.id = c.employee_id
           WHERE e.company_id = ?';
$params = [$companyId];

if ($search !== '') {
    $sql .= ' AND (c.title LIKE ? OR c.type LIKE ? OR e.name LIKE ?)';
    $like    = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($statusFilter, ['valid','expiring','expired'], true)) {
    $sql .= ' AND c.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== '') {
    $sql .= ' AND c.type = ?';
    $params[] = $typeFilter;
}
if ($empFilter !== '') {
    $sql .= ' AND e.id = ?';
    $params[] = (int)$empFilter;
}
$sql .= ' ORDER BY c.expiry_date ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allCerts = $stmt->fetchAll();

$pag          = paginate($allCerts, (int)($_GET['page'] ?? 1), 25);
$certificates = $pag['items'];

// Tipos únicos para o filtro
$allTypes = $db->prepare(
    'SELECT DISTINCT c.type FROM certificates c JOIN employees e ON e.id = c.employee_id
     WHERE e.company_id = ? ORDER BY c.type'
);
$allTypes->execute([$companyId]);
$certTypes = $allTypes->fetchAll(\PDO::FETCH_COLUMN);

// Funcionários únicos para o filtro
$allEmps = $db->prepare(
    'SELECT DISTINCT e.id, e.name FROM employees e
     JOIN certificates c ON c.employee_id = e.id
     WHERE e.company_id = ? ORDER BY e.name'
);
$allEmps->execute([$companyId]);
$certEmps = $allEmps->fetchAll();

$pageTitle = 'Certificados';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Certificados</h1>
        <p class="page-subtitle"><?= $pag['total'] ?> certificado(s) encontrado(s)</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/certificate-form.php" class="btn btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Novo Certificado
    </a>
</div>

<?php renderFlashes(); ?>

<!-- Filtros -->
<form method="GET" class="section-card mb-5">
    <div class="section-card-body">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="<?= e($search) ?>"
                       placeholder="Buscar certificados..." class="form-input pl-10">
            </div>
            <select name="status" class="form-input">
                <option value="">Todos os Status</option>
                <option value="valid"    <?= $statusFilter==='valid'    ?'selected':'' ?>>Válidos</option>
                <option value="expiring" <?= $statusFilter==='expiring' ?'selected':'' ?>>Expirando</option>
                <option value="expired"  <?= $statusFilter==='expired'  ?'selected':'' ?>>Expirados</option>
            </select>
            <select name="type" class="form-input">
                <option value="">Todos os Tipos</option>
                <?php foreach ($certTypes as $t): ?>
                    <option value="<?= e($t) ?>" <?= $typeFilter===$t?'selected':'' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="emp" class="form-input">
                <option value="">Todos os Colaboradores</option>
                <?php foreach ($certEmps as $ce): ?>
                    <option value="<?= (int)$ce['id'] ?>" <?= $empFilter==(string)$ce['id']?'selected':'' ?>>
                        <?= e($ce['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
            <?php if ($search||$statusFilter||$typeFilter||$empFilter): ?>
                <a href="<?= BASE_URL ?>/admin/certificates.php" class="btn btn-secondary btn-sm">Limpar</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Toggle Grid/Lista + contador -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
    <div style="display:flex;border:1px solid #e2e8f0;border-radius:.5rem;overflow:hidden;background:#fff">
        <button id="btn-grid" onclick="setView('grid')"
                style="padding:.4rem .875rem;font-size:.8rem;font-weight:600;border:none;cursor:pointer;
                       background:#1e3a8a;color:#fff;transition:background .15s">
            Grid
        </button>
        <button id="btn-list" onclick="setView('list')"
                style="padding:.4rem .875rem;font-size:.8rem;font-weight:600;border:none;cursor:pointer;
                       background:#fff;color:#64748b;transition:background .15s">
            Lista
        </button>
    </div>
    <span style="font-size:.8rem;padding:.3rem .75rem;border-radius:99px;background:#eff6ff;
                 color:#1e3a8a;border:1px solid #bfdbfe;font-weight:600">
        <?= count($certificates) ?> certificado(s)
    </span>
</div>

<!-- ── VISTA GRID ─────────────────────────────────────────── -->
<div id="view-grid">
<?php if (empty($certificates)): ?>
    <div class="section-card">
        <div class="py-16 text-center text-gray-400">
            <svg class="h-12 w-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <p class="font-medium text-gray-500">Nenhum certificado encontrado</p>
        </div>
    </div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem">
<?php foreach ($certificates as $cert):
    $badge = getStatusBadge($cert['status']);
    $statusLabel = match($cert['status']) {
        'valid'    => 'Válido',
        'expiring' => 'Expirando',
        'expired'  => 'Expirado',
        default    => '—',
    };
    $statusColor = match($cert['status']) {
        'valid'    => '#16a34a',
        'expiring' => '#b45309',
        'expired'  => '#dc2626',
        default    => '#64748b',
    };
    $qrUrl = generateQRCodeUrl(getCertificatePublicUrl($cert['hash']), 120);
    $empInitials = strtoupper(mb_substr($cert['employee_name'], 0, 1));
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;
            box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s;cursor:pointer"
     onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,.1)';this.style.transform='translateY(-2px)'"
     onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,.04)';this.style.transform='translateY(0)'"
     onclick="window.open('<?= getCertificatePublicUrl($cert['hash']) ?>','_blank')">

    <!-- Header azul com logo + título -->
    <div style="background:#1e3a8a;padding:.65rem 1rem;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:.5rem">
            <div style="width:1.1rem;height:1.1rem;border-radius:50%;background:#facc15;flex-shrink:0"></div>
            <span style="font-size:.65rem;font-weight:700;color:#fff;letter-spacing:.06em">CARTEIRINHA PROFISSIONAL</span>
        </div>
    </div>

    <div style="padding:.875rem">
        <!-- Tipo + Status -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem">
            <span style="font-size:.7rem;font-weight:700;padding:.15rem .5rem;background:#eff6ff;
                         color:#1e3a8a;border:1px solid #bfdbfe;border-radius:99px">
                <?= e($cert['type']) ?>
            </span>
            <span style="font-size:.7rem;font-weight:700;color:<?= $statusColor ?>">
                <?= $statusLabel ?>
            </span>
        </div>

        <!-- Título do certificado -->
        <h3 style="font-size:.875rem;font-weight:600;color:#0f172a;line-height:1.4;
                   margin-bottom:.75rem;min-height:2.5rem">
            <?= e($cert['title']) ?>
        </h3>

        <!-- Funcionário -->
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
            <div style="width:2rem;height:2rem;border-radius:50%;background:#1e3a8a;
                        display:flex;align-items:center;justify-content:center;
                        overflow:hidden;flex-shrink:0;border:2px solid #dbeafe">
                <?php if (!empty($cert['employee_photo'])): ?>
                    <img src="<?= e($cert['employee_photo']) ?>" alt=""
                         style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <span style="font-size:.7rem;font-weight:700;color:#fff"><?= $empInitials ?></span>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size:.8rem;font-weight:600;color:#374151"><?= e($cert['employee_name']) ?></div>
                <div style="font-size:.7rem;color:#94a3b8"><?= e($cert['employee_role']) ?></div>
            </div>
        </div>

        <!-- Datas -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.875rem;font-size:.72rem">
            <div>
                <div style="color:#94a3b8;margin-bottom:.1rem;text-transform:uppercase;
                            font-size:.62rem;letter-spacing:.05em">Emissão</div>
                <div style="font-weight:600;color:#374151"><?= formatDate($cert['issued_date']) ?></div>
            </div>
            <div>
                <div style="color:#94a3b8;margin-bottom:.1rem;text-transform:uppercase;
                            font-size:.62rem;letter-spacing:.05em">Validade</div>
                <div style="font-weight:700;color:<?= $statusColor ?>"><?= formatDate($cert['expiry_date']) ?></div>
            </div>
        </div>

        <!-- QR Code centralizado -->
        <div style="display:flex;justify-content:center;align-items:center;padding:.5rem 0 .25rem;border-top:1px solid #f1f5f9">
            <img src="<?= e($qrUrl) ?>" alt="QR Code"
                 style="width:90px;height:90px;object-fit:contain">
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /#view-grid -->

<!-- ── VISTA LISTA ─────────────────────────────────────── -->
<div id="view-list" style="display:none">
<div class="table-wrapper">
    <?php if (empty($certificates)): ?>
        <div class="py-16 text-center text-gray-400">
            <p class="font-medium text-gray-500">Nenhum certificado encontrado</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Certificado</th>
                <th class="hidden md:table-cell">Funcionário</th>
                <th>Tipo</th>
                <th class="hidden lg:table-cell">Emissão</th>
                <th>Validade</th>
                <th>Status</th>
                <th class="text-right">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($certificates as $cert):
            $badge = getStatusBadge($cert['status']);
            $dateClass = match($cert['status']) {
                'expired'  => 'color:#dc2626;font-weight:700',
                'expiring' => 'color:#b45309;font-weight:700',
                default    => 'color:#16a34a;font-weight:700',
            };
        ?>
        <tr>
            <td>
                <div style="font-weight:600;color:#0f172a;font-size:.875rem"><?= e($cert['title']) ?></div>
                <div style="font-size:.75rem;color:#94a3b8" class="md:hidden"><?= e($cert['employee_name']) ?></div>
            </td>
            <td class="hidden md:table-cell" style="font-size:.875rem;color:#64748b"><?= e($cert['employee_name']) ?></td>
            <td>
                <span style="font-size:.7rem;font-weight:700;padding:.15rem .5rem;background:#eff6ff;
                             color:#1e3a8a;border:1px solid #bfdbfe;border-radius:99px">
                    <?= e($cert['type']) ?>
                </span>
            </td>
            <td class="hidden lg:table-cell" style="font-size:.875rem;color:#64748b"><?= formatDate($cert['issued_date']) ?></td>
            <td style="font-size:.875rem;<?= $dateClass ?>"><?= formatDate($cert['expiry_date']) ?></td>
            <td><span class="badge badge-<?= $cert['status'] ?>"><?= e($badge['label']) ?></span></td>
            <td class="text-right">
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:.4rem">
                    <a href="<?= getCertificatePublicUrl($cert['hash']) ?>" target="_blank"
                       class="btn btn-secondary btn-sm">QR</a>
                    <a href="<?= BASE_URL ?>/admin/certificate-form.php?id=<?= (int)$cert['id'] ?>"
                       class="btn btn-secondary btn-sm">Editar</a>
                    <form method="POST" action="<?= BASE_URL ?>/admin/certificate-delete.php"
                          class="inline" onsubmit="return confirm('Excluir este certificado?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div><!-- /#view-list -->

<?= $pag['html'] ?>

<script>
const savedView = localStorage.getItem('cert-view') || 'grid';
function setView(v) {
    document.getElementById('view-grid').style.display = v === 'grid' ? 'block' : 'none';
    document.getElementById('view-list').style.display = v === 'list' ? 'block' : 'none';
    document.getElementById('btn-grid').style.background = v === 'grid' ? '#1e3a8a' : '#fff';
    document.getElementById('btn-grid').style.color = v === 'grid' ? '#fff' : '#64748b';
    document.getElementById('btn-list').style.background = v === 'list' ? '#1e3a8a' : '#fff';
    document.getElementById('btn-list').style.color = v === 'list' ? '#fff' : '#64748b';
    localStorage.setItem('cert-view', v);
}
setView(savedView);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
