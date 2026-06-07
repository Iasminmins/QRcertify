<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$doc    = null;
$errors = [];

$docTypes = [
    'aso'                  => 'ASO – Atestado de Saúde Ocupacional',
    'nr_certificado'       => 'NR / Certificado',
    'certificado_assinado' => 'Certificado Assinado',
    'foto'                 => 'Foto',
    'pdf_geral'            => 'PDF Geral',
    'doc_word'             => 'Documento Word',
    'outro'                => 'Outro',
];

// Carrega em edição
if ($isEdit) {
    $s = $db->prepare(
        'SELECT * FROM employee_documents WHERE id = ? AND company_id = ? LIMIT 1'
    );
    $s->execute([$id, $companyId]);
    $doc = $s->fetch();
    if (!$doc) {
        setFlash('error', 'Documento não encontrado.');
        redirect('/admin/documents.php');
    }
}

// Colaboradores
$empStmt = $db->prepare(
    'SELECT id, name, role FROM employees WHERE company_id = ? AND status = "active" ORDER BY name ASC'
);
$empStmt->execute([$companyId]);
$employees = $empStmt->fetchAll();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $employeeId  = (int)($_POST['employee_id'] ?? 0);
    $docType     = trim($_POST['doc_type']    ?? 'outro');
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $issueDate   = trim($_POST['issue_date']  ?? '') ?: null;
    $expiryDate  = trim($_POST['expiry_date'] ?? '') ?: null;

    if (!$employeeId) $errors[] = 'Selecione um colaborador.';
    if (!$title)      $errors[] = 'O título é obrigatório.';
    if (!isset($docTypes[$docType])) $docType = 'outro';

    $filePath = $doc['file_path'] ?? null;
    $fileName = $doc['file_name'] ?? null;
    $fileExt  = $doc['file_ext']  ?? null;
    $fileSize = $doc['file_size'] ?? 0;

    // Upload de arquivo novo
    if (!empty($_FILES['file']['name'])) {
        $file       = $_FILES['file'];
        $origName   = $file['name'];
        $tmpPath    = $file['tmp_name'];
        $fileSize   = $file['size'];
        $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed    = ['pdf','jpg','jpeg','png','gif','webp','bmp','doc','docx'];

        if (!in_array($ext, $allowed)) {
            $errors[] = 'Tipo de arquivo não permitido. Use: PDF, JPG, PNG, DOC, DOCX.';
        } elseif ($fileSize > 20 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande. Máximo: 20 MB.';
        } else {
            $destDir  = __DIR__ . '/../uploads/documents/';
            $newName  = uniqid('doc_', true) . '.' . $ext;
            $destPath = $destDir . $newName;
            if (move_uploaded_file($tmpPath, $destPath)) {
                // Remove arquivo antigo se existir
                if ($filePath && file_exists(__DIR__ . '/../' . $filePath)) {
                    @unlink(__DIR__ . '/../' . $filePath);
                }
                $filePath = 'uploads/documents/' . $newName;
                $fileName = $origName;
                $fileExt  = $ext;
            } else {
                $errors[] = 'Erro ao salvar o arquivo. Verifique as permissões.';
            }
        }
    }

    if (empty($errors) && !$filePath && !$isEdit) {
        $errors[] = 'Selecione um arquivo para enviar.';
    }

    if (empty($errors)) {
        // Calcula status
        $status = 'sem_validade';
        if ($expiryDate) {
            $today = new DateTime();
            $exp   = new DateTime($expiryDate);
            $diff  = $today->diff($exp)->days;
            if ($exp < $today) {
                $status = 'vencido';
            } elseif ($diff <= 30) {
                $status = 'vencendo';
            } else {
                $status = 'valido';
            }
        }

        if ($isEdit) {
            $db->prepare(
                'UPDATE employee_documents
                 SET employee_id=?, doc_type=?, title=?, description=?,
                     issue_date=?, expiry_date=?, status=?,
                     file_path=?, file_name=?, file_ext=?, file_size=?,
                     updated_at=NOW()
                 WHERE id=? AND company_id=?'
            )->execute([
                $employeeId, $docType, $title, $description,
                $issueDate, $expiryDate, $status,
                $filePath, $fileName, $fileExt, $fileSize,
                $id, $companyId
            ]);
            setFlash('success', 'Documento atualizado com sucesso!');
        } else {
            $db->prepare(
                'INSERT INTO employee_documents
                 (company_id, employee_id, doc_type, title, description,
                  file_path, file_name, file_ext, file_size,
                  issue_date, expiry_date, status, uploaded_by)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)'
            )->execute([
                $companyId, $employeeId, $docType, $title, $description,
                $filePath, $fileName, $fileExt, $fileSize,
                $issueDate, $expiryDate, $status, $user['id']
            ]);
            setFlash('success', 'Documento adicionado com sucesso!');
        }
        redirect('/admin/documents.php');
    }
}

$pageTitle = $isEdit ? 'Editar Documento' : 'Adicionar Documento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Voltar -->
<div class="mb-5">
    <a href="<?= BASE_URL ?>/admin/documents.php"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Documentos
    </a>
</div>

<?php renderFlashes(); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error mb-4">
    <ul class="list-disc list-inside space-y-1">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ── Formulário principal ─────────────────────────────────────── -->
    <div class="lg:col-span-2">
        <div class="section-card">
            <div class="section-card-header">
                <h1 class="section-card-title flex items-center gap-2">
                    <svg class="h-5 w-5 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <?= $isEdit ? 'Editar Documento' : 'Adicionar Documento' ?>
                </h1>
            </div>
            <div class="section-card-body">
                <form method="POST" enctype="multipart/form-data" id="doc-form">
                    <?= csrfField() ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                        <!-- Colaborador -->
                        <div class="sm:col-span-2">
                            <label class="form-label">Colaborador <span class="text-red-500">*</span></label>
                            <select name="employee_id" id="sel-employee" class="form-select w-full" required>
                                <option value="">Selecione o colaborador...</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"
                                        <?= ($doc['employee_id'] ?? ($_GET['employee_id'] ?? 0)) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> — <?= htmlspecialchars($emp['role']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Tipo do documento -->
                        <div>
                            <label class="form-label">Tipo de Documento <span class="text-red-500">*</span></label>
                            <select name="doc_type" id="sel-type" class="form-select w-full" required>
                                <?php foreach ($docTypes as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= ($doc['doc_type'] ?? 'outro') === $k ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Título -->
                        <div>
                            <label class="form-label">Título <span class="text-red-500">*</span></label>
                            <input type="text" name="title" id="inp-title" class="form-input w-full" required
                                   placeholder="Ex: ASO Admissional 2025"
                                   value="<?= htmlspecialchars($doc['title'] ?? '') ?>">
                        </div>

                        <!-- Data de emissão -->
                        <div>
                            <label class="form-label">Data de Emissão</label>
                            <input type="date" name="issue_date" class="form-input w-full"
                                   value="<?= $doc['issue_date'] ?? '' ?>">
                        </div>

                        <!-- Data de validade -->
                        <div>
                            <label class="form-label">Data de Validade</label>
                            <input type="date" name="expiry_date" class="form-input w-full"
                                   value="<?= $doc['expiry_date'] ?? '' ?>">
                        </div>

                        <!-- Descrição -->
                        <div class="sm:col-span-2">
                            <label class="form-label">Descrição / Observações</label>
                            <textarea name="description" class="form-input w-full" rows="2"
                                      placeholder="Informações adicionais sobre o documento..."><?= htmlspecialchars($doc['description'] ?? '') ?></textarea>
                        </div>

                        <!-- Upload de arquivo -->
                        <div class="sm:col-span-2">
                            <label class="form-label">
                                Arquivo
                                <?php if (!$isEdit): ?><span class="text-red-500">*</span><?php endif; ?>
                                <span class="text-xs font-normal text-gray-400 ml-1">PDF, JPG, PNG, DOC, DOCX — máx. 20 MB</span>
                            </label>

                            <!-- Drop zone -->
                            <div id="drop-zone"
                                 class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer
                                        hover:border-industrial-blue/50 hover:bg-blue-50/30 transition"
                                 onclick="document.getElementById('file-input').click()">
                                <div id="drop-content">
                                    <svg class="h-10 w-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                    <p class="text-gray-500 font-medium">Clique ou arraste o arquivo aqui</p>
                                    <p class="text-xs text-gray-400 mt-1">PDF, JPG, PNG, DOC, DOCX</p>
                                </div>
                                <div id="drop-preview" class="hidden"></div>
                            </div>
                            <input type="file" id="file-input" name="file" class="hidden"
                                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx">

                            <?php if ($isEdit && !empty($doc['file_name'])): ?>
                            <p class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                                <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Arquivo atual: <strong><?= htmlspecialchars($doc['file_name']) ?></strong>
                                · Selecione outro para substituir.
                            </p>
                            <?php endif; ?>
                        </div>

                    </div><!-- /grid -->

                    <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
                        <button type="submit" class="btn btn-primary">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <?= $isEdit ? 'Salvar Alterações' : 'Salvar Documento' ?>
                        </button>
                        <a href="<?= BASE_URL ?>/admin/documents.php" class="btn btn-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Painel lateral: leitura inteligente ──────────────────────── -->
    <div>
        <div class="section-card sticky top-4">
            <div class="section-card-header">
                <h2 class="section-card-title flex items-center gap-2">
                    <svg class="h-5 w-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                    Leitura Inteligente
                </h2>
            </div>
            <div class="section-card-body space-y-3">
                <p class="text-sm text-gray-500">
                    Envie o arquivo e clique em <strong>Ler Documento</strong> para extrair dados automaticamente (ASO, NR, certificados).
                </p>
                <button type="button" id="btn-read"
                        class="btn btn-secondary w-full" disabled
                        onclick="readDocument()">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    Ler Documento
                </button>

                <div id="ocr-loading" class="hidden text-center py-4">
                    <div class="inline-flex items-center gap-2 text-sm text-gray-500">
                        <svg class="animate-spin h-4 w-4 text-industrial-blue" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Analisando documento...
                    </div>
                </div>

                <div id="ocr-result" class="hidden">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800 mb-3">
                        ✅ Dados extraídos! Campos preenchidos automaticamente.
                    </div>
                    <div id="ocr-fields" class="space-y-2 text-xs text-gray-600"></div>
                </div>

                <div id="ocr-error" class="hidden">
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
                        ⚠️ Não foi possível extrair dados automaticamente. Preencha manualmente.
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /grid -->

<script>
// ── Drop Zone ──────────────────────────────────────────────────────────
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const btnRead   = document.getElementById('btn-read');
let   selectedFile = null;

fileInput.addEventListener('change', function() {
    if (this.files[0]) handleFile(this.files[0]);
});

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-industrial-blue','bg-blue-50'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-industrial-blue','bg-blue-50'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('border-industrial-blue','bg-blue-50');
    const f = e.dataTransfer.files[0];
    if (f) { fileInput.files = e.dataTransfer.files; handleFile(f); }
});

function handleFile(file) {
    selectedFile = file;
    const ext    = file.name.split('.').pop().toLowerCase();
    const isImg  = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const content = document.getElementById('drop-content');
    const preview = document.getElementById('drop-preview');
    content.classList.add('hidden');
    preview.classList.remove('hidden');

    const size = file.size < 1024*1024
        ? (file.size/1024).toFixed(1)+' KB'
        : (file.size/1024/1024).toFixed(1)+' MB';

    if (isImg) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = `
                <img src="${e.target.result}" class="max-h-32 mx-auto rounded-lg shadow mb-2 object-contain">
                <p class="text-sm text-gray-600 font-medium">${file.name}</p>
                <p class="text-xs text-gray-400">${size}</p>`;
        };
        reader.readAsDataURL(file);
    } else {
        const icons = {pdf:'📄', doc:'📝', docx:'📝'};
        preview.innerHTML = `
            <div class="text-4xl mb-2">${icons[ext]||'📎'}</div>
            <p class="text-sm text-gray-700 font-semibold">${file.name}</p>
            <p class="text-xs text-gray-400">${size}</p>`;
    }

    // Habilita leitura inteligente para imagens e PDFs
    if (['jpg','jpeg','png','gif','webp','pdf'].includes(ext)) {
        btnRead.disabled = false;
        btnRead.classList.remove('opacity-50');
    }

    // Auto-preenche título se vazio
    const inp = document.getElementById('inp-title');
    if (!inp.value) {
        inp.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]/g,' ');
    }
}

// ── Leitura Inteligente via IA ─────────────────────────────────────────
async function readDocument() {
    if (!selectedFile) return;

    document.getElementById('ocr-loading').classList.remove('hidden');
    document.getElementById('ocr-result').classList.add('hidden');
    document.getElementById('ocr-error').classList.add('hidden');
    btnRead.disabled = true;

    try {
        // Converte arquivo para base64
        const base64 = await fileToBase64(selectedFile);
        const ext     = selectedFile.name.split('.').pop().toLowerCase();
        const isPdf   = ext === 'pdf';

        // Monta o conteúdo para a API
        let msgContent = [];
        if (isPdf) {
            msgContent.push({
                type: 'document',
                source: { type: 'base64', media_type: 'application/pdf', data: base64 }
            });
        } else {
            const mt = ext === 'png' ? 'image/png' : 'image/jpeg';
            msgContent.push({
                type: 'image',
                source: { type: 'base64', media_type: mt, data: base64 }
            });
        }

        msgContent.push({
            type: 'text',
            text: `Analise este documento de segurança do trabalho (ASO, NR, certificado, etc).
Extraia as seguintes informações em JSON puro (sem markdown):
{
  "tipo_documento": "aso|nr_certificado|certificado_assinado|foto|pdf_geral|outro",
  "titulo_sugerido": "string",
  "nome_colaborador": "string ou null",
  "cpf_colaborador": "string ou null",
  "cargo_colaborador": "string ou null",
  "data_emissao": "YYYY-MM-DD ou null",
  "data_validade": "YYYY-MM-DD ou null",
  "medico_nome": "string ou null",
  "medico_crm": "string ou null",
  "resultado": "apto|inapto|null",
  "tipo_aso": "admissional|periodico|retorno_trabalho|mudanca_risco|demissional|null",
  "nr_numero": "string ou null (ex: NR-35)",
  "observacoes": "string ou null"
}
Retorne APENAS o JSON, sem texto extra.`
        });

        const resp = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 1000,
                messages: [{ role: 'user', content: msgContent }]
            })
        });

        const data = await resp.json();
        const text = (data.content || []).map(b => b.text || '').join('');

        let parsed;
        try {
            parsed = JSON.parse(text.replace(/```json|```/g, '').trim());
        } catch(e) {
            throw new Error('JSON inválido');
        }

        applyOcrData(parsed);

    } catch(e) {
        console.error(e);
        document.getElementById('ocr-error').classList.remove('hidden');
    } finally {
        document.getElementById('ocr-loading').classList.add('hidden');
        btnRead.disabled = false;
    }
}

function applyOcrData(data) {
    const mapType = {
        'aso':'aso','nr_certificado':'nr_certificado',
        'certificado_assinado':'certificado_assinado',
        'foto':'foto','pdf_geral':'pdf_geral','doc_word':'doc_word','outro':'outro'
    };

    if (data.tipo_documento && mapType[data.tipo_documento]) {
        document.getElementById('sel-type').value = mapType[data.tipo_documento];
    }
    if (data.titulo_sugerido) {
        document.getElementById('inp-title').value = data.titulo_sugerido;
    }
    if (data.data_emissao) {
        document.querySelector('[name=issue_date]').value = data.data_emissao;
    }
    if (data.data_validade) {
        document.querySelector('[name=expiry_date]').value = data.data_validade;
    }
    if (data.observacoes) {
        document.querySelector('[name=description]').value = data.observacoes;
    }

    // Tenta selecionar colaborador por nome (busca parcial)
    if (data.nome_colaborador) {
        const sel   = document.getElementById('sel-employee');
        const name  = data.nome_colaborador.toLowerCase();
        for (const opt of sel.options) {
            if (opt.text.toLowerCase().includes(name)) {
                sel.value = opt.value;
                break;
            }
        }
    }

    // Exibe campos extraídos
    const fields = document.getElementById('ocr-fields');
    const rows = [];
    if (data.nome_colaborador) rows.push(['Colaborador', data.nome_colaborador]);
    if (data.data_emissao)     rows.push(['Emissão', data.data_emissao]);
    if (data.data_validade)    rows.push(['Validade', data.data_validade]);
    if (data.resultado)        rows.push(['Resultado', data.resultado]);
    if (data.medico_nome)      rows.push(['Médico', data.medico_nome]);
    if (data.nr_numero)        rows.push(['NR', data.nr_numero]);
    if (data.tipo_aso)         rows.push(['Tipo ASO', data.tipo_aso]);

    fields.innerHTML = rows.map(([k,v]) =>
        `<div class="flex justify-between gap-2 py-1 border-b border-gray-100">
            <span class="font-medium text-gray-500">${k}:</span>
            <span class="text-gray-800 text-right">${v}</span>
         </div>`
    ).join('');

    document.getElementById('ocr-result').classList.remove('hidden');
}

function fileToBase64(file) {
    return new Promise((res, rej) => {
        const r = new FileReader();
        r.onload  = () => res(r.result.split(',')[1]);
        r.onerror = () => rej(new Error('Leitura falhou'));
        r.readAsDataURL(file);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
