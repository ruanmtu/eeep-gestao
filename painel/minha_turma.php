<?php
/* Painel da turma: o(a) professor(a) diretor(a) de turma acompanha a turma dele
 * como a gestão acompanha a escola. Só leitura e registro: excluir é da gestão. */
require __DIR__ . '/../includes/app.php';
exigir_login();

$minhas = turmas_dirigidas();
$opcoes = eh_gestao() ? turmas_lista() : $minhas;
if (!$opcoes) {
    flash('warning', eh_gestao() ? 'Não há turmas cadastradas neste ano letivo.' : 'Você não é diretor(a) de turma. A coordenação define o diretor de cada turma em "Cursos e turmas".');
    redirecionar('index.php');
}
$tid = (int)($_GET['turma'] ?? 0);
if (!$tid || !pode_painel_turma($tid)) $tid = (int)($minhas[0]['id'] ?? $opcoes[0]['id']);
$turma = q_um('SELECT t.*, c.nome AS curso, c.sigla, u.nome AS dt_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id LEFT JOIN usuarios u ON u.id = t.diretor_turma_id WHERE t.id = ?', [$tid]);
$aba = in_array($_GET['aba'] ?? '', ['alunos', 'frequencia', 'notas', 'ocorrencias', 'observacoes', 'busca', 'autorizacoes'], true) ? $_GET['aba'] : 'alunos';
$R = turma_resumo($tid);
$ini_ano = ANO_LETIVO . '-01-01';

/* alunos em ordem alfabética (o nº é a posição na lista) */
$alunos = q_todos("SELECT a.* FROM alunos a WHERE a.turma_id = ? AND a.situacao = 'ativo' ORDER BY a.nome", [$tid]);
if (class_exists('Collator')) { $col = new Collator('pt_BR'); usort($alunos, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); }
$ids = array_map('intval', array_column($alunos, 'id'));
$in = $ids ? implode(',', $ids) : '0';
$por = fn(string $sql, array $p = []) => array_column(q_todos($sql, $p), 'n', 'aluno_id');
$f_mes = $por("SELECT aluno_id, COUNT(*) AS n FROM faltas WHERE aluno_id IN ($in) AND data_falta >= ? GROUP BY aluno_id", [date('Y-m-01')]);
$f_ano = $por("SELECT aluno_id, COUNT(*) AS n FROM faltas WHERE aluno_id IN ($in) AND data_falta >= ? GROUP BY aluno_id", [$ini_ano]);
$f_sem_just = $por("SELECT aluno_id, COUNT(*) AS n FROM faltas WHERE aluno_id IN ($in) AND data_falta >= ? AND justificada = 0 GROUP BY aluno_id", [$ini_ano]);
$oc_ano = $por("SELECT aluno_id, COUNT(*) AS n FROM ocorrencias WHERE aluno_id IN ($in) AND data_ocorrencia >= ? GROUP BY aluno_id", [$ini_ano]);
$med = $por("SELECT aluno_id, AVG(nota) AS n FROM notas WHERE aluno_id IN ($in) AND ano_letivo = ? GROUP BY aluno_id", [ANO_LETIVO]);
/* observações que os professores registram nos alunos da turma (o DT acompanha aqui) */
$obs = [];
try { $obs = q_todos("SELECT o.*, a.nome AS aluno_nome, a.foto AS aluno_foto, u.foto AS usuario_foto FROM aluno_observacoes o JOIN alunos a ON a.id = o.aluno_id LEFT JOIN usuarios u ON u.id = o.usuario_id
                        WHERE a.turma_id = ? AND a.situacao = 'ativo' ORDER BY o.criado_em DESC, o.id DESC LIMIT 400", [$tid]); } catch (PDOException $e) {}
$obs_30 = count(array_filter($obs, fn($o) => $o['criado_em'] >= date('Y-m-d', strtotime('-30 days'))));
$oc_ano_total = array_sum($oc_ano);
$em_alerta = array_flip(array_map(fn($a) => (int)$a['aluno_id'], $R['alertas']));

$titulo = 'Minha turma';
$subtitulo = turma_nome($turma) . ' · diretor(a) de turma: ' . ($turma['dt_nome'] ? primeiro_ultimo($turma['dt_nome']) : 'não definido');
$menu = 'minha_turma';
require __DIR__ . '/../includes/layout_topo.php';
?>

<?php $cor_t = cor_curso($turma['curso']); $dt_ini = $turma['dt_nome'] ? mb_strtoupper(mb_substr($turma['dt_nome'], 0, 1) . mb_substr(strrchr(' ' . $turma['dt_nome'], ' '), 1, 1)) : '?'; ?>
<section class="mt-hero mb-3 nao-imprimir" style="--tc:<?= $cor_t ?>">
    <span class="mt-deco" aria-hidden="true"><i></i></span>
    <div class="mt-turma"><b><?= (int)$turma['serie'] ?>º<?= e($turma['letra']) ?></b><span><?= e($turma['sigla'] ?: mb_substr($turma['curso'], 0, 3)) ?></span></div>
    <div class="mt-tx">
        <span class="mt-sobre"><i class="bi bi-person-workspace"></i>Painel do diretor de turma</span>
        <h2><?= e(turma_nome($turma)) ?></h2>
        <div class="mt-meta">
            <span class="mt-dt"><i><?= e($dt_ini) ?></i><span><small>Diretor(a) de turma</small><?= e($turma['dt_nome'] ? primeiro_ultimo($turma['dt_nome']) : 'não definido') ?></span></span>
            <span><small>Curso</small><?= e($turma['curso']) ?></span>
            <span><small>Ano letivo</small><?= ANO_LETIVO ?></span>
        </div>
    </div>
    <div class="mt-acoes">
        <?php if (count($opcoes) > 1): ?>
        <form method="get"><input type="hidden" name="aba" value="<?= e($aba) ?>">
            <select class="form-select" name="turma" onchange="this.form.submit()" aria-label="Trocar de turma">
                <?php foreach ($opcoes as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$t['id'] === $tid ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?>
            </select></form>
        <?php endif; ?>
        <button class="btn btn-light" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
        <small><i class="bi bi-info-circle me-1"></i>Registrar e editar pode; excluir é só com a gestão.</small>
    </div>
</section>

<section class="mt-kpis mb-3" aria-label="Números da turma">
    <?php $kp = [
        ['people-fill', 'Alunos ativos', $R['alunos'], '', 'verde', 'alunos'],
        ['calendar2-x-fill', 'Faltas hoje', $R['faltas_hoje'], $R['faltas_sem'] . ' na semana', 'laranja', 'frequencia'],
        ['exclamation-triangle-fill', 'Em alerta de faltas', count($R['alertas']), FALTAS_SEGUIDAS_ALERTA . '+ faltas seguidas', count($R['alertas']) ? 'vermelho' : 'verde', 'frequencia#alertas'],
        ['shield-fill-exclamation', 'Ocorrências no mês', $R['oc_mes'], $R['oc_hoje'] . ' hoje', $R['oc_hoje'] ? 'vermelho' : 'azul', 'ocorrencias'],
        ['chat-square-heart-fill', 'Observações', $obs_30, 'nos últimos 30 dias', 'roxo', 'observacoes'],
        ['journal-check', 'Média geral', $R['media'] ? num($R['media'], 1) : '–', ANO_LETIVO, $R['media'] && $R['media'] < MEDIA_APROVACAO ? 'vermelho' : 'verde', 'notas'],
    ];
    foreach ($kp as $i => [$ic, $rot, $val, $sub, $cor, $ab]): $n = is_numeric($val) ? (float)$val : null; ?>
    <a class="mt-kpi c-<?= $cor ?>" href="?turma=<?= $tid ?>&amp;aba=<?= $ab ?>" style="--i:<?= $i ?>">
        <span class="mt-kpi-ic"><i class="bi bi-<?= $ic ?>"></i></span>
        <strong<?= $n !== null && is_int($val) ? ' data-contar="' . (int)$val . '"' : '' ?>><?= e((string)$val) ?></strong>
        <span class="mt-kpi-rot"><?= e($rot) ?></span>
        <?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?>
        <i class="mt-kpi-seta bi bi-arrow-up-right"></i>
    </a>
    <?php endforeach; ?>
</section>

<nav class="mt-abas mb-3 nao-imprimir" aria-label="Seções da turma">
    <?php foreach (['alunos' => ['people', 'Alunos', count($alunos)], 'frequencia' => ['calendar2-check', 'Frequência', null], 'notas' => ['journal-check', 'Notas', null], 'ocorrencias' => ['shield-exclamation', 'Ocorrências', $oc_ano_total], 'observacoes' => ['chat-square-text', 'Observações', count($obs)], 'busca' => ['telephone-outbound', 'Busca ativa', null], 'autorizacoes' => ['patch-check', 'Autorizações', null]] as $k => [$ic, $rot, $cnt]): ?>
        <a class="<?= $aba === $k ? 'ativo' : '' ?>" href="?turma=<?= $tid ?>&amp;aba=<?= $k ?>"><i class="bi bi-<?= $ic ?>"></i><?= $rot ?><?php if ($cnt): ?><span><?= $cnt ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>
<?= cabecalho_impressao(turma_nome($turma) . ' · ' . ['alunos' => 'Alunos', 'frequencia' => 'Frequência', 'notas' => 'Notas', 'ocorrencias' => 'Ocorrências', 'observacoes' => 'Observações', 'busca' => 'Busca ativa', 'autorizacoes' => 'Autorizações'][$aba], 'Diretor(a) de turma: ' . ($turma['dt_nome'] ?: '—')) ?>

<?php if ($aba === 'alunos'): ?>
<div class="card"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th class="num">Nº</th><th>Aluno</th><th>Responsável</th><th class="num">Faltas no mês</th><th class="num">Faltas no ano</th><th class="num">Média</th><th class="num">Ocorrências</th></tr></thead>
    <tbody><?php foreach ($alunos as $i => $a): $aid = (int)$a['id']; $tel = $a['responsavel_telefone'] ?: $a['telefone']; $m = $med[$aid] ?? null; ?>
        <tr>
            <td class="num text-secondary"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
            <td><div class="d-flex align-items-center gap-2"><?= avatar($a, 'aluno', 'avatar-sm') ?><div><a class="nome-link fw-semibold" href="aluno.php?id=<?= $aid ?>"><?= e(nome_proprio($a['nome'])) ?></a>
                <?php if (isset($em_alerta[$aid])): ?><span class="etiqueta vermelho ms-1">faltas seguidas</span><?php endif; ?></div></div></td>
            <td class="small"><?= e($a['responsavel_nome'] ?: '—') ?><?php if ($tel): $w = link_whatsapp($tel, 'Olá! Aqui é da ' . $ESCOLA['nome'] . ', diretor(a) de turma de ' . nome_proprio($a['nome']) . '.'); ?><br>
                <?php if ($w): ?><a href="<?= e($w) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp text-success"></i> <?= e($tel) ?></a><?php else: ?><?= e($tel) ?><?php endif; ?><?php endif; ?></td>
            <td class="num"><?= (int)($f_mes[$aid] ?? 0) ?: '<span class="text-secondary">0</span>' ?></td>
            <td class="num"><strong><?= (int)($f_ano[$aid] ?? 0) ?></strong><?php if (($f_sem_just[$aid] ?? 0) && ($f_sem_just[$aid] ?? 0) < ($f_ano[$aid] ?? 0)): ?><small class="text-secondary d-block"><?= (int)$f_sem_just[$aid] ?> sem justif.</small><?php endif; ?></td>
            <td class="num <?= $m !== null && $m < MEDIA_APROVACAO ? 'text-danger fw-bold' : '' ?>"><?= $m !== null ? num($m, 1) : '–' ?></td>
            <td class="num"><?= ($oc_ano[$aid] ?? 0) ? '<a href="aluno.php?id=' . $aid . '#ocorrencias">' . (int)$oc_ano[$aid] . '</a>' : '<span class="text-secondary">0</span>' ?></td>
        </tr>
    <?php endforeach; ?></tbody></table></div></div>

<?php elseif ($aba === 'frequencia'):
    [$seg, $sex] = semana_param();
    $dias = []; for ($d = 0; $d < 5; $d++) $dias[] = date('Y-m-d', strtotime("$seg +$d days"));
    $fs = [];
    foreach (q_todos("SELECT aluno_id, data_falta, justificada FROM faltas WHERE aluno_id IN ($in) AND data_falta BETWEEN ? AND ?", [$seg, $sex]) as $f) $fs[(int)$f['aluno_id']][$f['data_falta']] = (int)$f['justificada'];
    $tot_dia = array_fill_keys($dias, 0); foreach ($fs as $x) foreach ($x as $d => $_) $tot_dia[$d]++;
    $abrev = ['seg', 'ter', 'qua', 'qui', 'sex']; ?>
<?php if ($R['alertas']): ?>
<section class="alerta-sequencia mb-4" id="alertas"><div class="alerta-topo"><span class="pulso"></span><h2><?= count($R['alertas']) ?> aluno(s) com <?= FALTAS_SEGUIDAS_ALERTA ?> ou mais faltas seguidas</h2></div>
    <ul><?php foreach ($R['alertas'] as $a): ?><li><span class="selo-sequencia"><i class="bi bi-exclamation-triangle-fill"></i><?= count($a['dias']) ?> seguidas</span>
        <div class="flex-grow-1"><a class="nome-link" href="aluno.php?id=<?= $a['aluno_id'] ?>#frequencia"><?= e(nome_proprio($a['nome'])) ?></a><div class="small text-secondary"><?= e(dias_curtos($a['dias'])) ?></div></div>
        <a class="btn btn-sm btn-danger" href="aluno.php?id=<?= $a['aluno_id'] ?>#busca">Registrar busca ativa</a></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<div class="card">
    <div class="card-header"><h2>Faltas da semana</h2>
        <div class="btn-group ms-auto nao-imprimir"><a class="btn btn-sm btn-light" href="?turma=<?= $tid ?>&aba=frequencia&semana=<?= date('Y-m-d', strtotime("$seg -7 days")) ?>"><i class="bi bi-chevron-left"></i></a>
            <span class="btn btn-sm btn-light disabled text-dark" style="opacity:1"><?= e(semana_rotulo($seg, $sex)) ?></span>
            <a class="btn btn-sm btn-light" href="?turma=<?= $tid ?>&aba=frequencia&semana=<?= date('Y-m-d', strtotime("$seg +7 days")) ?>"><i class="bi bi-chevron-right"></i></a></div></div>
    <div class="table-responsive"><table class="table table-sm align-middle dt-grade">
        <thead><tr><th class="num">Nº</th><th>Aluno</th><?php foreach ($dias as $k => $d): ?><th class="text-center"><?= $abrev[$k] ?><small class="d-block fw-normal text-secondary"><?= date('d/m', strtotime($d)) ?></small></th><?php endforeach; ?><th class="num">Semana</th></tr></thead>
        <tbody><?php foreach ($alunos as $i => $a): $aid = (int)$a['id']; $n = count($fs[$aid] ?? []); ?>
            <tr class="<?= $n ? '' : 'text-secondary' ?>"><td class="num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td><td><a class="nome-link" href="aluno.php?id=<?= $aid ?>#frequencia"><?= e(nome_proprio($a['nome'])) ?></a></td>
                <?php foreach ($dias as $d): $j = $fs[$aid][$d] ?? null; ?><td class="text-center"><?= $j === null ? '<span class="dt-p">·</span>' : ($j ? '<span class="dt-j" title="Falta justificada">J</span>' : '<span class="dt-f" title="Falta">F</span>') ?></td><?php endforeach; ?>
                <td class="num fw-bold"><?= $n ?: '' ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th></th><th>Faltas no dia</th><?php foreach ($dias as $d): ?><th class="text-center"><?= $tot_dia[$d] ?></th><?php endforeach; ?><th class="num"><?= array_sum($tot_dia) ?></th></tr></tfoot>
    </table></div>
</div>

<?php elseif ($aba === 'notas'):
    $per = periodo_atual();
    $bim = (int)($_GET['bim'] ?? 0) ?: (int)($per['bimestre'] ?? 1);
    $bim = max(1, min(4, $bim));
    $notas = q_todos("SELECT n.aluno_id, n.disciplina_id, n.nota, d.nome FROM notas n JOIN disciplinas d ON d.id = n.disciplina_id
                       WHERE n.aluno_id IN ($in) AND n.ano_letivo = ? AND n.bimestre = ? ORDER BY d.tipo, d.nome", [ANO_LETIVO, $bim]);
    $discs = []; $tab = [];
    foreach ($notas as $n) { $discs[(int)$n['disciplina_id']] = $n['nome']; $tab[(int)$n['aluno_id']][(int)$n['disciplina_id']] = (float)$n['nota']; }
    $media_disc = [];
    foreach ($discs as $did => $_) { $v = array_filter(array_map(fn($x) => $x[$did] ?? null, $tab), fn($x) => $x !== null); $media_disc[$did] = $v ? array_sum($v) / count($v) : null; } ?>
<div class="card">
    <div class="card-header"><h2>Notas do <?= $bim ?>º bimestre</h2>
        <div class="btn-group ms-auto nao-imprimir"><?php for ($b = 1; $b <= 4; $b++): ?><a class="btn btn-sm <?= $b === $bim ? 'btn-primary' : 'btn-light' ?>" href="?turma=<?= $tid ?>&aba=notas&bim=<?= $b ?>"><?= $b ?>º</a><?php endfor; ?></div></div>
    <?php if ($discs): ?>
    <div class="table-responsive"><table class="table table-sm align-middle dt-notas">
        <thead><tr><th class="num">Nº</th><th>Aluno</th><?php foreach ($discs as $nome): ?><th class="text-center" title="<?= e($nome) ?>"><span class="dt-disc"><?= e(mb_strimwidth($nome, 0, 14, '…')) ?></span></th><?php endforeach; ?><th class="num">Média</th><th class="num">Abaixo de <?= num(MEDIA_APROVACAO, 0) ?></th></tr></thead>
        <tbody><?php foreach ($alunos as $i => $a): $aid = (int)$a['id']; $ns = $tab[$aid] ?? []; $mm = $ns ? array_sum($ns) / count($ns) : null; $baixo = count(array_filter($ns, fn($x) => $x < MEDIA_APROVACAO)); ?>
            <tr><td class="num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td><td><a class="nome-link" href="aluno.php?id=<?= $aid ?>#notas"><?= e(nome_proprio($a['nome'])) ?></a></td>
                <?php foreach ($discs as $did => $_): $x = $ns[$did] ?? null; ?><td class="text-center <?= $x !== null && $x < MEDIA_APROVACAO ? 'nota-abaixo' : '' ?>"><?= $x !== null ? num($x, 1) : '–' ?></td><?php endforeach; ?>
                <td class="num fw-bold <?= $mm !== null && $mm < MEDIA_APROVACAO ? 'text-danger' : '' ?>"><?= $mm !== null ? num($mm, 1) : '–' ?></td>
                <td class="num"><?= $baixo ? '<span class="etiqueta vermelho">' . $baixo . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th></th><th>Média da turma</th><?php foreach ($discs as $did => $_): ?><th class="text-center"><?= $media_disc[$did] !== null ? num($media_disc[$did], 1) : '–' ?></th><?php endforeach; ?><th></th><th></th></tr></tfoot>
    </table></div>
    <?php else: ?><div class="vazio py-5"><i class="bi bi-journal"></i><strong>Nenhuma nota lançada no <?= $bim ?>º bimestre</strong>As notas aparecem aqui quando a gestão lançar.</div><?php endif; ?>
</div>

<?php elseif ($aba === 'ocorrencias'):
    $lista = ocorrencias_lista('a.turma_id = ? AND o.data_ocorrencia >= ?', [$tid, $ini_ano], 300);
    $por_cat = array_count_values(array_map(fn($o) => categoria_ocorrencia($o)[0], $lista)); arsort($por_cat); ?>
<div class="row g-3">
    <div class="col-xl-8"><div class="card">
        <div class="card-header"><h2>Ocorrências da turma em <?= ANO_LETIVO ?></h2><span class="etiqueta"><?= count($lista) ?></span>
            <?php if (pode('ocorrencias')): ?><a class="btn btn-sm btn-danger ms-auto nao-imprimir" href="ocorrencias.php#nova"><i class="bi bi-plus-lg me-1"></i>Registrar</a><?php endif; ?></div>
        <?php if ($lista): ?><ul class="oc-hoje-lista" style="max-height:none">
            <?php foreach ($lista as $o): [$rot, , $ic] = categoria_ocorrencia($o); ?>
            <li class="grav-<?= e($o['gravidade']) ?>" id="oc-<?= (int)$o['id'] ?>">
                <span class="oc-hora"><b><?= date('d/m', strtotime($o['data_ocorrencia'])) ?></b><small class="text-secondary"><?= $o['hora'] ? substr($o['hora'], 0, 5) : '' ?></small></span>
                <div class="flex-grow-1 min-w-0"><a class="nome-link fw-semibold" href="aluno.php?id=<?= (int)$o['aluno_id'] ?>#ocorrencias"><?= e(nome_proprio($o['nome'])) ?></a>
                    <div class="oc-hoje-cat"><span class="etiqueta <?= cor_ocorrencia($o) ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= e($rot) ?></span><?php if ($o['local']): ?><small class="text-secondary"><i class="bi bi-geo-alt"></i> <?= e($o['local']) ?></small><?php endif; ?></div>
                    <p class="oc-hoje-desc"><?= e(mb_strimwidth((string)$o['descricao'], 0, 220, '…')) ?></p></div>
                <span class="oc-por"><small><?= e(primeiro_ultimo((string)($o['por'] ?? '—'))) ?></small></span></li>
            <?php endforeach; ?></ul>
        <?php else: ?><div class="vazio py-5"><i class="bi bi-emoji-smile"></i><strong>Nenhuma ocorrência na turma</strong></div><?php endif; ?>
    </div></div>
    <div class="col-xl-4"><div class="card"><div class="card-header"><h2>Por tipo</h2></div>
        <div class="card-body py-2"><?php if ($por_cat): $mx = max($por_cat); ?><ul class="lista-linhas"><?php foreach ($por_cat as $k => $n): ?>
            <li><span style="width:170px" class="text-truncate"><?= e($k) ?></span><span class="barra-prop"><span style="width:<?= round($n / $mx * 100) ?>%"></span></span><strong style="width:28px;text-align:right"><?= $n ?></strong></li>
        <?php endforeach; ?></ul><?php else: ?><p class="text-secondary small mb-0">Sem registros.</p><?php endif; ?></div></div></div>
</div>

<?php elseif ($aba === 'observacoes'):
    $cont_t = array_count_values(array_column($obs, 'tipo'));
    $atencao_alunos = [];
    foreach ($obs as $o) if ($o['tipo'] === 'atencao') $atencao_alunos[(int)$o['aluno_id']] = ($atencao_alunos[(int)$o['aluno_id']] ?? 0) + 1;
    arsort($atencao_alunos); $nomes_al = array_column($obs, 'aluno_nome', 'aluno_id'); ?>
<div class="mt-obs">
    <div class="mt-obs-lista card">
        <div class="card-header"><h2><i class="bi bi-chat-square-text me-1"></i>Observações dos professores</h2>
            <div class="mt-filtro ms-auto" role="group" aria-label="Filtrar">
                <button type="button" class="ativo" data-f="">Todas <b><?= count($obs) ?></b></button>
                <?php foreach (TIPOS_OBSERVACAO as $k => [$r, $ic, $c]): ?><button type="button" class="c-<?= $c ?>" data-f="<?= $k ?>"><i class="bi bi-<?= $ic ?>"></i><?= e($r) ?> <b><?= (int)($cont_t[$k] ?? 0) ?></b></button><?php endforeach; ?>
            </div></div>
        <?php if ($obs): ?>
        <ol class="mt-linha">
            <?php $dia_ant = ''; foreach ($obs as $o): [$r, $ic, $c] = TIPOS_OBSERVACAO[$o['tipo']] ?? TIPOS_OBSERVACAO['neutra']; $dia = substr($o['criado_em'], 0, 10);
                if ($dia !== $dia_ant): $dia_ant = $dia; ?><li class="mt-dia"><span><?= $dia === date('Y-m-d') ? 'Hoje' : ($dia === date('Y-m-d', strtotime('-1 day')) ? 'Ontem' : e(dia_semana($dia)) . ', ' . date('d/m', strtotime($dia))) ?></span></li><?php endif; ?>
            <li class="mt-ob c-<?= $c ?>" data-t="<?= e($o['tipo']) ?>">
                <span class="mt-ob-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                <div class="mt-ob-cx">
                    <div class="mt-ob-topo"><a class="nome-link" href="aluno.php?id=<?= (int)$o['aluno_id'] ?>#observacoes"><?= e(nome_proprio($o['aluno_nome'])) ?></a><span class="etiqueta <?= $c ?>"><?= e($r) ?></span><time><?= date('H:i', strtotime($o['criado_em'])) ?></time></div>
                    <p><?= nl2br(e($o['texto'])) ?></p>
                    <small class="mt-ob-por"><?= avatar(['nome' => $o['usuario_nome'] ?? '—', 'foto' => $o['usuario_foto'] ?? null], 'usuario', 'avatar-xs') ?>Prof. <?= e(primeiro_ultimo((string)($o['usuario_nome'] ?? '—'))) ?></small>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <div class="vazio py-4" id="mtObsVazio" hidden><i class="bi bi-funnel"></i>Nenhuma observação desse tipo.</div>
        <?php else: ?><div class="vazio py-5"><i class="bi bi-chat-square-text"></i><strong>Nenhuma observação ainda</strong>Quando um professor anotar algo na ficha de um aluno desta turma, aparece aqui.</div><?php endif; ?>
    </div>
    <aside class="mt-obs-lado">
        <div class="card mt-obs-resumo"><div class="card-body">
            <h3><i class="bi bi-pie-chart me-1"></i>Resumo</h3>
            <?php $tot = max(1, count($obs)); foreach (TIPOS_OBSERVACAO as $k => [$r, $ic, $c]): $n = (int)($cont_t[$k] ?? 0); ?>
            <div class="mt-res c-<?= $c ?>"><span><i class="bi bi-<?= $ic ?>"></i><?= e($r) ?></span><b><?= $n ?></b><i class="mt-res-b"><i style="width:<?= round($n / $tot * 100) ?>%"></i></i></div>
            <?php endforeach; ?>
        </div></div>
        <div class="card"><div class="card-body">
            <h3><i class="bi bi-eye me-1"></i>Pedem atenção</h3>
            <?php if ($atencao_alunos): ?><ul class="mt-aten"><?php foreach (array_slice($atencao_alunos, 0, 6, true) as $aid => $n): ?>
                <li><a class="nome-link" href="aluno.php?id=<?= $aid ?>#observacoes"><?= e(primeiro_ultimo(nome_proprio($nomes_al[$aid] ?? ''))) ?></a><span><?= $n ?> ponto<?= $n > 1 ? 's' : '' ?></span></li>
            <?php endforeach; ?></ul>
            <?php else: ?><p class="small text-secondary mb-0">Nenhum ponto de atenção registrado. 👍</p><?php endif; ?>
            <p class="small text-secondary mt-2 mb-0"><i class="bi bi-bell me-1"></i>Você recebe um aviso no sino sempre que um professor registra um ponto de atenção ou uma ocorrência nesta turma.</p>
        </div></div>
    </aside>
</div>
<script>
(function () {
  const bts = document.querySelectorAll(".mt-filtro button"), itens = document.querySelectorAll(".mt-ob"), dias = document.querySelectorAll(".mt-dia"), vz = document.getElementById("mtObsVazio");
  bts.forEach(b => b.addEventListener("click", () => {
    bts.forEach(x => x.classList.toggle("ativo", x === b)); const f = b.dataset.f; let n = 0;
    itens.forEach(li => { const ok = !f || li.dataset.t === f; li.hidden = !ok; if (ok) n++; });
    dias.forEach(d => { let x = d.nextElementSibling, vis = false; while (x && !x.classList.contains("mt-dia")) { if (!x.hidden) vis = true; x = x.nextElementSibling; } d.hidden = !vis; });
    if (vz) vz.hidden = n > 0;
  }));
})();
</script>

<?php elseif ($aba === 'busca'):
    $lista = busca_lista('a.turma_id = ?', [$tid], 300); ?>
<div class="card"><div class="card-header"><h2>Providências de busca ativa</h2><span class="etiqueta"><?= count($lista) ?></span></div>
    <?php if ($lista): ?><ul class="ba-lista"><?php foreach ($lista as $b) echo busca_cartao($b, true); ?></ul>
    <?php else: ?><div class="vazio py-5"><i class="bi bi-telephone-outbound"></i><strong>Nenhuma providência registrada</strong>Registre na ficha do aluno, aba "Busca ativa".</div><?php endif; ?>
</div>

<?php else:
    $lista = autorizacoes_lista('a.turma_id = ? AND s.data_hora >= ?', [$tid, $ini_ano . ' 00:00:00'], 300); ?>
<div class="card"><div class="card-header"><h2>Autorizações da turma em <?= ANO_LETIVO ?></h2><span class="etiqueta"><?= count($lista) ?></span></div>
    <?php if ($lista): ?><div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Data</th><th>Aluno</th><th>Autorização</th><th>Motivo</th></tr></thead>
        <tbody><?php foreach ($lista as $s): ?><tr><td class="text-nowrap"><?= date('d/m H:i', strtotime($s['data_hora'])) ?></td>
            <td><a class="nome-link" href="aluno.php?id=<?= (int)$s['aluno_id'] ?>"><?= e(nome_proprio($s['nome'])) ?></a></td>
            <td><i class="bi bi-<?= tipo_autorizacao($s)[1] ?> me-1"></i><?= e(autorizacao_nome($s)) ?></td><td class="small"><?= e((string)($s['motivo'] ?? '')) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><div class="vazio py-5"><i class="bi bi-patch-check"></i><strong>Nenhuma autorização no ano</strong></div><?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  if (matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  document.querySelectorAll(".mt-kpi [data-contar]").forEach((el, i) => {
    const alvo = +el.dataset.contar; if (!alvo) return; const ini = performance.now() + 150 + i * 90, dur = 900;
    el.textContent = "0";
    const passo = t => { const p = Math.max(0, Math.min(1, (t - ini) / dur)); el.textContent = Math.round(alvo * (1 - Math.pow(1 - p, 3))).toLocaleString("pt-BR"); if (p < 1) requestAnimationFrame(passo); };
    requestAnimationFrame(passo);
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout_rodape.php';
