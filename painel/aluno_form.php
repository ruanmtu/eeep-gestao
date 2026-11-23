<?php
require __DIR__ . '/../includes/app.php';
exigir('alunos.editar');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$aluno = $id ? aluno_buscar($id) : null;
if ($id && !$aluno) { flash('warning', 'Aluno não encontrado.'); redirecionar('alunos.php'); }

/* v59 · Ficha simplificada: nome, turma, foto, contato do responsável e observação médica.
   Os outros dados que já existirem no banco ficam guardados (não são apagados). */
$campos = ['nome', 'turma_id', 'responsavel_nome', 'responsavel_parentesco', 'responsavel_telefone', 'tem_obs_medica', 'obs_medica'];
$d = $aluno ?: ['situacao' => 'ativo', 'turma_id' => (int)($_GET['turma'] ?? 0) ?: null, 'tem_obs_medica' => 0];
$d['tem_obs_medica'] = (int)($d['tem_obs_medica'] ?? 0);
$erros = [];

if (eh_post()) {
    csrf_verificar();
    foreach ($campos as $c) $d[$c] = trim((string)($_POST[$c] ?? ''));
    $d['nome'] = preg_replace('/\s+/', ' ', mb_strtoupper($d['nome']));
    $d['tem_obs_medica'] = ($_POST['tem_obs_medica'] ?? '0') === '1' ? 1 : 0;
    if (!$d['tem_obs_medica']) $d['obs_medica'] = '';
    if ($aluno && isset(SITUACOES[$_POST['situacao'] ?? ''])) $d['situacao'] = $_POST['situacao'];

    if (mb_strlen($d['nome']) < 5) $erros['nome'] = 'Informe o nome completo.';
    if (!$d['turma_id'] || !q_valor('SELECT id FROM turmas WHERE id = ?', [$d['turma_id']])) $erros['turma_id'] = 'Escolha a turma.';
    if ($d['responsavel_telefone'] !== '' && strlen(preg_replace('/\D/', '', $d['responsavel_telefone'])) < 10) $erros['responsavel_telefone'] = 'Informe o telefone com DDD.';
    if ($d['tem_obs_medica'] && mb_strlen($d['obs_medica']) < 3) $erros['obs_medica'] = 'Escreva qual é a observação médica.';

    if (!$erros) {
        $valores = array_map(fn($c) => $d[$c] === '' ? null : $d[$c], $campos);
        $valores[array_search('tem_obs_medica', $campos, true)] = $d['tem_obs_medica'];
        try {
            if ($aluno) {
                q('UPDATE alunos SET ' . implode(', ', array_map(fn($c) => "$c = ?", $campos)) . ', situacao = ?, atualizado_em = ? WHERE id = ?',
                  [...$valores, $d['situacao'] ?? $aluno['situacao'], date('Y-m-d H:i:s'), $id]);
                registrar_log('aluno_editado', 'Ficha atualizada', $id);
                flash('success', 'Ficha de ' . nome_proprio($d['nome']) . ' atualizada.');
            } else {
                q('INSERT INTO alunos (' . implode(', ', $campos) . ', situacao, data_matricula) VALUES (' . implode(', ', array_fill(0, count($campos) + 2, '?')) . ')', [...$valores, 'ativo', date('Y-m-d')]);
                $id = (int)db()->lastInsertId();
                registrar_log('aluno_cadastrado', 'Aluno inserido', $id);
                flash('success', nome_proprio($d['nome']) . ' foi inserido(a) na turma.');
            }
            aluno_turmas_sincronizar();   // v75: guarda a turma do ano
        } catch (PDOException $e) {
            flash('warning', 'Falta atualizar o banco para a ficha nova (observação médica). Rode atualizacao_banco_v59.sql.');
            redirecionar('aluno_form.php' . ($aluno ? '?id=' . $id : ''));
        }
        if (!empty($_FILES['foto']['name'])) {
            try { q('UPDATE alunos SET foto = ? WHERE id = ?', [foto_salvar($_FILES['foto'], 'alunos', $id, $aluno['foto'] ?? null), $id]); }
            catch (RuntimeException $e) { flash('warning', 'Os dados foram salvos, mas a foto não: ' . $e->getMessage()); }
        }
        if (!$aluno && !empty($_POST['continuar'])) redirecionar('aluno_form.php?turma=' . (int)$d['turma_id']);
        redirecionar('aluno.php?id=' . $id);
    }
}

function campo(string $nome, string $rotulo, array $d, array $erros, string $tipo = 'text', array $extra = []): string
{
    $obrig = !empty($extra['obrigatorio']);
    $h = '<label class="form-label' . ($obrig ? ' obrigatorio' : '') . '" for="c_' . $nome . '">' . e($rotulo) . '</label>';
    $h .= '<input type="' . $tipo . '" class="form-control' . (isset($erros[$nome]) ? ' is-invalid' : '') . '" id="c_' . $nome . '" name="' . $nome . '" value="' . e($d[$nome] ?? '') . '"'
        . ($obrig ? ' required' : '') . (isset($extra['max']) ? ' maxlength="' . $extra['max'] . '"' : '')
        . (isset($extra['ph']) ? ' placeholder="' . e($extra['ph']) . '"' : '') . (isset($extra['auto']) ? ' autocomplete="' . $extra['auto'] . '"' : '') . '>';
    if (isset($erros[$nome])) $h .= '<div class="invalid-feedback">' . e($erros[$nome]) . '</div>';
    return $h;
}

$titulo = $aluno ? 'Editar aluno' : 'Inserir aluno';
$menu = $aluno ? 'alunos' : 'aluno_novo';
require __DIR__ . '/../includes/layout_topo.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Revise os campos destacados abaixo.</div>
<?php endif; ?>

<form method="post" class="card fa-ficha" enctype="multipart/form-data" novalidate>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="fa-topo">
        <label class="fa-foto" for="c_foto" title="<?= !empty($aluno['foto']) ? 'Trocar foto' : 'Adicionar foto' ?>">
            <span id="previaFoto"><?= avatar($aluno ?: ['nome' => ($d['nome'] ?? '') ?: '?'], 'aluno', 'avatar-lg') ?></span>
            <span class="fa-cam"><i class="bi bi-camera-fill"></i></span>
        </label>
        <input type="file" class="d-none" id="c_foto" name="foto" data-recorte="1:1" data-saida="800" data-recorte-titulo="Enquadrar a foto do aluno" accept="image/*">
        <div>
            <h2><?= $aluno ? e(nome_proprio($aluno['nome'])) : 'Novo aluno' ?></h2>
            <p>Toque na foto para tirar ou escolher uma imagem. Os campos com * são obrigatórios.</p>
        </div>
    </div>
    <div class="card-body">
        <div class="fa-bloco">
            <span class="fa-n">1</span>
            <div class="row g-3 flex-grow-1">
                <div class="col-md-7"><?= campo('nome', 'Nome completo do aluno', $d, $erros, 'text', ['obrigatorio' => 1, 'max' => 150, 'auto' => 'off']) ?></div>
                <div class="col-md-5">
                    <label class="form-label obrigatorio" for="c_turma">Turma</label>
                    <select class="form-select<?= isset($erros['turma_id']) ? ' is-invalid' : '' ?>" id="c_turma" name="turma_id" required>
                        <option value="">Selecione</option>
                        <?php foreach (turmas_lista(false) as $t): ?>
                            <option value="<?= $t['id'] ?>"<?= (int)($d['turma_id'] ?? 0) === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?><?= $t['ativo'] ? '' : ' (inativa)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($erros['turma_id'])): ?><div class="invalid-feedback"><?= e($erros['turma_id']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="fa-bloco">
            <span class="fa-n">2</span>
            <div class="flex-grow-1">
                <h3 class="fa-h">Contato do responsável</h3>
                <div class="row g-3">
                    <div class="col-md-5"><?= campo('responsavel_nome', 'Nome do responsável', $d, $erros, 'text', ['max' => 150]) ?></div>
                    <div class="col-sm-5 col-md-3"><?= campo('responsavel_parentesco', 'Parentesco', $d, $erros, 'text', ['max' => 40, 'ph' => 'Mãe, pai, avó…']) ?></div>
                    <div class="col-sm-7 col-md-4"><?= campo('responsavel_telefone', 'Telefone / WhatsApp', $d, $erros, 'tel', ['max' => 20, 'ph' => '(88) 90000-0000']) ?></div>
                </div>
            </div>
        </div>
        <div class="fa-bloco">
            <span class="fa-n">3</span>
            <div class="flex-grow-1">
                <h3 class="fa-h">Este aluno tem alguma observação médica?</h3>
                <div class="fa-sn" role="radiogroup" aria-label="Observação médica">
                    <label><input type="radio" name="tem_obs_medica" value="0"<?= $d['tem_obs_medica'] ? '' : ' checked' ?>><span><i class="bi bi-check-circle"></i>Não</span></label>
                    <label class="sim"><input type="radio" name="tem_obs_medica" value="1"<?= $d['tem_obs_medica'] ? ' checked' : '' ?>><span><i class="bi bi-heart-pulse"></i>Sim</span></label>
                </div>
                <div class="fa-med" id="faMed"<?= $d['tem_obs_medica'] ? '' : ' hidden' ?>>
                    <label class="form-label obrigatorio" for="c_med">Qual é a observação?</label>
                    <textarea class="form-control<?= isset($erros['obs_medica']) ? ' is-invalid' : '' ?>" id="c_med" name="obs_medica" rows="3" maxlength="2000" placeholder="Ex.: asma, usa bombinha; alergia a dipirona; diabetes tipo 1…"><?= e($d['obs_medica'] ?? '') ?></textarea>
                    <?php if (isset($erros['obs_medica'])): ?><div class="invalid-feedback"><?= e($erros['obs_medica']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($aluno): ?>
        <div class="fa-bloco fa-sit">
            <span class="fa-n"><i class="bi bi-person-gear"></i></span>
            <div><label class="form-label" for="c_sit">Situação na escola</label>
                <select class="form-select" id="c_sit" name="situacao">
                    <?php foreach (SITUACOES as $k2 => $v): ?><option value="<?= $k2 ?>"<?= ($aluno['situacao'] ?? '') === $k2 ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="barra-salvar">
        <a class="btn btn-light" href="<?= $aluno ? 'aluno.php?id=' . $id : 'alunos.php' ?>">Cancelar</a>
        <?php if (!$aluno): ?>
            <button class="btn btn-outline-primary ms-auto" type="submit" name="continuar" value="1">Inserir e cadastrar outro</button>
        <?php endif; ?>
        <button class="btn btn-primary <?= $aluno ? 'ms-auto' : '' ?>" type="submit"><i class="bi bi-check-lg me-1"></i><?= $aluno ? 'Salvar alterações' : 'Inserir aluno' ?></button>
    </div>
</form>

<?php
$script_pagina = ($script_pagina ?? '') . '<script>
document.getElementById("c_foto").addEventListener("change", e => { const a = e.target.files[0]; if (a) previaFoto.innerHTML = `<img class="avatar avatar-lg" src="${URL.createObjectURL(a)}" alt="">`; });
document.querySelectorAll("input[name=tem_obs_medica]").forEach(r => r.addEventListener("change", () => { const s = document.querySelector("input[name=tem_obs_medica]:checked").value === "1"; faMed.hidden = !s; if (s) document.getElementById("c_med").focus(); }));
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
