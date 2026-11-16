<?php
/* Resultados da escola: todos os gráficos de desempenho num lugar só
   1) resultados por turma e período (disciplinas, destaques, alunos abaixo da média)
   2) rendimento por turma (notas da planilha do Power BI)
   3) média conceitual por turma (só turmas com a nota conceitual fechada) */
require __DIR__ . '/../includes/app.php';
if (!eh_gestao() && !pode('relatorios')) exigir('relatorios');

$rd_turmas = turmas_lista();
$rd_bims = bimestres_com_nota();
$rd_bim = $rd_bims ? end($rd_bims) : 0;

/* rendimento por turma (notas importadas), no ano ou num período */
$rend_bim = (int)($_GET['rb'] ?? 0); if ($rend_bim < 0 || $rend_bim > 4) $rend_bim = 0;
$notas_turma = q_todos("SELECT t.id, t.serie, t.letra, c.sigla, c.nome AS curso, ROUND(AVG(n.nota), 2) AS media, COUNT(n.id) AS qtd
                          FROM notas n JOIN alunos a ON a.id = n.aluno_id JOIN turmas t ON t.id = a.turma_id JOIN cursos c ON c.id = t.curso_id
                         WHERE n.ano_letivo = ?" . ($rend_bim ? ' AND n.bimestre = ?' : '') . " GROUP BY t.id, t.serie, t.letra, c.sigla, c.nome ORDER BY media DESC",
                       $rend_bim ? [ANO_LETIVO, $rend_bim] : [ANO_LETIVO]);
$media_escola = $notas_turma ? array_sum(array_column($notas_turma, 'media')) / count($notas_turma) : null;
$abaixo = count(array_filter($notas_turma, fn($t) => (float)$t['media'] < MEDIA_APROVACAO));

/* média conceitual por turma */
$per = periodo_atual();
$nc_bim = (int)($_GET['nb'] ?? ($per['bimestre'] ?? 1)); if ($nc_bim < 1 || $nc_bim > 4) $nc_bim = 1;
$nc = [];
$nc_erro = false;
try {
    foreach ($rd_turmas as $t) {
        $s = nc_situacao((int)$t['id'], $nc_bim);
        if (!$s['disciplinas']) continue;
        $m = array_filter($s['medias'], fn($v) => $v !== null);
        $nc[] = ['t' => $t, 'fechada' => $s['fechada'], 'media' => $s['fechada'] && $m ? array_sum($m) / count($m) : null,
                 'feitas' => $s['feitas'], 'total' => count($s['disciplinas'])];
    }
    usort($nc, fn($a, $b) => [$b['fechada'], $b['media'] ?? 0] <=> [$a['fechada'], $a['media'] ?? 0]);
} catch (PDOException $e) { $nc_erro = true; }

$titulo = 'Resultados da escola';
$menu = 'resultados';
$usa_graficos = true;
require __DIR__ . '/../includes/layout_topo.php';
$aba_q = fn(array $x) => '?' . http_build_query(array_merge(['rb' => $rend_bim, 'nb' => $nc_bim], $x));
?>
<?php if ($rd_turmas): ?>
<section class="rd mb-4" id="resultados" data-bim="<?= $rd_bim ?>" data-turma="<?= (int)$rd_turmas[0]['id'] ?>" data-media="<?= MEDIA_APROVACAO ?>">
    <div class="rd-topo">
        <div class="rd-tit"><span class="rd-ic"><i class="bi bi-bar-chart-line-fill"></i></span><div><h2>Resultados por turma</h2><small>Escolha o período e a turma</small></div></div>
        <div class="rd-periodos" role="tablist" aria-label="Período">
            <?php foreach ([1, 2, 3, 4] as $b): ?><button type="button" data-bim="<?= $b ?>"<?= in_array($b, $rd_bims, true) ? '' : ' disabled title="Sem notas lançadas"' ?>><?= $b ?>º per.</button><?php endforeach; ?>
            <button type="button" data-bim="0">Ano</button>
        </div>
    </div>
    <nav class="rd-turmas" aria-label="Turma">
        <?php foreach ($rd_turmas as $t): ?><button type="button" data-turma="<?= $t['id'] ?>" style="--tc:<?= cor_curso($t['curso']) ?>"><?= $t['serie'] ?>º <?= e($t['sigla'] ?: $t['curso']) ?><?= count(array_filter($rd_turmas, fn($x) => $x['serie'] == $t['serie'] && $x['curso_id'] == $t['curso_id'])) > 1 ? ' ' . e($t['letra']) : '' ?></button><?php endforeach; ?>
    </nav>
    <div class="rd-corpo" id="rdCorpo"><div class="rd-carregando"><span></span>Carregando resultados…</div></div>
</section>
<?php endif; ?>

<div class="rs-grade mb-4">
    <!-- Rendimento por turma -->
    <section class="card rs-card" id="rendimento">
        <div class="card-header rs-cab">
            <div><h2><i class="bi bi-graph-up-arrow me-1"></i>Rendimento por turma</h2><small>Média das notas da planilha do Power BI · <?= $rend_bim ? $rend_bim . 'º período' : 'ano de ' . ANO_LETIVO ?></small></div>
            <div class="rs-per" role="group" aria-label="Período do rendimento">
                <?php foreach ([0, 1, 2, 3, 4] as $b): ?><a class="<?= $b === $rend_bim ? 'ativo' : '' ?>" href="<?= e($aba_q(['rb' => $b])) ?>#rendimento"><?= $b ? $b . 'º' : 'Ano' ?></a><?php endforeach; ?>
            </div>
        </div>
        <?php if ($notas_turma): ?>
        <div class="rs-kpis">
            <div><small>Média da escola</small><b class="<?= $media_escola < MEDIA_APROVACAO ? 'ruim' : '' ?>"><?= number_format($media_escola, 1, ',', '') ?></b></div>
            <div><small>Melhor turma</small><b><?= e($notas_turma[0]['serie'] . 'º ' . $notas_turma[0]['letra'] . ' ' . $notas_turma[0]['sigla']) ?></b></div>
            <div><small>Abaixo de <?= number_format(MEDIA_APROVACAO, 1, ',', '') ?></small><b class="<?= $abaixo ? 'ruim' : '' ?>"><?= $abaixo ?> turma<?= $abaixo === 1 ? '' : 's' ?></b></div>
        </div>
        <div class="card-body pt-0">
            <div class="grafico" style="height:<?= max(220, (int)ceil(count($notas_turma) * 28)) ?>px"><canvas id="gNotas" aria-label="Média de notas por turma"></canvas></div>
            <p class="rs-leg"><span class="ok"></span>na média ou acima <span class="ruim"></span>abaixo de <?= number_format(MEDIA_APROVACAO, 1, ',', '') ?> <a class="ms-auto" href="relatorios.php?aba=notas">Relatório detalhado <i class="bi bi-arrow-right"></i></a></p>
        </div>
        <?php else: ?>
        <div class="vazio"><i class="bi bi-bar-chart"></i><strong>Sem notas <?= $rend_bim ? 'neste período' : 'neste ano' ?></strong>O gráfico aparece depois que o diretor importar a planilha do Power BI.</div>
        <?php endif; ?>
    </section>

    <!-- Média conceitual por turma -->
    <section class="card rs-card" id="conceitual">
        <div class="card-header rs-cab">
            <div><h2><i class="bi bi-clipboard2-check me-1"></i>Média conceitual por turma</h2><small><?= $nc_bim ?>º bimestre · só aparece com todos os professores avaliados</small></div>
            <div class="rs-per" role="group" aria-label="Bimestre da nota conceitual">
                <?php foreach ([1, 2, 3, 4] as $b): ?><a class="<?= $b === $nc_bim ? 'ativo' : '' ?>" href="<?= e($aba_q(['nb' => $b])) ?>#conceitual"><?= $b ?>º</a><?php endforeach; ?>
            </div>
        </div>
        <?php if ($nc_erro): ?>
        <div class="vazio"><i class="bi bi-database-exclamation"></i><strong>Falta atualizar o banco</strong>Rode atualizacao_banco_v59.sql.</div>
        <?php elseif (!$nc): ?>
        <div class="vazio"><i class="bi bi-clipboard2"></i><strong>Nenhuma turma com professores vinculados</strong>Vincule professores e disciplinas em Professores e componentes.</div>
        <?php else: ?>
        <ul class="rs-nc">
            <?php foreach ($nc as $x): $t = $x['t']; ?>
            <li>
                <a href="nota_conceitual.php?aba=ver&amp;turma=<?= $t['id'] ?>&amp;bimestre=<?= $nc_bim ?>"><i class="turma-dot" style="background:<?= cor_curso($t['curso']) ?>"></i><?= e($t['serie'] . 'º ' . $t['letra'] . ' ' . ($t['sigla'] ?: $t['curso'])) ?></a>
                <?php if ($x['fechada']): ?>
                <span class="rs-nc-bar"><i style="width:<?= max(3, $x['media'] * 10) ?>%" class="<?= $x['media'] < MEDIA_APROVACAO ? 'ruim' : '' ?>"></i></span><b class="<?= $x['media'] < MEDIA_APROVACAO ? 'ruim' : '' ?>"><?= number_format($x['media'], 1, ',', '') ?></b>
                <?php else: ?>
                <span class="rs-nc-pend"><i style="width:<?= round($x['feitas'] / $x['total'] * 100) ?>%"></i><em><?= $x['feitas'] ?>/<?= $x['total'] ?> disciplinas</em></span><b class="rs-aguard" title="Restam professores colocarem a nota desta turma"><i class="bi bi-hourglass-split"></i></b>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
<?php
$script_pagina = '<script>
configurarGraficos();
const gn = document.getElementById("gNotas");
if (gn) new Chart(gn, { type: "bar",
  data: { labels: ' . json_encode(array_map(fn($t) => $t['serie'] . 'º ' . $t['letra'] . ' ' . $t['sigla'], $notas_turma)) . ',
          datasets: [{ label: "Média", data: ' . json_encode(array_map(fn($t) => (float)$t['media'], $notas_turma)) . ',
            backgroundColor: ' . json_encode(array_map(fn($t) => (float)$t['media'] >= MEDIA_APROVACAO ? '#13692a' : '#c0261b', $notas_turma)) . ', borderRadius: 6, maxBarThickness: 22 }] },
  options: { indexAxis: "y", plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 10 }, y: { grid: { display: false } } } } });
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
