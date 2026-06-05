<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$canImportCsv = companyHasFeature($companyId, 'csv_import');

$asoTypeMap = [
    'admissional'      => 'admissional',
    'periodico'        => 'periodico',
    'periódico'        => 'periodico',
    'retorno_trabalho' => 'retorno_trabalho',
    'retorno ao trabalho' => 'retorno_trabalho',
    'mudanca_risco'    => 'mudanca_risco',
    'mudança de risco' => 'mudanca_risco',
    'demissional'      => 'demissional',
];
$resultMap = [
    'apto'     => 'apto',
    'inapto'   => 'inapto',
    'pendente' => 'pendente',
];

// ── Download do modelo CSV ────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_aso.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# tipo_aso aceito: admissional | periodico | retorno_trabalho | mudanca_risco | demissional'], ';');
    fputcsv($out, ['# resultado aceito: apto | inapto | pendente'], ';');
    fputcsv($out, ['cpf_funcionario','tipo_aso','data_exame','data_validade','resultado','medico','crm','observacao'], ';');
    fputcsv($out, ['123.456.789-00','periodico','10/01/2025','10/01/2026','apto','Dr. Carlos Melo','CRM-12345',''], ';');
    fputcsv($out, ['987.654.321-00','admissional','05/03/2025','05/03/2026','apto','Dra. Ana Lima','CRM-67890','Sem restrições'], ';');
    fclose($out);
    exit;
}

// ── Processamento do upload ───────────────────────────────────
$results  = null;
$errors   = [];
$imported = 0;
$skipped  = [];

function parseDateBRAso(string $d): ?string {
    $d = trim($d);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } elseif (!$canImportCsv) {
        $errors[] = 'Importação por planilha está disponível nos planos Pro, Business e Enterprise.';
    } elseif (empty($_FILES['csvfile']['tmp_name'])) {
        $errors[] = 'Nenhum arquivo enviado.';
    } else {
        $file = $_FILES['csvfile'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'])) {
            $errors[] = 'Formato inválido. Envie um arquivo .csv';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande (máximo 2 MB).';
        } else {
            $raw = file_get_contents($file['tmp_name']);
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $sep = (substr_count(explode("\n",$raw)[0]??'',';') >=
                    substr_count(explode("\n",$raw)[0]??'',',')) ? ';' : ',';
            $tmp = tmpfile(); fwrite($tmp, $raw); rewind($tmp);

            // Pula linhas de comentário que começam com #
            while (($header = fgetcsv($tmp, 0, $sep)) !== false) {
                if (!isset($header[0]) || strpos(trim($header[0]), '#') !== 0) break;
            }
            if (!$header) {
                $errors[] = 'Arquivo vazio ou cabeçalho inválido.';
            } else {
                $header = array_map(fn($h) => strtolower(trim(preg_replace('/[\s\(\)]/','', $h))), $header);

                $colMap = [
                    'cpf'       => ['cpf_funcionario','cpf'],
                    'tipo'      => ['tipo_aso','tipoaso','tipo','aso_type'],
                    'exame'     => ['data_exame','dataexame','exame','exam_date'],
                    'validade'  => ['data_validade','datavalidade','validade','expiration_date'],
                    'resultado' => ['resultado','result','result_status'],
                    'medico'    => ['medico','médico','doctor','doctor_name'],
                    'crm'       => ['crm','doctor_crm'],
                    'obs'       => ['observacao','observação','notes','obs'],
                ];

                $idx = [];
                foreach ($colMap as $field => $aliases) {
                    foreach ($aliases as $alias) {
                        $pos = array_search($alias, $header);
                        if ($pos !== false) { $idx[$field] = $pos; break; }
                    }
                }

                $missing = array_diff(['cpf','tipo','exame','validade','resultado'], array_keys($idx));
                if (!empty($missing)) {
                    $errors[] = 'Colunas obrigatórias não encontradas: ' . implode(', ', $missing) . '. Verifique o modelo.';
                } else {
                    $stmtEmp = $db->prepare(
                        'SELECT id FROM employees WHERE company_id=? AND cpf=? AND status="active" LIMIT 1'
                    );
                    $stmtIns = $db->prepare(
                        'INSERT INTO employee_asos
                           (company_id,employee_id,aso_type,exam_date,expiration_date,
                            result_status,document_status,doctor_name,doctor_crm,notes,created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                    );

                    $lineNum = 1;
                    $db->beginTransaction();
                    try {
                        while (($row = fgetcsv($tmp, 0, $sep)) !== false) {
                            $lineNum++;
                            if (count(array_filter($row)) === 0) continue;

                            $get = fn($f) => isset($idx[$f]) ? trim($row[$idx[$f]] ?? '') : '';

                            $cpfRaw    = preg_replace('/\D/', '', $get('cpf'));
                            $tipoRaw   = strtolower(trim($get('tipo')));
                            $exameDate = parseDateBRAso($get('exame'));
                            $validDate = parseDateBRAso($get('validade'));
                            $resultRaw = strtolower(trim($get('resultado')));
                            $medico    = $get('medico');
                            $crm       = $get('crm');
                            $obs       = $get('obs');

                            $tipoVal   = $asoTypeMap[$tipoRaw]   ?? null;
                            $resultVal = $resultMap[$resultRaw]  ?? null;

                            $lineErrors = [];
                            if ($cpfRaw === '')  $lineErrors[] = 'CPF vazio';
                            if (!$tipoVal)       $lineErrors[] = "tipo_aso \"{$tipoRaw}\" inválido — use: admissional, periodico, retorno_trabalho, mudanca_risco, demissional";
                            if (!$exameDate)     $lineErrors[] = 'data_exame inválida (use DD/MM/AAAA)';
                            if (!$validDate)     $lineErrors[] = 'data_validade inválida (use DD/MM/AAAA)';
                            if (!$resultVal)     $lineErrors[] = "resultado \"{$resultRaw}\" inválido — use: apto, inapto, pendente";

                            if (!empty($lineErrors)) {
                                $skipped[] = "Linha {$lineNum}: " . implode('; ', $lineErrors);
                                continue;
                            }

                            $stmtEmp->execute([$companyId, $cpfRaw]);
                            $emp = $stmtEmp->fetch();
                            if (!$emp) {
                                $skipped[] = "Linha {$lineNum}: funcionário com CPF {$cpfRaw} não encontrado ou inativo";
                                continue;
                            }

                            $today = new DateTime();
                            $expDt = new DateTime($validDate);
                            $diff  = (int)$today->diff($expDt)->format('%r%a');
                            $docStatus = $diff < 0 ? 'vencido' : ($diff <= 30 ? 'vencendo' : 'valido');

                            $stmtIns->execute([
                                $companyId, $emp['id'], $tipoVal,
                                $exameDate, $validDate,
                                $resultVal, $docStatus,
                                $medico ?: null, $crm ?: null,
                                $obs    ?: null,
                                $user['id'],
                            ]);
                            $imported++;
                        }
                        $db->commit();
                        auditLog('aso.import', "company #{$companyId}",
                                 json_encode(['imported' => $imported, 'skipped' => count($skipped)]));
                        $results = true;
                    } catch (\Exception $e) {
                        $db->rollBack();
                        $errors[] = 'Erro ao importar: ' . $e->getMessage();
                    }
                }
            }
            fclose($tmp);
        }
    }
}

$pageTitle = 'Importar ASO';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/employees.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Importar ASO via Planilha</h1>
    <p class="text-gray-500 text-sm mt-1">Importe Atestados de Saúde Ocupacional em massa a partir de um arquivo CSV.</p>
</div>

<?php if (!$canImportCsv): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
    <p class="font-semibold mb-1">Recurso disponível no plano Pro ou superior</p>
    <p>A importação por planilha não está incluída no plano Starter.
        <a href="<?= BASE_URL ?>/admin/plano.php" class="text-industrial-blue underline font-medium">Faça upgrade</a>.
    </p>
</div>
<?php else: ?>

<?php if ($results): ?>
<div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-5">
    <div class="flex items-center gap-2 text-green-800 font-semibold mb-2">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Importação concluída
    </div>
    <p class="text-green-700 text-sm">
        <strong><?= $imported ?></strong> ASO(s) importado(s) com sucesso.
        <?php if (!empty($skipped)): ?><strong><?= count($skipped) ?></strong> linha(s) ignorada(s).<?php endif; ?>
    </p>
    <?php if (!empty($skipped)): ?>
    <details class="mt-3">
        <summary class="text-sm text-red-700 cursor-pointer hover:underline font-medium">
            ⚠️ Ver linhas com erro (<?= count($skipped) ?>)
        </summary>
        <ul class="mt-2 space-y-1">
            <?php foreach ($skipped as $s): ?>
                <li class="text-xs text-red-700 bg-red-50 border border-red-100 rounded px-2 py-1.5"><?= e($s) ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
    <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <form method="POST" enctype="multipart/form-data"
              class="bg-white rounded-lg shadow-sm border p-6 space-y-5">
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
                    <p class="text-sm text-gray-600" id="dropLabel">Clique para selecionar ou arraste o arquivo aqui</p>
                    <p class="text-xs text-gray-400 mt-1">CSV, máximo 2 MB</p>
                </div>
                <input type="file" name="csvfile" id="csvfile" accept=".csv,.txt" class="hidden" required onchange="updateLabel(this)">
            </div>
            <div class="flex items-center gap-3 pt-2 border-t">
                <button type="submit"
                        class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition flex items-center gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Importar ASO
                </button>
                <a href="?template=1" class="text-sm text-industrial-blue hover:underline flex items-center gap-1">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Baixar modelo CSV
                </a>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Colunas</h3>
            <div class="space-y-2">
                <?php foreach ([
                    ['cpf_funcionario', 'CPF do colaborador',         true],
                    ['tipo_aso',        'Tipo do ASO (ver abaixo)',   true],
                    ['data_exame',      'Data do exame (DD/MM/AAAA)', true],
                    ['data_validade',   'Validade (DD/MM/AAAA)',      true],
                    ['resultado',       'apto / inapto / pendente',   true],
                    ['medico',          'Nome do médico',             false],
                    ['crm',             'CRM do médico',              false],
                    ['observacao',      'Observação interna',         false],
                ] as [$col, $desc, $req]): ?>
                <div class="flex items-center gap-2 text-xs">
                    <code class="bg-gray-100 text-gray-800 px-1.5 py-0.5 rounded font-mono"><?= $col ?></code>
                    <span class="text-gray-500"><?= $desc ?></span>
                    <span class="ml-auto <?= $req ? 'text-red-500 font-medium' : 'text-gray-400' ?>">
                        <?= $req ? 'obrig.' : 'opcional' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-2">Valores aceitos em <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">tipo_aso</code></h3>
            <div class="space-y-1">
                <?php foreach (['admissional','periodico','retorno_trabalho','mudanca_risco','demissional'] as $t): ?>
                <code class="block text-xs bg-gray-50 border rounded px-2 py-1 text-gray-700"><?= $t ?></code>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-xs text-blue-800 space-y-1">
            <p><strong>Dicas:</strong></p>
            <p>• O <strong>document_status</strong> (valido/vencendo/vencido) é calculado automaticamente</p>
            <p>• O CPF precisa bater com um colaborador ativo cadastrado</p>
            <p>• Valores de tipo e resultado fora da lista serão rejeitados com mensagem de erro</p>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function updateLabel(input) {
    const label = document.getElementById('dropLabel');
    if (input.files && input.files[0]) {
        label.textContent = input.files[0].name + ' (' + (input.files[0].size/1024).toFixed(0) + ' KB)';
        label.className = 'text-sm text-industrial-blue font-medium';
    }
}
const dz = document.getElementById('dropzone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('border-industrial-blue','bg-blue-50'); });
dz.addEventListener('dragleave', () => dz.classList.remove('border-industrial-blue','bg-blue-50'));
dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('border-industrial-blue','bg-blue-50');
    const file = e.dataTransfer.files[0];
    if (file) { const dt = new DataTransfer(); dt.items.add(file);
        document.getElementById('csvfile').files = dt.files; updateLabel(document.getElementById('csvfile')); }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
