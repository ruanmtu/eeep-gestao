<?php
require __DIR__ . '/../includes/app.php';
exigir('busca_ativa');

/* Turmas que a pessoa acompanha (diretor de turma vê só as suas) */
$turmas = eh_gestao() ? turmas_lista() : turmas_dirigidas();
$ids_turmas = array_map('intval', array_column($turmas, 'id'));
$filtro_turmas = $ids_turmas ? 'a.turma_id IN (' . implode(',', $ids_turmas) . ')' : '1 = 0';

$f_turma = in_array((int)($_GET['turma'] ?? 0), $ids_turmas, true) ? (int)$_GET['turma'] : 0;
$f_acao = isset(ACOES_BUSCA[$_GET['acao'] ?? '']) ? $_GET['acao'] : '';
$f_mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
$ini_mes = $f_mes . '-01'; $fim_mes = date('Y-m-t', strtotime($ini_mes));
$hoje = date('Y-m-d');

/* 1. Precisam de atenção: alerta de faltas seguidas ou muitas faltas sem justificativa nos últimos 30 dias */
$atencao = [];
foreach (alertas_sequencia(true) as $al) {
    $atencao[(int)$al['aluno_id']] = ['motivo' => count($al['dias']) . ' faltas seguidas (até ' . data_br($al['fim']) . ')', 'peso' => 100 + count($al['dias'])];
}
$lim = date('Y-m-d', strtotime('-30 days'));
foreach (q_todos("SELECT f.aluno_id, COUNT(*) AS n FROM faltas f JOIN alunos a ON a.id = f.aluno_id
                   WHERE a.situacao = 'ativo' AND f.justificada = 0 AND f.data_falta >= ? GROUP BY f.aluno_id HAVING COUNT(*) >= 5", [$lim]) as $r) {
    $atencao[(int)$r['aluno_id']] ??= ['motivo' => $r['n'] . ' faltas sem justificativa em 30 dias', 'peso' => (int)$r['n']];
}
$lista_atencao = [];
if ($atencao) {
    $ultima = [];
    foreach (q_todos('SELECT aluno_id, MAX(data_acao) AS d FROM busca_ativa WHERE aluno_id IN (' . implode(',', array_keys($atencao)) . ') GROUP BY aluno_id') as $r) $ultima[(int)$r['aluno_id']] = $r['d'];
    foreach (q_todos("SELECT a.id, a.nome, a.foto, a.responsavel_nome, a.responsavel_telefone, a.nome_mae, t.serie, t.letra, c.nome AS curso, c.sigla
                        FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                       WHERE a.id IN (" . implode(',', array_keys($atencao)) . ") AND $filtro_turmas" . ($f_turma ? ' AND a.turma_id = ' . $f_turma : '')) as $a) {
        $ul = $ultima[(int)$a['id']] ?? null;
        if ($ul && $ul >= date('Y-m-d', strtotime('-7 days')) && $atencao[(int)$a['id']]['peso'] < 100) continue;   // já teve providência na última semana
        $lista_atencao[] = $a + $atencao[(int)$a['id']] + ['ultima' => $ul];
    }
    usort($lista_atencao, fn($x, $y) => $y['peso'] <=> $x['peso']);
}

/* 2. Retornos previstos (aluno deveria voltar) */
$retornos = busca_lista("b.retorno_previsto BETWEEN ? AND ? AND $filtro_turmas", [date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+7 days'))], 50);

/* 3. Providências do mês */
$w = "b.data_acao BETWEEN ? AND ? AND $filtro_turmas"; $p = [$ini_mes, $fim_mes];
if ($f_turma) { $w .= ' AND a.turma_id = ?'; $p[] = $f_turma; }
if ($f_acao) { $w .= ' AND b.acao = ?'; $p[] = $f_acao; }
$registros = busca_lista($w, $p, 500);
$alunos_mes = count(array_unique(array_column($registros, 'aluno_id')));
$retornaram = count(array_filter($registros, fn($r) => $r['resultado'] === 'aluno_retornou'));
$por_acao = array_count_values(array_column($registros, 'acao'));
arsort($por_acao);

$titulo = 'Busca ativa';
$subtitulo = 'Providências do diretor de turma e da gestão para trazer o aluno de volta e garantir a permanência na escola.';
$menu = 'busca_ativa';
require __DIR__ . '/../includes/layout_topo.php';
?>

<section class="kpis mb-4">
    <a class="kpi kpi-<?= $lista_atencao ? 'vermelho' : 'verde' ?>" href="#atencao"><span class="kpi-ic"><i class="bi bi-exclamation-triangle"></i></span><span><strong><?= count($lista_atencao) ?></strong><small>precisam de atenção</small></span></a>
    <a class="kpi kpi-laranja" href="#registros"><span class="kpi-ic"><i class="bi bi-telephone-outbound"></i></span><span><strong><?= count($registros) ?></strong><small>providências em <?= e(mb_strtolower(MESES[(int)substr($f_mes, 5, 2)])) ?></small></span></a>
    <a class="kpi kpi-verde" href="#registros"><span class="kpi-ic"><i class="bi bi-people"></i></span><span><strong><?= $alunos_mes ?></strong><small>alunos acompanhados</small></span></a>
    <a class="kpi kpi-verde" href="#registros"><span class="kpi-ic"><i class="bi bi-arrow-return-left"></i></span><span><strong><?= $retornaram ?></strong><small>alunos que retornaram</small></span></a>
</section>

<div class="row g-4">
    <div class="col-xl-5">
        <div class="card mb-4" id="atencao">
            <div class="card-header"><h2>Precisam de atenção</h2><span class="etiqueta <?= $lista_atencao ? 'vermelho' : 'cinza' ?>"><?= count($lista_atencao) ?></span></div>
            <?php if ($lista_atencao): ?>
            <ul class="ba-atencao">
                <?php foreach ($lista_atencao as $a): ?>
                <li>
                    <?= avatar($a, 'aluno', 'avatar-sm') ?>
                    <div class="flex-grow-1 min-w-0">
                        <a class="nome-link fw-semibold" href="aluno.php?id=<?= $a['id'] ?>#busca"><?= e(nome_proprio($a['nome'])) ?></a>
                        <small class="d-block text-secondary"><?= e($a['serie'] . 'º ' . $a['letra'] . ' · ' . ($a['sigla'] ?: $a['curso'])) ?></small>
                        <small class="d-block text-danger fw-semibold"><?= e($a['motivo']) ?></small>
                        <small class="d-block text-secondary"><?= $a['ultima'] ? 'Última providência: ' . data_br($a['ultima']) : 'Nenhuma providência registrada' ?>
                            <?= ($a['responsavel_telefone'] ?? '') ? ' · <a href="tel:' . e(preg_replace('/\D/', '', $a['responsavel_telefone'])) . '"><i class="bi bi-telephone"></i> ' . e($a['responsavel_telefone']) . '</a>' : '' ?></small>
                    </div>
                    <a class="btn btn-sm btn-laranja text-nowrap" href="aluno.php?id=<?= $a['id'] ?>#busca"><i class="bi bi-plus-lg"></i> Registrar</a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><div class="vazio py-4"><i class="bi bi-emoji-smile"></i>Nenhum aluno com faltas seguidas ou muitas faltas sem providência.</div><?php endif; ?>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h2>Retornos previstos</h2><span class="small text-secondary">próximos 7 dias</span></div>
            <?php if ($retornos): ?><ul class="ba-atencao">
                <?php foreach ($retornos as $r): ?>
                <li><span class="ag-data <?= $r['retorno_previsto'] < $hoje ? 'vermelho' : ($r['retorno_previsto'] === $hoje ? 'ambar' : 'verde') ?>"><b><?= date('d', strtotime($r['retorno_previsto'])) ?></b><?= MESES_CURTO[(int)substr($r['retorno_previsto'], 5, 2)] ?></span>
                    <div class="flex-grow-1 min-w-0"><a class="nome-link fw-semibold" href="aluno.php?id=<?= $r['aluno_id'] ?>#busca"><?= e(nome_proprio($r['nome'])) ?></a>
                        <small class="d-block text-secondary"><?= e($r['serie'] . 'º ' . $r['letra']) ?> · <?= e(ACOES_BUSCA[$r['acao']][0] ?? '') ?> em <?= data_br($r['data_acao']) ?></small></div></li>
                <?php endforeach; ?></ul>
            <?php else: ?><div class="vazio py-4"><i class="bi bi-calendar-check"></i>Nenhum retorno combinado para os próximos dias.</div><?php endif; ?>
        </div>

        <?php if ($por_acao): $mx = max($por_acao); ?>
        <div class="card"><div class="card-header"><h2>Providências por tipo</h2><span class="small text-secondary">no mês</span></div>
            <div class="card-body py-2"><ul class="lista-linhas"><?php foreach ($por_acao as $k => $n): ?>
                <li><span style="width:200px" class="text-truncate"><i class="bi bi-<?= ACOES_BUSCA[$k][1] ?? 'dot' ?> me-1"></i><?= e(ACOES_BUSCA[$k][0] ?? $k) ?></span><span class="barra-prop"><span style="width:<?= round($n / $mx * 100) ?>%"></span></span><strong style="width:28px;text-align:right"><?= $n ?></strong></li>
            <?php endforeach; ?></ul></div></div>
        <?php endif; ?>
    </div>

    <div class="col-xl-7">
        <div class="card" id="registros">
            <div class="card-header"><h2>Providências registradas</h2>
                <button class="btn btn-sm btn-light ms-auto nao-imprimir" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir relatório</button></div>
            <form class="card-body border-bottom d-flex gap-2 flex-wrap nao-imprimir" method="get">
                <input class="form-control form-control-sm" type="month" name="mes" value="<?= e($f_mes) ?>" style="width:auto" aria-label="Mês">
                <select class="form-select form-select-sm" name="turma" style="width:auto" aria-label="Turma"><option value="">Todas as turmas</option>
                    <?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= $f_turma === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select>
                <select class="form-select form-select-sm" name="acao" style="width:auto" aria-label="Providência"><option value="">Todas as providências</option>
                    <?php foreach (ACOES_BUSCA as $k => [$r]): ?><option value="<?= $k ?>"<?= $f_acao === $k ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-primary">Filtrar</button>
            </form>
            <?= cabecalho_impressao('Busca ativa escolar · ' . MESES[(int)substr($f_mes, 5, 2)] . ' de ' . substr($f_mes, 0, 4), count($registros) . ' providência(s) · ' . $alunos_mes . ' aluno(s)') ?>
            <?php if ($registros): ?><ul class="ba-lista"><?php foreach ($registros as $b) echo busca_cartao($b, true); ?></ul>
            <?php else: ?><div class="vazio py-5"><i class="bi bi-telephone-outbound"></i><strong>Nenhuma providência neste filtro</strong>Para registrar, abra a ficha do aluno na aba "Busca ativa".</div><?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_rodape.php';
