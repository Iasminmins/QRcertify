<?php
/**
 * Funções utilitárias do sistema
 */

/**
 * Escapa string para output seguro em HTML.
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redireciona para outra URL e encerra o script.
 */
function redirect(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit;
}

/**
 * Formata uma data (yyyy-mm-dd) para o formato brasileiro (dd/mm/yyyy).
 */
function formatDate(?string $date): string {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '-';
}

/**
 * Calcula o status de um certificado baseado na data de validade.
 */
function getCertStatus(string $expiryDate): string {
    $expiry = strtotime($expiryDate);
    $now = time();
    $diffDays = ($expiry - $now) / (60 * 60 * 24);

    if ($diffDays <= 0) return 'expired';
    if ($diffDays <= 30) return 'expiring';
    return 'valid';
}

/**
 * Retorna o label em português e a classe CSS de um status.
 */
function getStatusBadge(string $status): array {
    switch ($status) {
        case 'valid':
            return ['label' => 'Válido', 'class' => 'bg-green-100 text-green-800 border-green-200'];
        case 'expired':
            return ['label' => 'Expirado', 'class' => 'bg-red-100 text-red-800 border-red-200'];
        case 'expiring':
            return ['label' => 'Expirando', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'];
        case 'active':
            return ['label' => 'Ativo', 'class' => 'bg-green-100 text-green-800 border-green-200'];
        case 'inactive':
            return ['label' => 'Inativo', 'class' => 'bg-gray-100 text-gray-800 border-gray-200'];
        default:
            return ['label' => 'Indefinido', 'class' => 'bg-gray-100 text-gray-800'];
    }
}

/**
 * Gera URL pública para validação de certificado via QR Code.
 */
function getCertificatePublicUrl(string $hash): string {
    return BASE_URL . '/public/certificate.php?hash=' . urlencode($hash);
}

/**
 * Garante hash público da carteira do funcionário.
 */
function ensureEmployeePublicHash(int $employeeId): ?string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT public_hash FROM employees WHERE id = ? LIMIT 1');
        $stmt->execute([$employeeId]);
        $hash = $stmt->fetchColumn();
        if (is_string($hash) && strlen($hash) >= 32) {
            return $hash;
        }

        try {
            $hash = generateHash();
            $upd = $db->prepare('UPDATE employees SET public_hash = ? WHERE id = ?');
            $upd->execute([$hash, $employeeId]);
            return $hash;
        } catch (\Exception $e) {
            return null;
        }
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Gera URL pública da carteira digital de um funcionário (todos os certs).
 */
function getEmployeePublicUrl(int $employeeId): string {
    $hash = ensureEmployeePublicHash($employeeId);
    if ($hash) {
        return BASE_URL . '/public/employee.php?hash=' . urlencode($hash);
    }
    return BASE_URL . '/public/employee.php?id=' . $employeeId;
}

/**
 * Gera URL pública da Carteira de Autorização (NRs + ASO + ramais).
 */
function getAuthorizationPublicUrl(int $employeeId): string {
    $hash = ensureEmployeePublicHash($employeeId);
    if ($hash) {
        return BASE_URL . '/public/authorization.php?hash=' . urlencode($hash);
    }
    return BASE_URL . '/public/authorization.php';
}

/**
 * Gera QR Code em SVG localmente (sem dependência externa).
 * Retorna uma string SVG pronta para uso como src em data URI ou inline.
 */
function generateQRCodeSvg(string $data, int $size = 200, string $dark = '#000000', string $light = '#ffffff'): string {
    require_once __DIR__ . '/qrcode.php';
    try {
        return QRCode::svg($data, $size, $dark, $light);
    } catch (\Throwable $e) {
        // Fallback: quadrado placeholder
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" text-anchor="middle" fill="#9ca3af" font-size="12" dy=".3em">QR Code</text></svg>';
    }
}

/**
 * Gera data URI de um QR Code SVG — pode ser usado direto em <img src="...">.
 */
function generateQRCodeDataUri(string $data, int $size = 200): string {
    $svg = generateQRCodeSvg($data, $size);
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Gera URL da imagem de um QR Code.
 * Usa gerador local (SVG puro) como data URI — sem dependência externa.
 * Mantido por compatibilidade com código existente que usa <img src="...">.
 */
function generateQRCodeUrl(string $data, int $size = 200): string {
    return generateQRCodeDataUri($data, $size);
}

/**
 * Define uma mensagem flash (aparece na próxima requisição e some).
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Recupera e remove a mensagem flash.
 */
function getFlash(string $type): ?string {
    if (isset($_SESSION['flash'][$type])) {
        $msg = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $msg;
    }
    return null;
}

/**
 * Mostra todas as mensagens flash em HTML.
 */
function renderFlashes(): void {
    foreach (['success', 'error', 'info', 'warning'] as $type) {
        $msg = getFlash($type);
        if ($msg) {
            echo '<div class="flash flash-' . $type . '">' . e($msg) . '</div>';
        }
    }
}

/**
 * Gera e valida tokens CSRF para proteção de formulários.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function csrfCheck(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Gera um hash único para um certificado (usado no QR Code).
 */
function generateHash(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Valida CPF com cálculo dos dígitos verificadores.
 */
function validarCpf(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false; // todos iguais (ex: 111.111.111-11)

    // 1º dígito verificador
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (int)$cpf[$i] * (10 - $i);
    $r1 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    if ((int)$cpf[9] !== $r1) return false;

    // 2º dígito verificador
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += (int)$cpf[$i] * (11 - $i);
    $r2 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    return (int)$cpf[10] === $r2;
}

/**
 * Formata telefone brasileiro: (11) 91234-5678 ou (11) 1234-5678
 */
function formatPhone(?string $phone): string {
    if (empty($phone)) return '-';
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11) {
        return '(' . substr($d,0,2) . ') ' . substr($d,2,5) . '-' . substr($d,7,4);
    }
    if (strlen($d) === 10) {
        return '(' . substr($d,0,2) . ') ' . substr($d,2,4) . '-' . substr($d,6,4);
    }
    return $phone;
}

/**
 * Formata CEP: 12345-678
 */
function formatCep(?string $cep): string {
    if (empty($cep)) return '-';
    $d = preg_replace('/\D/', '', $cep);
    if (strlen($d) === 8) {
        return substr($d,0,5) . '-' . substr($d,5,3);
    }
    return $cep;
}

/**
 * Paginação simples.
 * Retorna ['items' => array slice, 'total' => int, 'pages' => int,
 *          'page' => int, 'perPage' => int, 'html' => string]
 *
 * Uso:
 *   $pag = paginate($allRows, (int)($_GET['page']??1), 20);
 *   foreach ($pag['items'] as $row) { ... }
 *   echo $pag['html'];
 */
function paginate(array $items, int $page = 1, int $perPage = 20): array {
    $total  = count($items);
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $slice  = array_slice($items, $offset, $perPage);

    // Monta HTML da paginação
    $html = '';
    if ($pages > 1) {
        // Preserva todos os parâmetros GET exceto 'page'
        $qs = $_GET;
        $html .= '<nav class="flex items-center justify-between mt-4 text-sm">';
        $html .= '<span class="text-gray-500">'.($offset+1).'-'.min($offset+$perPage,$total).' de '.$total.'</span>';
        $html .= '<div class="flex items-center gap-1">';

        // Anterior
        if ($page > 1) {
            $qs['page'] = $page - 1;
            $html .= '<a href="?'.http_build_query($qs).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50 text-gray-700">‹</a>';
        }

        // Números
        $range = 2;
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= $range) {
                $qs['page'] = $i;
                $active = $i === $page
                    ? 'bg-industrial-blue text-white border-industrial-blue'
                    : 'border-gray-300 text-gray-700 hover:bg-gray-50';
                $html .= '<a href="?'.http_build_query($qs).'" class="px-3 py-1.5 border rounded-md '.$active.'">'.$i.'</a>';
            } elseif (abs($i - $page) === $range + 1) {
                $html .= '<span class="px-2 text-gray-400">…</span>';
            }
        }

        // Próximo
        if ($page < $pages) {
            $qs['page'] = $page + 1;
            $html .= '<a href="?'.http_build_query($qs).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50 text-gray-700">›</a>';
        }

        $html .= '</div></nav>';
    }

    return compact('slice', 'total', 'pages', 'page', 'perPage', 'html') +
           ['items' => $slice];
}

/**
 * Faz upload de um arquivo validado.
 *
 * @param array  $fileInput   $_FILES['campo']
 * @param string $type        'photo' | 'certificate'
 * @param string|null $oldPath Caminho anterior (para deletar)
 * @return array ['ok' => bool, 'path' => string|null, 'error' => string|null]
 */
function uploadFile(array $fileInput, string $type, ?string $oldPath = null): array {
    if (empty($fileInput['tmp_name']) || $fileInput['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null]; // sem arquivo — ok
    }

    if ($fileInput['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Erro no upload (código ' . $fileInput['error'] . ').'];
    }

    $config = [
        'photo' => [
            'dir'      => __DIR__ . '/../uploads/photos/',
            'maxBytes' => 3 * 1024 * 1024,        // 3 MB
            'mimes'    => ['image/jpeg','image/png','image/webp','image/gif'],
            'exts'     => ['jpg','jpeg','png','webp','gif'],
        ],
        'certificate' => [
            'dir'      => __DIR__ . '/../uploads/certificates/',
            'maxBytes' => 10 * 1024 * 1024,       // 10 MB
            'mimes'    => ['application/pdf'],
            'exts'     => ['pdf'],
        ],
    ];

    if (!isset($config[$type])) {
        return ['ok' => false, 'path' => null, 'error' => 'Tipo de upload inválido.'];
    }

    $c    = $config[$type];
    $size = $fileInput['size'];
    $ext  = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));

    // Valida tamanho
    if ($size > $c['maxBytes']) {
        $mb = round($c['maxBytes'] / 1024 / 1024);
        return ['ok' => false, 'path' => null, 'error' => "Arquivo muito grande (máximo {$mb} MB)."];
    }

    // Valida extensão
    if (!in_array($ext, $c['exts'], true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Extensão não permitida: .' . $ext];
    }

    // Valida MIME real (lê o arquivo, não confia no cliente)
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($fileInput['tmp_name']);
    if (!in_array($realMime, $c['mimes'], true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Tipo de arquivo não permitido.'];
    }

    // Gera nome único e move
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $c['dir'] . $filename;

    if (!move_uploaded_file($fileInput['tmp_name'], $destPath)) {
        return ['ok' => false, 'path' => null, 'error' => 'Falha ao salvar o arquivo. Verifique as permissões da pasta.'];
    }

    // Remove arquivo antigo se existir
    if ($oldPath) {
        $oldFull = __DIR__ . '/../' . ltrim($oldPath, '/');
        if (file_exists($oldFull)) @unlink($oldFull);
    }

    // Retorna caminho relativo (a partir da raiz do projeto)
    $relPath = 'uploads/' . $type . 's/' . $filename;
    return ['ok' => true, 'path' => $relPath, 'error' => null];
}

/**
 * Registra uma entrada no log de auditoria (versão aprimorada).
 *
 * @param string      $action      Código da ação: employee.create, cert.delete, etc.
 * @param string      $module      Módulo: colaboradores, certificados, aso, relatorios, etc.
 * @param string      $description Descrição legível: "Amanda criou o colaborador João."
 * @param string      $target      Identificador legível do alvo: "employee #42"
 * @param string      $detail      JSON legado (mantido para compatibilidade)
 * @param string|null $entityType  Tipo da entidade: employee, certificate, aso, etc.
 * @param int|null    $entityId    ID da entidade afetada
 * @param string|null $entityName  Nome legível da entidade
 * @param array|null  $oldValues   Valores antes da alteração
 * @param array|null  $newValues   Valores depois da alteração
 */
function auditLog(
    string  $action,
    string  $target      = '',
    string  $detail      = '',
    string  $module      = '',
    string  $description = '',
    ?string $entityType  = null,
    ?int    $entityId    = null,
    ?string $entityName  = null,
    ?array  $oldValues   = null,
    ?array  $newValues   = null
): void {
    try {
        $db        = getDB();
        $user      = currentUser();
        $userId    = is_array($user) ? ($user['id']         ?? null) : null;
        $companyId = is_array($user) ? ($user['company_id'] ?? null) : null;
        $userName  = is_array($user) ? ($user['name']       ?? null) : null;
        $userEmail = is_array($user) ? ($user['email']      ?? null) : null;
        $ip        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Infere módulo a partir da action se não fornecido
        if ($module === '') {
            $module = match(true) {
                str_starts_with($action, 'login')      => 'autenticacao',
                str_starts_with($action, 'logout')     => 'autenticacao',
                str_starts_with($action, 'password')   => 'autenticacao',
                str_starts_with($action, 'employee')   => 'colaboradores',
                str_starts_with($action, 'cert')       => 'certificados',
                str_starts_with($action, 'aso')        => 'aso',
                str_starts_with($action, 'export')     => 'relatorios',
                str_starts_with($action, 'import')     => 'importacao',
                str_starts_with($action, 'qr')         => 'qrcode',
                str_starts_with($action, 'settings')   => 'configuracoes',
                str_starts_with($action, 'department') => 'areas',
                str_starts_with($action, 'notify')     => 'notificacoes',
                default                                => 'sistema',
            };
        }

        $stmt = $db->prepare(
            'INSERT INTO audit_log
             (user_id, company_id, action, target, detail, ip,
              user_name, user_email, module, entity_type, entity_id,
              entity_name, description, old_values, new_values, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId, $companyId, $action, $target ?: null, $detail ?: null, $ip,
            $userName, $userEmail, $module ?: null,
            $entityType, $entityId, $entityName,
            $description ?: null,
            $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);
    } catch (\Exception $e) {
        // Não deixa o log quebrar o fluxo principal
    }
}

/**
 * Faz uma requisição autenticada à API do Asaas.
 * Retorna o array decodificado do JSON de resposta.
 */
/**
 * Renderiza a landing com URLs absolutas (BASE_URL).
 */
function renderLandingPage(): void {
    $file = __DIR__ . '/../landing.html';
    if (!is_readable($file)) {
        http_response_code(500);
        echo 'Página inicial não encontrada.';
        return;
    }

    $html = file_get_contents($file);
    $base = rtrim(BASE_URL, '/');

    $html = str_replace(
        [
            'href="index.php"',
            'href="login.php"',
        ],
        [
            'href="' . $base . '/"',
            'href="' . $base . '/login.php"',
        ],
        $html
    );

    // checkout.php e checkout.php?plan=pro etc.
    $html = preg_replace(
        '#href="checkout\.php([^"]*)"#',
        'href="' . $base . '/checkout.php$1"',
        $html
    );

    $html = str_replace('href="admin/suporte/', 'href="' . $base . '/admin/suporte/', $html);

    echo $html;
}

function asaasRequest(string $method, string $endpoint, array $body = []): array {
    $apiKey = (defined('ASAAS_ENV') && ASAAS_ENV === 'production' && defined('ASAAS_KEY_PROD'))
        ? ASAAS_KEY_PROD
        : ASAAS_KEY;

    if (empty($apiKey)) {
        error_log('[Asaas] API key nao configurada.');
        return ['errors' => [['description' => 'Gateway de pagamento nao configurado.']]];
    }

    $url = ASAAS_URL . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access_token: ' . $apiKey,
            'User-Agent: QRCertify/1.0',
        ],
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($method !== 'GET' && !empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw ?: '{}', true) ?: [];
    // Loga erros da API para debug
    if ($code >= 400) {
        error_log("[Asaas] {$method} {$endpoint} → HTTP {$code}: {$raw}");
    }
    return $decoded;
}

function buildAsaasCheckoutUrl(string $checkoutId): string {
    return 'https://asaas.com/checkoutSession/show?id=' . rawurlencode($checkoutId);
}
