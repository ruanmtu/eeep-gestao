<?php
require __DIR__ . '/../includes/app.php';
exigir('relatorios');

$aba = in_array($_GET['aba'] ?? '', ['frequencia', 'turmas', 'alerta', 'notas'], true) ? $_GET['aba'] : 'frequencia';
$curso_id = (int)($_GET['curso'] ?? 0);
$mes = (int)($_GET['mes'] ?? 0);
$ano = ANO_VER;   // escolhido no seletor de ano do topo
$ini = $mes ? sprintf('%04d-%02d-01', $ano, $mes) : "$ano-01-01";
$fim = $mes ? date('Y-m-t', strtotime($ini)) : "$ano-12-31";
$periodo_txt = $mes ? MESES[$mes] . ' de ' . $ano : 'ano de ' . $ano;

$wc = $curso_id ? ' AND t.curso_id = ' . $curso_id : '';   // inteiro já validado
$turmas_ano = q_todos("SELECT t.id, t.serie, t.letra, c.nome AS curso, c.sigla,
                              (SELECT COUNT(*) FROM aluno_turmas xt WHERE xt.turma_id = t.id AND xt.situacao IN ('ativo','concluido')) AS alunos,
                              (SELECT COUNT(*) FROM faltas f WHERE f.aluno_id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = t.id) AND f.data_falta BETWEEN ? AND ?) AS faltas,
                              (SELECT COUNT(*) FROM faltas f WHERE f.aluno_id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = t.id) AND f.justificada = 1 AND f.data_falta BETWEEN ? AND ?) AS justificadas
                         FROM turmas t JOIN cursos c ON c.id = t.curso_id
                        WHERE t.ano_letivo = ? $wc ORDER BY t.serie, t.letra", [$ini, $fim, $ini, $fim, $ano]);

if (!$mes) { $fpl = faltas_planilha_por_turma($ano); foreach ($turmas_ano as &$t) $t['faltas'] = (int)$t['faltas'] + ($fpl[(int)$t['id']] ?? 0); unset($t); }

$usa_graficos = true;
$script = '';

/* ---------- Dados por aba ---------- */
if ($aba === 'frequencia') {
    $series = [];
    foreach (q_todos("SELECT c.nome AS curso, MONTH(f.data_falta) AS m, COUNT(*) AS n
                        FROM faltas f JOIN alunos a ON a.id = f.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                       WHERE f.data_falta BETWEEN ? AND ? $wc GROUP BY c.nome, MONTH(f.data_falta)", ["$ano-01-01", "$ano-12-31"]) as $r) {
        $series[$r['curso']] ??= array_fill(1, 12, 0);
        $series[$r['curso']][(int)$r['m']] = (int)$r['n'];
    }
    $por_curso = [];
    foreach ($turmas_ano as $t) $por_curso[$t['curso']] = ($por_curso[$t['curso']] ?? 0) + (int)$t['faltas'];
    arsort($por_curso);
    $cor_curso = array_flip(array_column(cursos_lista(false), 'nome'));   // mesma cor para o mesmo curso nos dois gráficos
    ksort($series);
    $ds = [];
    foreach ($series as $nome => $vals) $ds[] = ['label' => $nome, 'data' => array_values($vals), 'cor' => cor_curso($nome)];
    $script = '<script>configurarGraficos();
    new Chart(document.getElementById("gMeses"), { type: "line",
      data: { labels: ' . json_encode(array_values(MESES_CURTO)) . ', datasets: ' . json_encode($ds) . '.map(d => ({ label: d.label, data: d.data, borderColor: d.cor, backgroundColor: d.cor, cubicInterpolationMode: "monotone", pointRadius: 3, borderWidth: 2.5 })) },
      options: { interaction: { mode: "index", intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
    new Chart(document.getElementById("gCursos"), { type: "doughnut",
      data: { labels: ' . json_encode(array_keys($por_curso)) . ', datasets: [{ data: ' . json_encode(array_values($por_curso)) . ', backgroundColor: ' . json_encode(array_map(fn($n) => cor_curso($n), array_keys($por_curso))) . ', borderColor: "#fff", borderWidth: 3 }] },
      options: { cutout: "62%", plugins: { legend: { position: "bottom" } } } });
    </script>';
}

if ($aba === 'turmas') {
    $dias = [];
    foreach (q_todos("SELECT t.id AS turma_id, DAYOFWEEK(f.data_falta) AS d, COUNT(*) AS n FROM faltas f JOIN alunos a ON a.id = f.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id)
                      WHERE f.data_falta BETWEEN ? AND ? $wc GROUP BY t.id, DAYOFWEEK(f.data_falta)", [$ini, $fim]) as $r) {
        $dias[$r['turma_id']][(int)$r['d']] = (int)$r['n'];
    }
    $max_dia = 1; foreach ($dias as $l) $max_dia = max($max_dia, ...array_values($l));
    $top = q_todos("SELECT a.id, a.nome, t.serie, t.letra, c.nome AS curso, COUNT(f.id) AS n
                      FROM faltas f JOIN alunos a ON a.id = f.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                     WHERE f.data_falta BETWEEN ? AND ? $wc GROUP BY a.id, a.nome, t.serie, t.letra, c.nome ORDER BY n DESC, a.nome LIMIT 20", [$ini, $fim]);
    $ord = $turmas_ano;
    usort($ord, fn($a, $b) => ($b['alunos'] ? $b['faltas'] / $b['alunos'] : 0) <=> ($a['alunos'] ? $a['faltas'] / $a['alunos'] : 0));
    $script = '<script>configurarGraficos();
    new Chart(document.getElementById("gTurmas"), { type: "bar",
      data: { labels: ' . json_encode(array_map(fn($t) => $t['serie'] . 'º ' . $t['letra'] . ' ' . $t['sigla'], $ord)) . ',
              datasets: [{ label: "Faltas por aluno", data: ' . json_encode(array_map(fn($t) => $t['alunos'] ? round($t['faltas'] / $t['alunos'], 2) : 0, $ord)) . ', backgroundColor: "#13692a", borderRadius: 5, maxBarThickness: 26 }] },
      options: { indexAxis: "y", plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true }, y: { grid: { display: false } } } } });
    </script>';
}

if ($aba === 'alerta') {
    $minimo = max(1, (int)($_GET['minimo'] ?? FALTAS_ATENCAO));
    $alerta = q_todos("SELECT a.id, a.nome, a.responsavel_nome, a.responsavel_telefone, t.serie, t.letra, c.nome AS curso,
                              COUNT(f.id) AS n, SUM(CASE WHEN f.justificada = 1 THEN 0 ELSE 1 END) AS nj, MAX(f.data_falta) AS ultima
                         FROM faltas f JOIN alunos a ON a.id = f.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                        WHERE a.situacao = 'ativo' AND f.data_falta BETWEEN ? AND ? $wc
                        GROUP BY a.id, a.nome, a.responsavel_nome, a.responsavel_telefone, t.serie, t.letra, c.nome
                       HAVING COUNT(f.id) >= ? ORDER BY n DESC, a.nome", [$ini, $fim, $minimo]);
    if (($_GET['exportar'] ?? '') === 'csv') {
        enviar_csv('busca-ativa-' . date('Y-m-d') . '.csv', ['Aluno', 'Turma', 'Faltas', 'Não justificadas', 'Última falta', 'Responsável', 'Telefone'],
            array_map(fn($a) => [nome_proprio($a['nome']), turma_nome($a), $a['n'], $a['nj'], data_br($a['ultima']), $a['responsavel_nome'], $a['responsavel_telefone']], $alerta));
    }
    $usa_graficos = false;
}

if ($aba === 'notas') {
    $bim = (int)($_GET['bimestre'] ?? 0);
    $wb = $bim ? ' AND n.bimestre = ' . $bim : '';
    $medias_turma = q_todos("SELECT t.id, t.serie, t.letra, c.nome AS curso, ROUND(AVG(n.nota), 1) AS media, COUNT(n.id) AS lancadas,
                                    SUM(CASE WHEN n.nota < ? THEN 1 ELSE 0 END) AS abaixo
                               FROM notas n JOIN alunos a ON a.id = n.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                              WHERE n.ano_letivo = ? $wb $wc GROUP BY t.id, t.serie, t.letra, c.nome ORDER BY t.serie, t.letra", [MEDIA_APROVACAO, $ano]);
    $baixo = q_todos("SELECT a.id, a.nome, t.serie, t.letra, c.nome AS curso, d.nome AS disciplina, ROUND(AVG(n.nota), 1) AS media
                        FROM notas n JOIN alunos a ON a.id = n.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                        JOIN disciplinas d ON d.id = n.disciplina_id
                       WHERE n.ano_letivo = ? AND a.situacao = 'ativo' $wb $wc
                       GROUP BY a.id, a.nome, t.serie, t.letra, c.nome, d.nome HAVING AVG(n.nota) < ? ORDER BY t.serie, t.letra, a.nome LIMIT 400", [$ano, MEDIA_APROVACAO]);
    $usa_graficos = false;
}

$titulo = 'Relatórios';
$subtitulo = 'Período: ' . $periodo_txt . ($curso_id ? ' · ' . q_valor('SELECT nome FROM cursos WHERE id = ?', [$curso_id]) : ' · todos os cursos');
$menu = 'relatorios';
require __DIR__ . '/../includes/layout_topo.php';
$base_qs = http_build_query(['curso' => $curso_id ?: null, 'mes' => $mes ?: null, 'ano' => $ano]);
?>

<ul class="nav nav-abas mb-3">
    <?php foreach (['frequencia' => 'Frequência', 'turmas' => 'Comparativo de turmas', 'alerta' => 'Busca ativa', 'notas' => 'Desempenho'] as $k => $v): ?>
        <li class="nav-item"><a class="nav-link<?= $aba === $k ? ' active' : '' ?>" href="?aba=<?= $k ?>&<?= $base_qs ?>"><?= $v ?></a></li>
    <?php endforeach; ?>
</ul>

<form class="filtros row g-2 align-items-end" method="get">
    <input type="hidden" name="aba" value="<?= e($aba) ?>">
    <div class="col-md-4"><label class="form-label" for="f_curso">Curso</label>
        <select class="form-select" id="f_curso" name="curso"><option value="">Todos os cursos</option>
        <?php foreach (cursos_lista() as $c): ?><option value="<?= $c['id'] ?>"<?= $curso_id === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
    <?php if ($aba !== 'notas'): ?>
    <div class="col-6 col-md-2"><label class="form-label" for="f_mes">Mês</label>
        <select class="form-select" id="f_mes" name="mes"><option value="">Ano inteiro</option>
        <?php foreach (MESES as $n => $m): ?><option value="<?= $n ?>"<?= $mes === $n ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></div>
    <?php else: ?>
    <div class="col-6 col-md-2"><label class="form-label" for="f_bim">Bimestre</label>
        <select class="form-select" id="f_bim" name="bimestre"><option value="">Todos</option>
        <?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>"<?= ($bim ?? 0) === $b ? ' selected' : '' ?>><?= $b ?>º bimestre</option><?php endfor; ?></select></div>
    <?php endif; ?>
    <?php if ($aba === 'alerta'): ?>
    <div class="col-6 col-md-2"><label class="form-label" for="f_min">A partir de</label>
        <div class="input-group"><input type="number" min="1" class="form-control" id="f_min" name="minimo" value="<?= $minimo ?>"><span class="input-group-text">faltas</span></div></div>
    <?php endif; ?>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Aplicar</button>
        <button class="btn btn-light" type="button" onclick="window.print()" title="Imprimir" aria-label="Imprimir"><i class="bi bi-printer"></i></button></div>
</form>

<?= cabecalho_impressao('Relatório · ' . ['frequencia' => 'Frequência', 'turmas' => 'Comparativo de turmas', 'alerta' => 'Busca ativa', 'notas' => 'Desempenho'][$aba], $subtitulo) ?>

<?php if ($aba === 'frequencia'):
    $tf = array_sum(array_column($turmas_ano, 'faltas')); $ta = array_sum(array_column($turmas_ano, 'alunos')); $tj = array_sum(array_column($turmas_ano, 'justificadas')); ?>
    <div class="row g-3 mb-3">
        <div class="col-sm-4"><div class="indicador indicador-destaque"><div class="ic"><i class="bi bi-calendar-x"></i></div><div><div class="rotulo">Faltas no período</div><div class="valor"><?= $tf ?></div><div class="nota"><?= $tj ?> justificadas</div></div></div></div>
        <div class="col-sm-4"><div class="indicador"><div class="ic"><i class="bi bi-person"></i></div><div><div class="rotulo">Média por aluno</div><div class="valor"><?= num($ta ? $tf / $ta : 0) ?></div><div class="nota"><?= $ta ?> alunos ativos</div></div></div></div>
        <div class="col-sm-4"><div class="indicador"><div class="ic ambar"><i class="bi bi-percent"></i></div><div><div class="rotulo">Sem justificativa</div><div class="valor"><?= $tf ? round(($tf - $tj) / $tf * 100) : 0 ?>%</div><div class="nota">das faltas do período</div></div></div></div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-xl-8"><div class="card h-100"><div class="card-header"><h2>Faltas por mês e curso em <?= $ano ?></h2></div><div class="card-body"><div class="grafico"><canvas id="gMeses"></canvas></div></div></div></div>
        <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h2>Distribuição por curso</h2></div><div class="card-body"><div class="grafico"><canvas id="gCursos"></canvas></div></div></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h2>Resumo por turma</h2></div>
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Turma</th><th class="num">Alunos</th><th class="num">Faltas</th><th class="num">Justificadas</th><th class="num">Por aluno</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($turmas_ano as $t): $m = $t['alunos'] ? $t['faltas'] / $t['alunos'] : 0;
                [$cls, $txt] = $m >= 3 ? ['vermelho', 'Crítica'] : ($m >= 1.5 ? ['ambar', 'Atenção'] : ['', 'Regular']); ?>
                <tr><td><a class="nome-link" href="alunos.php?turma=<?= $t['id'] ?>"><?= e(turma_nome($t)) ?></a></td>
                    <td class="num"><?= $t['alunos'] ?></td><td class="num"><strong><?= $t['faltas'] ?></strong></td><td class="num"><?= $t['justificadas'] ?></td>
                    <td class="num"><?= num($m) ?></td><td><span class="etiqueta <?= $cls ?>"><?= $txt ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($aba === 'turmas'): ?>
    <div class="row g-3 mb-3">
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h2>Faltas por aluno, por turma</h2></div><div class="card-body"><div class="grafico" style="height:<?= max(260, count($ord) * 30) ?>px"><canvas id="gTurmas"></canvas></div></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h2>Em que dia cada turma mais falta</h2></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Turma</th><?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex'] as $d): ?><th class="num"><?= $d ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($turmas_ano as $t): ?><tr><td class="text-nowrap"><?= e($t['serie'] . 'º ' . $t['letra'] . ' · ' . $t['sigla']) ?></td>
                    <?php for ($d = 2; $d <= 6; $d++): $n = $dias[$t['id']][$d] ?? 0; $alpha = $n ? 0.12 + 0.75 * $n / $max_dia : 0; ?>
                        <td class="num" style="background:rgba(19,105,42,<?= round($alpha, 2) ?>);color:<?= $alpha > .5 ? '#fff' : 'inherit' ?>"><?= $n ?: '·' ?></td>
                    <?php endfor; ?></tr><?php endforeach; ?></tbody>
            </table></div></div></div>
    </div>
    <div class="card"><div class="card-header"><h2>20 alunos com mais faltas</h2></div>
        <?php if ($top): ?><div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>#</th><th>Aluno</th><th>Turma</th><th class="num">Faltas</th></tr></thead>
            <tbody><?php foreach ($top as $i => $a): ?><tr><td class="text-secondary"><?= $i + 1 ?></td><td><a class="nome-link" href="aluno.php?id=<?= $a['id'] ?>"><?= e(nome_proprio($a['nome'])) ?></a></td>
                <td><?= e(turma_nome($a)) ?></td><td class="num"><?= badge_faltas((int)$a['n']) ?></td></tr><?php endforeach; ?></tbody>
        </table></div><?php else: ?><div class="vazio"><i class="bi bi-emoji-smile"></i><strong>Nenhuma falta no período</strong></div><?php endif; ?>
    </div>

<?php elseif ($aba === 'alerta'): ?>
    <div class="card">
        <div class="card-header"><h2>Alunos com <?= $minimo ?> faltas ou mais</h2><span class="etiqueta ambar"><?= count($alerta) ?> aluno(s)</span>
            <div class="acoes"><a class="btn btn-sm btn-light" href="?aba=alerta&<?= $base_qs ?>&minimo=<?= $minimo ?>&exportar=csv"><i class="bi bi-filetype-csv me-1"></i>Exportar</a></div></div>
        <?php if ($alerta): ?><div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Aluno</th><th>Turma</th><th class="num">Faltas</th><th class="num">Sem justif.</th><th>Última</th><th>Responsável</th></tr></thead>
            <tbody><?php foreach ($alerta as $a): $tel = preg_replace('/\D/', '', (string)$a['responsavel_telefone']); ?>
                <tr><td><a class="nome-link" href="aluno.php?id=<?= $a['id'] ?>#frequencia"><?= e(nome_proprio($a['nome'])) ?></a></td><td><?= e(turma_nome($a)) ?></td>
                    <td class="num"><?= badge_faltas((int)$a['n']) ?></td><td class="num"><?= (int)$a['nj'] ?></td><td><?= data_br($a['ultima']) ?></td>
                    <td><?php if ($a['responsavel_telefone']): ?><?= e($a['responsavel_nome'] ?: 'Responsável') ?><br>
                        <a href="https://wa.me/55<?= e($tel) ?>" target="_blank" rel="noopener" class="small"><i class="bi bi-whatsapp me-1"></i><?= e($a['responsavel_telefone']) ?></a>
                        <?php else: ?><a class="small" href="aluno_form.php?id=<?= $a['id'] ?>">Cadastrar telefone</a><?php endif; ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div><?php else: ?><div class="vazio"><i class="bi bi-emoji-smile"></i><strong>Nenhum aluno atingiu <?= $minimo ?> faltas no período</strong>Diminua o número mínimo para ver quem está mais perto.</div><?php endif; ?>
    </div>

<?php else: ?>
    <div class="row g-3">
        <div class="col-xl-5"><div class="card h-100"><div class="card-header"><h2>Média por turma</h2></div>
            <?php if ($medias_turma): ?><div class="table-responsive"><table class="table">
                <thead><tr><th>Turma</th><th class="num">Média</th><th class="num">Notas abaixo de <?= num(MEDIA_APROVACAO) ?></th></tr></thead>
                <tbody><?php foreach ($medias_turma as $t): ?><tr><td><?= e(turma_nome($t)) ?></td><td class="num"><?= nota_fmt($t['media']) ?></td><td class="num"><?= $t['abaixo'] ?> de <?= $t['lancadas'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div><?php else: ?><div class="vazio"><i class="bi bi-journal"></i><strong>Nenhuma nota lançada ainda</strong>Os professores lançam pelo menu Notas.</div><?php endif; ?>
        </div></div>
        <div class="col-xl-7"><div class="card h-100"><div class="card-header"><h2>Alunos abaixo da média</h2><span class="etiqueta vermelho"><?= count($baixo) ?></span></div>
            <?php if ($baixo): ?><div class="table-responsive tabela-rolagem"><table class="table table-hover">
                <thead><tr><th>Aluno</th><th>Turma</th><th>Disciplina</th><th class="num">Média</th></tr></thead>
                <tbody><?php foreach ($baixo as $b): ?><tr><td><a class="nome-link" href="aluno.php?id=<?= $b['id'] ?>#notas"><?= e(nome_proprio($b['nome'])) ?></a></td><td class="small"><?= e(turma_nome($b)) ?></td><td><?= e($b['disciplina']) ?></td><td class="num"><?= nota_fmt($b['media']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div><?php else: ?><div class="vazio"><i class="bi bi-trophy"></i><strong>Ninguém abaixo da média</strong></div><?php endif; ?>
        </div></div>
    </div>
<?php endif; ?>

<?php
$script_pagina = $script;
require __DIR__ . '/../includes/layout_rodape.php';
