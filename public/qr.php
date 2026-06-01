<?php
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../includes/qrcode.php";
$data = trim($_GET["data"] ?? "");
$size = max(60, min(800, (int)($_GET["size"] ?? 200)));
if ($data === "") { http_response_code(400); exit("Missing data"); }
header("Content-Type: image/svg+xml");
header("Cache-Control: public, max-age=86400");
try { echo QRCode::svg($data, $size); } catch (\Throwable $e) { echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'"><rect width="100%" height="100%" fill="#f3f4f6"/></svg>'; }