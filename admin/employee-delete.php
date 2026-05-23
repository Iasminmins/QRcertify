<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    setFlash('error', 'Requisição inválida.');
    redirect('/admin/employees.php');
}

$id = (int) ($_POST['id'] ?? 0);
$user = currentUser();
$db = getDB();

$stmt = $db->prepare('DELETE FROM employees WHERE id = ? AND company_id = ?');
$stmt->execute([$id, $user['company_id']]);

if ($stmt->rowCount() > 0) {
    setFlash('success', 'Funcionário removido com sucesso.');
} else {
    setFlash('error', 'Funcionário não encontrado.');
}

redirect('/admin/employees.php');
