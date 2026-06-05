<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// ── Busca áreas com estatísticas ─────────────────────────────────────────────
$departments = $db->prepare("
    SELECT
        d.id,
        d.name,
        d.manager_name,

        COUNT(DISTINCT e.id)  AS total_employees,

        SUM(CASE WHEN e.id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM certificates cx
            WHERE cx.employee_id = e.id AND cx.status IN ('expired','expiring')
        ) AND EXISTS (
            SELECT 1 FROM certificates cv
            WHERE cv.employee_id = e.id
        ) THEN 1 ELSE 0 END) AS count_regular,

        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM certificates ca
            WHERE ca.employee_id = e.id AND ca.status = 'expiring'
        ) AND NOT EXISTS (
            SELECT 1 FROM certificates ce
            WHERE ce.employee_id = e.id AND ce.status = 'expired'
        ) THEN 1 ELSE 0 END) AS count_attention,

        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM certificates ci
            WHERE ci.employee_id = e.id AND ci.status = 'expired'
        ) THEN 1 ELSE 0 END) AS count_irregular,

        SUM(CASE WHEN NOT EXISTS (
            SELECT 1 FROM certificates cs
            WHERE cs.employee_id = e.id
        ) THEN 1 ELSE 0 END) AS count_no_cert

    FROM departments d
    LEFT JOIN employees e ON (e.department_id = d.id OR (e.department_id IS NULL AND e.department = d.name)) AND e.status = 'active'
    WHERE d.company_id = ?
    GROUP BY d.id, d.name, d.manager_name
    ORDER BY d.name ASC
");
$departments->execute([$companyId]);
$departments = $departments->fetchAll();

// ── Colaboradores SEM área vinculada ─────────────────────────────────────────
$noArea = $db->prepare("
    SELECT
        COUNT(DISTINCT e.id) AS total,
        SUM(CASE WHEN e.id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM certificates cx WHERE cx.employee_id = e.id AND cx.status IN ('expired','expiring')
        ) AND EXISTS (
            SELECT 1 FROM certificates cv WHERE cv.employee_id = e.id
        ) THEN 1 ELSE 0 END) AS count_regular,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM certificates ca WHERE ca.employee_id = e.id AND ca.status = 'expiring'
        ) AND NOT EXISTS (
            SELECT 1 FROM certificates ce WHERE ce.employee_id = e.id AND ce.status = 'expired'
        ) THEN 1 ELSE 0 END) AS count_attention,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM certificates ci WHERE ci.employee_id = e.id AND ci.status = 'expired'
        ) THEN 1 ELSE 0 END) AS count_irregular,
        SUM(CASE WHEN NOT EXISTS (
            SELECT 1 FROM certificates cs WHERE cs.employee_id = e.id
        ) THEN 1 ELSE 0 END) AS count_no_cert
    FROM employees e
    WHERE e.company_id = ? AND e.status = 'active'
      AND e.department_id IS NULL
      AND (e.department IS NULL OR e.department = '' OR e.department NOT IN (
          SELECT name FROM departments WHERE company_id = e.company_id
      ))
");
$noArea->execute([$companyId]);
$noArea = $noArea->fetch();

$pageTitle = 'Áreas e Setores';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Áreas e Setores</h1>
        <p class="page-subtitle"><?= count($departments) ?> área<?= count($departments) !== 1 ? 's' : '' ?> cadastrada<?= count($departments) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="<?= BASE_URL ?>/admin/department-form.php" class="btn btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nova Área
    </a>
</div>

<?php if (empty($departments) && (int)($noArea['total'] ?? 0) === 0): ?>
<!-- Estado vazio -->
<div class="card text-center py-16">
    <svg class="h-12 w-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
    </svg>
    <h3 class="text-gray-600 font-semibold mb-1">Nenhuma área cadastrada</h3>
    <p class="text-gray-400 text-sm mb-6">Crie áreas e vincule colaboradores para acompanhar a conformidade por setor.</p>
    <a href="<?= BASE_URL ?>/admin/department-form.php" class="btn btn-primary inline-flex">Nova Área</a>
</div>

<?php else: ?>

<!-- Tabela de áreas -->
<div class="card p-0 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                <th class="px-4 py-3 text-left">Área / Setor</th>
                <th class="px-4 py-3 text-left">Gestor</th>
                <th class="px-4 py-3 text-center">Total</th>
                <th class="px-4 py-3 text-center">
                    <span class="inline-flex items-center gap-1 text-green-600">
                        <span class="h-2 w-2 rounded-full bg-green-500 inline-block"></span>Regular
                    </span>
                </th>
                <th class="px-4 py-3 text-center">
                    <span class="inline-flex items-center gap-1 text-amber-600">
                        <span class="h-2 w-2 rounded-full bg-amber-400 inline-block"></span>Atenção
                    </span>
                </th>
                <th class="px-4 py-3 text-center">
                    <span class="inline-flex items-center gap-1 text-red-600">
                        <span class="h-2 w-2 rounded-full bg-red-500 inline-block"></span>Irregular
                    </span>
                </th>
                <th class="px-4 py-3 text-center text-gray-400">Sem cert.</th>
                <th class="px-4 py-3 text-right">Ações</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">

        <?php foreach ($departments as $dept):
            $total      = (int)$dept['total_employees'];
            $regular    = (int)$dept['count_regular'];
            $attention  = (int)$dept['count_attention'];
            $irregular  = (int)$dept['count_irregular'];
            $noCert     = (int)$dept['count_no_cert'];
            // Conformidade %
            $conf = $total > 0 ? round(($regular / $total) * 100) : 0;
            // Cor da linha
            $rowBg = $irregular > 0 ? 'bg-red-50/40' : ($attention > 0 ? 'bg-amber-50/30' : '');
        ?>
        <tr class="hover:bg-gray-50 transition <?= $rowBg ?>">
            <!-- Nome da área -->
            <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <div class="h-8 w-8 rounded-lg bg-industrial-blue/10 flex items-center justify-center flex-shrink-0">
                        <svg class="h-4 w-4 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5"/>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900"><?= e($dept['name']) ?></p>
                        <?php if ($total > 0): ?>
                        <div class="mt-1 h-1.5 w-24 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?= $irregular > 0 ? 'bg-red-500' : ($attention > 0 ? 'bg-amber-400' : 'bg-green-500') ?>"
                                 style="width:<?= $conf ?>%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-0.5"><?= $conf ?>% conformidade</p>
                        <?php endif; ?>
                    </div>
                </div>
            </td>

            <!-- Gestor -->
            <td class="px-4 py-3">
                <?php if ($dept['manager_name']): ?>
                    <p class="font-medium text-gray-800"><?= e($dept['manager_name']) ?></p>
                <?php else: ?>
                    <span class="text-gray-400 text-xs italic">Sem gestor</span>
                <?php endif; ?>
            </td>

            <!-- Números -->
            <td class="px-4 py-3 text-center font-bold text-gray-700"><?= $total ?></td>
            <td class="px-4 py-3 text-center">
                <span class="inline-block font-bold text-green-600"><?= $regular ?></span>
            </td>
            <td class="px-4 py-3 text-center">
                <span class="inline-block font-bold <?= $attention > 0 ? 'text-amber-600' : 'text-gray-300' ?>"><?= $attention ?></span>
            </td>
            <td class="px-4 py-3 text-center">
                <span class="inline-block font-bold <?= $irregular > 0 ? 'text-red-600' : 'text-gray-300' ?>"><?= $irregular ?></span>
            </td>
            <td class="px-4 py-3 text-center">
                <span class="inline-block text-gray-400"><?= $noCert ?></span>
            </td>

            <!-- Ações -->
            <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                    <a href="<?= BASE_URL ?>/admin/employees.php?department_id=<?= $dept['id'] ?>"
                       title="Ver colaboradores"
                       class="text-gray-400 hover:text-industrial-blue transition">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/department-form.php?id=<?= $dept['id'] ?>"
                       title="Editar"
                       class="text-gray-400 hover:text-industrial-blue transition">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/department-delete.php?id=<?= $dept['id'] ?>"
                       title="Excluir"
                       onclick="return confirm('Excluir a área \'<?= e(addslashes($dept['name'])) ?>\'? Os colaboradores não serão removidos.')"
                       class="text-gray-400 hover:text-red-500 transition">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>

        <!-- Linha: sem área vinculada -->
        <?php if ((int)($noArea['total'] ?? 0) > 0):
            $total     = (int)$noArea['total'];
            $regular   = (int)$noArea['count_regular'];
            $attention = (int)$noArea['count_attention'];
            $irregular = (int)$noArea['count_irregular'];
            $noCert    = (int)$noArea['count_no_cert'];
        ?>
        <tr class="bg-gray-50 border-t-2 border-dashed border-gray-200">
            <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <div class="h-8 w-8 rounded-lg bg-gray-200 flex items-center justify-center flex-shrink-0">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                        </svg>
                    </div>
                    <p class="text-sm text-gray-500 italic">Sem área definida</p>
                </div>
            </td>
            <td class="px-4 py-3"><span class="text-gray-400 text-xs italic">—</span></td>
            <td class="px-4 py-3 text-center font-bold text-gray-500"><?= $total ?></td>
            <td class="px-4 py-3 text-center font-bold text-green-600"><?= $regular ?></td>
            <td class="px-4 py-3 text-center font-bold <?= $attention > 0 ? 'text-amber-600' : 'text-gray-300' ?>"><?= $attention ?></td>
            <td class="px-4 py-3 text-center font-bold <?= $irregular > 0 ? 'text-red-600' : 'text-gray-300' ?>"><?= $irregular ?></td>
            <td class="px-4 py-3 text-center text-gray-400"><?= $noCert ?></td>
            <td class="px-4 py-3 text-right">
                <a href="<?= BASE_URL ?>/admin/employees.php?department_id=none"
                   class="text-xs text-gray-400 hover:text-industrial-blue transition">Ver colaboradores →</a>
            </td>
        </tr>
        <?php endif; ?>

        </tbody>
    </table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
