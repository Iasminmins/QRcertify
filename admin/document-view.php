<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id    = (int)($_GET['id']    ?? 0);
$thumb = (int)($_GET['thumb'] ?? 0); // se 1, serve miniatura inline

$stmt = $db->prepare(
    'SELECT * FROM employee_documents WHERE id = ? AND company_id = ? LIMIT 1'
);
$stmt->execute([$id, $companyId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Documento não encontrado.');
}

$filePath = __DIR__ . '/../' . $doc['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    die('Arquivo não encontrado no servidor.');
}

$ext = strtolower($doc['file_ext'] ?? '');

$mimes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
if ($thumb && in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
    // Serve inline para preview
    header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
} elseif (in_array($ext, ['pdf','jpg','jpeg','png','gif','webp'])) {
    // PDF e imagens: abre inline no browser
    header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
} else {
    // Outros: forçar download
    header('Content-Disposition: attachment; filename="' . basename($doc['file_name']) . '"');
}
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
