<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/documents.php');
}
if (!csrfCheck()) { die('CSRF inválido.'); }

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id = (int)($_POST['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT * FROM employee_documents WHERE id = ? AND company_id = ? LIMIT 1'
);
$stmt->execute([$id, $companyId]);
$doc = $stmt->fetch();

if (!$doc) {
    setFlash('error', 'Documento não encontrado.');
    redirect('/admin/documents.php');
}

// Remove o arquivo físico
$filePath = __DIR__ . '/../' . $doc['file_path'];
if (file_exists($filePath)) {
    @unlink($filePath);
}

// Remove do banco
$db->prepare('DELETE FROM employee_documents WHERE id = ? AND company_id = ?')
   ->execute([$id, $companyId]);

setFlash('success', 'Documento excluído com sucesso.');
redirect('/admin/documents.php');
