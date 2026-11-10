<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$canImportCsv = companyHasFeature($companyId, 'csv_import');

// ── Download do modelo CSV ────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_certificados.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# Datas no formato DD/MM/AAAA — ex: 15/03/2025'], ';');
    fputcsv($out, ['cpf_funcionario','titulo','tipo','data_emissao','data_validade'], ';');
    fputcsv($out, ['123.456.789-00','NR-10 - Segurança em Instalações Elétricas','NR-10','15/01/2024','15/01/2026'], ';');
    fputcsv($out, ['987.654.321-00','NR-35 - Trabalho em Altura','NR-35','20/03/2024','20/03/2026'], ';');
    fclose($out);
    exit;
}

// ── Processamento do upload ───────────────────────────────────
$results  = null;
$errors   = [];
$imported = 0;
$skipped  = [];

function parseDateBR(string $d): ?string {
    $d = trim($d);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
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
        $file    = $_FILES['csvfile'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'])) {
            $errors[] = 'Formato inválido. Envie um arquivo .csv';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande (máximo 2 MB).';
        } else {
            $raw  = file_get_contents($file['tmp_name']);
            $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $sep  = (substr_count(explode("\n", $raw)[0] ?? '', ';') >=
                     substr_count(explode("\n", $raw)[0] ?? '', ',')) ? ';' : ',';
            $tmp  = tmpfile();
            fwrite($tmp, $raw); rewind($tmp);

            // Pula linhas de comentário que começam com #
            while (($header = fgetcsv($tmp, 0, $sep)) !== false) {
                if (!isset($header[0]) || strpos(trim($header[0]), '#') !== 0) break;
            }
            if (!$header) {
                $errors[] = 'Arquivo vazio ou cabeçalho inválido.';
            } else {
                $header = array_map(fn($h) => strtolower(trim(preg_replace('/[\s\(\)]/','', $h))), $header);

                $colMap = [
                    'cpf'     => ['cpf_funcionario','cpf','documentofuncionario'],
                    'titulo'  => ['titulo','título','title','nome','certificado'],
                    'tipo'    => ['tipo','type','nr','categoria'],
                    'emissao' => ['data_emissao','dataemissao','emissao','emissão','issued','issued_date'],
                    'validade'=> ['data_validade','datavalidade','validade','expiry','expiry_date','vencimento'],
                ];

                $idx = [];
                foreach ($colMap as $field => $aliases) {
                    foreach ($aliases as $alias) {
                        $pos = array_search($alias, $header);
                        if ($pos !== false) { $idx[$field] = $pos; break; }
                    }
                }

                $missing = array_diff(['cpf','titulo','tipo','emissao','validade'], array_keys($idx));
                if (!empty($missing)) {
                    $errors[] = 'Colunas obrigatórias não encontradas: ' . implode(', ', $missing) . '. Verifique o modelo.';
                } else {
                    $stmtEmp = $db->prepare(
                        'SELECT id FROM employees WHERE company_id=? AND cpf=? AND status="active" LIMIT 1'
                    );
                    $stmtIns = $db->prepare(
                        'INSERT INTO certificates (employee_id,title,type,issued_date,expiry_date,status,hash,created_by)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );

                    $lineNum = 1;
                    $db->beginTransaction();
                    try {
                        while (($row = fgetcsv($tmp, 0, $sep)) !== false) {
                            $lineNum++;
                            if (count(array_filter($row)) === 0) continue;

                            $get = fn($f) => isset($idx[$f]) ? trim($row[$idx[$f]] ?? '') : '';

                            $cpfRaw  = preg_replace('/\D/', '', $get('cpf'));
                            $titulo  = $get('titulo');
                            $tipo    = $get('tipo');
                            $emissao = parseDateBR($get('emissao'));
                            $validad = parseDateBR($get('validade'));

                            $lineErrors = [];
                            if ($cpfRaw === '')    $lineErrors[] = 'CPF vazio';
                            if ($titulo  === '')   $lineErrors[] = 'título vazio';
                            if ($tipo    === '')   $lineErrors[] = 'tipo vazio';
                            if (!$emissao)         $lineErrors[] = 'data de emissão inválida (use DD/MM/AAAA)';
                            if (!$validad)         $lineErrors[] = 'data de validade inválida (use DD/MM/AAAA)';

                            if (!empty($lineErrors)) {
                                $skipped[] = "Linha {$lineNum}: " . implode(', ', $lineErrors);
                                continue;
                            }

                            $stmtEmp->execute([$companyId, $cpfRaw]);
                            $emp = $stmtEmp->fetch();
                            if (!$emp) {
                                $skipped[] = "Linha {$lineNum}: funcionário com CPF {$cpfRaw} não encontrado ou inativo";
                                continue;
                            }

                            $status = getCertStatus($validad);
                            $hash   = generateHash();

                            $stmtIns->execute([$emp['id'], $titulo, $tipo, $emissao, $validad, $status, $hash, $user['id']]);
                            $imported++;
                        }
                        $db->commit();
                        auditLog('certificate.import', "company #{$companyId}",
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

$pageTitle = 'Importar Certificados';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/certificates.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Certificados
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Importar Certificados via Planilha</h1>
    <p class="text-gray-500 text-sm mt-1">Importe certificados em massa a partir de um arquivo CSV.</p>
</div>

<?php if (!$canImportCsv): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
    <p class="font-semibold mb-1">Recurso disponível no plano Pro ou superior</p>
    <p>A importação por planilha não está incluída no plano Starter.
        <a href="<?= BASE_URL ?>/admin/plano.php" class="text-industrial-blue underline font-medium">Faça upgrade</a> para desbloquear.
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
        <strong><?= $imported ?></strong> certificado(s) importado(s) com sucesso.
        <?php if (!empty($skipped)): ?>
            <strong><?= count($skipped) ?></strong> linha(s) ignorada(s).
        <?php endif; ?>
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
    <a href="<?= BASE_URL ?>/admin/certificates.php"
       class="mt-3 inline-block text-sm text-industrial-blue hover:underline font-medium">
        Ver lista de certificados →
    </a>
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
                    Importar Certificados
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
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Colunas obrigatórias</h3>
            <div class="space-y-2">
                <?php foreach ([
                    ['cpf_funcionario', 'CPF do colaborador',        true],
                    ['titulo',          'Nome completo do certificado', true],
                    ['tipo',            'Tipo: NR-10, NR-35, CIPA…', true],
                    ['data_emissao',    'Data emissão (DD/MM/AAAA)', true],
                    ['data_validade',   'Data validade (DD/MM/AAAA)',true],
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
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-xs text-blue-800 space-y-1">
            <p><strong>Dicas importantes:</strong></p>
            <p>• O <strong>CPF</strong> precisa bater exatamente com um colaborador ativo cadastrado</p>
            <p>• Datas no formato <strong>DD/MM/AAAA</strong> (ex: 15/03/2025)</p>
            <p>• O status (válido/vencendo/expirado) é calculado automaticamente</p>
            <p>• O QR Code é gerado automaticamente para cada certificado</p>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-xs text-amber-800">
            <strong>Atenção:</strong> Certificados com CPF não encontrado no sistema serão ignorados e aparecerão no relatório de erros.
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
