<?php
/* Mapa de turma: a gestão e o(a) diretor(a) de turma montam as carteiras como estão na sala
 * e colocam cada aluno no lugar; os demais professores da turma só consultam. */
require __DIR__ . '/../includes/app.php';
exigir('alunos.ver');

$opcoes = eh_gestao() ? turmas_lista() : array_values(array_merge(turmas_dirigidas(), turmas_do_usuario()));
$vistos = []; $opcoes = array_values(array_filter($opcoes, function ($t) use (&$vistos) { if (isset($vistos[$t['id']])) return false; return $vistos[$t['id']] = true; }));
if (!$opcoes) { flash('warning', 'Você ainda não tem turmas atribuídas. Peça à coordenação.'); redirecionar('index.php'); }
$ids = array_map('intval', array_column($opcoes, 'id'));
$pedida = (int)($_GET['turma'] ?? $_POST['turma'] ?? 0);
$tid = in_array($pedida, $ids, true) ? $pedida : (int)(turmas_dirigidas()[0]['id'] ?? $ids[0]);
$turma = q_um('SELECT t.*, c.nome AS curso, c.sigla FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?', [$tid]);
$editar = pode_editar_mapa($tid);
const MAPA_MAX = 14;   // até 14 filas e 14 carteiras por fila

if (eh_post() && ($_POST['acao'] ?? '') === 'salvar') {
    csrf_verificar();
    if (!$editar) { flash('warning', 'Só a gestão e o(a) diretor(a) desta turma podem alterar o mapa.'); redirecionar('mapa_sala.php?turma=' . $tid); }
    $j = json_decode((string)($_POST['mapa'] ?? ''), true) ?: [];
    $validos = array_flip(array_map('intval', array_column(q_todos("SELECT id FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido'))", [$tid]), 'id')));
    $cad = [];
    foreach ((array)($j['cadeiras'] ?? []) as $k)
        if (preg_match('/^(\d+)-(\d+)$/', (string)$k, $m) && $m[1] < MAPA_MAX && $m[2] < MAPA_MAX) $cad[$k] = true;
    $alu = []; $usados = [];
    foreach ((array)($j['alunos'] ?? []) as $k => $a) {
        $a = (int)$a;
        if (!isset($cad[$k]) || !isset($validos[$a]) || isset($usados[$a])) continue;
        $alu[$k] = $a; $usados[$a] = 1;
    }
    $mesa = in_array($j['mesa'] ?? '', ['esquerda', 'centro', 'direita'], true) ? $j['mesa'] : 'centro';
    $f = 0; $p = 0; foreach (array_keys($cad) as $k) { [$a, $b] = array_map('intval', explode('-', $k)); $f = max($f, $a + 1); $p = max($p, $b + 1); }
    $json = json_encode(['v' => 2, 'cadeiras' => array_keys($cad), 'alunos' => (object)$alu, 'mesa' => $mesa]);
    $agora = date('Y-m-d H:i:s');
    if (q_valor('SELECT 1 FROM mapas_sala WHERE turma_id = ?', [$tid])) q('UPDATE mapas_sala SET filas = ?, colunas = ?, lugares = ?, atualizado_por = ?, atualizado_em = ? WHERE turma_id = ?', [$f, $p, $json, uid(), $agora, $tid]);
    else q('INSERT INTO mapas_sala (turma_id, filas, colunas, lugares, atualizado_por, atualizado_em) VALUES (?, ?, ?, ?, ?, ?)', [$tid, $f, $p, $json, uid(), $agora]);
    registrar_log('mapa_sala', turma_nome($turma) . ': ' . count($cad) . ' carteiras, ' . count($alu) . ' alunos');
    flash('success', 'Mapa de turma salvo: ' . count($cad) . ' carteiras e ' . count($alu) . ' aluno(s) no lugar.');
    redirecionar('mapa_sala.php?turma=' . $tid);
}

$mapa = mapa_sala_ler($tid);
$alunos = q_todos("SELECT id, nome, foto FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) ORDER BY nome", [$tid]);
if (class_exists('Collator')) { $col = new Collator('pt_BR'); usort($alunos, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); }
$dados = [];
foreach ($alunos as $i => $a) $dados[(int)$a['id']] = ['n' => $i + 1, 'nome' => nome_proprio($a['nome']), 'curto' => primeiro_ultimo(nome_proprio($a['nome'])), 'av' => avatar($a, 'aluno', 'avatar-md')];
$por = $mapa['por'] ? q_valor('SELECT nome FROM usuarios WHERE id = ?', [$mapa['por']]) : null;
$dt_nome = !empty($turma['diretor_turma_id']) ? q_valor('SELECT nome FROM usuarios WHERE id = ?', [$turma['diretor_turma_id']]) : null;
$pode_oc = pode('ocorrencias');

$titulo = 'Mapa de turma';
$subtitulo = 'A sala vista de cima: porta, quadro, mesa do professor, janelas e o lugar de cada aluno.';
$menu = 'mapa_sala';
require __DIR__ . '/../includes/layout_topo.php';
?>
<nav class="ms-turmas nao-imprimir" aria-label="Escolha a turma">
    <?php foreach ($opcoes as $t): ?>
        <a class="ms-turma<?= (int)$t['id'] === $tid ? ' ativa' : '' ?>" href="?turma=<?= $t['id'] ?>" style="--tc:<?= cor_curso($t['curso'] ?? '') ?>">
            <b><?= $t['serie'] ?>º<?= e($t['letra']) ?></b><span><?= e($t['sigla'] ?? $t['curso'] ?? '') ?></span></a>
    <?php endforeach; ?>
</nav>

<div class="ms-barra nao-imprimir" style="--tc:<?= cor_curso($turma['curso']) ?>">
    <div class="ms-titulo"><span class="ms-selo"><?= $turma['serie'] ?>º<b><?= e($turma['letra']) ?></b></span>
        <div><strong><?= e($turma['curso']) ?></strong>
            <small><?= count($alunos) ?> alunos · <b id="infoCad"><?= count($mapa['cadeiras']) ?></b> carteiras<?= $mapa['em'] ? ' · atualizado em ' . data_hora_br($mapa['em']) . ($por ? ' por ' . e(primeiro_ultimo($por)) : '') : ' · mapa ainda não montado' ?></small></div></div>
    <div class="ms-acoes-topo">
        <?php if ($editar): ?>
        <button type="button" class="btn btn-primary btn-sm ms2-so-ver" data-ir="<?= $mapa['cadeiras'] ? 'alunos' : 'montar' ?>"><i class="bi bi-pencil-square me-1"></i><?= $mapa['cadeiras'] ? 'Editar mapa' : 'Montar o mapa' ?></button>
        <nav class="ms2-passos ms2-so-ed" aria-label="Etapas">
            <button type="button" data-ir="montar"><span>1</span>Montar a sala</button><i aria-hidden="true"></i>
            <button type="button" data-ir="alunos"><span>2</span>Colocar os alunos</button>
        </nav>
        <button type="button" class="btn btn-light btn-sm ms2-so-ed" id="btDesfazer" disabled title="Desfazer (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i><span class="d-none d-xl-inline ms-1">Desfazer</span></button>
        <button type="button" class="btn btn-light btn-sm ms2-so-ed" data-ir="ver"><i class="bi bi-eye me-1"></i>Ver como fica</button>
        <?php endif; ?>
        <?php if ($pode_oc): ?><a class="btn btn-sm ms-btn-oc" href="ocorrencias.php?nova=fora_mapa&turma=<?= $tid ?>#nova"><i class="bi bi-exclamation-diamond me-1"></i>Aluno fora do mapa</a><?php endif; ?>
        <a class="btn btn-light btn-sm" href="mapa_sala_imprimir.php?turma=<?= $tid ?>&imprimir=1" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Imprimir</a>
    </div>
</div>
<?php if (!$editar): ?>
<p class="ms-aviso nao-imprimir"><i class="bi bi-lock"></i><span>Você pode consultar este mapa. Quem monta é <?= $dt_nome ? 'o(a) diretor(a) de turma, <b>' . e(primeiro_ultimo($dt_nome)) . '</b>,' : 'o(a) diretor(a) de turma' ?> ou a gestão. Toque num aluno para ver a ficha<?= $pode_oc ? ' ou registrar que ele estava fora do lugar' : '' ?>.</span></p>
<?php endif; ?>
<?= cabecalho_impressao('Mapa de turma · ' . turma_nome($turma), count($alunos) . ' alunos · ' . count($mapa['cadeiras']) . ' carteiras · ' . date('d/m/Y')) ?>

<form method="post" id="formMapa" class="ms-area<?= $editar ? ' editar' : '' ?>" data-modo="ver">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="turma" value="<?= $tid ?>"><input type="hidden" name="mapa" id="mapaJson">
    <div class="ms-sala" id="sala">
        <div class="ms-chao">
            <div class="ms-parede">
                <div class="ms-quadro"><span class="ms-ic verde"><i class="bi bi-easel2"></i></span><span><b>Quadro</b><small>frente da sala</small></span></div>
            </div>
            <div class="ms-frente mesa-<?= e($mapa['mesa']) ?>" id="frente">
                <div class="ms-porta"><span class="ms-ic"><i class="bi bi-door-open"></i></span><b>Porta</b></div>
                <div class="ms-mesa"><span class="ms-ic laranja"><i class="bi bi-person-workspace"></i></span><span><b>Mesa do professor</b></span></div>
            </div>
            <div class="ms-grade" id="grade"></div>
            <div class="ms-fundo"><span>fundo da sala</span></div>
        </div>
        <div class="ms-janela" aria-label="Janelas"><span><i class="bi bi-window"></i>Janelas</span></div>
    </div>
    <?php if ($editar): ?>
    <aside class="ms-lado nao-imprimir">
        <!-- visão: resumo e atalho para editar -->
        <div class="card ms-painel ms-p-ver"><div class="card-body">
            <h3 class="h6 mb-2"><i class="bi bi-grid-3x3-gap me-1"></i>Resumo do mapa</h3>
            <div class="ms-resumo"><span><b id="rCad">0</b>carteiras</span><span><b id="rSent">0</b>sentados</span><span class="ambar"><b id="rSem">0</b>sem lugar</span></div>
            <div class="ms2-atalhos">
                <button type="button" data-ir="montar"><span class="ms2-n">1</span><span><b>Montar a sala</b><small>formato e posição das carteiras</small></span><i class="bi bi-chevron-right"></i></button>
                <button type="button" data-ir="alunos"><span class="ms2-n">2</span><span><b>Colocar os alunos</b><small>quem senta em cada carteira</small></span><i class="bi bi-chevron-right"></i></button>
            </div>
        </div></div>

        <!-- passo 1: montar a sala -->
        <div class="card ms-painel ms-p-montar"><div class="card-body">
            <div class="ms2-tit"><span class="ms2-n">1</span><div><h3>Monte a sala</h3><small>Escolha o formato. Depois ajuste carteira por carteira, se precisar.</small></div></div>
            <div class="ms2-formatos" role="radiogroup" aria-label="Formato da sala">
                <button type="button" data-formato="fileiras" role="radio"><span class="ms2-ico f-fileiras"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><b>Fileiras</b></button>
                <button type="button" data-formato="duplas" role="radio"><span class="ms2-ico f-duplas"><i></i><i></i><u></u><i></i><i></i><i></i><i></i><u></u><i></i><i></i></span><b>Duplas</b></button>
                <button type="button" data-formato="u" role="radio"><span class="ms2-ico f-u"><i></i><u></u><u></u><i></i><i></i><u></u><u></u><i></i><i></i><i></i><i></i><i></i></span><b>Em U</b></button>
                <button type="button" data-formato="grupos" role="radio"><span class="ms2-ico f-grupos"><i></i><i></i><u></u><i></i><i></i><i></i><i></i><u></u><i></i><i></i></span><b>Grupos de 4</b></button>
            </div>
            <div class="ms2-ajustes">
                <div class="ms-step"><span>Carteiras</span><button type="button" data-step="mTotal" data-d="-1" aria-label="Menos carteiras">−</button><output id="mTotal">0</output><button type="button" data-step="mTotal" data-d="1" aria-label="Mais carteiras">+</button></div>
                <div class="ms-step" id="linhaCol"><span id="rotCol">Fileiras</span><button type="button" data-step="mCol" data-d="-1" aria-label="Menos">−</button><output id="mCol">6</output><button type="button" data-step="mCol" data-d="1" aria-label="Mais">+</button></div>
                <small class="ms-conta" id="mConta"></small>
            </div>
            <div class="ms2-dica"><i class="bi bi-hand-index"></i><span><b>Ajuste fino na sala:</b> toque num <b>+</b> para pôr uma carteira e no <b>×</b> para tirar.</span></div>
            <label class="form-label mt-3 mb-1">Mesa do professor</label>
            <div class="ms-seg" id="mesaPos" role="radiogroup">
                <button type="button" data-mesa="esquerda"><i class="bi bi-align-start"></i>Esquerda</button>
                <button type="button" data-mesa="centro"><i class="bi bi-align-center"></i>Centro</button>
                <button type="button" data-mesa="direita"><i class="bi bi-align-end"></i>Direita</button>
            </div>
            <button class="btn btn-sm btn-link text-danger px-0 mt-2" type="button" id="btLimparSala"><i class="bi bi-eraser me-1"></i>Tirar todas as carteiras</button>
            <button class="btn btn-primary w-100 mt-2" type="button" data-ir="alunos">Próximo: colocar os alunos<i class="bi bi-arrow-right ms-1"></i></button>
        </div></div>

        <!-- passo 2: colocar os alunos -->
        <div class="card ms-painel ms-p-alunos"><div class="card-body pb-2">
            <div class="ms2-tit"><span class="ms2-n">2</span><div><h3>Coloque os alunos</h3><small>Toque no aluno e depois na carteira. Ou arraste.</small></div></div>
            <div class="ms2-prog"><div><b id="pSent">0</b> de <b id="pTot">0</b> sentados</div><span><i id="pBarra"></i></span></div>
            <div class="ms2-auto">
                <span>Preencher sozinho</span>
                <div><button type="button" id="btAlfa"><i class="bi bi-sort-alpha-down"></i>Ordem alfabética</button>
                <button type="button" id="btSortear"><i class="bi bi-shuffle"></i>Sortear</button>
                <button type="button" id="btCompletar"><i class="bi bi-magic"></i>Completar vazias</button></div>
            </div>
        </div>
            <div class="ms2-lista-cab"><b>Sem lugar</b><span class="etiqueta" id="contSem">0</span>
                <label class="ms2-seguir"><input type="checkbox" id="seguir" checked> seguir para o próximo</label></div>
            <input class="form-control form-control-sm mx-3" style="width:calc(100% - 2rem)" type="search" id="buscaSem" placeholder="Procurar aluno…" aria-label="Procurar aluno">
            <div class="ms-sem" id="semLugar"></div>
            <div class="px-3 pb-3"><button class="btn btn-sm btn-link text-danger px-0" type="button" id="btLimpar"><i class="bi bi-eraser me-1"></i>Tirar todos os alunos das carteiras</button></div>
        </div>

        <button class="btn btn-primary w-100 ms-salvar" type="submit"><i class="bi bi-check2-circle me-1"></i>Salvar mapa <span class="ms-mudou" id="mudou" hidden>alterado</span></button>
    </aside>
    <?php endif; ?>
</form>

<div class="ms-pop" id="pop" hidden></div>
<div class="ms2-toast" id="toast" hidden><span id="toastTx"></span><button type="button" class="ms2-t-tirar" id="toastTirar" hidden><i class="bi bi-box-arrow-up-right me-1"></i>Tirar da carteira</button><button type="button" class="ms2-t-x" id="toastX" aria-label="Cancelar">Cancelar</button></div>

<?php
$script_pagina = '<script>window.MAPA = ' . json_encode([
    'A' => (object)$dados, 'MAX' => MAPA_MAX, 'EDIT' => $editar, 'OC' => $pode_oc,
    'cad' => array_values($mapa['cadeiras']), 'alu' => (object)$mapa['alunos'], 'mesa' => $mapa['mesa'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script><script src="' . RAIZ . 'assets/js/mapa_sala.js?v=1"></script>';
require __DIR__ . '/../includes/layout_rodape.php';
