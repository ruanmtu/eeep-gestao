<?php
require __DIR__ . '/../includes/app.php';
exigir('provas');

$professor = restrito_a_atribuicoes();

/* Disciplinas que o usuário pode usar */
/* v74: no bloco de provas / simulado o professor pode juntar disciplinas dos colegas (as dele aparecem primeiro) */
$disciplinas = q_todos('SELECT d.id, d.nome, d.tipo, c.nome AS curso FROM disciplinas d LEFT JOIN cursos c ON c.id = d.curso_id WHERE d.ativo = 1 ORDER BY d.tipo, c.nome, d.nome');
$minhas_disc = $professor ? array_map('intval', array_column(q_todos('SELECT DISTINCT a.disciplina_id FROM atribuicoes a JOIN turmas t ON t.id = a.turma_id WHERE a.usuario_id = ? AND t.ano_letivo = ?', [uid(), ANO_LETIVO]), 'disciplina_id')) : [];
$ids_disc = array_map('intval', array_column($disciplinas, 'id'));

/* Criar prova (uma disciplina ou um bloco com várias) */
if (eh_post() && ($_POST['acao'] ?? '') === 'criar') {
    csrf_verificar();
    $titulo = trim((string)$_POST['titulo']);
    $serie = (int)$_POST['serie'];
    $bim = (int)$_POST['bimestre'];
    $nalt = (int)$_POST['num_alternativas'] === 4 ? 4 : 5;
    $data = (string)($_POST['data_aplicacao'] ?? '');
    $h_ini = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['hora_inicio'] ?? '')) ? $_POST['hora_inicio'] : '';
    $h_fim = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['hora_fim'] ?? '')) ? $_POST['hora_fim'] : '';
    $aplicador = mb_substr(trim((string)($_POST['aplicador'] ?? '')), 0, 160);
    $nomes_disc = array_column($disciplinas, 'nome', 'id');
    $partes = [];
    foreach ((array)($_POST['parte_disc'] ?? []) as $i => $d) {
        $d = (int)$d; $nq = (int)($_POST['parte_n'][$i] ?? 0);
        $val = (float)str_replace(',', '.', (string)($_POST['parte_valor'][$i] ?? '10'));
        if (!$d && !$nq) continue;
        $partes[] = [$d, $nq, $val];
    }
    $total = array_sum(array_column($partes, 1));
    $erro = null;
    if (mb_strlen($titulo) < 3) $erro = 'Dê um título à prova.';
    elseif ($serie < 1 || $serie > 3 || $bim < 1 || $bim > 4) $erro = 'Confira a série e o bimestre.';
    elseif (!$partes) $erro = 'Escolha pelo menos uma disciplina.';
    elseif (!$h_ini || !$h_fim) $erro = 'Informe a duração da prova: horário de início e de término.';
    elseif ($h_fim <= $h_ini) $erro = 'O horário de término precisa ser depois do início.';
    else foreach ($partes as [$d, $nq, $val]) {
        if (!in_array($d, $ids_disc, true)) { $erro = 'Escolha a disciplina de cada linha.'; break; }
        if ($nq < 1) { $erro = 'Informe quantas questões tem cada disciplina.'; break; }
        if ($val <= 0 || $val > 100) { $erro = 'O valor de cada disciplina vai de 0,1 a 100.'; break; }
    }
    if (!$erro && $total > OMR_MAX_QUESTOES) $erro = 'A folha comporta até ' . OMR_MAX_QUESTOES . " questões (o bloco tem $total).";
    if ($erro) {
        flash('warning', $erro);
    } else {
        q('INSERT INTO provas (titulo, disciplina_id, serie, ano_letivo, bimestre, data_aplicacao, hora_inicio, hora_fim, aplicador, modelo_folha, num_questoes, num_alternativas, valor, criado_por)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$titulo, $partes[0][0], $serie, ANO_LETIVO, $bim, data_valida($data) ? $data : null, $h_ini . ':00', $h_fim . ':00', $aplicador ?: null, OMR_MODELO_ATUAL,
           $total, $nalt, count($partes) === 1 ? $partes[0][2] : 10, uid()]);
        $id = (int)db()->lastInsertId();
        foreach ($partes as $o => [$d, $nq, $val]) q('INSERT INTO prova_partes (prova_id, ordem, disciplina_id, nome, num_questoes, valor) VALUES (?, ?, ?, ?, ?, ?)', [$id, $o + 1, $d, $nomes_disc[$d], $nq, $val]);
        prova_acesso_gravar($id, (string)($_POST['visibilidade'] ?? 'todos'), (array)($_POST['acesso'] ?? []));
        if (($_POST['visibilidade'] ?? '') === 'selecionados' && ($quem = prova_acesso_lista($id)))
            notificar(['usuarios' => $quem], 'Gabarito compartilhado: ' . $titulo, primeiro_ultimo(usuario()['nome']) . ' deixou você ver, editar e corrigir esta prova.', 'aviso', 'prova.php?id=' . $id);
        registrar_log('prova_criada', $titulo);
        flash('success', 'Prova criada. Agora marque as respostas certas do gabarito.');
        redirecionar('prova.php?id=' . $id);
    }
    redirecionar('provas.php');
}

$w = 'p.ano_letivo = ?'; $p = [ANO_LETIVO];
[$wv, $pv] = provas_filtro_visiveis(); $w .= ' AND ' . $wv; $p = array_merge($p, $pv);
$provas = q_todos("SELECT p.*, d.nome AS disciplina, u.nome AS autor,
                          (SELECT COUNT(*) FROM prova_resultados r WHERE r.prova_id = p.id) AS corrigidas,
                          (SELECT COUNT(*) FROM alunos a JOIN turmas t ON t.id = a.turma_id
                            WHERE a.situacao = 'ativo' AND t.serie = p.serie AND t.ano_letivo = p.ano_letivo
                              AND (d.curso_id IS NULL OR t.curso_id = d.curso_id)) AS alunos
                     FROM provas p JOIN disciplinas d ON d.id = p.disciplina_id LEFT JOIN usuarios u ON u.id = p.criado_por
                    WHERE $w ORDER BY p.criado_em DESC, p.id DESC", $p);
$partes_de = [];
foreach (q_todos('SELECT prova_id, nome FROM prova_partes ORDER BY prova_id, ordem') as $pp) $partes_de[(int)$pp['prova_id']][] = $pp['nome'];

$titulo = 'Correção de provas';
$subtitulo = 'Cadastre a prova ou o bloco de provas, imprima as folhas de resposta e corrija com a câmera do celular.';
$menu = 'provas';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="pg-acoes"><?= janela_botao('novaProva', 'Nova prova', 'file-earmark-plus') ?></div>
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h2>Provas de <?= ANO_LETIVO ?></h2><span class="etiqueta cinza"><?= count($provas) ?></span></div>
            <?php if ($provas): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th style="min-width:240px">Prova</th><th>Série / bim.</th><th class="num">Questões</th><th>Gabarito</th><th class="num">Corrigidas</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($provas as $pr): $pronto = $pr['gabarito'] && strlen($pr['gabarito']) === (int)$pr['num_questoes'] && !str_contains($pr['gabarito'], '-'); ?>
                    <tr>
                        <td><a class="nome-link" href="prova.php?id=<?= $pr['id'] ?>"><?= e($pr['titulo']) ?></a><br>
                            <?php if (count($partes_de[(int)$pr['id']] ?? []) > 1): ?><span class="etiqueta azul mt-1"><i class="bi bi-collection me-1"></i>Bloco · <?= count($partes_de[(int)$pr['id']]) ?> disciplinas</span><br><small class="text-secondary"><?= e(implode(' · ', $partes_de[(int)$pr['id']])) ?></small><?php else: ?><small class="text-secondary"><?= e($pr['disciplina']) ?></small><?php endif; ?><br><small class="text-secondary"><i class="bi bi-person me-1"></i><?= e($pr['autor'] ?? '—') ?></small>
                            <?php [$vr, $vi] = VISIBILIDADE_PROVA[$pr['visibilidade'] ?? 'todos'] ?? VISIBILIDADE_PROVA['todos']; ?><small class="pv-selo pv-<?= e($pr['visibilidade'] ?? 'todos') ?>" title="Quem vê este gabarito"><i class="bi bi-<?= $vi ?>"></i><?= e($vr) ?></small></td>
                        <td class="text-nowrap"><?= $pr['serie'] ?>º ano · <?= $pr['bimestre'] ?>º bim.</td>
                        <td class="num"><?= $pr['num_questoes'] ?></td>
                        <td><?= $pronto ? '<span class="etiqueta">Pronto</span>' : '<span class="etiqueta ambar">Falta preencher</span>' ?></td>
                        <td class="num"><?= $pr['corrigidas'] ?> <small class="text-secondary">/ <?= $pr['alunos'] ?></small></td>
                        <td class="td-acoes">
                            <a class="btn btn-sm btn-light" href="prova.php?id=<?= $pr['id'] ?>">Abrir</a>
                            <?php if ($pronto): ?><a class="btn btn-sm btn-primary" href="prova_corrigir.php?id=<?= $pr['id'] ?>"><i class="bi bi-camera me-1"></i>Corrigir</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <div class="vazio"><i class="bi bi-ui-checks-grid"></i><strong>Nenhuma prova cadastrada</strong>Crie a primeira prova no formulário ao lado.</div>
            <?php endif; ?>
        </div>

        <div class="card mt-3"><div class="card-body">
            <h3 class="h6 mb-3">Como funciona</h3>
            <ol class="lista-passos mb-0">
                <li><strong>Crie a prova</strong> e marque as respostas certas do gabarito.</li>
                <li><strong>Imprima as folhas de resposta</strong> da turma. Cada folha já sai com o nome do aluno e um código que o sistema reconhece.</li>
                <li><strong>Depois da prova, abra "Corrigir"</strong> no celular e aponte a câmera para cada folha. O sistema reconhece o aluno pelo QR code, lê as marcações e salva a nota sozinho. Folhas de outro modelo também podem ser corrigidas.</li>
                <li><strong>Confira e salve.</strong> No final, lance as notas no diário com um clique.</li>
            </ol>
        </div></div>
    </div>

    </div>
</div>

<?= janela_abre('novaProva', 'Nova prova', 'file-earmark-plus') ?>
        <div class="card">
            <div class="card-header"><h2>Nova prova</h2></div>
            <div class="card-body">
            <?php if (!$disciplinas): ?>
                <p class="mb-0">Você ainda não tem disciplinas atribuídas. Peça à coordenação para fazer isso em "Cursos e turmas › Atribuições".</p>
            <?php else: ?>
                <form method="post" class="row g-3">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="criar">
                    <div class="col-12"><label class="form-label obrigatorio" for="p_tit">Título da prova</label>
                        <input class="form-control" id="p_tit" name="titulo" required maxlength="120" placeholder="Ex.: Simulado CREDE 12 – 2026.3"></div>
                    <div class="col-12">
                        <label class="form-label obrigatorio">Disciplinas e questões</label>
                        <div class="partes-lista" id="partes"></div>
                        <button class="btn btn-sm btn-light mt-2" type="button" id="maisParte"><i class="bi bi-plus-lg me-1"></i>Adicionar disciplina (bloco de provas)</button>
                        <div class="form-text" id="totalPartes"></div>
                    </div>
                    <div class="col-6"><label class="form-label obrigatorio" for="p_serie">Série</label>
                        <select class="form-select" id="p_serie" name="serie"><option value="1">1º ano</option><option value="2">2º ano</option><option value="3">3º ano</option></select></div>
                    <div class="col-6"><label class="form-label obrigatorio" for="p_bim">Bimestre</label>
                        <select class="form-select" id="p_bim" name="bimestre"><?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>"><?= $b ?>º</option><?php endfor; ?></select></div>
                    <div class="col-6"><label class="form-label obrigatorio" for="p_nalt">Alternativas</label>
                        <select class="form-select" id="p_nalt" name="num_alternativas"><option value="5">A a E</option><option value="4">A a D</option></select></div>
                    <div class="col-6"><label class="form-label" for="p_data">Data</label>
                        <input class="form-control" type="date" id="p_data" name="data_aplicacao"></div>
                    <div class="col-12"><label class="form-label obrigatorio">Duração da prova</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="small text-secondary">das</span><input class="form-control" type="time" name="hora_inicio" required aria-label="Início" value="07:30">
                            <span class="small text-secondary">às</span><input class="form-control" type="time" name="hora_fim" required aria-label="Término" value="09:30"></div>
                        <div class="form-text">Sai impressa nas instruções da folha de respostas.</div></div>
                    <div class="col-12"><label class="form-label" for="p_apl">Professor(es) aplicador(es) <span class="text-secondary fw-normal">· opcional</span></label>
                        <input class="form-control" id="p_apl" name="aplicador" maxlength="160" placeholder="Deixe em branco para preencher à mão na folha"></div>
                    <template id="modeloParte">
                        <div class="parte-linha">
                            <span class="parte-cor"></span>
                            <select class="form-select form-select-sm" name="parte_disc[]" aria-label="Disciplina" required>
                                <option value="">Escolha a disciplina</option>
                                <?php $op_d = fn($d) => '<option value="' . $d['id'] . '">' . e($d['nome']) . (!empty($d['curso']) ? ' (' . e($d['curso']) . ')' : '') . '</option>';
                                if ($minhas_disc): ?><optgroup label="Minhas disciplinas"><?php foreach ($disciplinas as $d) if (in_array((int)$d['id'], $minhas_disc, true)) echo $op_d($d); ?></optgroup>
                                <optgroup label="Outras disciplinas (bloco ou simulado)"><?php foreach ($disciplinas as $d) if (!in_array((int)$d['id'], $minhas_disc, true)) echo $op_d($d); ?></optgroup>
                                <?php else: foreach ($disciplinas as $d) echo $op_d($d); endif; ?>
                            </select>
                            <button class="btn btn-sm btn-light parte-tirar" type="button" aria-label="Tirar disciplina"><i class="bi bi-x-lg"></i></button>
                            <span></span>
                            <div class="parte-num">
                                <label>Questões <input class="form-control form-control-sm" type="number" name="parte_n[]" min="1" max="<?= OMR_MAX_QUESTOES ?>" value="10"></label>
                                <label>Vale <input class="form-control form-control-sm" name="parte_valor[]" value="10" inputmode="decimal"></label>
                            </div>
                        </div>
                    </template>
                    <div class="col-12"><label class="form-label obrigatorio">Quem pode ver este gabarito</label>
                        <?= prova_visibilidade_campos('todos') ?>
                        <div class="form-text">Num bloco de provas ou simulado, um gabarito só serve para todos: quem você escolher vê, edita e corrige.</div></div>
                    <div class="col-12"><button class="btn btn-primary w-100" type="submit">Criar e preencher gabarito</button>
                        <div class="form-text">A prova vale para todas as turmas da série<?= $professor ? ' que você leciona' : '' ?>.</div></div>
                </form>
            <?php endif; ?>
            </div>
        </div>
<?= janela_fecha() ?>
<?php
$script_pagina = '<script>
(function () {
  const lista = document.getElementById("partes"), modelo = document.getElementById("modeloParte"); if (!lista) return;
  const CORES = ' . json_encode(CORES_PARTES) . ', MAX = ' . OMR_MAX_QUESTOES . ';
  function atualizar() {
    const linhas = [...lista.children]; let total = 0, ini = 1;
    linhas.forEach((l, i) => {
      const n = +l.querySelector("[name=\'parte_n[]\']").value || 0;
      l.querySelector(".parte-cor").style.background = CORES[i % CORES.length];
      l.querySelector(".parte-cor").textContent = n > 0 ? ini + "–" + (ini + n - 1) : "";
      l.querySelector(".parte-tirar").hidden = linhas.length === 1;
      ini += n; total += n;
    });
    const t = document.getElementById("totalPartes");
    t.innerHTML = (linhas.length > 1 ? "<b>Bloco de provas</b>: uma folha para " + linhas.length + " disciplinas · " : "") + total + " questões no total" + (total > MAX ? " <span class=\"text-danger\">(máximo " + MAX + ")</span>" : "") + ". Questões por disciplina, valor = nota máxima.";
  }
  function nova() { lista.appendChild(modelo.content.cloneNode(true)); atualizar(); }
  document.getElementById("maisParte").addEventListener("click", () => { nova(); lista.lastElementChild.querySelector("select").focus(); });
  lista.addEventListener("input", atualizar);
  lista.addEventListener("click", e => { const b = e.target.closest(".parte-tirar"); if (b) { b.closest(".parte-linha").remove(); atualizar(); } });
  nova();
})();
</script>';
$script_pagina .= prova_visibilidade_script();
require __DIR__ . '/../includes/layout_rodape.php';
