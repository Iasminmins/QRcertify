<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('/admin/employees.php'); }

$s = $db->prepare(
    'SELECT a.*, e.id AS emp_id FROM employee_asos a
     JOIN employees e ON e.id = a.employee_id
     WHERE a.id = ? AND a.company_id = ? LIMIT 1'
);
$s->execute([$id, $companyId]);
$aso = $s->fetch();
if (!$aso) { redirect('/admin/employees.php'); }

// Remove arquivo PDF se existir
if (!empty($aso['file_path'])) {
    $full = __DIR__ . '/../' . ltrim($aso['file_path'], '/');
    if (file_exists($full)) @unlink($full);
}

$db->prepare('DELETE FROM employee_asos WHERE id = ? AND company_id = ?')
   ->execute([$id, $companyId]);

auditLog('aso.delete', "aso #{$id}", json_encode(['employee_id' => $aso['emp_id']]));
setFlash('success', 'ASO excluído com sucesso.');
redirect('/admin/employee-details.php?id=' . $aso['emp_id']);
