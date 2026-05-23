<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfCheck()) {
    setFlash('error', 'Requisição inválida.');
    redirect('/admin/certificates.php');
}

$id = (int) ($_POST['id'] ?? 0);
$user = currentUser();
$db = getDB();

// Garante que o certificado pertence à empresa do usuário
$stmt = $db->prepare(
    'DELETE c FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     WHERE c.id = ? AND e.company_id = ?'
);
$stmt->execute([$id, $user['company_id']]);

if ($stmt->rowCount() > 0) {
    setFlash('success', 'Certificado removido com sucesso.');
} else {
    setFlash('error', 'Certificado não encontrado.');
}

redirect('/admin/certificates.php');
