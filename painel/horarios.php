<?php
require __DIR__ . '/../includes/app.php';
exigir_login();

$ver_todos = pode('horarios.todos');
$editar = pode('horarios.editar');
$aulas = aulas();
$modo = $ver_todos && in_array($_GET['ver'] ?? '', ['professor', 'turma', 'dia', 'aulas'], true) ? $_GET['ver'] : 'professor';
if ($modo === 'aulas' && !$editar) $modo = 'professor';
$prof_id = $ver_todos ? (int)($_GET['professor'] ?? uid()) : uid();
$hoje_dow = (int)date('N');

if (eh_post()) {
    csrf_verificar();
    if (!$editar) { flash('danger', 'Seu perfil pode ver o horário, mas não alterar.'); redirecionar('horarios.php'); }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'grade') {
        $pid = (int)$_POST['professor'];
        $prof = q_um('SELECT id, nome FROM usuarios WHERE id = ?', [$pid]);
        if (!$prof) redirecionar('horarios.php');
        $novas = [];
        foreach ((array)($_POST['cel'] ?? []) as $chave => $v) {
            [$d, $a] = array_map('intval', explode('-', $chave) + [1 => 0]);
            if (!isset(DIAS_UTEIS[$d]) || !isset($aulas[$a]) || $v === '') continue;
            if ($v === 'pl') { $novas[] = [$d, $a, null, null, 'Planejamento']; continue; }
            [$t, $di] = array_map('intval', explode(':', $v) + [1 => 0]);
            if ($t && $di) $novas[] = [$d, $a, $t, $di, null];
        }
        /* conflito: a turma já tem outro professor no mesmo dia e aula */
        $conf = [];
        foreach ($novas as [$d, $a, $t]) {
            if (!$t) continue;
            $o = q_um('SELECT h.*, u.nome, d.nome AS disc FROM horarios h JOIN usuarios u ON u.id = h.usuario_id LEFT JOIN disciplinas d ON d.id = h.disciplina_id
                        WHERE h.turma_id = ? AND h.dia_semana = ? AND h.aula = ? AND h.usuario_id <> ?', [$t, $d, $a, $pid]);
            if ($o) $conf[] = DIAS_UTEIS[$d] . ', ' . $a . 'ª aula: a turma já tem ' . $o['disc'] . ' com ' . primeiro_ultimo($o['nome']);
        }
        if ($conf) { flash('danger', 'Não salvei por conflito de horário. ' . implode(' · ', array_slice($conf, 0, 6)) . (count($conf) > 6 ? ' …' : '')); redirecionar("horarios.php?professor=$pid&editar=1"); }
        db()->beginTransaction();
        q('DELETE FROM horarios WHERE usuario_id = ?', [$pid]);
        foreach ($novas as [$d, $a, $t, $di, $sala]) q('INSERT INTO horarios (usuario_id, dia_semana, aula, turma_id, disciplina_id, sala) VALUES (?, ?, ?, ?, ?, ?)', [$pid, $d, $a, $t, $di, $sala]);
        db()->commit();
        registrar_log('horario', 'Horário de ' . $prof['nome'] . ': ' . count($novas) . ' aula(s)');
        flash('success', 'Horário de ' . $prof['nome'] . ' salvo (' . count($novas) . ' aula(s) na semana).');
        redirecionar("horarios.php?professor=$pid");
    }
    if ($acao === 'aulas') {
        $novo = [];
        foreach ((array)($_POST['ini'] ?? []) as $n => $i) {
            $f = (string)($_POST['fim'][$n] ?? '');
            if (preg_match('/^\d{2}:\d{2}$/', (string)$i) && preg_match('/^\d{2}:\d{2}$/', $f) && $f > $i) $novo[count($novo) + 1] = [$i, $f];
        }
        if ($novo) { config_gravar('horarios_aulas', json_encode($novo)); flash('success', count($novo) . ' aulas por dia salvas.'); }
        redirecionar('horarios.php?ver=aulas');
    }
}

$professores = $ver_todos ? q_todos("SELECT u.id, u.nome, u.foto, u.cargo, u.perfil, (SELECT COUNT(*) FROM horarios h WHERE h.usuario_id = u.id) AS aulas_sem
                                      FROM usuarios u WHERE u.ativo = 1 AND (u.perfil = 'professor' OR EXISTS (SELECT 1 FROM horarios h WHERE h.usuario_id = u.id) OR EXISTS (SELECT 1 FROM atribuicoes a WHERE a.usuario_id = u.id))
                                     ORDER BY u.nome") : [];

$titulo = 'Horários';
$subtitulo = $ver_todos ? 'Horário de aulas de todos os professores, por professor, por turma ou por dia.' : 'Seu horário de aulas da semana.';
$menu = 'horarios';
require __DIR__ . '/../includes/layout_topo.php';

/** Grade semanal (dias × aulas) em cartões. No celular vira uma lista do dia escolhido.
 *  $celula($dia, $aula) devolve o HTML do quadrinho (vazio = aula livre) */
function grade_semana(array $aulas, callable $celula, int $hoje_dow, bool $edicao = false): string
{
    $agora = aula_agora();
    $dsel = $hoje_dow <= 5 ? $hoje_dow : 1;
    $h = '<div class="hz-semana' . ($edicao ? ' hz-edicao' : '') . '" data-dia="' . $dsel . '">';
    $h .= '<div class="hz-dias" role="tablist" aria-label="Dia da semana">';
    foreach (DIAS_UTEIS as $d => $n) $h .= '<button type="button" role="tab" data-d="' . $d . '" class="' . ($d === $dsel ? 'ativo' : '') . '">' . mb_substr($n, 0, 3) . ($d === $hoje_dow ? '<i></i>' : '') . '</button>';
    $h .= '</div><div class="hz-grade">';
    $h .= '<div class="hz-h hz-canto">Aula</div>';
    foreach (DIAS_UTEIS as $d => $n) $h .= '<div class="hz-h' . ($d === $hoje_dow ? ' hoje' : '') . '" data-d="' . $d . '">' . $n . ($d === $hoje_dow ? '<small>hoje</small>' : '') . '</div>';
    $ant = null;
    foreach ($aulas as $a => [$i, $f]) {
        if ($ant && $i !== $ant) $h .= '<div class="hz-pausa"><span><i class="bi bi-' . ((strtotime($i) - strtotime($ant)) >= 2400 ? 'cup-hot' : 'cup-straw') . '"></i>' . ((strtotime($i) - strtotime($ant)) >= 2400 ? 'Almoço' : 'Intervalo') . ' · ' . $ant . '–' . $i . '</span></div>';
        $h .= '<div class="hz-aula' . ($agora === $a ? ' agora' : '') . '"><b>' . $a . 'ª</b><small>' . $i . '<br>' . $f . '</small></div>';
        foreach (DIAS_UTEIS as $d => $_) {
            $c = $celula($d, $a);
            $h .= '<div class="hz-cel' . ($d === $hoje_dow ? ' hoje' : '') . ($d === $hoje_dow && $agora === $a ? ' agora' : '') . ($c === '' ? ' livre' : '') . '" data-d="' . $d . '"><span class="hz-hora">' . $a . 'ª · ' . $i . '–' . $f . '</span>' . ($c === '' ? '<span class="hz-vazio">livre</span>' : $c) . '</div>';
        }
        $ant = $f;
    }
    return $h . '</div></div>';
}
$cor_disc = fn($id) => 'c' . ((int)$id % 8);
$hz_script = true;
?>
<?php if ($ver_todos): ?>
<ul class="nav nav-abas mb-3">
    <li class="nav-item"><a class="nav-link<?= $modo === 'professor' ? ' active' : '' ?>" href="?ver=professor"><i class="bi bi-person-video3 me-1"></i>Por professor</a></li>
    <li class="nav-item"><a class="nav-link<?= $modo === 'turma' ? ' active' : '' ?>" href="?ver=turma"><i class="bi bi-people me-1"></i>Por turma</a></li>
    <li class="nav-item"><a class="nav-link<?= $modo === 'dia' ? ' active' : '' ?>" href="?ver=dia"><i class="bi bi-calendar-day me-1"></i>Todos no dia</a></li>
    <?php if ($editar): ?><li class="nav-item"><a class="nav-link<?= $modo === 'aulas' ? ' active' : '' ?>" href="?ver=aulas"><i class="bi bi-clock me-1"></i>Horário das aulas</a></li><?php endif; ?>
</ul>
<?php endif; ?>

<?php if ($modo === 'professor'):
    $prof = q_um('SELECT id, nome, foto, cargo, perfil, nome_exibicao FROM usuarios WHERE id = ?', [$prof_id]) ?: usuario();
    $grade = grade_professor((int)$prof['id']);
    $modo_edicao = $editar && !empty($_GET['editar']);
    $total = array_sum(array_map('count', $grade));
    $hoje_lista = $grade[$hoje_dow] ?? [];
    ksort($hoje_lista);
    $atribs = $modo_edicao ? q_todos('SELECT a.turma_id, a.disciplina_id, d.nome AS disciplina, t.serie, t.letra, c.sigla FROM atribuicoes a JOIN disciplinas d ON d.id = a.disciplina_id
                                       JOIN turmas t ON t.id = a.turma_id JOIN cursos c ON c.id = t.curso_id WHERE a.usuario_id = ? AND t.ano_letivo = ? ORDER BY t.serie, t.letra, d.nome', [$prof['id'], ANO_VER]) : []; ?>
    <?php
    $turmas_p = []; $discs_p = [];
    foreach ($grade as $dd) foreach ($dd as $c) if ($c['turma_id']) { $turmas_p[$c['turma_id']] = 1; $discs_p[$c['disciplina_id']] = 1; }
    $agora_a = aula_agora(); $prox = null; $atual_c = null;
    foreach ($hoje_lista as $a => $c) { if ($agora_a === $a) $atual_c = [$a, $c]; elseif (!$prox && ($aulas[$a][0] ?? '') > date('H:i')) $prox = [$a, $c]; }
    $rot = fn($c) => $c['turma_id'] ? $c['serie'] . 'º ' . $c['letra'] . ' ' . $c['sigla'] . ' · ' . $c['disciplina'] : ($c['sala'] ?: 'Planejamento'); ?>
    <section class="hz-topo">
        <div class="hz-quem">
            <?= avatar($prof, 'usuario', 'avatar-lg') ?>
            <div><small><?= $prof['id'] == uid() ? 'Meu horário' : 'Horário do professor' ?></small><h2><?= e($prof['nome']) ?></h2><span><?= e(cargo_usuario($prof)) ?></span></div>
        </div>
        <div class="hz-nums">
            <div><b><?= $total ?></b><small>aulas na semana</small></div>
            <div><b><?= count($turmas_p) ?></b><small>turmas</small></div>
            <div><b><?= count($discs_p) ?></b><small>disciplinas</small></div>
        </div>
        <div class="hz-agora<?= $atual_c ? ' em-aula' : '' ?>">
            <?php if ($hoje_dow > 5): ?><small>Fim de semana</small><b>Sem aulas hoje</b>
            <?php elseif ($atual_c): ?><small><i class="bi bi-broadcast"></i> Agora · <?= $atual_c[0] ?>ª aula até <?= $aulas[$atual_c[0]][1] ?></small><b><?= e($rot($atual_c[1])) ?></b>
            <?php elseif ($prox): ?><small><i class="bi bi-hourglass-split"></i> Próxima · <?= $prox[0] ?>ª aula às <?= $aulas[$prox[0]][0] ?></small><b><?= e($rot($prox[1])) ?></b>
            <?php else: ?><small>Hoje</small><b><?= $hoje_lista ? 'Aulas do dia encerradas' : 'Sem aulas hoje' ?></b><?php endif; ?>
        </div>
    </section>
    <div class="hz-barra">
        <?php if ($ver_todos): ?>
        <form method="get" class="hz-sel"><input type="hidden" name="ver" value="professor"><i class="bi bi-person-video3"></i>
            <select class="form-select" name="professor" onchange="this.form.submit()" aria-label="Professor">
                <?php foreach ($professores as $p): ?><option value="<?= $p['id'] ?>"<?= (int)$p['id'] === (int)$prof['id'] ? ' selected' : '' ?>><?= e($p['nome']) ?> · <?= (int)$p['aulas_sem'] ?> aula(s)</option><?php endforeach; ?>
            </select></form>
        <?php endif; ?>
        <div class="ms-auto d-flex gap-2">
            <button class="btn btn-light" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
            <?php if ($editar && !$modo_edicao): ?><a class="btn btn-primary" href="?ver=professor&professor=<?= $prof['id'] ?>&editar=1"><i class="bi bi-pencil me-1"></i>Montar horário</a><?php endif; ?>
        </div>
    </div>
    <?= cabecalho_impressao('Horário de ' . $prof['nome'], 'Ano letivo ' . ANO_VER) ?>

    <?php if (!$modo_edicao && $hoje_dow <= 5 && $hoje_lista): ?>
    <div class="hz-hoje mb-3">
        <span class="hz-hoje-t"><i class="bi bi-calendar-day"></i>Hoje</span>
        <div class="hz-hoje-l"><?php foreach ($hoje_lista as $a => $c): $fim = $aulas[$a][1] ?? ''; $st = $agora_a === $a ? 'agora' : ($fim !== '' && $fim <= date('H:i') ? 'foi' : 'vem'); ?>
            <div class="hz-hc <?= $st ?> <?= $c['turma_id'] ? $cor_disc($c['disciplina_id']) : 'pl' ?>"><small><?= $a ?>ª · <?= e($aulas[$a][0] ?? '') ?></small><b><?= e($c['turma_id'] ? $c['serie'] . 'º ' . $c['letra'] . ' ' . $c['sigla'] : ($c['sala'] ?: 'Planejamento')) ?></b><span><?= e($c['turma_id'] ? $c['disciplina'] : '') ?></span><?php if ($st === 'agora'): ?><em>agora</em><?php endif; ?></div>
        <?php endforeach; ?></div>
    </div>
    <?php endif; ?>

    <?php if ($modo_edicao): ?>
    <form method="post" class="card hz-card">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="grade"><input type="hidden" name="professor" value="<?= $prof['id'] ?>">
        <div class="card-header"><h2>Montar horário</h2><span class="small text-secondary">Escolha, em cada aula, a turma e a disciplina. As opções vêm das aulas atribuídas ao professor em Cursos e turmas.</span></div>
        <?php if (!$atribs): ?><div class="alert alert-warning m-3">Este professor ainda não tem aulas atribuídas. Cadastre em <a href="configuracoes.php?aba=atribuicoes">Cursos e turmas › Atribuições</a>.</div><?php endif; ?>
        <?= grade_semana($aulas, function ($d, $a) use ($grade, $atribs) {
            $c = $grade[$d][$a] ?? null;
            $atual = $c ? ($c['turma_id'] ? $c['turma_id'] . ':' . $c['disciplina_id'] : 'pl') : '';
            $h = '<select class="form-select form-select-sm sel-grade" name="cel[' . $d . '-' . $a . ']" aria-label="' . DIAS_UTEIS[$d] . ' ' . $a . 'ª aula"><option value="">—</option><option value="pl"' . ($atual === 'pl' ? ' selected' : '') . '>Planejamento</option>';
            foreach ($atribs as $x) { $v = $x['turma_id'] . ':' . $x['disciplina_id']; $h .= '<option value="' . $v . '"' . ($atual === $v ? ' selected' : '') . '>' . e($x['serie'] . 'º ' . $x['letra'] . ' · ' . $x['disciplina']) . '</option>'; }
            return $h . '</select>';
        }, $hoje_dow, true) ?>
        <div class="barra-salvar"><a class="btn btn-light" href="?ver=professor&professor=<?= $prof['id'] ?>">Cancelar</a><button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-check2 me-1"></i>Salvar horário</button></div>
    </form>
    <?php else: ?>
    <div class="card hz-card">
        <?= grade_semana($aulas, function ($d, $a) use ($grade, $cor_disc) {
            $c = $grade[$d][$a] ?? null;
            if (!$c) return '';
            if (!$c['turma_id']) return '<div class="aula-cel pl"><i class="bi bi-journal-text"></i>' . e($c['sala'] ?: 'Planejamento') . '</div>';
            return '<div class="aula-cel ' . $cor_disc($c['disciplina_id']) . '"><strong>' . e($c['serie'] . 'º ' . $c['letra']) . ' <span>' . e($c['sigla']) . '</span></strong><small title="' . e($c['disciplina']) . '">' . e($c['disciplina']) . '</small></div>';
        }, $hoje_dow) ?>
        <?php if (!$total): ?><div class="vazio py-4 hz-sem"><i class="bi bi-calendar3-week"></i><strong>Horário ainda não cadastrado</strong><?= $editar ? 'Clique em “Montar horário”.' : 'A coordenação cadastra o horário de cada professor.' ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($modo === 'turma'):
    $turmas = turmas_lista();
    $tid = (int)($_GET['turma'] ?? ($turmas[0]['id'] ?? 0));
    $g = [];
    foreach (q_todos('SELECT h.*, u.nome, d.nome AS disciplina FROM horarios h JOIN usuarios u ON u.id = h.usuario_id LEFT JOIN disciplinas d ON d.id = h.disciplina_id WHERE h.turma_id = ?', [$tid]) as $r) $g[(int)$r['dia_semana']][(int)$r['aula']] = $r;
    $tsel = null; foreach ($turmas as $t) if ((int)$t['id'] === $tid) $tsel = $t; ?>
    <div class="hz-barra">
        <form method="get" class="hz-sel"><input type="hidden" name="ver" value="turma"><i class="bi bi-people"></i><select class="form-select" name="turma" onchange="this.form.submit()" aria-label="Turma">
            <?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$t['id'] === $tid ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select></form>
        <button class="btn btn-light ms-auto" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
    </div>
    <?= $tsel ? cabecalho_impressao('Horário da turma ' . turma_nome($tsel), 'Ano letivo ' . ANO_VER) : '' ?>
    <div class="card hz-card"><?= grade_semana($aulas, function ($d, $a) use ($g, $cor_disc) {
        $c = $g[$d][$a] ?? null;
        return $c ? '<div class="aula-cel ' . $cor_disc($c['disciplina_id']) . '"><strong>' . e($c['disciplina'] ?? '—') . '</strong><small>' . e(primeiro_ultimo($c['nome'])) . '</small></div>' : '';
    }, $hoje_dow) ?></div>

<?php elseif ($modo === 'dia'):
    $dia = (int)($_GET['dia'] ?? min(5, $hoje_dow));
    if (!isset(DIAS_UTEIS[$dia])) $dia = 1;
    $linhas = [];
    foreach (q_todos('SELECT h.*, u.nome, u.foto, t.serie, t.letra, c.sigla, d.nome AS disciplina FROM horarios h JOIN usuarios u ON u.id = h.usuario_id
                       LEFT JOIN turmas t ON t.id = h.turma_id LEFT JOIN cursos c ON c.id = t.curso_id LEFT JOIN disciplinas d ON d.id = h.disciplina_id
                      WHERE h.dia_semana = ? ORDER BY u.nome', [$dia]) as $r) { $linhas[$r['usuario_id']]['p'] = $r; $linhas[$r['usuario_id']]['a'][(int)$r['aula']] = $r; } ?>
    <div class="hz-barra">
        <div class="chips-filtro"><?php foreach (DIAS_UTEIS as $d => $n): ?><a class="chip<?= $d === $dia ? ' ativo' : '' ?>" href="?ver=dia&dia=<?= $d ?>"><?= $n ?><?= $d === $hoje_dow ? ' · hoje' : '' ?></a><?php endforeach; ?></div>
        <button class="btn btn-light ms-auto" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
    </div>
    <?= cabecalho_impressao('Horário de todos os professores · ' . DIAS_UTEIS[$dia], 'Ano letivo ' . ANO_VER) ?>
    <div class="card hz-card"><div class="table-responsive"><table class="table grade-horario grade-dia mb-0">
        <thead><tr><th>Professor</th><?php foreach ($aulas as $a => [$i]): ?><th class="text-center<?= $dia === $hoje_dow && aula_agora() === $a ? ' agora' : '' ?>"><?= $a ?>ª<small><?= $i ?></small></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($linhas as $uid_l => $l): ?>
            <tr><td class="text-nowrap"><a class="nome-link" href="?ver=professor&professor=<?= $uid_l ?>"><?= e(primeiro_ultimo($l['p']['nome'])) ?></a></td>
            <?php foreach ($aulas as $a => $_): $c = $l['a'][$a] ?? null; ?>
                <td class="text-center<?= $dia === $hoje_dow && aula_agora() === $a ? ' agora' : '' ?>"><?= $c ? ($c['turma_id'] ? '<div class="aula-cel ' . $cor_disc($c['disciplina_id']) . '"><strong>' . e($c['serie'] . 'º' . $c['letra']) . '</strong><small>' . e(mb_strimwidth((string)$c['disciplina'], 0, 14, '…')) . '</small></div>' : '<div class="aula-cel pl">PL</div>') : '' ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (!$linhas): ?><div class="vazio py-4"><i class="bi bi-calendar3-week"></i>Nenhum horário cadastrado para <?= mb_strtolower(DIAS_UTEIS[$dia]) ?>.</div><?php endif; ?></div>

<?php else: ?>
    <form method="post" class="card" style="max-width:620px">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="aulas">
        <div class="card-header"><h2>Horário das aulas</h2><span class="small text-secondary">vale para o horário dos professores e para as reservas</span></div>
        <div class="card-body">
            <div id="linhasAulas">
            <?php $n = 0; foreach ($aulas + [count($aulas) + 1 => ['', ''], count($aulas) + 2 => ['', '']] as $a => [$i, $f]): $n++; ?>
                <div class="row g-2 mb-2 align-items-center"><div class="col-3 fw-semibold"><?= $n ?>ª aula</div>
                    <div class="col"><input class="form-control" type="time" name="ini[]" value="<?= e($i) ?>" aria-label="Início da <?= $n ?>ª aula"></div>
                    <div class="col"><input class="form-control" type="time" name="fim[]" value="<?= e($f) ?>" aria-label="Fim da <?= $n ?>ª aula"></div></div>
            <?php endforeach; ?>
            </div>
            <div class="form-text">Deixe em branco as linhas que não usar. Intervalos e almoço são os espaços entre uma aula e outra.</div>
        </div>
        <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit">Salvar</button></div>
    </form>
<?php endif; ?>
<?php
$script_pagina = '<script>
document.querySelectorAll(".hz-semana").forEach(s => s.querySelectorAll(".hz-dias button").forEach(b => b.addEventListener("click", () => {
  s.dataset.dia = b.dataset.d; s.querySelectorAll(".hz-dias button").forEach(x => x.classList.toggle("ativo", x === b)); })));
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
