<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// ── Download do modelo CSV ────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_funcionarios.csv"');
    // BOM UTF-8 para Excel abrir corretamente
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'nome','email','cpf','cargo','departamento','telefone',
        'cep','logradouro','numero','complemento','bairro','cidade','uf','status'
    ], ';');
    // Linha de exemplo
    fputcsv($out, [
        'João da Silva','joao@empresa.com','123.456.789-00','Eletricista','Manutenção','(11) 91234-5678',
        '01310-100','Av. Paulista','1000','Sala 5','Bela Vista','São Paulo','SP','ativo'
    ], ';');
    fclose($out);
    exit;
}

// ── Processamento do upload ───────────────────────────────────
$results   = null;
$errors    = [];
$imported  = 0;
$skipped   = [];

$canImportCsv = companyHasFeature($companyId, 'csv_import');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } elseif (!$canImportCsv) {
        $errors[] = 'Importação por planilha está disponível nos planos Pro, Business e Enterprise. Faça upgrade em Meu Plano.';
    } elseif (empty($_FILES['csvfile']['tmp_name'])) {
        $errors[] = 'Nenhum arquivo enviado.';
    } else {
        $file     = $_FILES['csvfile'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed  = ['csv', 'txt'];

        if (!in_array($ext, $allowed)) {
            $errors[] = 'Formato inválido. Envie um arquivo .csv';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande (máximo 2 MB).';
        } else {
            // Detecta separador (vírgula ou ponto-e-vírgula)
            $raw       = file_get_contents($file['tmp_name']);
            // Remove BOM se houver
            $raw       = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $separator = (substr_count(explode("\n", $raw)[0] ?? '', ';') >=
                          substr_count(explode("\n", $raw)[0] ?? '', ',')) ? ';' : ',';

            $tmpFile   = tmpfile();
            fwrite($tmpFile, $raw);
            rewind($tmpFile);

            // Lê cabeçalho
            $header = fgetcsv($tmpFile, 0, $separator);
            if (!$header) {
                $errors[] = 'Arquivo vazio ou cabeçalho inválido.';
            } else {
                // Normaliza nomes de colunas
                $header = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '', $h))), $header);

                $colMap = [
                    'nome'         => ['nome','name'],
                    'email'        => ['email','e-mail','email'],
                    'cpf'          => ['cpf'],
                    'cargo'        => ['cargo','role','funcao','função'],
                    'departamento' => ['departamento','setor','department','sector'],
                    'telefone'     => ['telefone','phone','fone','celular'],
                    'cep'          => ['cep'],
                    'logradouro'   => ['logradouro','rua','endereco','endereço','address'],
                    'numero'       => ['numero','número','num','number'],
                    'complemento'  => ['complemento','complement'],
                    'bairro'       => ['bairro','neighborhood'],
                    'cidade'       => ['cidade','city'],
                    'uf'           => ['uf','estado','state'],
                    'status'       => ['status'],
                ];

                // Mapeia índices das colunas
                $idx = [];
                foreach ($colMap as $field => $aliases) {
                    foreach ($aliases as $alias) {
                        $pos = array_search($alias, $header);
                        if ($pos !== false) { $idx[$field] = $pos; break; }
                    }
                }

                $requiredCols = ['nome','email','cpf','cargo'];
                $missing = array_diff($requiredCols, array_keys($idx));
                if (!empty($missing)) {
                    $errors[] = 'Colunas obrigatórias não encontradas: ' . implode(', ', $missing) .
                                '. Verifique o modelo.';
                } else {
                    $lineNum  = 1;
                    $stmtChk  = $db->prepare('SELECT id FROM employees WHERE (email=? OR cpf=?) AND company_id=?');
                    $stmtIns  = $db->prepare(
                        'INSERT INTO employees
                           (company_id,name,email,cpf,role,department,phone,public_hash,status,
                            cep,address,number,complement,neighborhood,city,state)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );

                    $db->beginTransaction();
                    try {
                        while (($row = fgetcsv($tmpFile, 0, $separator)) !== false) {
                            $lineNum++;
                            if (count(array_filter($row)) === 0) continue; // linha vazia

                            $get = function(string $field) use ($row, $idx): string {
                                return isset($idx[$field]) ? trim($row[$idx[$field]] ?? '') : '';
                            };

                            $name   = $get('nome');
                            $email  = strtolower($get('email'));
                            $cpf    = preg_replace('/\D/', '', $get('cpf'));
                            $role   = $get('cargo');
                            $dept   = $get('departamento');
                            $phone  = preg_replace('/\D/', '', $get('telefone'));
                            $cep    = preg_replace('/\D/', '', $get('cep'));
                            $addr   = $get('logradouro');
                            $num    = $get('numero');
                            $comp   = $get('complemento');
                            $neigh  = $get('bairro');
                            $city   = $get('cidade');
                            $state  = strtoupper($get('uf'));
                            $status = in_array(strtolower($get('status')), ['inativo','inactive']) ? 'inactive' : 'active';

                            // Validações básicas
                            $lineErrors = [];
                            if ($name === '')  $lineErrors[] = 'nome vazio';
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $lineErrors[] = 'e-mail inválido';
                            if (!validarCpf($cpf)) $lineErrors[] = 'CPF inválido';
                            if ($role === '')  $lineErrors[] = 'cargo vazio';

                            if (!empty($lineErrors)) {
                                $skipped[] = "Linha {$lineNum} ({$name}): " . implode(', ', $lineErrors);
                                continue;
                            }

                            // Verifica duplicata
                            $stmtChk->execute([$email, $cpf, $companyId]);
                            if ($stmtChk->fetch()) {
                                $skipped[] = "Linha {$lineNum} ({$name}): já existe (e-mail ou CPF duplicado)";
                                continue;
                            }

                            $capacity = checkEmployeeCapacity($companyId, 1);
                            if (!$capacity['ok']) {
                                $skipped[] = "Linha {$lineNum} ({$name}): limite do plano atingido ({$capacity['current']}/{$capacity['limit']})";
                                continue;
                            }

                            $stmtIns->execute([
                                $companyId, $name, $email, $cpf, $role,
                                $dept ?: null,
                                $phone ?: null, generateHash(), $status,
                                $cep ?: null, $addr ?: null, $num ?: null,
                                $comp ?: null, $neigh ?: null,
                                $city ?: null, $state ?: null,
                            ]);
                            $imported++;
                        }
                        $db->commit();
                        auditLog('employee.import', "company #{$companyId}",
                                 json_encode(['imported' => $imported, 'skipped' => count($skipped)]));
                        $results = true;
                    } catch (\Exception $e) {
                        $db->rollBack();
                        $errors[] = 'Erro ao importar: ' . $e->getMessage();
                    }
                }
            }
            fclose($tmpFile);
        }
    }
}

$pageTitle = 'Importar Funcionários';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/employees.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Funcionários
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Importar Funcionários via Planilha</h1>
    <p class="text-gray-500 text-sm mt-1">Importe vários funcionários de uma vez a partir de um arquivo CSV.</p>
</div>

<?php if (!$canImportCsv): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
    <p class="font-semibold mb-1">Recurso disponível no plano Pro ou superior</p>
    <p>A importação por planilha não está incluída no plano Starter.
        <a href="<?= BASE_URL ?>/admin/plano.php" class="text-industrial-blue underline font-medium">Faça upgrade</a> para desbloquear.
    </p>
</div>
<?php else: ?>

<!-- Resultado da importação -->
<?php if ($results): ?>
    <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-5">
        <div class="flex items-center gap-2 text-green-800 font-semibold mb-2">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Importação concluída
        </div>
        <p class="text-green-700 text-sm">
            <strong><?= $imported ?></strong> funcionário(s) importado(s) com sucesso.
            <?php if (!empty($skipped)): ?>
                <strong><?= count($skipped) ?></strong> linha(s) ignorada(s).
            <?php endif; ?>
        </p>
        <?php if (!empty($skipped)): ?>
            <details class="mt-3">
                <summary class="text-sm text-green-700 cursor-pointer hover:underline">Ver linhas ignoradas (<?= count($skipped) ?>)</summary>
                <ul class="mt-2 space-y-1">
                    <?php foreach ($skipped as $s): ?>
                        <li class="text-xs text-amber-700 bg-amber-50 rounded px-2 py-1"><?= e($s) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/employees.php"
           class="mt-3 inline-block text-sm text-industrial-blue hover:underline font-medium">
            Ver lista de funcionários →
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULÁRIO DE UPLOAD -->
    <div class="lg:col-span-2">
        <form method="POST" enctype="multipart/form-data"
              class="bg-white rounded-lg shadow-sm border p-6 space-y-5" id="importForm">
            <?= csrfField() ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Arquivo CSV *</label>
                <div id="dropzone"
                     class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-industrial-blue transition"
                     onclick="document.getElementById('csvfile').click()">
                    <svg class="h-10 w-10 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p class="text-sm text-gray-600" id="dropLabel">
                        Clique para selecionar ou arraste o arquivo aqui
                    </p>
                    <p class="text-xs text-gray-400 mt-1">CSV, máximo 2 MB</p>
                </div>
                <input type="file" name="csvfile" id="csvfile" accept=".csv,.txt"
                       class="hidden" required onchange="updateLabel(this)">
            </div>

            <div class="flex items-center gap-3 pt-2 border-t">
                <button type="submit"
                        class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition flex items-center gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Importar Funcionários
                </button>
                <a href="<?= BASE_URL ?>/admin/employee-import.php?template=1"
                   class="text-sm text-industrial-blue hover:underline flex items-center gap-1">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Baixar modelo CSV
                </a>
            </div>
        </form>
    </div>

    <!-- INSTRUÇÕES -->
    <div class="space-y-4">
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Como importar</h3>
            <ol class="space-y-2 text-sm text-gray-600 list-decimal list-inside">
                <li>Baixe o <strong>modelo CSV</strong> ao lado</li>
                <li>Abra no Excel ou Google Sheets</li>
                <li>Preencha um funcionário por linha</li>
                <li>Salve como <strong>.csv</strong> com separador <strong>ponto-e-vírgula</strong></li>
                <li>Faça o upload aqui</li>
            </ol>
        </div>

        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Colunas obrigatórias</h3>
            <div class="space-y-1">
                <?php
                $cols = [
                    ['nome',      'Nome completo',   true],
                    ['email',     'E-mail',          true],
                    ['cpf',       'CPF (11 dígitos)', true],
                    ['cargo',     'Cargo/função',    true],
                    ['telefone',  'Telefone',        false],
                    ['cep',       'CEP',             false],
                    ['logradouro','Rua/Avenida',     false],
                    ['numero',    'Número',          false],
                    ['bairro',    'Bairro',          false],
                    ['cidade',    'Cidade',          false],
                    ['uf',        'UF (2 letras)',   false],
                    ['status',    'ativo / inativo', false],
                ];
                foreach ($cols as [$col, $desc, $req]):
                ?>
                <div class="flex items-center gap-2 text-xs">
                    <code class="bg-gray-100 text-gray-800 px-1.5 py-0.5 rounded font-mono"><?= $col ?></code>
                    <span class="text-gray-500"><?= $desc ?></span>
                    <?php if ($req): ?>
                        <span class="ml-auto text-red-500 font-medium">obrig.</span>
                    <?php else: ?>
                        <span class="ml-auto text-gray-400">opcional</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-xs text-amber-800">
            <strong>Atenção:</strong> Funcionários com e-mail ou CPF já cadastrado serão <strong>ignorados</strong>
            (não causam erro, aparecem no relatório de linhas ignoradas).
        </div>
    </div>

</div>

<?php endif; ?>

<script>
function updateLabel(input) {
    const label = document.getElementById('dropLabel');
    if (input.files && input.files[0]) {
        const name = input.files[0].name;
        const size = (input.files[0].size / 1024).toFixed(0);
        label.textContent = name + ' (' + size + ' KB)';
        label.className = 'text-sm text-industrial-blue font-medium';
    }
}
// Drag and drop
const dz = document.getElementById('dropzone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('border-industrial-blue','bg-blue-50'); });
dz.addEventListener('dragleave', () => dz.classList.remove('border-industrial-blue','bg-blue-50'));
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('border-industrial-blue','bg-blue-50');
    const file = e.dataTransfer.files[0];
    if (file) {
        const input = document.getElementById('csvfile');
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        updateLabel(input);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
