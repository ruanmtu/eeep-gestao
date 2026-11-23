<?php
require __DIR__ . '/../includes/app.php';
exigir('apresentacao');


$ano = in_array((int)($_GET['ano'] ?? 0), anos_letivos(), true) ? (int)$_GET['ano'] : ANO_VER;
$bim = max(0, min(4, (int)($_GET['bimestre'] ?? 0)));
$curso_id = (int)($_GET['curso'] ?? 0);
$ini = $bim ? bimestres($ano)[$bim][0] : "$ano-01-01";
$fim = $bim ? bimestres($ano)[$bim][1] : "$ano-12-31";
$cursos = q_todos('SELECT id, nome, sigla FROM cursos ORDER BY nome');
$curso_nome = '';
foreach ($cursos as $c) if ((int)$c['id'] === $curso_id) $curso_nome = $c['nome'];

/* ---------- Turmas e alunos ---------- */
$turmas = [];
foreach (q_todos('SELECT t.id, t.serie, t.letra, c.nome AS curso, c.sigla FROM turmas t JOIN cursos c ON c.id = t.curso_id
                   WHERE t.ano_letivo = ?' . ($curso_id ? ' AND t.curso_id = ' . $curso_id : '') . ' ORDER BY t.serie, t.letra', [$ano]) as $t) {
    $turmas[(int)$t['id']] = $t + ['rot' => $t['serie'] . 'º ' . $t['letra'], 'alunos' => 0, 'faltas' => 0, 'faltosos' => 0, 'notas' => [], 'abaixo' => 0, 'qtd' => 0];
}
$alunos = [];
if ($turmas) foreach (q_todos("SELECT a.id, a.nome, xt.turma_id FROM aluno_turmas xt JOIN alunos a ON a.id = xt.aluno_id WHERE xt.situacao IN ('ativo','concluido') AND xt.turma_id IN (" . implode(',', array_keys($turmas)) . ')') as $a) {
    $alunos[(int)$a['id']] = $a + ['faltas' => 0, 'notas' => [], 'disc' => []];
    $turmas[(int)$a['turma_id']]['alunos']++;
}

/* ---------- Faltas (coleta diária + planilha) ---------- */
foreach (q_todos('SELECT aluno_id, COUNT(*) AS n FROM faltas WHERE data_falta BETWEEN ? AND ? GROUP BY aluno_id', [$ini, $fim]) as $r)
    if (isset($alunos[(int)$r['aluno_id']])) $alunos[(int)$r['aluno_id']]['faltas'] += (int)$r['n'];
try {
    foreach (q_todos('SELECT aluno_id, SUM(faltas) AS n FROM faltas_bimestre WHERE ano_letivo = ?' . ($bim ? ' AND bimestre = ' . $bim : '') . ' GROUP BY aluno_id', [$ano]) as $r)
        if (isset($alunos[(int)$r['aluno_id']])) $alunos[(int)$r['aluno_id']]['faltas'] += (int)$r['n'];
} catch (PDOException $e) {}

/* ---------- Notas (médias do bimestre) ---------- */
$por_disc = [];
$por_bim = [];
foreach (q_todos('SELECT n.aluno_id, n.bimestre, n.nota, d.nome AS disciplina FROM notas n JOIN disciplinas d ON d.id = n.disciplina_id WHERE n.ano_letivo = ?', [$ano]) as $r) {
    $aid = (int)$r['aluno_id'];
    if (!isset($alunos[$aid])) continue;
    $nota = (float)$r['nota'];
    $sig = $turmas[(int)$alunos[$aid]['turma_id']]['sigla'];
    $por_bim[(int)$r['bimestre']][$sig][] = $nota;
    if ($bim && (int)$r['bimestre'] !== $bim) continue;
    $alunos[$aid]['notas'][] = $nota;
    $alunos[$aid]['disc'][$r['disciplina']][] = $nota;
    $por_disc[$r['disciplina']][] = $nota;
}
$media = fn(array $v) => $v ? array_sum($v) / count($v) : null;
$todas = [];
foreach ($alunos as $id => &$a) {
    $t = &$turmas[(int)$a['turma_id']];
    $t['faltas'] += $a['faltas'];
    if ($a['faltas']) $t['faltosos']++;
    foreach ($a['notas'] as $n) { $t['notas'][] = $n; $todas[] = $n; }
    $a['media'] = $media($a['notas']);
    $a['disc_abaixo'] = count(array_filter($a['disc'], fn($v) => $media($v) < MEDIA_APROVACAO));
    if ($a['disc_abaixo']) $t['abaixo']++;
    unset($t);
}
unset($a);
foreach ($turmas as &$t) $t['media'] = $media($t['notas']);
unset($t);

$media_geral = $media($todas);
$pct_aprov = $todas ? count(array_filter($todas, fn($n) => $n >= MEDIA_APROVACAO)) / count($todas) * 100 : null;
$total_faltas = array_sum(array_column($alunos, 'faltas'));
$busca_ativa = count(array_filter($alunos, fn($a) => $a['faltas'] >= FALTAS_CRITICO));
$com_abaixo = count(array_filter($alunos, fn($a) => $a['disc_abaixo'] > 0));
$n_saidas = $n_emprestimos = $n_ocorrencias = 0;
try { $n_saidas = (int)q_valor("SELECT COUNT(*) FROM saidas WHERE tipo = 'saida' AND status = 'autorizada' AND data_hora BETWEEN ? AND ?", ["$ini 00:00:00", "$fim 23:59:59"]); } catch (PDOException $e) {}
try { $n_emprestimos = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE retirada BETWEEN ? AND ?', ["$ini 00:00:00", "$fim 23:59:59"]); } catch (PDOException $e) {}
try { $n_ocorrencias = (int)q_valor("SELECT COUNT(*) FROM ocorrencias WHERE tipo <> 'elogio' AND data_ocorrencia BETWEEN ? AND ?", [$ini, $fim]); } catch (PDOException $e) {}

$disc_medias = array_map($media, $por_disc);
arsort($disc_medias);
$lista_alunos = array_values($alunos);
$top_faltas = array_filter($lista_alunos, fn($a) => $a['faltas'] > 0);
usort($top_faltas, fn($x, $y) => $y['faltas'] <=> $x['faltas']);
$top_abaixo = array_filter($lista_alunos, fn($a) => $a['disc_abaixo'] > 0);
usort($top_abaixo, fn($x, $y) => [$y['disc_abaixo'], $x['media']] <=> [$x['disc_abaixo'], $y['media']]);
$destaques = array_filter($lista_alunos, fn($a) => count($a['notas']) >= 3);
usort($destaques, fn($x, $y) => $y['media'] <=> $x['media']);
$turmas_faltas = array_values($turmas);
usort($turmas_faltas, fn($x, $y) => $y['faltas'] <=> $x['faltas']);

/* Comparativo entre bimestres (média por curso) */
ksort($por_bim);
$siglas = array_values(array_unique(array_column($turmas, 'sigla')));
$comp = [];
foreach ($siglas as $s) foreach ([1, 2, 3, 4] as $b) $comp[$s][] = isset($por_bim[$b][$s]) ? round($media($por_bim[$b][$s]), 2) : null;
$bims_com_nota = array_keys($por_bim);

$periodo = ($bim ? $bim . 'º bimestre de ' : 'Ano letivo ') . $ano . ($curso_nome ? ' · ' . $curso_nome : '');
$u = usuario();
$fmt = fn($v, $c = 1) => $v === null ? '—' : number_format((float)$v, $c, ',', '.');
$nome_t = fn($a) => $turmas[(int)$a['turma_id']]['rot'] . ' ' . $turmas[(int)$a['turma_id']]['sigla'];
registrar_log('apresentacao', $periodo);

/* Dados dos gráficos */
$g = [
    'turmas'  => ['rot' => array_map(fn($t) => $t['rot'] . ' ' . $t['sigla'], array_values($turmas)), 'media' => array_map(fn($t) => $t['media'] === null ? null : round($t['media'], 2), array_values($turmas))],
    'faltas'  => ['rot' => array_map(fn($t) => $t['rot'] . ' ' . $t['sigla'], array_values($turmas)), 'n' => array_column(array_values($turmas), 'faltas'), 'f' => array_column(array_values($turmas), 'faltosos')],
    'disc'    => ['rot' => array_keys($disc_medias), 'media' => array_map(fn($v) => round($v, 2), array_values($disc_medias))],
    'comp'    => $comp,
    'media_ok'=> MEDIA_APROVACAO,
];
$slides = [];
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apresentação · <?= e($periodo) ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<link href="../assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
    @page { size: 1280px 720px; margin: 0; }
    :root { --tinta: #0e1a12; --cinza: #5f6f64; --linha: #e3ebe5; --verde: #13692a; --verde-2: #1e9a2b; --verde-claro: #e8f4e9; --vermelho: #c0261b; --vermelho-claro: #fdecea; --ambar: #b26a00; --fundo: #e9eeea; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--fundo); font-family: "Plus Jakarta Sans", system-ui, sans-serif; color: var(--tinta); }
    .barra { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px 20px; background: #fff; border-bottom: 1px solid var(--linha); }
    .barra a, .barra button, .barra select { font: 600 14px var(--fonte, inherit); font-family: inherit; height: 38px; padding: 0 14px; border-radius: 10px; border: 1px solid var(--linha); background: #fff; color: var(--tinta); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .barra .primario { background: var(--tinta); color: #fff; border-color: var(--tinta); }
    .barra .titulo { font-weight: 800; margin-right: auto; }
    .barra form { display: flex; gap: 8px; flex-wrap: wrap; }
    .slides { max-width: 1100px; margin: 24px auto 60px; padding: 0 16px; display: flex; flex-direction: column; gap: 22px; }
    .quadro { position: relative; width: 100%; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 14px; box-shadow: 0 6px 24px rgba(14, 26, 18, .12); background: #fff; }
    .slide { position: absolute; top: 0; left: 0; width: 1280px; height: 720px; transform-origin: 0 0; background: #fff; padding: 56px 64px 72px; display: flex; flex-direction: column; overflow: hidden; }
    .slide h2 { font-size: 34px; letter-spacing: -.02em; margin: 0 0 6px; }
    .slide .sub { color: var(--cinza); font-size: 17px; margin: 0 0 26px; }
    .slide .rodape { position: absolute; left: 64px; right: 64px; bottom: 24px; display: flex; align-items: center; gap: 14px; font-size: 13px; color: var(--cinza); border-top: 1px solid var(--linha); padding-top: 12px; }
    .slide .rodape img { height: 26px; }
    .slide .rodape .pag { margin-left: auto; font-weight: 700; }
    .slide .tag { display: inline-block; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--verde); margin-bottom: 10px; }
    /* capa */
    .capa { background: radial-gradient(circle at 85% 20%, #1e9a2b 0, transparent 45%), linear-gradient(135deg, #0c4a1d, #0e1a12 70%); color: #fff; justify-content: center; }
    .capa .logo { position: absolute; top: 48px; left: 64px; height: 64px; background: #fff; padding: 10px 16px; border-radius: 14px; }
    .capa h1 { font-size: 64px; line-height: 1.05; letter-spacing: -.03em; margin: 0 0 18px; max-width: 900px; }
    .capa p { font-size: 22px; color: rgba(255,255,255,.78); margin: 0; }
    .capa .quem { position: absolute; bottom: 48px; left: 64px; font-size: 17px; color: rgba(255,255,255,.85); }
    .capa .quem strong { display: block; font-size: 20px; color: #fff; }
    .capa .rodape { display: none; }
    /* números */
    .numeros { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
    .numero { border: 1px solid var(--linha); border-radius: 18px; padding: 22px 24px; }
    .numero .r { font-size: 15px; color: var(--cinza); font-weight: 600; }
    .numero .v { font-size: 52px; font-weight: 800; letter-spacing: -.03em; line-height: 1.1; margin: 6px 0 2px; }
    .numero .n { font-size: 14px; color: var(--cinza); }
    .numero.destaque { background: var(--tinta); color: #fff; border-color: var(--tinta); }
    .numero.destaque .r, .numero.destaque .n { color: rgba(255,255,255,.7); }
    .numero.ruim .v { color: var(--vermelho); }
    .numero.bom .v { color: var(--verde); }
    .grafico { position: relative; flex: 1; min-height: 0; }
    .duas { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; flex: 1; min-height: 0; }
    .lista { list-style: none; margin: 0; padding: 0; }
    .lista li { display: flex; align-items: center; gap: 12px; padding: 7px 0; border-bottom: 1px solid var(--linha); font-size: 16px; }
    .lista .p { width: 30px; height: 30px; border-radius: 9px; background: var(--verde-claro); color: var(--verde); display: grid; place-items: center; font-weight: 800; font-size: 13px; flex: none; }
    .lista .nm { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
    .lista .t { color: var(--cinza); font-size: 13px; font-weight: 500; }
    .lista .v { font-weight: 800; font-size: 18px; }
    .lista.ruim .p { background: var(--vermelho-claro); color: var(--vermelho); }
    .lista.ruim .v { color: var(--vermelho); }
    h3 { font-size: 18px; margin: 0 0 8px; }
    table.resumo { width: 100%; border-collapse: collapse; font-size: 15px; }
    table.resumo th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--cinza); padding: 8px 10px; border-bottom: 2px solid var(--linha); }
    table.resumo td { padding: 7px 10px; border-bottom: 1px solid var(--linha); }
    table.resumo .n { text-align: right; font-variant-numeric: tabular-nums; }
    .bom-t { color: var(--verde); font-weight: 800; } .ruim-t { color: var(--vermelho); font-weight: 800; }
    .barrinha { display: inline-block; height: 8px; border-radius: 4px; background: var(--vermelho); opacity: .75; vertical-align: middle; margin-right: 8px; }
    .vazio { flex: 1; display: grid; place-items: center; color: var(--cinza); font-size: 20px; text-align: center; border: 2px dashed var(--linha); border-radius: 18px; }
    .fim { background: linear-gradient(135deg, #0c4a1d, #0e1a12 70%); color: #fff; justify-content: center; align-items: center; text-align: center; }
    .fim h1 { font-size: 72px; margin: 0 0 12px; } .fim p { color: rgba(255,255,255,.75); font-size: 20px; margin: 0; }
    .fim .rodape { display: none; }
    /* modo apresentação */
    body.apresentando { background: #000; overflow: hidden; }
    body.apresentando .barra { display: none; }
    body.apresentando .slides { position: fixed; inset: 0; margin: 0; padding: 0; max-width: none; display: block; }
    body.apresentando .quadro { display: none; position: absolute; border-radius: 0; box-shadow: none; aspect-ratio: auto; }
    body.apresentando .quadro.ativo { display: block; }
    .dica-tecla { position: fixed; bottom: 14px; left: 50%; transform: translateX(-50%); color: rgba(255,255,255,.6); font-size: 13px; display: none; }
    body.apresentando .dica-tecla { display: block; animation: some 3s 2s forwards; }
    @keyframes some { to { opacity: 0; } }
    @media print {
        body { background: #fff; }
        .barra, .dica-tecla { display: none !important; }
        .slides { display: block; margin: 0; padding: 0; max-width: none; }
        .quadro { width: 1280px; height: 720px; aspect-ratio: auto; border-radius: 0; box-shadow: none; page-break-after: always; break-after: page; }
        .slide { transform: none !important; }
        .capa, .fim { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .numero.destaque { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>
<div class="barra">
    <a href="index.php"><i class="bi bi-arrow-left"></i>Painel</a>
    <span class="titulo">Apresentação · <?= e($periodo) ?></span>
    <form method="get">
        <select name="bimestre" onchange="this.form.submit()" aria-label="Período">
            <option value="0">Ano todo</option>
            <?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>"<?= $bim === $b ? ' selected' : '' ?>><?= $b ?>º bimestre</option><?php endfor; ?>
        </select>
        <select name="curso" onchange="this.form.submit()" aria-label="Curso">
            <option value="0">Todos os cursos</option>
            <?php foreach ($cursos as $c): ?><option value="<?= $c['id'] ?>"<?= $curso_id === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
        </select>
        <select name="ano" onchange="this.form.submit()" aria-label="Ano letivo">
            <?php foreach (anos_letivos() as $a): ?><option value="<?= $a ?>"<?= $ano === $a ? ' selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
        </select>
    </form>
    <button type="button" onclick="window.print()"><i class="bi bi-file-earmark-pdf"></i>Salvar em PDF</button>
    <button type="button" class="primario" id="btApresentar"><i class="bi bi-play-fill"></i>Apresentar</button>
</div>

<main class="slides">
<?php
$rodape = fn(string $t) => '<div class="rodape"><img src="../assets/img/logo-doc-2026.png" alt=""><span>' . e($t) . ' · ' . e($periodo) . '</span><span class="pag"></span></div>';
ob_start(); ?>
    <section class="slide capa">
        <img class="logo" src="../assets/img/logo-doc-2026.png" alt="<?= e($ESCOLA['nome']) ?>">
        <span class="tag" style="color:#9fe0a6">Reunião de resultados</span>
        <h1><?= $bim ? 'Resultados do ' . $bim . 'º bimestre' : 'Panorama do ano letivo' ?> <?= $ano ?></h1>
        <p><?= e($ESCOLA['nome_oficial']) ?><?= $curso_nome ? ' · Curso de ' . e($curso_nome) : '' ?></p>
        <div class="quem"><strong><?= e($u['nome']) ?></strong><?= e(cargo_usuario($u)) ?> · <?= date('d/m/Y') ?></div>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Visão geral</span>
        <h2>A escola em números</h2>
        <p class="sub"><?= count($alunos) ?> alunos ativos em <?= count($turmas) ?> turmas</p>
        <div class="numeros">
            <div class="numero destaque"><div class="r">Média geral</div><div class="v"><?= $fmt($media_geral) ?></div><div class="n">de todas as notas do período</div></div>
            <div class="numero <?= $pct_aprov !== null && $pct_aprov < 70 ? 'ruim' : 'bom' ?>"><div class="r">Notas acima da média</div><div class="v"><?= $pct_aprov === null ? '—' : $fmt($pct_aprov, 0) . '%' ?></div><div class="n">nota ≥ <?= $fmt(MEDIA_APROVACAO) ?></div></div>
            <div class="numero <?= $com_abaixo ? 'ruim' : '' ?>"><div class="r">Alunos com nota abaixo</div><div class="v"><?= $com_abaixo ?></div><div class="n">em pelo menos uma disciplina</div></div>
            <div class="numero"><div class="r">Faltas no período</div><div class="v"><?= number_format($total_faltas, 0, ',', '.') ?></div><div class="n"><?= count($alunos) ? $fmt($total_faltas / count($alunos)) : '0' ?> por aluno</div></div>
            <div class="numero <?= $busca_ativa ? 'ruim' : '' ?>"><div class="r">Busca ativa</div><div class="v"><?= $busca_ativa ?></div><div class="n"><?= FALTAS_CRITICO ?> faltas ou mais</div></div>
            <div class="numero"><div class="r">Liberações de saída</div><div class="v"><?= $n_saidas ?></div><div class="n">no período</div></div>
            <div class="numero"><div class="r">Ocorrências</div><div class="v"><?= $n_ocorrencias ?></div><div class="n">disciplinares e pedagógicas</div></div>
            <div class="numero"><div class="r">Livros emprestados</div><div class="v"><?= $n_emprestimos ?></div><div class="n">Centro de Multimeios</div></div>
        </div>
        <?= $rodape('Visão geral') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Rendimento</span>
        <h2>Média por turma</h2>
        <p class="sub">Verde: média igual ou acima de <?= $fmt(MEDIA_APROVACAO) ?> · vermelho: abaixo</p>
        <?php if ($todas): ?><div class="grafico"><canvas id="gTurmas"></canvas></div><?php else: ?><div class="vazio">Ainda não há notas neste período.<br>Lance as avaliações ou importe a planilha.</div><?php endif; ?>
        <?= $rodape('Média por turma') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Rendimento</span>
        <h2>Média por disciplina</h2>
        <p class="sub">Da maior para a menor média</p>
        <?php if ($disc_medias): ?><div class="grafico"><canvas id="gDisc"></canvas></div><?php else: ?><div class="vazio">Ainda não há notas neste período.</div><?php endif; ?>
        <?= $rodape('Média por disciplina') ?>
    </section>
<?php $slides[] = ob_get_clean();
if (count($bims_com_nota) > 1): ob_start(); ?>
    <section class="slide">
        <span class="tag">Evolução</span>
        <h2>Média por bimestre</h2>
        <p class="sub">Comparativo entre os cursos ao longo do ano</p>
        <div class="grafico"><canvas id="gComp"></canvas></div>
        <?= $rodape('Evolução por bimestre') ?>
    </section>
<?php $slides[] = ob_get_clean(); endif; ob_start(); ?>
    <section class="slide">
        <span class="tag">Frequência</span>
        <h2>Faltas por turma</h2>
        <p class="sub"><?php if ($turmas_faltas && $turmas_faltas[0]['faltas']): ?>Mais faltas: <strong><?= e($turmas_faltas[0]['rot'] . ' ' . $turmas_faltas[0]['curso']) ?></strong> (<?= $turmas_faltas[0]['faltas'] ?>) · menos faltas: <strong><?= e(end($turmas_faltas)['rot'] . ' ' . end($turmas_faltas)['curso']) ?></strong> (<?= end($turmas_faltas)['faltas'] ?>)<?php else: ?>Coleta diária + planilha importada<?php endif; ?></p>
        <?php if ($total_faltas): ?><div class="grafico"><canvas id="gFaltas"></canvas></div><?php else: ?><div class="vazio">Nenhuma falta registrada neste período.</div><?php endif; ?>
        <?= $rodape('Faltas por turma') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Resumo</span>
        <h2>Quadro das turmas</h2>
        <p class="sub" style="margin-bottom:14px">Alunos, média, quem está abaixo da média e faltas</p>
        <?php $maxf = max(1, ...array_column($turmas, 'faltas') ?: [1]); ?>
        <table class="resumo">
            <thead><tr><th>Turma</th><th class="n">Alunos</th><th class="n">Média</th><th class="n">Alunos abaixo</th><th>Faltas</th><th class="n">Faltosos</th></tr></thead>
            <tbody>
            <?php foreach ($turmas as $t): ?>
                <tr><td><strong><?= e($t['rot']) ?></strong> · <?= e($t['curso']) ?></td><td class="n"><?= $t['alunos'] ?></td>
                    <td class="n <?= $t['media'] === null ? '' : ($t['media'] >= MEDIA_APROVACAO ? 'bom-t' : 'ruim-t') ?>"><?= $fmt($t['media']) ?></td>
                    <td class="n"><?= $t['abaixo'] ?></td>
                    <td><span class="barrinha" style="width:<?= round($t['faltas'] / $maxf * 160) ?>px"></span><?= $t['faltas'] ?></td>
                    <td class="n"><?= $t['faltosos'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?= $rodape('Quadro das turmas') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Atenção</span>
        <h2>Alunos que precisam de acompanhamento</h2>
        <p class="sub">Para a busca ativa e o plano de recuperação</p>
        <div class="duas">
            <div><h3>Mais faltas</h3>
                <?php if ($top_faltas): ?><ol class="lista ruim"><?php foreach (array_slice($top_faltas, 0, 8) as $i => $a): ?>
                    <li><span class="p"><?= $i + 1 ?></span><span class="nm"><?= e(nome_proprio($a['nome'])) ?> <span class="t">· <?= e($nome_t($a)) ?></span></span><span class="v"><?= $a['faltas'] ?></span></li>
                <?php endforeach; ?></ol><?php else: ?><p class="sub">Nenhuma falta no período.</p><?php endif; ?></div>
            <div><h3>Mais disciplinas abaixo da média</h3>
                <?php if ($top_abaixo): ?><ol class="lista ruim"><?php foreach (array_slice($top_abaixo, 0, 8) as $i => $a): ?>
                    <li><span class="p"><?= $i + 1 ?></span><span class="nm"><?= e(nome_proprio($a['nome'])) ?> <span class="t">· <?= e($nome_t($a)) ?></span></span><span class="v"><?= $a['disc_abaixo'] ?></span></li>
                <?php endforeach; ?></ol><?php else: ?><p class="sub">Nenhum aluno abaixo da média.</p><?php endif; ?></div>
        </div>
        <?= $rodape('Acompanhamento') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide">
        <span class="tag">Destaques</span>
        <h2>Maiores médias</h2>
        <p class="sub">Alunos com as melhores médias no período</p>
        <?php if ($destaques): $d = array_slice($destaques, 0, 12); ?>
        <div class="duas">
            <?php foreach (array_chunk($d, 6, true) as $parte): ?><ol class="lista"><?php foreach ($parte as $i => $a): ?>
                <li><span class="p"><?= $i + 1 ?></span><span class="nm"><?= e(nome_proprio($a['nome'])) ?> <span class="t">· <?= e($nome_t($a)) ?></span></span><span class="v" style="color:var(--verde)"><?= $fmt($a['media']) ?></span></li>
            <?php endforeach; ?></ol><?php endforeach; ?>
        </div>
        <?php else: ?><div class="vazio">Ainda não há notas suficientes.</div><?php endif; ?>
        <?= $rodape('Destaques') ?>
    </section>
<?php $slides[] = ob_get_clean(); ob_start(); ?>
    <section class="slide fim">
        <h1>Obrigado!</h1>
        <p><?= e($ESCOLA['nome_oficial']) ?></p>
        <p style="margin-top:28px;font-size:14px;color:rgba(255,255,255,.55)"><?= e(impresso_por()) ?></p>
    </section>
<?php $slides[] = ob_get_clean();
foreach ($slides as $i => $s) echo '<div class="quadro" data-i="' . $i . '">' . str_replace('<span class="pag"></span>', '<span class="pag">' . ($i + 1) . ' / ' . count($slides) . '</span>', $s) . '</div>';
?>
</main>
<div class="dica-tecla">← → para navegar · Esc para sair</div>

<script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
const D = <?= json_encode($g, JSON_UNESCAPED_UNICODE) ?>;
Chart.defaults.font.family = '"Plus Jakarta Sans", system-ui, sans-serif';
Chart.defaults.font.size = 15;
Chart.defaults.color = '#3a4a3f';
Chart.defaults.borderColor = '#e3ebe5';
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.animation = false;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
const cor = v => v === null ? '#cfd8d2' : (v >= D.media_ok ? '#1e9a2b' : '#c0261b');
const rotulos = { id: 'rotulos', afterDatasetsDraw(c) {
  const { ctx } = c; ctx.save(); ctx.font = '700 13px "Plus Jakarta Sans"'; ctx.fillStyle = '#0e1a12';
  c.data.datasets.forEach((ds, di) => { if (ds.semRotulo) return; c.getDatasetMeta(di).data.forEach((el, i) => {
    const v = ds.data[i]; if (v === null || v === undefined) return;
    const txt = typeof v === 'number' && !Number.isInteger(v) ? v.toFixed(1).replace('.', ',') : String(v);
    if (c.options.indexAxis === 'y') { ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.fillText(txt, el.x + 6, el.y); }
    else { ctx.textAlign = 'center'; ctx.textBaseline = 'bottom'; ctx.fillText(txt, el.x, el.y - 4); }
  }); }); ctx.restore(); } };
const linhaMedia = { id: 'linhaMedia', afterDraw(c) {
  const s = c.options.indexAxis === 'y' ? c.scales.x : c.scales.y; const p = s.getPixelForValue(D.media_ok); const { ctx, chartArea: a } = c;
  ctx.save(); ctx.setLineDash([6, 5]); ctx.strokeStyle = '#b26a00'; ctx.lineWidth = 2; ctx.beginPath();
  if (c.options.indexAxis === 'y') { ctx.moveTo(p, a.top); ctx.lineTo(p, a.bottom); } else { ctx.moveTo(a.left, p); ctx.lineTo(a.right, p); }
  ctx.stroke(); ctx.restore(); } };
const el = id => document.getElementById(id);
if (el('gTurmas')) new Chart(el('gTurmas'), { type: 'bar', plugins: [rotulos, linhaMedia],
  data: { labels: D.turmas.rot, datasets: [{ data: D.turmas.media, backgroundColor: D.turmas.media.map(cor), borderRadius: 8, maxBarThickness: 64 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 10, ticks: { stepSize: 2 } }, x: { grid: { display: false } } }, layout: { padding: { top: 20 } } } });
if (el('gDisc')) new Chart(el('gDisc'), { type: 'bar', plugins: [rotulos, linhaMedia],
  data: { labels: D.disc.rot, datasets: [{ data: D.disc.media, backgroundColor: D.disc.media.map(cor), borderRadius: 6, maxBarThickness: 26 }] },
  options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 10 }, y: { grid: { display: false }, ticks: { font: { size: D.disc.rot.length > 16 ? 11 : 14 } } } }, layout: { padding: { right: 40 } } } });
if (el('gFaltas')) new Chart(el('gFaltas'), { type: 'bar', plugins: [rotulos],
  data: { labels: D.faltas.rot, datasets: [
    { label: 'Faltas', data: D.faltas.n, backgroundColor: '#c0261b', borderRadius: 8, maxBarThickness: 56 },
    { label: 'Alunos faltosos', data: D.faltas.f, backgroundColor: '#f2a99f', borderRadius: 8, maxBarThickness: 56 } ] },
  options: { plugins: { legend: { position: 'top', align: 'end' } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }, layout: { padding: { top: 20 } } } });
if (el('gComp')) { const paleta = ['#13692a', '#1d6fb8', '#c0261b', '#b26a00', '#6b3fa0'];
  new Chart(el('gComp'), { type: 'line', plugins: [linhaMedia],
    data: { labels: ['1º bim', '2º bim', '3º bim', '4º bim'], datasets: Object.entries(D.comp).map(([k, v], i) => ({ label: k, data: v, borderColor: paleta[i % 5], backgroundColor: paleta[i % 5], borderWidth: 3, pointRadius: 6, spanGaps: true, tension: .25 })) },
    options: { plugins: { legend: { position: 'top', align: 'end' } }, scales: { y: { min: 0, max: 10 } } } }); }

/* Encaixe dos slides (1280×720) na tela */
const quadros = [...document.querySelectorAll('.quadro')];
let atual = 0;
function encaixar() {
  const ap = document.body.classList.contains('apresentando');
  quadros.forEach(q => {
    const s = q.querySelector('.slide');
    if (ap) {
      const k = Math.min(innerWidth / 1280, innerHeight / 720);
      q.style.width = 1280 * k + 'px'; q.style.height = 720 * k + 'px';
      q.style.left = (innerWidth - 1280 * k) / 2 + 'px'; q.style.top = (innerHeight - 720 * k) / 2 + 'px';
      s.style.transform = 'scale(' + k + ')';
    } else {
      q.style.width = q.style.height = q.style.left = q.style.top = '';
      s.style.transform = 'scale(' + q.clientWidth / 1280 + ')';
    }
  });
}
function mostrar(i) { atual = Math.max(0, Math.min(quadros.length - 1, i)); quadros.forEach((q, k) => q.classList.toggle('ativo', k === atual)); }
function apresentar() {
  document.body.classList.add('apresentando'); mostrar(0); encaixar();
  if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
}
function sair() { document.body.classList.remove('apresentando'); if (document.fullscreenElement) document.exitFullscreen(); encaixar(); }
document.getElementById('btApresentar').addEventListener('click', apresentar);
document.addEventListener('keydown', e => {
  if (!document.body.classList.contains('apresentando')) return;
  if (['ArrowRight', 'PageDown', ' ', 'Enter'].includes(e.key)) { e.preventDefault(); mostrar(atual + 1); }
  if (['ArrowLeft', 'PageUp', 'Backspace'].includes(e.key)) { e.preventDefault(); mostrar(atual - 1); }
  if (e.key === 'Home') mostrar(0);
  if (e.key === 'End') mostrar(quadros.length - 1);
  if (e.key === 'Escape') sair();
});
document.addEventListener('click', e => {
  if (!document.body.classList.contains('apresentando')) return;
  mostrar(atual + (e.clientX < innerWidth / 3 ? -1 : 1));
});
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && document.body.classList.contains('apresentando')) sair(); });
addEventListener('resize', encaixar);
encaixar();
</script>
</body>
</html>
