<?php
/* Notas (v63) — só consulta.
   As notas entram no sistema apenas pela planilha do Power BI (CSV), enviada pela gestão em "Planilha do Power BI".
   Não existe lançamento manual nem criação de avaliação. As notas ficam no histórico (ficha) de cada aluno. */
require __DIR__ . '/../includes/app.php';
exigir('notas');

$turmas = turmas_do_usuario();
$turma_id = (int)($_GET['turma'] ?? 0);
$turma = null; foreach ($turmas as $t) if ((int)$t['id'] === $turma_id) $turma = $t;
if (!$turma && $turmas && !isset($_GET['turma'])) { $turma = $turmas[0]; $turma_id = (int)$turma['id']; }
$aba = ($_GET['aba'] ?? '') === 'disciplina' ? 'disciplina' : 'quadro';
$bims_com = $turma ? bimestres_com_nota($turma_id) : [];
$bim = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : ($bims_com ? (int)end($bims_com) : 0);
if ($bim < 0 || $bim > 4) $bim = 0;

/* disciplinas visíveis: professor vê as suas; gestão vê todas as que têm nota na turma */
$disciplinas = [];
if ($turma) {
    $com_nota = q_todos('SELECT DISTINCT d.id, d.nome FROM notas n JOIN alunos a ON a.id = n.aluno_id JOIN disciplinas d ON d.id = n.disciplina_id
                          WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND n.ano_letivo = ? ORDER BY d.nome', [$turma_id, ANO_VER]);
    if (restrito_a_atribuicoes()) { $minhas = array_map('intval', array_column(disciplinas_do_usuario($turma), 'id')); $com_nota = array_values(array_filter($com_nota, fn($d) => in_array((int)$d['id'], $minhas, true))); }
    $disciplinas = $com_nota;
}
$ids_disc = array_map('intval', array_column($disciplinas, 'id'));
$disc_id = (int)($_GET['disciplina'] ?? 0);
if (!in_array($disc_id, $ids_disc, true)) $disc_id = $ids_disc[0] ?? 0;

$alunos = $turma ? q_todos("SELECT id, nome FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) ORDER BY nome", [$turma_id]) : [];
if (class_exists('Collator')) { $col = new Collator('pt_BR'); usort($alunos, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); }

/* notas: [aluno][disciplina][bimestre] => nota */
$N = [];
if ($turma && $ids_disc) {
    $in = implode(',', array_fill(0, count($ids_disc), '?'));
    foreach (q_todos("SELECT n.aluno_id, n.disciplina_id, n.bimestre, n.nota FROM notas n JOIN alunos a ON a.id = n.aluno_id
                       WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND n.ano_letivo = ? AND n.disciplina_id IN ($in)", array_merge([$turma_id, ANO_VER], $ids_disc)) as $r)
        $N[(int)$r['aluno_id']][(int)$r['disciplina_id']][(int)$r['bimestre']] = (float)$r['nota'];
}
$media = function (array $v): ?float { $v = array_filter($v, fn($x) => $x !== null); return $v ? array_sum($v) / count($v) : null; };
$nota_quadro = fn(int $aid, int $did) => $bim ? ($N[$aid][$did][$bim] ?? null) : $media($N[$aid][$did] ?? []);
$ultima = null;
try { $ultima = q_valor('SELECT MAX(atualizado_em) FROM notas WHERE ano_letivo = ?', [ANO_VER]); } catch (PDOException $e) {}

/* exportar o que está na tela */
if ($turma && ($_GET['exportar'] ?? '') === 'csv') {
    if ($aba === 'quadro') {
        $cab = array_merge(['Aluno'], array_column($disciplinas, 'nome'), ['Média geral']);
        $lin = array_map(function ($a) use ($disciplinas, $nota_quadro, $media) { $vs = array_map(fn($d) => $nota_quadro((int)$a['id'], (int)$d['id']), $disciplinas); $m = $media($vs);
            return array_merge([nome_proprio($a['nome'])], array_map(fn($v) => $v === null ? '' : num($v, 1), $vs), [$m === null ? '' : num($m, 1)]); }, $alunos);
    } else {
        $cab = ['Aluno', '1º período', '2º período', '3º período', '4º período', 'Média anual'];
        $lin = array_map(function ($a) use ($N, $disc_id, $media) { $vs = [1 => null, 2 => null, 3 => null, 4 => null]; foreach ($vs as $b => $_) $vs[$b] = $N[(int)$a['id']][$disc_id][$b] ?? null; $m = $media($vs);
            return array_merge([nome_proprio($a['nome'])], array_map(fn($v) => $v === null ? '' : num($v, 1), array_values($vs)), [$m === null ? '' : num($m, 1)]); }, $alunos);
    }
    enviar_csv('notas-' . $turma['serie'] . $turma['letra'] . '-' . ($aba === 'quadro' ? ($bim ? $bim . 'periodo' : 'ano') : 'disciplina') . '.csv', $cab, $lin);
}

$titulo = 'Notas';
$menu = 'notas';
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';
$qs = fn(array $x = []) => '?' . http_build_query(array_merge(['aba' => $aba, 'turma' => $turma_id ?: null, 'bimestre' => $bim, 'disciplina' => $disc_id ?: null], $x));
$cl = fn(?float $v) => $v === null ? 'nt-vazia' : ($v < MEDIA_APROVACAO ? 'nt-baixa' : ($v >= 9 ? 'nt-alta' : 'nt-ok'));
$fmt = fn(?float $v) => $v === null ? '—' : num($v, 1);
?>
<div class="nt-topo">
    <div class="nt-topo-tx">
        <span class="nt-ic"><i class="bi bi-journal-check"></i></span>
        <div><h2>Notas de <?= ANO_VER ?></h2>
            <small><i class="bi bi-lock me-1"></i>Somente consulta · as notas chegam pela planilha do Power BI<?= $ultima ? ' · última atualização em ' . date('d/m/Y \à\s H:i', strtotime((string)$ultima)) : '' ?></small></div>
    </div>
    <?php if (pode('importar')): ?><a class="btn btn-light nt-enviar" href="importar.php"><i class="bi bi-cloud-arrow-up me-1"></i>Enviar planilha (CSV)</a><?php endif; ?>
</div>

<?php if (!$turmas): ?>
<div class="card"><div class="vazio"><i class="bi bi-person-lock"></i><strong>Você ainda não tem turmas atribuídas</strong>A coordenação vincula suas turmas e disciplinas em Professores e componentes.</div></div>
<?php else: ?>
<form method="get" class="card nt-filtro mb-3">
    <input type="hidden" name="aba" value="<?= $aba ?>">
    <label><span>Turma</span><select class="form-select" name="turma" onchange="this.form.submit()">
        <?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$t['id'] === $turma_id ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select></label>
    <?php if ($aba === 'disciplina'): ?>
    <label><span>Disciplina</span><select class="form-select" name="disciplina" onchange="this.form.submit()"<?= $disciplinas ? '' : ' disabled' ?>>
        <?php foreach ($disciplinas as $d): ?><option value="<?= $d['id'] ?>"<?= (int)$d['id'] === $disc_id ? ' selected' : '' ?>><?= e($d['nome']) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
    <div class="nc-bims"><span>Período</span><div>
        <?php for ($b = 1; $b <= 4; $b++): ?><button type="submit" name="bimestre" value="<?= $b ?>" class="<?= $b === $bim ? 'ativo' : '' ?>"<?= in_array($b, $bims_com, true) ? '' : ' disabled title="Sem notas neste período"' ?>><?= $b ?>º</button><?php endfor; ?>
        <button type="submit" name="bimestre" value="0" class="<?= $bim === 0 ? 'ativo' : '' ?>">Ano</button></div></div>
    <?php endif; ?>
</form>

<ul class="nav nav-abas mb-3">
    <li class="nav-item"><a class="nav-link<?= $aba === 'quadro' ? ' active' : '' ?>" href="<?= e($qs(['aba' => 'quadro'])) ?>"><i class="bi bi-grid-3x3-gap me-1"></i>Quadro da turma</a></li>
    <li class="nav-item"><a class="nav-link<?= $aba === 'disciplina' ? ' active' : '' ?>" href="<?= e($qs(['aba' => 'disciplina'])) ?>"><i class="bi bi-journal-text me-1"></i>Por disciplina</a></li>
</ul>

<?php if (!$disciplinas): ?>
<div class="card"><div class="vazio"><i class="bi bi-cloud-arrow-up"></i><strong>Ainda não há notas desta turma em <?= ANO_VER ?></strong>As notas aparecem aqui assim que a gestão enviar a planilha do Power BI.</div></div>
<?php elseif ($aba === 'quadro'): ?>
<div class="card nt-card">
    <div class="card-header"><h2><?= e(turma_nome($turma)) ?> · <?= $bim ? $bim . 'º período' : 'média do ano' ?></h2>
        <div class="acoes"><a class="btn btn-sm btn-light" href="<?= e($qs(['exportar' => 'csv'])) ?>"><i class="bi bi-download me-1"></i>CSV</a><button class="btn btn-sm btn-light" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button></div></div>
    <div class="table-responsive"><table class="table nt-tab">
        <thead><tr><th class="nt-al">Aluno</th><th class="num nt-mg">Média<br>geral</th><?php foreach ($disciplinas as $d): ?><th class="num nt-dh" title="<?= e($d['nome']) ?>"><span><?= e($d['nome']) ?></span></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($alunos as $k => $a): $vs = array_map(fn($d) => $nota_quadro((int)$a['id'], (int)$d['id']), $disciplinas); $m = $media($vs); ?>
            <tr><td class="nt-al"><em><?= $k + 1 ?></em><a href="aluno.php?id=<?= $a['id'] ?>#notas" title="<?= e(nome_proprio($a['nome'])) ?>"><?= e(primeiro_ultimo(nome_proprio($a['nome']))) ?></a></td>
                <td class="num nt-mg"><b class="<?= $cl($m) ?>"><?= $fmt($m) ?></b></td>
                <?php foreach ($vs as $v): ?><td class="num"><span class="nt <?= $cl($v) ?>"><?= $fmt($v) ?></span></td><?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="nt-leg"><span class="nt nt-baixa">5,0</span>abaixo de <?= num(MEDIA_APROVACAO, 1) ?> <span class="nt nt-ok">7,0</span>na média <span class="nt nt-alta">9,5</span>destaque</p>
</div>
<?php else: $dn = ''; foreach ($disciplinas as $d) if ((int)$d['id'] === $disc_id) $dn = $d['nome']; ?>
<div class="card nt-card">
    <div class="card-header"><h2><?= e($dn) ?> · <?= e(turma_nome($turma)) ?></h2>
        <div class="acoes"><a class="btn btn-sm btn-light" href="<?= e($qs(['exportar' => 'csv'])) ?>"><i class="bi bi-download me-1"></i>CSV</a><button class="btn btn-sm btn-light" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button></div></div>
    <div class="table-responsive"><table class="table nt-tab">
        <thead><tr><th class="nt-al">Aluno</th><?php for ($b = 1; $b <= 4; $b++): ?><th class="num"><?= $b ?>º período</th><?php endfor; ?><th class="num nt-mg">Média anual</th></tr></thead>
        <tbody><?php foreach ($alunos as $k => $a): $vs = []; for ($b = 1; $b <= 4; $b++) $vs[$b] = $N[(int)$a['id']][$disc_id][$b] ?? null; $m = $media($vs); ?>
            <tr><td class="nt-al"><em><?= $k + 1 ?></em><a href="aluno.php?id=<?= $a['id'] ?>#notas"><?= e(nome_proprio($a['nome'])) ?></a></td>
                <?php foreach ($vs as $v): ?><td class="num"><span class="nt <?= $cl($v) ?>"><?= $fmt($v) ?></span></td><?php endforeach; ?>
                <td class="num nt-mg"><b class="<?= $cl($m) ?>"><?= $fmt($m) ?></b></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
