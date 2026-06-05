<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('/admin/departments.php'); }

$s = $db->prepare('SELECT * FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
$s->execute([$id, $companyId]);
$dept = $s->fetch();
if (!$dept) { redirect('/admin/departments.php'); }

// Solta FK dos employees antes de deletar
$db->prepare('UPDATE employees SET department_id = NULL WHERE department_id = ?')->execute([$id]);

$db->prepare('DELETE FROM departments WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
auditLog('department.delete', "department #{$id}", $dept['name']);

setFlash('success', 'Área "' . $dept['name'] . '" excluída. Colaboradores mantidos sem área.');
redirect('/admin/departments.php');
