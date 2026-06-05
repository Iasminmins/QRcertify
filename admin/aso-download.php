<?php
/**
 * Download protegido do PDF do ASO.
 * Exige login com role admin ou superadmin.
 * Registra acesso no audit log.
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

$safeName = 'ASO_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $aso['emp_name'])
          . '_' . $aso['aso_type'] . '_' . date('Ymd') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, no-cache');
readfile($fullPath);
exit;
