<?php
/* Nota conceitual (v63)
   O professor escolhe só a TURMA. A nota que ele dá a cada aluno (3 aspectos, somam até 10) vale para TODAS
   as disciplinas que ele leciona naquela turma. O professor só edita o período em andamento; períodos que já
   passaram ficam só para consulta (a gestão pode editar qualquer período).
   A média conceitual do aluno = média de todas as disciplinas da turma; só aparece com todos os professores concluídos. */
require __DIR__ . '/../includes/app.php';
exigir('nota_conceitual');

$gerir = pode('nota_conceitual.gerir');
$abas = ['lancar' => 'Avaliar', 'ver' => 'Visualizar médias'] + ($gerir ? ['criterios' => 'Critérios'] : []);
$aba = isset($abas[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'lancar';
$crit = nc_criterios();
$per = periodo_atual();
$bim_atual = (int)($per['bimestre'] ?? 0);
$bim_padrao = $bim_atual ?: max(1, min(4, (int)(($per['proximo'] ?? 5) - 1)));
$bim = (int)($_GET['bimestre'] ?? $_POST['bimestre'] ?? $bim_padrao);
if ($bim < 1 || $bim > 4) $bim = $bim_padrao;
$editavel = nc_pode_editar($bim);
$fim_bim = bimestres(ANO_VER)[$bim][1] ?? null;

$turmas = nc_turmas();
$ids_turmas = array_map('intval', array_column($turmas, 'id'));
$turma_id = (int)($_GET['turma'] ?? $_POST['turma'] ?? 0);
if (!in_array($turma_id, $ids_turmas, true)) $turma_id = 0;
$turma = null; foreach ($turmas as $t) if ((int)$t['id'] === $turma_id) $turma = $t;

/* quem está sendo avaliado como "professor": o próprio usuário; a gestão escolhe o professor */
$profs = $turma ? nc_professores_turma($turma_id) : [];
$prof_id = uid();
if ($turma && $gerir) {
    $pid = (int)($_GET['professor'] ?? $_POST['professor'] ?? 0);
    $prof_id = isset($profs[$pid]) ? $pid : (isset($profs[uid()]) ? uid() : (int)(array_key_first($profs) ?? 0));
}
$minhas = $profs[$prof_id]['disciplinas'] ?? [];
$prof_nome = $profs[$prof_id]['nome'] ?? '';

$url = fn(array $x = []) => 'nota_conceitual.php?' . http_build_query(array_filter(['aba' => $aba, 'turma' => $turma_id ?: null, 'professor' => $gerir && $prof_id ? $prof_id : null, 'bimestre' => $bim] + $x, fn($v) => $v !== null));

/* ---------- Ações ---------- */
if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'salvar' && $turma && $minhas) {
        if (!$editavel) { flash('warning', 'O ' . $bim . 'º período já terminou. As notas ficam só para consulta; para alterar, fale com a gestão.'); redirecionar($url()); }
        try {
            [$s, $ap, $err] = nc_salvar($turma_id, array_keys($minhas), $bim, (array)($_POST['n'] ?? []));
            $dtx = count($minhas) . ' disciplina' . (count($minhas) > 1 ? 's' : '');
            if ($s || $ap) flash('success', ($s ? $s . ' aluno' . ($s > 1 ? 's avaliados' : ' avaliado') : '') . ($s && $ap ? ' · ' : '') . ($ap ? $ap . ' nota' . ($ap > 1 ? 's apagadas' : ' apagada') : '') . ' em ' . $dtx . ' · ' . $bim . 'º período.');
            if ($err) flash('warning', 'Não salvos: ' . implode('; ', array_slice($err, 0, 6)) . (count($err) > 6 ? '…' : '') . '.');
            if (!$s && !$ap && !$err) flash('warning', 'Nada para salvar.');
            registrar_log('nota_conceitual', turma_nome($turma) . ' · ' . implode(', ', $minhas) . ' · ' . $bim . 'º bim' . ($prof_id !== uid() ? ' · por ' . $prof_nome : ''));
        } catch (PDOException $e) { flash('warning', 'A tabela da nota conceitual ainda não existe. Rode a atualização do banco (atualizacao_banco_v59.sql).'); }
        redirecionar($url());
    }
    if ($acao === 'criterios' && $gerir) {
        $novo = []; $soma = 0;
        foreach ([0, 1, 2] as $i) {
            $nome = trim((string)($_POST['nome'][$i] ?? ''));
            $max = (float)str_replace(',', '.', (string)($_POST['max'][$i] ?? 0));
            $itens = [];
            foreach (preg_split('/\R/', (string)($_POST['itens'][$i] ?? '')) as $l) {
                $l = trim($l); if ($l === '') continue;
                [$t, $d] = array_map('trim', array_pad(explode('|', $l, 2), 2, ''));
                $itens[] = [mb_substr($t, 0, 80), mb_substr($d, 0, 400)];
            }
            if ($nome === '' || $max <= 0) { flash('warning', 'Cada aspecto precisa de um nome e de um valor maior que zero.'); redirecionar('nota_conceitual.php?aba=criterios'); }
            $novo[] = ['nome' => mb_substr($nome, 0, 60), 'max' => round($max, 2), 'itens' => $itens];
            $soma += round($max, 2);
        }
        if (abs($soma - 10) > 0.001) { flash('warning', 'Os três valores precisam somar 10 (agora somam ' . nc_num($soma, 2) . ').'); redirecionar('nota_conceitual.php?aba=criterios'); }
        config_gravar('nota_conceitual_criterios', json_encode($novo, JSON_UNESCAPED_UNICODE));
        registrar_log('nota_conceitual_criterios', 'alterados');
        flash('success', 'Critérios salvos. Valem para todas as turmas.');
        redirecionar('nota_conceitual.php?aba=criterios');
    }
    redirecionar($url());
}

/* ---------- Dados ---------- */
$erro_tabela = false;
$alunos = $notas = [];
$sit = null; $pend_prof = []; $n_prof = 0;
try {
    if ($aba === 'lancar' && $turma && $minhas) {
        $alunos = nc_alunos($turma_id);
        $todas = nc_notas_turma($turma_id, $bim);
        foreach ($alunos as $a) foreach (array_keys($minhas) as $did) if (isset($todas[(int)$a['id']][$did])) { $notas[(int)$a['id']] = $todas[(int)$a['id']][$did]; break; }
    }
    if ($aba === 'ver' && $turma) {
        $sit = nc_situacao($turma_id, $bim);
        $n_prof = count($profs);
        foreach ($profs as $pid => $p) {
            $faltam = 0;
            foreach ($sit['alunos'] as $a) foreach (array_keys($p['disciplinas']) as $did) if (!isset($sit['notas'][(int)$a['id']][$did])) { $faltam++; break; }
            if ($faltam) $pend_prof[] = ['nome' => $p['nome'], 'disciplinas' => array_values($p['disciplinas']), 'faltam' => $faltam];
        }
    }
} catch (PDOException $e) { $erro_tabela = true; }
$avaliados = count($notas);
$cores = ['azul' => '#1d5fb0', 'laranja' => '#d4650a', 'verde' => '#13692a'];
$curto = fn(string $n) => preg_replace('/^Aspectos?\s+/iu', '', $n);

$titulo = 'Nota conceitual';
$menu = 'nota_conceitual';
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';
?>
<ul class="nav nav-abas mb-3">
    <?php foreach ($abas as $k => $r): ?><li class="nav-item"><a class="nav-link<?= $aba === $k ? ' active' : '' ?>" href="?aba=<?= $k ?><?= $turma_id && $k !== 'criterios' ? '&amp;turma=' . $turma_id . '&amp;bimestre=' . $bim : '' ?>"><i class="bi bi-<?= ['lancar' => 'pencil-square', 'ver' => 'bar-chart-steps', 'criterios' => 'sliders'][$k] ?> me-1"></i><?= $r ?></a></li><?php endforeach; ?>
</ul>

<?php if ($erro_tabela): ?>
<div class="card"><div class="vazio"><i class="bi bi-database-exclamation"></i><strong>Falta atualizar o banco</strong>Importe o arquivo atualizacao_banco_v59.sql no phpMyAdmin (ou abra verificar.php e clique no botão verde).</div></div>
<?php endif; ?>

<?php if ($aba !== 'criterios'): ?>
<form method="get" class="card nc-filtro mb-3">
    <input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="bimestre" value="<?= $bim ?>">
    <div class="nc-filtro-g<?= $aba === 'lancar' && $gerir ? '' : ' sem-prof' ?>">
        <label><span>Turma</span>
            <select class="form-select" name="turma" onchange="this.form.professor && (this.form.professor.value=''); this.form.submit()">
                <option value="">Escolha a turma</option>
                <?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$t['id'] === $turma_id ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?>
            </select></label>
        <?php if ($aba === 'lancar' && $gerir): ?>
        <label><span>Professor</span>
            <select class="form-select" name="professor" onchange="this.form.submit()"<?= $turma ? '' : ' disabled' ?>>
                <?php if (!$profs): ?><option value="">Nenhum professor vinculado</option><?php endif; ?>
                <?php foreach ($profs as $pid => $p): ?><option value="<?= $pid ?>"<?= $pid === $prof_id ? ' selected' : '' ?>><?= e($p['nome']) ?> · <?= count($p['disciplinas']) ?> disc.</option><?php endforeach; ?>
            </select></label>
        <?php endif; ?>
        <div class="nc-bims" role="group" aria-label="Período">
            <span>Período</span>
            <div><?php for ($b = 1; $b <= 4; $b++): ?><button type="submit" name="bimestre" value="<?= $b ?>" class="<?= $b === $bim ? 'ativo' : '' ?><?= $b === $bim_atual ? ' atual' : '' ?>" title="<?= $b === $bim_atual ? 'Período em andamento' : ($gerir || $b === $bim_atual ? '' : 'Só consulta') ?>"><?= $b ?>º<?php if ($aba === 'lancar' && !$gerir && $b !== $bim_atual): ?><i class="bi bi-lock-fill"></i><?php endif; ?></button><?php endfor; ?></div>
        </div>
    </div>
</form>
<?php if (!$turmas): ?>
<div class="card"><div class="vazio"><i class="bi bi-people"></i><strong>Nenhuma turma atribuída a você</strong>A coordenação vincula suas turmas e disciplinas em Professores e componentes.</div></div>
<?php endif; ?>
<?php endif; ?>

<?php /* ===================== AVALIAR ===================== */ if ($aba === 'lancar' && $turma && !$erro_tabela): ?>
    <?php if (!$minhas): ?>
    <div class="card"><div class="vazio"><i class="bi bi-journal-x"></i><strong><?= $gerir ? 'Nenhum professor vinculado a esta turma' : 'Você não leciona nesta turma' ?></strong>A coordenação vincula professores e disciplinas em Professores e componentes.</div></div>
    <?php else: ?>
    <?php if (!$editavel): ?>
    <div class="nc-trava mb-3"><i class="bi bi-lock-fill"></i><div><b>O <?= $bim ?>º período terminou<?= $fim_bim ? ' em ' . data_br($fim_bim) : '' ?>. As notas ficam só para consulta.</b><small>Você só edita a nota conceitual do período em andamento<?= $bim_atual ? ' (' . $bim_atual . 'º período)' : '' ?>. Para corrigir uma nota antiga, fale com a gestão.</small></div></div>
    <?php endif; ?>

    <?php
    $tots = array_map(fn($n) => (float)$n['total'], $notas);
    $media_t = $tots ? array_sum($tots) / count($tots) : null;
    $abaixo_t = count(array_filter($tots, fn($v) => $v < 6));
    $pct = $alunos ? round($avaliados / count($alunos) * 100) : 0;
    $prof_mostra = $prof_nome ?: (usuario()['nome'] ?? '');
    $ini_prof = mb_strtoupper(mb_substr($prof_mostra, 0, 1) . mb_substr(strrchr(' ' . $prof_mostra, ' '), 1, 1));
    $cor_t = cor_curso($turma['curso']);
    ?>
    <section class="ncx-hero mb-3" style="--tc:<?= $cor_t ?>">
        <svg class="ncx-deco" viewBox="0 0 600 220" preserveAspectRatio="xMaxYMid slice" aria-hidden="true">
            <defs><pattern id="ncxPontos" width="18" height="18" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.6" fill="rgba(255,255,255,.16)"/></pattern></defs>
            <rect x="330" y="0" width="270" height="220" fill="url(#ncxPontos)"/>
            <circle cx="560" cy="20" r="120" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="26"/>
            <circle cx="470" cy="210" r="60" fill="rgba(240,124,18,.18)"/>
            <g transform="translate(395 38) rotate(-8)" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
                <rect x="0" y="10" width="92" height="118" rx="12"/><rect x="26" y="0" width="40" height="22" rx="7"/>
                <path d="M20 58l12 12 22-24M20 98h52"/>
            </g>
        </svg>
        <div class="ncx-turma"><b><?= (int)$turma['serie'] ?>º<?= e($turma['letra']) ?></b><span><?= e($turma['sigla'] ?: mb_substr($turma['curso'], 0, 3)) ?></span></div>
        <div class="ncx-tx">
            <span class="ncx-sobre"><i class="bi bi-clipboard2-check"></i>Nota conceitual · <?= $bim ?>º período<?= $editavel ? '' : ' · só consulta' ?></span>
            <h2><?= e(turma_nome($turma)) ?></h2>
            <div class="ncx-meta">
                <span class="ncx-prof"><i><?= e($ini_prof) ?></i><span><small>Professor(a)</small><?= e($prof_mostra) ?></span></span>
                <span><small>Curso</small><?= e($turma['curso']) ?></span>
                <span><small>Período</small><?= $bim ?>º bimestre<?= $editavel ? ' · em andamento' : '' ?></span>
            </div>
            <div class="ncx-discs"><span class="ncx-discs-t"><?= count($minhas) > 1 ? 'Disciplinas avaliadas (' . count($minhas) . ')' : 'Disciplina avaliada' ?></span>
                <?php foreach ($minhas as $dn): ?><span class="ncx-disc"><i class="bi bi-journal-bookmark-fill"></i><?= e($dn) ?></span><?php endforeach; ?></div>
            <?php if (count($minhas) > 1): ?><p class="ncx-obs"><i class="bi bi-info-circle"></i>A nota de cada aluno vale para as <?= count($minhas) ?> disciplinas: preencha uma vez só.</p><?php endif; ?>
        </div>
        <div class="ncx-lado">
            <div class="ncx-anel" style="--p:<?= $pct ?>"><div><b id="ncFeitos"><?= $avaliados ?></b><small>de <?= count($alunos) ?></small></div></div>
            <span class="ncx-anel-t">alunos avaliados</span>
            <div class="ncx-mini">
                <span><small>Média da turma</small><b><?= $media_t === null ? '—' : nc_num($media_t) ?></b></span>
                <span class="<?= $abaixo_t ? 'ruim' : '' ?>"><small>Abaixo de 6</small><b><?= $abaixo_t ?></b></span>
            </div>
        </div>
    </section>

    <div class="ncx-crits mb-3">
        <?php foreach ($crit as $i => $c): $peso = round((float)$c['max'] * 10); ?>
        <details class="ncx-crit" style="--nc:<?= $cores[$c['cor']] ?? '#13692a' ?>">
            <summary>
                <i class="bi bi-<?= e($c['icone']) ?> ncx-crit-fundo" aria-hidden="true"></i>
                <span class="ncx-crit-ic"><i class="bi bi-<?= e($c['icone']) ?>"></i></span>
                <div class="ncx-crit-tx"><small>Aspecto <?= $i + 1 ?></small><b><?= e($c['nome']) ?></b>
                    <span class="ncx-peso"><i style="width:<?= $peso ?>%"></i></span><em><?= $peso ?>% da nota · de 0 a <?= nc_num((float)$c['max']) ?> pontos</em></div>
                <span class="ncx-crit-max"><b><?= nc_num((float)$c['max'], 0) ?></b><small>pontos</small></span>
                <span class="ncx-ver">O que observar <i class="bi bi-chevron-down"></i></span>
            </summary>
            <ul><?php foreach ($c['itens'] as [$t, $d]): ?><li><b><?= e($t) ?></b><?= e($d) ?></li><?php endforeach; ?></ul>
        </details>
        <?php endforeach; ?>
    </div>

    <form method="post" class="card nc-card<?= $editavel ? '' : ' so-ler' ?>" id="ncForm" autocomplete="off">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="turma" value="<?= $turma_id ?>"><input type="hidden" name="professor" value="<?= $prof_id ?>"><input type="hidden" name="bimestre" value="<?= $bim ?>">
        <?php if (!$alunos): ?>
        <div class="vazio"><i class="bi bi-people"></i><strong>Turma sem alunos ativos</strong></div>
        <?php else: ?>
        <div class="nc-tabela nc-pro" role="table">
            <div class="nc-lin nc-topo" role="row">
                <span role="columnheader" class="ncp-h ncp-h-al"><span class="ncp-h-n">Nº</span><span><span><i class="bi bi-people-fill me-2"></i>Nome do aluno</span><small><?= count($alunos) ?> estudantes · ordem da chamada</small></span></span>
                <?php foreach ($crit as $i => $c): ?><span role="columnheader" class="ncp-h ncp-h-c" style="--nc:<?= $cores[$c['cor']] ?? '#13692a' ?>"><i class="bi bi-<?= e($c['icone']) ?> ncp-ic"></i><?= e(mb_strtolower($c['nome']) === mb_strtolower($curto($c['nome'])) ? $c['nome'] : 'Aspectos ' . mb_strtolower($curto($c['nome']))) ?><small>0 a <?= nc_num((float)$c['max'], 0) ?> pontos</small></span><?php endforeach; ?>
                <span role="columnheader" class="ncp-h ncp-h-m"><i class="bi bi-award-fill ncp-ic"></i>Média geral<small>0 a 10</small></span>
                <span role="columnheader" class="ncp-h ncp-h-ac"><?= $editavel ? '<span class="visually-hidden">Atalho</span>' : '' ?></span>
            </div>
            <?php foreach ($alunos as $k => $a): $n = $notas[(int)$a['id']] ?? null; ?>
            <div class="nc-lin<?= $n ? ' feito' : '' ?>" role="row">
                <span class="nc-nome" role="cell"><em><?= str_pad((string)($k + 1), 2, '0', STR_PAD_LEFT) ?></em><b><?= e(nome_proprio($a['nome'])) ?></b></span>
                <?php foreach ($crit as $i => $c): $v = $n ? (float)$n['c' . ($i + 1)] : null; ?>
                <label class="nc-cel" role="cell" style="--nc:<?= $cores[$c['cor']] ?? '#13692a' ?>"><span class="nc-rot"><?= e(mb_substr($curto($c['nome']), 0, 6)) ?>. <small>0 a <?= nc_num((float)$c['max'], 0) ?></small></span>
                    <input type="text" inputmode="decimal" maxlength="4" name="n[<?= $a['id'] ?>][<?= $i ?>]" value="<?= $v === null ? '' : e(nc_num($v, fmod($v, 1) == 0 ? 0 : (fmod($v * 10, 1) == 0 ? 1 : 2))) ?>" data-max="<?= (float)$c['max'] ?>" data-col="<?= $i ?>" placeholder="0–<?= nc_num((float)$c['max'], 0) ?>"<?= $editavel ? '' : ' disabled' ?> aria-label="<?= e($c['nome']) ?> de <?= e(primeiro_ultimo(nome_proprio($a['nome']))) ?>, de 0 a <?= nc_num((float)$c['max']) ?>"></label>
                <?php endforeach; ?>
                <span class="nc-tot" role="cell"><b><?= $n ? nc_num((float)$n['total']) : '—' ?></b></span>
                <span class="nc-acao" role="cell"><?php if ($editavel): ?><button type="button" class="nc-max" title="Preencher com a nota máxima (10)"><i class="bi bi-arrow-up-short"></i><span>Máx.</span></button><?php endif; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($editavel): ?>
        <div class="nc-salvar">
            <span id="ncAviso"><i class="bi bi-info-circle"></i>Use vírgula (ex.: 1,5). Valores acima do máximo não são aceitos. Deixe os 3 vazios para apagar.</span>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Salvar notas<?= count($minhas) > 1 ? ' nas ' . count($minhas) . ' disciplinas' : '' ?></button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </form>
    <?php endif; ?>
<?php elseif ($aba === 'lancar' && $turmas && !$turma): ?>
<div class="card"><div class="vazio"><i class="bi bi-clipboard2-check"></i><strong>Escolha a turma</strong>As disciplinas que você leciona nela aparecem sozinhas. A nota de cada aluno vale para todas elas.</div></div>
<?php endif; ?>

<?php /* ===================== VISUALIZAR ===================== */ if ($aba === 'ver' && !$erro_tabela): ?>
    <?php if (!$turma): ?>
    <?php if ($turmas): ?><div class="card"><div class="vazio"><i class="bi bi-bar-chart-steps"></i><strong>Escolha a turma</strong>A média conceitual aparece quando todos os professores da turma tiverem avaliado todos os alunos.</div></div><?php endif; ?>
    <?php elseif (!$sit['disciplinas']): ?>
    <div class="card"><div class="vazio"><i class="bi bi-person-x"></i><strong>Nenhum professor atribuído a esta turma</strong>A coordenação vincula professores e disciplinas em Professores e componentes.</div></div>
    <?php elseif (!$sit['fechada']): $tot = count($sit['disciplinas']); ?>
    <div class="card nc-pend">
        <div class="nc-pend-topo">
            <span class="nc-pend-ic"><i class="bi bi-hourglass-split"></i></span>
            <div><h2>Restam professores colocarem a nota desta turma</h2>
                <p><?= e(turma_nome($turma)) ?> · <?= $bim ?>º bimestre. A média conceitual só aparece quando todas as disciplinas estiverem completas, para ninguém usar uma média que ainda vai mudar.</p></div>
            <div class="nc-pend-num"><b><?= $n_prof - count($pend_prof) ?>/<?= $n_prof ?></b><small>professores concluíram</small><i style="--p:<?= $n_prof ? round(($n_prof - count($pend_prof)) / $n_prof * 100) : 0 ?>%"></i></div>
        </div>
        <ul class="nc-pend-lista">
            <?php foreach ($pend_prof as $p): ?>
            <li><span class="nc-pend-av"><?= e(mb_strtoupper(mb_substr($p['nome'], 0, 1))) ?></span>
                <div><b><?= e($p['nome']) ?></b><small><?= e(implode(' · ', $p['disciplinas'])) ?></small></div>
                <span class="etiqueta <?= $p['faltam'] === count($sit['alunos']) ? 'vermelho' : 'ambar' ?>"><?= $p['faltam'] === count($sit['alunos']) ? 'não começou' : 'faltam ' . $p['faltam'] . ' aluno' . ($p['faltam'] > 1 ? 's' : '') ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else:
        $mt = array_filter($sit['medias'], fn($v) => $v !== null); $media_turma = $mt ? array_sum($mt) / count($mt) : null; ?>
    <div class="card nc-ver">
        <div class="card-header nc-cab">
            <div><h2>Média conceitual · <?= e(turma_nome($turma)) ?></h2><small><?= $bim ?>º bimestre · média das <?= count($sit['disciplinas']) ?> disciplinas · todas completas</small></div>
            <div class="nc-ver-ac"><span class="nc-media-t"><small>Média da turma</small><b><?= nc_num($media_turma) ?></b></span>
                <button class="btn btn-light btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button></div>
        </div>
        <div class="table-responsive"><table class="table nc-quadro">
            <thead><tr><th class="nc-al">Aluno</th><th class="num nc-mc">Média<br>conceitual</th><?php foreach ($sit['disciplinas'] as $d): ?><th class="num nc-dh" title="<?= e($d['nome']) ?>"><span><?= e($d['nome']) ?></span></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($sit['alunos'] as $k => $a): $m = $sit['medias'][(int)$a['id']]; ?>
                <tr><td class="nc-al" title="<?= e(nome_proprio($a['nome'])) ?>"><em><?= $k + 1 ?></em><?= e(primeiro_ultimo(nome_proprio($a['nome']))) ?></td>
                    <td class="num nc-mc"><b class="<?= $m < 6 ? 'baixa' : '' ?>"><?= nc_num($m) ?></b></td>
                    <?php foreach ($sit['disciplinas'] as $d): $v = (float)$sit['notas'][(int)$a['id']][$d['id']]['total']; ?><td class="num<?= $v < 6 ? ' baixa' : '' ?>"><?= nc_num($v) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php /* ===================== CRITÉRIOS ===================== */ if ($aba === 'criterios' && $gerir): ?>
<form method="post" class="nc-cfg">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="criterios">
    <p class="text-secondary mb-3">Os três aspectos valem para todas as turmas e disciplinas. Os valores precisam somar <b>10</b>. Em cada linha da descrição use <code>Título | explicação</code>.</p>
    <div class="nc-cfg-g">
        <?php foreach ($crit as $i => $c): ?>
        <div class="card nc-cfg-c" style="--nc:<?= $cores[$c['cor']] ?? '#13692a' ?>">
            <div class="card-body">
                <span class="nc-crit-ic mb-2"><i class="bi bi-<?= e($c['icone']) ?>"></i></span>
                <label class="form-label">Nome do aspecto</label><input class="form-control mb-2" name="nome[<?= $i ?>]" value="<?= e($c['nome']) ?>" maxlength="60" required>
                <label class="form-label">Vale até (pontos)</label><input class="form-control mb-2 nc-cfg-max" name="max[<?= $i ?>]" value="<?= e(nc_num((float)$c['max'], fmod((float)$c['max'], 1) ? 2 : 0)) ?>" inputmode="decimal" required>
                <label class="form-label">O que se observa</label><textarea class="form-control" name="itens[<?= $i ?>]" rows="6"><?= e(implode("\n", array_map(fn($x) => $x[0] . ' | ' . $x[1], $c['itens']))) ?></textarea>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="nc-salvar mt-3"><span>Soma atual: <b id="ncSoma">10</b> de 10</span><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvar critérios</button></div>
</form>
<?php endif; ?>
<?php
$script_pagina = '<script>
(function () {
  const num = v => { v = String(v).trim().replace(",", "."); return v === "" ? null : (isNaN(+v) ? NaN : +v); };
  const fmt = v => v.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 2 });
  const br = v => String(v).replace(".", ",");
  const f = document.getElementById("ncForm");
  if (f && !f.classList.contains("so-ler")) {
    const feitos = document.getElementById("ncFeitos");
    const dica = document.createElement("div"); dica.className = "nc-dica"; dica.hidden = true; document.body.appendChild(dica);
    let tDica;
    const avisa = (i, txt) => { const r = i.getBoundingClientRect(); dica.textContent = txt; dica.hidden = false;
      dica.style.left = (r.left + r.width / 2 + scrollX) + "px"; dica.style.top = (r.top + scrollY - 8) + "px";
      i.classList.remove("negado"); void i.offsetWidth; i.classList.add("negado"); clearTimeout(tDica); tDica = setTimeout(() => dica.hidden = true, 1800); };
    const linha = l => {
      const ins = [...l.querySelectorAll("input[data-max]")]; let s = 0, cheios = 0;
      ins.forEach(i => { const v = num(i.value); if (v !== null && !isNaN(v)) { s += v; cheios++; } });
      const t = l.querySelector(".nc-tot b"); const ok = cheios === 3;
      t.textContent = ok ? fmt(s) : (cheios ? "…" : "—"); l.classList.toggle("feito", ok); l.classList.toggle("baixa", ok && s < 6); l.classList.toggle("parcial", !ok && cheios > 0);
    };
    const conta = () => { const n = f.querySelectorAll(".nc-lin.feito").length, tt = f.querySelectorAll(".nc-lin:not(.nc-topo)").length; if (feitos) feitos.textContent = n; const an = document.querySelector(".ncx-anel"); if (an && tt) an.style.setProperty("--p", Math.round(n / tt * 100)); };
    f.querySelectorAll("input[data-max]").forEach(i => { i.dataset.ok = i.value; });
    /* não aceita nada além de número, nem valor acima do máximo do aspecto */
    f.addEventListener("input", e => {
      const i = e.target; if (!i.matches("input[data-max]")) return;
      let v = i.value.replace(/[^0-9.,]/g, "").replace(".", ",");
      if ((v.match(/,/g) || []).length > 1) v = i.dataset.ok;
      const n = num(v), m = +i.dataset.max;
      if (n !== null && (isNaN(n) || n > m)) { i.value = i.dataset.ok; avisa(i, "O máximo aqui é " + br(m)); }
      else { i.value = v; i.dataset.ok = v; }
      linha(i.closest(".nc-lin")); conta(); f.dataset.sujo = 1;
    });
    f.querySelectorAll(".nc-max").forEach(b => b.addEventListener("click", () => {
      const l = b.closest(".nc-lin"); l.querySelectorAll("input[data-max]").forEach(i => { i.value = br(+i.dataset.max); i.dataset.ok = i.value; });
      linha(l); conta(); f.dataset.sujo = 1; l.classList.add("pulsa"); setTimeout(() => l.classList.remove("pulsa"), 600); }));
    /* Enter vai para o próximo campo */
    f.addEventListener("keydown", e => { if (e.key === "Enter" && e.target.matches("input[data-max]")) { e.preventDefault(); const l = [...f.querySelectorAll("input[data-max]")]; const k = l.indexOf(e.target); if (l[k + 1]) { l[k + 1].focus(); l[k + 1].select(); } } });
    f.addEventListener("submit", () => { delete f.dataset.sujo; });
    addEventListener("beforeunload", e => { if (f.dataset.sujo) { e.preventDefault(); e.returnValue = ""; } });
    f.querySelectorAll(".nc-lin:not(.nc-topo)").forEach(linha); conta();
  }
  const soma = document.getElementById("ncSoma");
  if (soma) { const ms = [...document.querySelectorAll(".nc-cfg-max")]; const at = () => { const s = ms.reduce((a, i) => a + (num(i.value) || 0), 0); soma.textContent = fmt(s); soma.parentElement.classList.toggle("text-danger", Math.abs(s - 10) > 0.001); }; ms.forEach(i => i.addEventListener("input", at)); at(); }
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
