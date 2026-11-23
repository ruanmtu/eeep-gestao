<?php
require __DIR__ . '/../includes/app.php';
exigir('reservas');

$aba = in_array($_GET['aba'] ?? '', ['agendar', 'agenda', 'minhas', 'recursos'], true) ? $_GET['aba'] : 'agendar';
/* a agenda de todos e o controle de retiradas ficam com quem tem a permissão (ex.: professor(a) bibliotecário(a)) */
$ve_agenda = pode('reservas.agenda') || pode('reservas.gerenciar');
if ($aba === 'recursos' && !pode('reservas.gerenciar')) $aba = 'agendar';
if ($aba === 'agenda' && !$ve_agenda) $aba = 'minhas';
$aulas = aulas();
$hoje = date('Y-m-d');

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'reservar') {
        $data = (string)($_POST['data'] ?? '');
        $sel_aulas = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['aulas'] ?? [])), fn($a) => isset($aulas[$a]))));
        $sel_rec = array_values(array_unique(array_map('intval', (array)($_POST['recursos'] ?? []))));
        $recs = [];
        foreach (recursos_lista() as $r) if (in_array((int)$r['id'], $sel_rec, true)) $recs[(int)$r['id']] = $r;
        $tem_ambiente = (bool)array_filter($recs, fn($r) => $r['tipo'] === 'ambiente');
        $metod = trim((string)($_POST['metodologia'] ?? ''));
        $desc = trim((string)($_POST['descricao'] ?? ''));
        $turma = (int)($_POST['turma_id'] ?? 0) ?: null;
        $erro = null;
        if (!data_valida($data)) $erro = 'Escolha a data.';
        elseif ($data < $hoje) $erro = 'Não dá para agendar em data que já passou.';
        elseif ((int)date('N', strtotime($data)) > 5 && !pode('reservas.gerenciar')) $erro = 'A data escolhida cai num fim de semana.';
        elseif (!$sel_aulas) $erro = 'Escolha pelo menos uma aula.';
        elseif (!$recs) $erro = 'Escolha o ambiente ou o equipamento.';
        elseif ($tem_ambiente && !in_array($metod, METODOLOGIAS, true)) $erro = 'Para reservar um ambiente, diga qual é a metodologia da aula.';
        elseif ($tem_ambiente && mb_strlen($desc) < 5) $erro = 'Conte em poucas palavras o que vai acontecer na aula (ex.: palestra sobre…).';
        if (!$erro) {
            /* conflitos: alguém já reservou o mesmo recurso no mesmo dia e aula */
            $conf = q_todos('SELECT r.aula, rc.nome AS recurso, u.nome AS professor, u.cargo, r.metodologia, r.criado_em FROM reservas r JOIN recursos rc ON rc.id = r.recurso_id
                              LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.data = ? AND r.recurso_id IN (' . implode(',', array_keys($recs)) . ') AND r.aula IN (' . implode(',', $sel_aulas) . ')
                              ORDER BY r.aula', [$data]);
            if ($conf) {
                $msg = '<strong>Conflito de agendamento em ' . data_br($data) . '.</strong> Estes itens já estavam reservados:<div class="rv-conf">';
                foreach ($conf as $c) $msg .= '<div><span class="rv-conf-aula">' . (int)$c['aula'] . 'ª aula</span><b>' . e($c['recurso']) . '</b><span><i class="bi bi-person"></i>' . e($c['professor'] ?? '—')
                    . ($c['cargo'] ? ' <small>' . e($c['cargo']) . '</small>' : '') . '</span>' . ($c['metodologia'] ? '<span class="rv-conf-met">' . e($c['metodologia']) . '</span>' : '') . '</div>';
                $msg .= '</div>Escolha outro horário ou outro equipamento.';
                $_SESSION['reserva_form'] = $_POST;
                $_SESSION['flash'][] = ['danger', $msg, true];
                redirecionar('reservas.php');
            }
            db()->beginTransaction();
            try {
                foreach ($recs as $rid => $r) foreach ($sel_aulas as $a)
                    q('INSERT INTO reservas (recurso_id, data, aula, usuario_id, turma_id, metodologia, descricao, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                      [$rid, $data, $a, uid(), $turma, $r['tipo'] === 'ambiente' ? $metod : null, $desc !== '' ? mb_substr($desc, 0, 255) : null, date('Y-m-d H:i:s')]);
                db()->commit();
            } catch (PDOException $e) {
                db()->rollBack();
                flash('danger', 'Alguém acabou de reservar o mesmo horário. Atualize a página e escolha de novo.');
                redirecionar('reservas.php');
            }
            registrar_log('reserva', implode(', ', array_column($recs, 'nome')) . ' · ' . data_br($data) . ' · aula(s) ' . implode(', ', $sel_aulas));
            flash('success', 'Reservado: ' . implode(', ', array_column($recs, 'nome')) . ' em ' . data_br($data) . ' (' . implode(', ', array_map(fn($a) => $a . 'ª', $sel_aulas)) . ' aula).');
            redirecionar('reservas.php?aba=minhas');
        }
        $_SESSION['reserva_form'] = $_POST;
        flash('warning', $erro);
        redirecionar('reservas.php');
    }

    if ($acao === 'cancelar') {
        $r = q_um('SELECT r.*, rc.nome FROM reservas r JOIN recursos rc ON rc.id = r.recurso_id WHERE r.id = ?', [(int)$_POST['id']]);
        if ($r && ((int)$r['usuario_id'] === uid() || pode('reservas.gerenciar'))) {
            q('DELETE FROM reservas WHERE id = ?', [$r['id']]);
            registrar_log('reserva_cancelada', $r['nome'] . ' · ' . data_br($r['data']) . ' · ' . $r['aula'] . 'ª aula');
            flash('success', 'Reserva cancelada: ' . $r['nome'] . ', ' . data_br($r['data']) . ', ' . $r['aula'] . 'ª aula.');
        }
        redirecionar('reservas.php?aba=' . (($_POST['volta'] ?? '') === 'agenda' ? 'agenda&data=' . ($r['data'] ?? $hoje) : 'minhas'));
    }

    /* Centro de Multimeios: retirada e devolução dos equipamentos agendados */
    if (in_array($acao, ['retirada', 'devolucao', 'retirada_desfazer'], true) && $ve_agenda) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
        $dia_v = data_valida($_POST['dia'] ?? '') ? $_POST['dia'] : $hoje;
        if ($ids) {
            $lista_ids = implode(',', $ids);
            $rows = q_todos("SELECT r.id, r.usuario_id, rc.nome, rc.tipo, u.nome AS professor FROM reservas r JOIN recursos rc ON rc.id = r.recurso_id LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.id IN ($lista_ids)");
            $nome_rec = $rows[0]['nome'] ?? '';
            if ($acao === 'retirada') {
                $quando = str_replace('T', ' ', (string)($_POST['quando'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $quando)) $quando = date('Y-m-d H:i');
                $tipo = in_array($_POST['tipo'] ?? '', ['professor', 'aluno', 'outro'], true) ? $_POST['tipo'] : 'professor';
                $nome = trim((string)($_POST['nome'] ?? ''));
                if ($tipo === 'professor') $nome = (string)($rows[0]['professor'] ?? $nome);
                if ($tipo !== 'professor' && mb_strlen($nome) < 3) { flash('warning', 'Informe o nome de quem retirou.'); redirecionar('reservas.php?aba=agenda&data=' . $dia_v); }
                $obs = trim((string)($_POST['obs'] ?? ''));
                q("UPDATE reservas SET retirada_em = ?, retirada_tipo = ?, retirada_nome = ?, retirada_obs = ?, retirada_registro = ?, devolvida_em = NULL WHERE id IN ($lista_ids)",
                  [$quando . ':00', $tipo, mb_substr($nome, 0, 120), $obs !== '' ? mb_substr($obs, 0, 255) : null, uid()]);
                registrar_log('reserva_retirada', $nome_rec . ' · ' . $nome);
                flash('success', $nome_rec . ' marcado como retirado por ' . $nome . ' às ' . substr($quando, 11, 5) . '.');
            } elseif ($acao === 'devolucao') {
                q("UPDATE reservas SET devolvida_em = ? WHERE id IN ($lista_ids) AND retirada_em IS NOT NULL", [date('Y-m-d H:i:s')]);
                registrar_log('reserva_devolucao', $nome_rec);
                flash('success', $nome_rec . ' devolvido ao Centro de Multimeios.');
            } else {
                q("UPDATE reservas SET retirada_em = NULL, retirada_tipo = NULL, retirada_nome = NULL, retirada_obs = NULL, retirada_registro = NULL, devolvida_em = NULL WHERE id IN ($lista_ids)");
                flash('success', 'Registro de retirada desfeito.');
            }
        }
        redirecionar('reservas.php?aba=agenda&data=' . $dia_v . '#retiradas');
    }

    if ($acao === 'recurso_salvar' && pode('reservas.gerenciar')) {
        $nome = trim((string)($_POST['nome'] ?? ''));
        $tipo = ($_POST['tipo'] ?? '') === 'ambiente' ? 'ambiente' : 'equipamento';
        $grupo = trim((string)($_POST['grupo'] ?? '')) ?: ($tipo === 'ambiente' ? 'Ambientes' : 'Equipamentos');
        $rid = (int)($_POST['id'] ?? 0);
        if (mb_strlen($nome) < 2) flash('warning', 'Informe o nome.');
        elseif (q_valor('SELECT id FROM recursos WHERE nome = ? AND id <> ?', [$nome, $rid])) flash('warning', 'Já existe "' . $nome . '".');
        elseif ($rid) { q('UPDATE recursos SET nome = ?, tipo = ?, grupo = ?, descricao = ?, ativo = ? WHERE id = ?', [mb_substr($nome, 0, 80), $tipo, mb_substr($grupo, 0, 40), trim((string)($_POST['descricao'] ?? '')) ?: null, !empty($_POST['ativo']) ? 1 : 0, $rid]); flash('success', '"' . $nome . '" atualizado.'); }
        else {
            $qtd = max(1, min(30, (int)($_POST['quantidade'] ?? 1)));
            for ($i = 1; $i <= $qtd; $i++) {
                $n = $qtd > 1 ? $nome . ' ' . $i : $nome;
                if (!q_valor('SELECT id FROM recursos WHERE nome = ?', [$n])) q('INSERT INTO recursos (nome, tipo, grupo, descricao, ordem) VALUES (?, ?, ?, ?, ?)', [mb_substr($n, 0, 80), $tipo, mb_substr($grupo, 0, 40), trim((string)($_POST['descricao'] ?? '')) ?: null, 60 + $i]);
            }
            flash('success', $qtd > 1 ? "$qtd itens cadastrados ($nome 1 a $nome $qtd)." : '"' . $nome . '" cadastrado.');
        }
        redirecionar('reservas.php?aba=recursos');
    }
    redirecionar('reservas.php');
}

$form = $_SESSION['reserva_form'] ?? [];
unset($_SESSION['reserva_form']);
$pre_data = $form['data'] ?? (data_valida($_GET['data'] ?? '') ? $_GET['data'] : $hoje);
$pre_aulas = array_map('intval', (array)($form['aulas'] ?? (isset($_GET['aula']) ? [(int)$_GET['aula']] : [])));
$pre_rec = array_map('intval', (array)($form['recursos'] ?? (isset($_GET['recurso']) ? [(int)$_GET['recurso']] : [])));

$titulo = 'Agendamentos';
$subtitulo = 'Agende ambientes (auditório, laboratórios…) e equipamentos (projetores, som…) por data e aula. O sistema não deixa duas reservas no mesmo horário.';
$menu = 'reservas';
require __DIR__ . '/../includes/layout_topo.php';
?>
<ul class="nav nav-abas mb-3">
    <li class="nav-item"><a class="nav-link<?= $aba === 'agendar' ? ' active' : '' ?>" href="?aba=agendar"><i class="bi bi-calendar-plus me-1"></i>Agendar</a></li>
    <li class="nav-item"><a class="nav-link<?= $aba === 'minhas' ? ' active' : '' ?>" href="?aba=minhas"><i class="bi bi-person-check me-1"></i>Meus agendamentos</a></li>
    <?php if ($ve_agenda): ?><li class="nav-item"><a class="nav-link<?= $aba === 'agenda' ? ' active' : '' ?>" href="?aba=agenda"><i class="bi bi-grid-3x3 me-1"></i>Agenda e retiradas</a></li><?php endif; ?>
    <?php if (pode('reservas.gerenciar')): ?><li class="nav-item"><a class="nav-link<?= $aba === 'recursos' ? ' active' : '' ?>" href="?aba=recursos"><i class="bi bi-gear me-1"></i>Ambientes e equipamentos</a></li><?php endif; ?>
</ul>

<?php if ($aba === 'agendar'): ?>
<form method="post" class="rv" id="formReserva" autocomplete="off">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="reservar">
    <section class="rv-passo rv-quando">
        <header><span class="rv-n">1</span><div><h2>Quando</h2><small>Escolha o dia e as aulas</small></div></header>
        <div class="rv-data">
            <label class="form-label obrigatorio" for="r_data">Data</label>
            <input class="form-control" type="date" id="r_data" name="data" value="<?= e($pre_data) ?>" min="<?= $hoje ?>" required>
            <span class="rv-dia" id="rvDia"></span>
        </div>
        <label class="form-label obrigatorio mt-3">Aula(s)</label>
        <div class="rv-turno"><span><i class="bi bi-sun"></i>Manhã</span></div>
        <div class="aulas-escolha rv-aulas">
            <?php foreach ($aulas as $n => [$i, $f]): if ($i >= '12:00' && empty($tarde_ok)): $tarde_ok = 1; ?></div><div class="rv-turno"><span><i class="bi bi-sunset"></i>Tarde</span></div><div class="aulas-escolha rv-aulas"><?php endif; ?>
                <label class="aula-op"><input type="checkbox" name="aulas[]" value="<?= $n ?>"<?= in_array($n, $pre_aulas, true) ? ' checked' : '' ?>><strong><?= $n ?>ª aula</strong><small><?= $i ?> – <?= $f ?></small></label>
            <?php endforeach; ?>
        </div>
        <div class="form-text">Pode marcar mais de uma aula seguida (ex.: 2ª e 3ª).</div>
        <div class="rv-quem"><?= avatar(usuario(), 'usuario', 'avatar-sm') ?><div><b><?= e(nome_exibicao()) ?></b><small><?= e(cargo_usuario()) ?> · quem está agendando</small></div></div>
    </section>

    <div class="rv-lado">
        <section class="rv-passo">
            <header><span class="rv-n">2</span><div><h2>O que reservar</h2><small id="resumoDisp">escolha a data e a aula</small></div></header>
            <div id="listaRecursos"><div class="rv-vazio"><i class="bi bi-hand-index"></i><b>Escolha a data e pelo menos uma aula</b><small>Aparecem aqui só os ambientes e equipamentos livres nesse horário.</small></div></div>
        </section>
        <section class="rv-passo" id="boxAmbiente" hidden>
            <header><span class="rv-n">3</span><div><h2>Sobre a aula no ambiente</h2><small>Ajuda a coordenação a organizar o espaço</small></div></header>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label obrigatorio" for="r_met">Metodologia</label>
                    <select class="form-select" id="r_met" name="metodologia"><option value="">Escolha</option><?php foreach (METODOLOGIAS as $m): ?><option<?= ($form['metodologia'] ?? '') === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="r_turma">Turma</label>
                    <select class="form-select" id="r_turma" name="turma_id"><option value="">—</option><?php foreach (turmas_lista() as $t): ?><option value="<?= $t['id'] ?>"<?= (int)($form['turma_id'] ?? 0) === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label obrigatorio" for="r_desc">O que vai acontecer</label>
                    <textarea class="form-control" id="r_desc" name="descricao" rows="2" maxlength="255" placeholder="Ex.: palestra sobre prevenção ao uso de drogas com convidado do CAPS"><?= e($form['descricao'] ?? '') ?></textarea></div>
            </div>
        </section>
        <div class="rv-confirma"><span id="rvSel">Nada escolhido ainda</span><button class="btn btn-primary btn-lg" type="submit" id="btReservar" disabled><i class="bi bi-calendar-check me-1"></i>Confirmar reserva</button></div>
    </div>
</form>

<?php elseif ($aba === 'agenda'):
    $dia = data_valida($_GET['data'] ?? '') ? $_GET['data'] : data_padrao();
    $res = reservas_do_dia($dia);
    $mapa = [];
    foreach ($res as $r) $mapa[(int)$r['recurso_id']][(int)$r['aula']] = $r;
    $recs = recursos_lista();
    /* equipamentos do dia, juntando as aulas seguidas do mesmo professor */
    $grupos = [];
    foreach ($res as $r) {
        if ($r['recurso_tipo'] === 'ambiente') continue;
        $k = $r['recurso_id'] . '-' . ($r['usuario_id'] ?? 0);
        $grupos[$k] ??= ['r' => $r, 'ids' => [], 'aulas' => []];
        $grupos[$k]['ids'][] = (int)$r['id']; $grupos[$k]['aulas'][] = (int)$r['aula'];
    }
    uasort($grupos, fn($a, $b) => min($a['aulas']) <=> min($b['aulas']));
    $n_ret = count(array_filter($grupos, fn($g) => $g['r']['retirada_em'] && !$g['r']['devolvida_em']));
    $n_dev = count(array_filter($grupos, fn($g) => $g['r']['devolvida_em']));
    $n_pend = count($grupos) - $n_ret - $n_dev;
    $ambientes = array_filter($res, fn($r) => $r['recurso_tipo'] === 'ambiente'); ?>
    <div class="rv-agenda-topo">
        <div class="btn-group">
            <a class="btn btn-light" href="?aba=agenda&data=<?= date('Y-m-d', strtotime("$dia -1 day")) ?>" aria-label="Dia anterior"><i class="bi bi-chevron-left"></i></a>
            <form method="get" class="d-flex"><input type="hidden" name="aba" value="agenda"><input class="form-control rounded-0" type="date" name="data" value="<?= $dia ?>" onchange="this.form.submit()" aria-label="Dia"></form>
            <a class="btn btn-light" href="?aba=agenda&data=<?= date('Y-m-d', strtotime("$dia +1 day")) ?>" aria-label="Próximo dia"><i class="bi bi-chevron-right"></i></a>
        </div>
        <div><b><?= ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'][(int)date('w', strtotime($dia))] ?>, <?= data_br($dia) ?></b><?php if ($dia === $hoje): ?><span class="etiqueta ms-2">hoje</span><?php else: ?><a class="ms-2 small" href="?aba=agenda">voltar para hoje</a><?php endif; ?></div>
    </div>
    <div class="rv-kpis">
        <div><i class="bi bi-calendar-check"></i><b><?= count($res) ?></b><span>aulas agendadas</span></div>
        <div class="laranja"><i class="bi bi-hourglass-split"></i><b><?= $n_pend ?></b><span>equipamento(s) a retirar</span></div>
        <div class="azul"><i class="bi bi-box-arrow-up-right"></i><b><?= $n_ret ?></b><span>com o professor agora</span></div>
        <div class="verde"><i class="bi bi-box-arrow-in-down-left"></i><b><?= $n_dev ?></b><span>já devolvido(s)</span></div>
    </div>

    <section class="card mb-3" id="retiradas">
        <div class="card-header"><h2><i class="bi bi-box-seam me-1"></i>Retiradas no Centro de Multimeios</h2><span class="small text-secondary">projetores, som, notebooks e outros equipamentos</span></div>
        <?php if ($grupos): ?>
        <ul class="rv-ret">
            <?php foreach ($grupos as $g): $r = $g['r']; sort($g['aulas']);
                $st = $r['devolvida_em'] ? 'dev' : ($r['retirada_em'] ? 'ret' : 'pend'); $ids = implode(',', $g['ids']);
                $ini = aulas()[min($g['aulas'])][0] ?? ''; $fim = aulas()[max($g['aulas'])][1] ?? ''; ?>
            <li class="<?= $st ?>">
                <span class="rv-ret-ic"><i class="bi bi-projector"></i></span>
                <div class="rv-ret-item"><b><?= e($r['recurso']) ?></b><small><?= e($r['grupo'] ?? 'Equipamento') ?></small></div>
                <div class="rv-ret-aulas"><?php foreach ($g['aulas'] as $a): ?><span><?= $a ?>ª</span><?php endforeach; ?><small><?= $ini ?> – <?= $fim ?></small></div>
                <div class="rv-ret-prof"><span class="rv-av"><?= e(iniciais((string)($r['professor'] ?? '?'))) ?></span><div><b><?= e(primeiro_ultimo((string)($r['professor'] ?? '—'))) ?></b><small><?= $r['serie'] ? e($r['serie'] . 'º ' . $r['letra'] . ' ' . $r['sigla']) : 'sem turma informada' ?></small></div></div>
                <div class="rv-ret-st">
                    <?php if ($st === 'pend'): ?><span class="rv-chip pend"><i class="bi bi-hourglass-split"></i>Aguardando retirada</span>
                    <?php else: ?><span class="rv-chip <?= $st ?>"><i class="bi bi-<?= $st === 'dev' ? 'check2-circle' : 'box-arrow-up-right' ?>"></i><?= $st === 'dev' ? 'Devolvido às ' . date('H:i', strtotime($r['devolvida_em'])) : 'Retirado às ' . date('H:i', strtotime($r['retirada_em'])) ?></span>
                        <small>por <?= e($r['retirada_nome'] ?? '—') ?> · <?= ['professor' => 'o(a) próprio(a) professor(a)', 'aluno' => 'aluno(a)', 'outro' => 'outra pessoa'][$r['retirada_tipo']] ?? '' ?><?= date('Y-m-d', strtotime($r['retirada_em'])) !== $dia ? ' · ' . date('d/m', strtotime($r['retirada_em'])) : '' ?><?= $r['retirada_obs'] ? ' · ' . e($r['retirada_obs']) : '' ?></small>
                    <?php endif; ?>
                </div>
                <div class="rv-ret-bt">
                    <?php if ($st === 'pend'): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRetirada" data-ids="<?= $ids ?>" data-item="<?= e($r['recurso']) ?>" data-prof="<?= e((string)($r['professor'] ?? '')) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Registrar retirada</button>
                    <?php elseif ($st === 'ret'): ?>
                        <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="devolucao"><input type="hidden" name="ids" value="<?= $ids ?>"><input type="hidden" name="dia" value="<?= $dia ?>">
                            <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-box-arrow-in-down-left me-1"></i>Devolvido</button></form>
                    <?php endif; ?>
                    <?php if ($st !== 'pend'): ?>
                        <form method="post" data-confirmar="Desfazer o registro de retirada de <?= e($r['recurso']) ?>?" data-botao="Desfazer"><?= csrf_campo() ?><input type="hidden" name="acao" value="retirada_desfazer"><input type="hidden" name="ids" value="<?= $ids ?>"><input type="hidden" name="dia" value="<?= $dia ?>">
                            <button class="btn btn-sm btn-light" type="submit" title="Desfazer" aria-label="Desfazer"><i class="bi bi-arrow-counterclockwise"></i></button></form>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><div class="rv-vazio"><i class="bi bi-box-seam"></i><b>Nenhum equipamento agendado neste dia</b><small>Quando um professor agendar projetor, som ou notebook, ele aparece aqui para registrar a retirada.</small></div><?php endif; ?>
    </section>

    <?php if ($ambientes): ?>
    <section class="card mb-3">
        <div class="card-header"><h2><i class="bi bi-door-open me-1"></i>Ambientes agendados</h2><span class="etiqueta cinza"><?= count($ambientes) ?></span></div>
        <ul class="rv-amb"><?php foreach ($ambientes as $r): ?>
            <li><span class="rv-amb-aula"><b><?= $r['aula'] ?>ª</b><?= aulas()[(int)$r['aula']][0] ?? '' ?></span>
                <div class="rv-ret-item"><b><?= e($r['recurso']) ?></b><small><?= e(trim(($r['metodologia'] ?? '') . ($r['metodologia'] && $r['descricao'] ? ' · ' : '') . ($r['descricao'] ?? ''))) ?: '—' ?></small></div>
                <div class="rv-ret-prof"><span class="rv-av"><?= e(iniciais((string)($r['professor'] ?? '?'))) ?></span><div><b><?= e(primeiro_ultimo((string)($r['professor'] ?? '—'))) ?></b><small><?= $r['serie'] ? e($r['serie'] . 'º ' . $r['letra'] . ' ' . $r['sigla']) : 'sem turma informada' ?></small></div></div>
                <?php if ((int)$r['usuario_id'] === uid() || pode('reservas.gerenciar')): ?>
                <form method="post" data-confirmar="Cancelar a reserva de <?= e($r['recurso']) ?> na <?= $r['aula'] ?>ª aula?" data-botao="Cancelar reserva"><?= csrf_campo() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="volta" value="agenda">
                    <button class="btn btn-sm btn-light" type="submit" aria-label="Cancelar"><i class="bi bi-x-lg"></i></button></form>
                <?php endif; ?></li>
        <?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <section class="card"><div class="card-header"><h2><i class="bi bi-grid-3x3 me-1"></i>Quadro do dia</h2><span class="small text-secondary">toque num + para agendar</span></div>
    <div class="table-responsive"><table class="table grade-reservas mb-0">
        <thead><tr><th>Ambiente / equipamento</th><?php foreach ($aulas as $n => [$i]): ?><th class="text-center"><?= $n ?>ª<small><?= $i ?></small></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php $grupo = null; foreach ($recs as $rc): if ($rc['grupo'] !== $grupo): $grupo = $rc['grupo']; ?><tr class="grupo"><td colspan="<?= count($aulas) + 1 ?>"><?= e($grupo ?: ($rc['tipo'] === 'ambiente' ? 'Ambientes' : 'Equipamentos')) ?></td></tr><?php endif; ?>
            <tr><td class="text-nowrap"><i class="bi bi-<?= $rc['tipo'] === 'ambiente' ? 'door-open' : 'projector' ?> me-1 text-secondary"></i><strong><?= e($rc['nome']) ?></strong></td>
            <?php foreach ($aulas as $n => $_): $r = $mapa[(int)$rc['id']][$n] ?? null; ?>
                <?php if ($r): ?><td class="ocupado<?= (int)$r['usuario_id'] === uid() ? ' meu' : '' ?><?= $r['devolvida_em'] ? ' dev' : ($r['retirada_em'] ? ' ret' : '') ?>" title="<?= e(($r['professor'] ?? '') . ($r['metodologia'] ? ' · ' . $r['metodologia'] : '') . ($r['descricao'] ? ' · ' . $r['descricao'] : '')) ?>">
                    <?= e(primeiro_ultimo((string)($r['professor'] ?? '—'))) ?><?php if ($r['serie']): ?><small><?= e($r['serie'] . 'º ' . $r['letra']) ?></small><?php endif; ?></td>
                <?php else: ?><td class="livre"><?php if ($dia >= $hoje): ?><a href="?aba=agendar&data=<?= $dia ?>&aula=<?= $n ?>&recurso=<?= $rc['id'] ?>" aria-label="Reservar <?= e($rc['nome']) ?> na <?= $n ?>ª aula">+</a><?php endif; ?></td><?php endif; ?>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></section>

    <?= janela_abre('modalRetirada', 'Registrar retirada', 'box-arrow-up-right', false, '') ?>
        <form method="post" id="formRetirada">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="retirada"><input type="hidden" name="ids" id="retIds"><input type="hidden" name="dia" value="<?= $dia ?>">
            <div class="rv-ret-cab"><i class="bi bi-projector"></i><div><b id="retItem"></b><small>agendado por <span id="retProf"></span></small></div></div>
            <label class="form-label mt-3">Quem retirou?</label>
            <div class="rv-quem-op">
                <label><input type="radio" name="tipo" value="professor" checked><span><i class="bi bi-person-badge"></i>O(a) professor(a)</span></label>
                <label><input type="radio" name="tipo" value="aluno"><span><i class="bi bi-mortarboard"></i>Um(a) aluno(a)</span></label>
                <label><input type="radio" name="tipo" value="outro"><span><i class="bi bi-person"></i>Outra pessoa</span></label>
            </div>
            <div class="mt-3" id="retNomeBox" hidden><label class="form-label obrigatorio" for="retNome">Nome de quem retirou</label><input class="form-control" id="retNome" name="nome" maxlength="120" placeholder="Ex.: João Pedro (2º B)"></div>
            <div class="row g-2 mt-1">
                <div class="col-sm-6"><label class="form-label" for="retQuando">Quando</label><input class="form-control" type="datetime-local" id="retQuando" name="quando" value="<?= date('Y-m-d\TH:i') ?>"></div>
                <div class="col-sm-6"><label class="form-label" for="retObs">Observação</label><input class="form-control" id="retObs" name="obs" maxlength="255" placeholder="Opcional (ex.: com cabo HDMI)"></div>
            </div>
            <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-check2 me-1"></i>Confirmar retirada</button>
        </form>
    <?= janela_fecha() ?>

<?php elseif ($aba === 'minhas'):
    $minhas = q_todos('SELECT r.*, rc.nome AS recurso, rc.tipo AS recurso_tipo, t.serie, t.letra, c.sigla FROM reservas r JOIN recursos rc ON rc.id = r.recurso_id
                        LEFT JOIN turmas t ON t.id = r.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                       WHERE r.usuario_id = ? ORDER BY r.data >= ? DESC, CASE WHEN r.data >= ? THEN r.data END ASC, r.data DESC, r.aula LIMIT 200', [uid(), $hoje, $hoje]); ?>
    <div class="card">
        <div class="card-header"><h2>Meus agendamentos</h2><a class="btn btn-sm btn-primary ms-auto" href="?aba=agendar"><i class="bi bi-plus-lg me-1"></i>Novo agendamento</a></div>
        <?php if ($minhas): ?>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Data</th><th>Aula</th><th>Ambiente / equipamento</th><th>Detalhes</th><th>Retirada</th><th></th></tr></thead>
            <tbody><?php foreach ($minhas as $r): $passou = $r['data'] < $hoje; ?>
                <tr class="<?= $passou ? 'text-secondary' : '' ?>">
                    <td class="text-nowrap"><strong><?= data_br($r['data']) ?></strong><br><small><?= DIAS_SEMANA[(int)date('w', strtotime($r['data'])) + 1] ?><?= $r['data'] === $hoje ? ' · <b class="text-success">hoje</b>' : '' ?></small></td>
                    <td class="text-nowrap"><?= e(aula_rotulo((int)$r['aula'])) ?></td>
                    <td><?= e($r['recurso']) ?></td>
                    <td class="small"><?= e(trim(($r['metodologia'] ?? '') . ($r['serie'] ? ' · ' . $r['serie'] . 'º ' . $r['letra'] : '') . ($r['descricao'] ? ' · ' . $r['descricao'] : ''))) ?: '—' ?></td>
                    <td class="small text-nowrap"><?php if ($r['recurso_tipo'] === 'ambiente'): ?><span class="text-secondary">—</span><?php elseif ($r['devolvida_em']): ?><span class="rv-chip dev"><i class="bi bi-check2-circle"></i>Devolvido</span><?php elseif ($r['retirada_em']): ?><span class="rv-chip ret"><i class="bi bi-box-arrow-up-right"></i>Retirado <?= date('H:i', strtotime($r['retirada_em'])) ?></span><?php else: ?><span class="rv-chip pend"><i class="bi bi-hourglass-split"></i>Retirar no Multimeios</span><?php endif; ?></td>
                    <td class="td-acoes"><?php if (!$passou && !$r['retirada_em']): ?><form method="post" data-confirmar="Cancelar esta reserva?" data-botao="Cancelar reserva"><?= csrf_campo() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-light" type="submit">Cancelar</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php else: ?><div class="vazio"><i class="bi bi-calendar-x"></i><strong>Você ainda não fez agendamentos</strong>Use a aba Agendar.</div><?php endif; ?>
    </div>

<?php else: $todos = recursos_lista(false); $ed = isset($_GET['editar']) ? q_um('SELECT * FROM recursos WHERE id = ?', [(int)$_GET['editar']]) : null; ?>
    <div class="row g-3">
        <div class="col-xl-7"><div class="card"><div class="card-header"><h2>Ambientes e equipamentos</h2><span class="etiqueta cinza"><?= count($todos) ?></span></div>
            <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Nome</th><th>Tipo</th><th>Grupo</th><th></th></tr></thead><tbody>
            <?php foreach ($todos as $r): ?><tr class="<?= $r['ativo'] ? '' : 'text-secondary' ?>"><td><strong><?= e($r['nome']) ?></strong><?= $r['descricao'] ? '<br><small class="text-secondary">' . e($r['descricao']) . '</small>' : '' ?></td>
                <td><span class="etiqueta <?= $r['tipo'] === 'ambiente' ? '' : 'azul' ?>"><?= $r['tipo'] === 'ambiente' ? 'Ambiente' : 'Equipamento' ?></span><?= $r['ativo'] ? '' : ' <span class="etiqueta cinza">inativo</span>' ?></td>
                <td class="small"><?= e($r['grupo'] ?? '') ?></td><td class="td-acoes"><a class="btn btn-sm btn-light" href="?aba=recursos&editar=<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        <div class="col-xl-5"><form method="post" class="card">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="recurso_salvar"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
            <div class="card-header"><h2><?= $ed ? 'Editar' : 'Cadastrar' ?></h2><?php if ($ed): ?><a class="ms-auto small" href="?aba=recursos">Novo</a><?php endif; ?></div>
            <div class="card-body row g-3">
                <div class="col-12"><label class="form-label obrigatorio" for="rc_n">Nome</label><input class="form-control" id="rc_n" name="nome" value="<?= e($ed['nome'] ?? '') ?>" maxlength="80" required placeholder="Ex.: Projetor, Sala de vídeo"></div>
                <div class="col-6"><label class="form-label" for="rc_t">Tipo</label><select class="form-select" id="rc_t" name="tipo"><option value="equipamento">Equipamento</option><option value="ambiente"<?= ($ed['tipo'] ?? '') === 'ambiente' ? ' selected' : '' ?>>Ambiente</option></select></div>
                <div class="col-6"><label class="form-label" for="rc_g">Grupo</label><input class="form-control" id="rc_g" name="grupo" value="<?= e($ed['grupo'] ?? '') ?>" maxlength="40" placeholder="Ex.: Projetores"></div>
                <div class="col-12"><label class="form-label" for="rc_d">Descrição</label><input class="form-control" id="rc_d" name="descricao" value="<?= e($ed['descricao'] ?? '') ?>" maxlength="200"></div>
                <?php if ($ed): ?><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="rc_a"<?= $ed['ativo'] ? ' checked' : '' ?>><label class="form-check-label" for="rc_a">Disponível para reserva</label></div></div>
                <?php else: ?><div class="col-6"><label class="form-label" for="rc_q">Quantidade</label><input class="form-control" type="number" id="rc_q" name="quantidade" value="1" min="1" max="30"><div class="form-text">Com 3, cria “Nome 1”, “Nome 2”, “Nome 3”.</div></div><?php endif; ?>
            </div>
            <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit">Salvar</button></div>
        </form></div>
    </div>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  /* janela de retirada (aba Agenda) */
  const mr = document.getElementById("modalRetirada");
  if (mr) {
    mr.addEventListener("show.bs.modal", e => { const b = e.relatedTarget; if (!b) return;
      retIds.value = b.dataset.ids; retItem.textContent = b.dataset.item; retProf.textContent = b.dataset.prof || "—"; retNome.value = ""; retObs.value = "";
      mr.querySelector("[name=tipo][value=professor]").checked = true; retNomeBox.hidden = true; retNome.required = false; });
    mr.querySelectorAll("[name=tipo]").forEach(r => r.addEventListener("change", () => { const p = r.value === "professor"; retNomeBox.hidden = p; retNome.required = !p; if (!p) retNome.focus(); }));
  }

  const f = document.getElementById("formReserva"); if (!f) return;
  const marcados = ' . json_encode($pre_rec) . ';
  const lista = document.getElementById("listaRecursos"), resumo = document.getElementById("resumoDisp"), bt = document.getElementById("btReservar"), amb = document.getElementById("boxAmbiente");
  const DIAS = ["domingo", "segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado"];
  const aulasSel = () => [...f.querySelectorAll("input[name=\'aulas[]\']:checked")].map(i => i.value);
  const vazio = (ic, t, s) => `<div class="rv-vazio"><i class="bi bi-${ic}"></i><b>${t}</b><small>${s}</small></div>`;
  const atualizarAmbiente = () => {
    const sel = [...f.querySelectorAll("input[name=\'recursos[]\']:checked")];
    const temAmb = sel.some(i => i.dataset.tipo === "ambiente");
    amb.hidden = !temAmb; r_met.required = temAmb; r_desc.required = temAmb; bt.disabled = !sel.length;
    const a = aulasSel();
    rvSel.innerHTML = sel.length ? `<b>${sel.length}</b> item(ns) · <b>${a.map(x => x + "ª").join(", ")}</b> aula · ${new Date(r_data.value + "T12:00").toLocaleDateString("pt-BR")}` : "Nada escolhido ainda";
  };
  const diaTexto = () => { rvDia.textContent = r_data.value ? DIAS[new Date(r_data.value + "T12:00").getDay()] : ""; };
  async function carregar() {
    diaTexto();
    const a = aulasSel(), d = r_data.value;
    if (!d || !a.length) { lista.innerHTML = vazio("hand-index", "Escolha a data e pelo menos uma aula", "Aparecem aqui só os ambientes e equipamentos livres nesse horário."); resumo.textContent = "escolha a data e a aula"; atualizarAmbiente(); return; }
    lista.innerHTML = "<div class=\\"rv-vazio\\"><span class=\\"spinner-border spinner-border-sm\\"></span><b>Verificando o que está livre…</b></div>";
    const j = await (await fetch("api.php?acao=disponiveis&data=" + d + "&aulas=" + a.join(","))).json();
    const livres = j.recursos.filter(r => r.livre), ocup = j.recursos.filter(r => !r.livre);
    const grupos = {}; livres.forEach(r => (grupos[r.grupo || (r.tipo === "ambiente" ? "Ambientes" : "Equipamentos")] ||= []).push(r));
    let h = "";
    for (const [g, rs] of Object.entries(grupos)) {
      h += `<div class="grupo-recursos"><h3>${esc(g)} <small>${rs.length} livre(s)</small></h3><div class="recursos-op">` +
        rs.map(r => `<label class="rec-op ${r.tipo}"><input type="checkbox" name="recursos[]" value="${r.id}" data-tipo="${r.tipo}" ${marcados.includes(r.id) ? "checked" : ""}>
          <i class="bi bi-${r.tipo === "ambiente" ? "door-open" : "projector"}"></i><span>${esc(r.nome)}</span></label>`).join("") + "</div></div>";
    }
    if (!livres.length) h = vazio("calendar-x", "Nada livre nesse horário", "Tente outra aula ou outro dia.");
    if (ocup.length) {
      /* já reservados: um cartão por item e professor, com as aulas juntas */
      const cards = [];
      ocup.forEach(r => { const pg = {}; r.ocupado.forEach(o => { const k = o.professor + "|" + (o.turma || "") + "|" + (o.metodologia || ""); (pg[k] ||= { o, aulas: [] }).aulas.push(o); });
        Object.values(pg).forEach(x => cards.push({ r, ...x })); });
      cards.sort((x, y) => x.aulas[0].aula - y.aulas[0].aula);
      h += `<details class="rv-ocup"><summary><i class="bi bi-lock"></i><b>${ocup.length}</b> já reservado(s) nesse horário<span>ver quem reservou</span></summary><div class="rv-ocup-lista">` +
        cards.map(c => `<div class="rv-oc">
          <div class="rv-oc-item"><i class="bi bi-${c.r.tipo === "ambiente" ? "door-open" : "projector"}"></i><div><b>${esc(c.r.nome)}</b><small>${esc(c.r.grupo || (c.r.tipo === "ambiente" ? "Ambiente" : "Equipamento"))}</small></div></div>
          <div class="rv-oc-aulas">${c.aulas.map(x => `<span>${x.aula}ª <small>${esc(x.hora)}</small></span>`).join("")}</div>
          <div class="rv-oc-prof"><span class="rv-av">${esc(c.o.iniciais)}</span><div><b>${esc(c.o.professor)}</b><small>${esc(c.o.cargo || "Professor(a)")}</small></div></div>
          <div class="rv-oc-info">${c.o.turma ? `<span class="rv-tag">${esc(c.o.turma)}</span>` : ""}${c.o.metodologia ? `<span class="rv-tag laranja">${esc(c.o.metodologia)}</span>` : ""}${!c.o.turma && !c.o.metodologia ? "<span class=\\"text-secondary small\\">sem detalhes</span>" : ""}</div>
        </div>`).join("") + "</div></details>";
    }
    lista.innerHTML = h;
    resumo.textContent = livres.length + " disponível(is) em " + new Date(d + "T12:00").toLocaleDateString("pt-BR") + " · " + a.map(x => x + "ª").join(", ") + " aula";
    lista.querySelectorAll("input").forEach(i => i.addEventListener("change", atualizarAmbiente));
    atualizarAmbiente();
  }
  r_data.addEventListener("change", carregar);
  f.querySelectorAll("input[name=\'aulas[]\']").forEach(i => i.addEventListener("change", carregar));
  carregar();
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
