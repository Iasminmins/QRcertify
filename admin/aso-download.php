<?php
/**
 * Download/visualização protegida do arquivo do ASO.
 * Aceita PDF, JPG, PNG, WEBP.
 * Exige login com role admin.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Não encontrado.'); }

$s = $db->prepare(
    'SELECT a.file_path, a.aso_type, e.name AS emp_name
     FROM employee_asos a
     JOIN employees e ON e.id = a.employee_id
     WHERE a.id = ? AND a.company_id = ? LIMIT 1'
);
$s->execute([$id, $companyId]);
$aso = $s->fetch();

if (!$aso || empty($aso['file_path'])) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$fullPath = __DIR__ . '/../' . ltrim($aso['file_path'], '/');
if (!file_exists($fullPath)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

auditLog('aso.download', "aso #{$id}", json_encode([
    'employee' => $aso['emp_name'],
    'type'     => $aso['aso_type'],
]));

// Detecta o mime real do arquivo
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($fullPath);

$mimeMap = [
    'application/pdf' => ['ext' => 'pdf',  'disposition' => 'inline'],
    'image/jpeg'      => ['ext' => 'jpg',  'disposition' => 'inline'],
    'image/png'       => ['ext' => 'png',  'disposition' => 'inline'],
    'image/webp'      => ['ext' => 'webp', 'disposition' => 'inline'],
    'image/gif'       => ['ext' => 'gif',  'disposition' => 'inline'],
];

$info = $mimeMap[$realMime] ?? ['ext' => 'bin', 'disposition' => 'attachment'];

$safeName = 'ASO_'
    . preg_replace('/[^a-zA-Z0-9_-]/', '_', $aso['emp_name'])
    . '_' . $aso['aso_type']
    . '_' . date('Ymd')
    . '.' . $info['ext'];

header('Content-Type: ' . $realMime);
header('Content-Disposition: ' . $info['disposition'] . '; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
readfile($fullPath);
exit;
