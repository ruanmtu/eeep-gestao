<?php
require __DIR__ . '/../includes/app.php';
exigir('saidas');
$autoriza = pode('saidas.autorizar');      // coordenação/direção: confirma e imprime; os demais só enviam o pedido

/* ---------- Nova autorização ---------- */
if (eh_post() && ($_POST['acao'] ?? '') === 'emitir') {
    csrf_verificar();
    $tipo = isset(TIPOS_AUTORIZACAO[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'saida';
    $aluno = aluno_buscar((int)($_POST['aluno_id'] ?? 0));
    $erro = null;
    $resp = trim((string)($_POST['responsavel_nome'] ?? ''));
    $par = trim((string)($_POST['responsavel_parentesco'] ?? ''));
    $titulo = $item = $local = $horario = $validade = null;
    if (!$aluno) $erro = 'Escolha o aluno.';
    elseif ($tipo === 'saida' && !pode_emitir_saida($aluno)) $erro = 'A liberação de saída de ' . nome_proprio($aluno['nome']) . ' só pode ser emitida pelo(a) diretor(a) da turma (' . turma_nome($aluno) . ') ou pelo(a) coordenador(a) do curso.';
    elseif ($tipo === 'saida') {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        $detalhe = trim((string)($_POST['detalhe'] ?? ''));
        $dh = str_replace('T', ' ', (string)($_POST['data_hora'] ?? ''));
        if (!in_array($motivo, MOTIVOS_SAIDA, true)) $erro = 'Escolha o motivo da saída.';
        elseif ($motivo === 'Outro' && mb_strlen($detalhe) < 3) $erro = 'Descreva o motivo da saída.';
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dh) || !data_valida(substr($dh, 0, 10))) $erro = 'Informe a data e a hora da saída.';
        elseif (mb_strlen($resp) < 3) $erro = 'Informe o nome do responsável que autorizou a saída.';
        $texto = $motivo . ($detalhe ? ' · ' . $detalhe : '');
    } else {
        $dia = (string)($_POST['data'] ?? '');
        $dh = $dia . ' ' . date('H:i');
        $ate = (string)($_POST['validade_fim'] ?? '');
        if (!data_valida($dia)) $erro = 'Informe a data da autorização.';
        elseif ($ate !== '' && (!data_valida($ate) || $ate < $dia)) $erro = 'A data final da validade precisa ser depois do início.';
        $validade = $ate !== '' ? $ate : null;
        $local = trim((string)($_POST['local'] ?? '')) ?: null;
        $horario = trim((string)($_POST['horario'] ?? '')) ?: null;
        if ($tipo === 'tablet') {
            $item = trim((string)($_POST['item'] ?? ''));
            $fin = trim((string)($_POST['finalidade'] ?? ''));
            if (!$erro && !in_array($fin, FINALIDADES_TABLET, true)) $erro = 'Escolha a finalidade de estudo.';
            $texto = $fin . (trim((string)($_POST['detalhe_tablet'] ?? '')) !== '' ? ' · ' . trim($_POST['detalhe_tablet']) : '');
            /* o aluno já tem uma autorização de tablet no mesmo período */
            if (!$erro) {
                $fim_novo = $ate !== '' ? $ate : $dia;
                foreach (autorizacoes_lista("s.tipo = 'tablet' AND s.aluno_id = ?", [$aluno['id']], 80) as $o) {
                    $ini_o = substr($o['data_hora'], 0, 10); $fim_o = $o['validade_fim'] ?: $ini_o;
                    if ($ini_o <= $fim_novo && $fim_o >= $dia) { $erro = nome_proprio($aluno['nome']) . ' já tem autorização de tablet ' . mb_strtolower(vigencia_texto($o)) . ' (código ' . implode('-', str_split($o['codigo'], 4)) . '). Se perdeu o cupom, reimprima pela lista.'; break; }
                }
            }
        } else {
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $texto = trim((string)($_POST['descricao'] ?? ''));
            if (!$erro && mb_strlen($titulo) < 4) $erro = 'Diga para onde o aluno vai (ex.: Laboratório de Informática).';
            if (!$erro && mb_strlen($texto) < 3) $texto = $titulo;
            $local = $local ?: ($titulo ? mb_substr($titulo, 0, 60) : null);
        }
    }
    if ($erro) {
        $_SESSION['saida_erro'] = $erro;
        $_SESSION['saida_form'] = $_POST;
        redirecionar('saidas.php?novo=' . $tipo);
    }
    $u = usuario();
    /* v74: saída (DT ou coordenador do curso) e autorização interna (qualquer professor) já saem autorizadas;
       o tablet continua indo para a coordenação quando quem pede não confirma autorizações */
    $autoriza = $tipo !== 'tablet' || $autoriza;
    $codigo = strtoupper(bin2hex(random_bytes(6)));
    $agora = date('Y-m-d H:i:s');
    q('INSERT INTO saidas (aluno_id, data_hora, motivo, responsavel_nome, responsavel_parentesco, autorizado_por, autorizado_nome, autorizado_cargo, codigo, tipo, titulo, item, local, horario, validade_fim, criado_em, status, solicitado_por, solicitado_nome, solicitado_em)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$aluno['id'], $dh . ':00', mb_substr($texto, 0, 255), $resp ?: null, $par ?: null, $autoriza ? uid() : null, $autoriza ? $u['nome'] : '', $autoriza ? cargo_usuario($u) : '', $codigo, $tipo,
       $titulo ? mb_substr($titulo, 0, 80) : null, $item ? mb_substr($item, 0, 60) : null, $local ? mb_substr($local, 0, 60) : null, $horario ? mb_substr($horario, 0, 60) : null, $validade, $agora,
       $autoriza ? 'autorizada' : 'pendente', $autoriza ? null : uid(), $autoriza ? null : $u['nome'], $autoriza ? null : $agora]);
    $id = (int)db()->lastInsertId();
    if (!$autoriza) {
        /* professor em sala: o pedido vai para o painel da coordenação, que confirma e imprime */
        $tn = TIPOS_AUTORIZACAO[$tipo][0];
        notificar(['usuarios' => usuarios_com_permissao('saidas.autorizar')],
            ($tipo === 'saida' ? 'Liberação de saída' : $tn) . ': ' . nome_proprio($aluno['nome']) . ' (' . $aluno['serie'] . 'º ' . $aluno['letra'] . ')',
            primeiro_ultimo($u['nome']) . ' pede: ' . $texto . ($resp ? ' · responsável: ' . $resp : ''), 'autorizacao', 'saidas.php?ver=' . $id . '#pendentes', true);
        registrar_log('autorizacao_pedida', $tn . ': ' . $texto, (int)$aluno['id']);
        flash('success', 'Pedido enviado à coordenação. Você será avisado quando for autorizado.');
        redirecionar('saidas.php?ver=' . $id . '#meus');
    }
    registrar_log('autorizacao', TIPOS_AUTORIZACAO[$tipo][0] . ': ' . $texto, (int)$aluno['id']);
    if ($tipo === 'outra') {
        /* autorização interna: todos os professores ficam sabendo, sem passar pela coordenação */
        $profs = array_map('intval', array_column(q_todos("SELECT DISTINCT u.id FROM usuarios u LEFT JOIN atribuicoes at ON at.usuario_id = u.id
                                                           WHERE u.ativo = 1 AND (u.perfil = 'professor' OR at.id IS NOT NULL)"), 'id'));
        $profs = array_values(array_diff($profs, [uid()]));
        if ($profs) notificar(['usuarios' => $profs],
            'Autorização interna: ' . nome_proprio($aluno['nome']) . ' (' . turma_nome($aluno) . ')',
            nome_proprio($aluno['nome']) . ' está autorizado(a) a sair da sala para ' . $titulo . ($horario ? ' · ' . $horario : '') . '. Autorizado por ' . primeiro_ultimo($u['nome']) . '.',
            'autorizacao', 'saidas.php?ver=' . $id);
        flash('success', 'Autorização interna registrada. Todos os professores foram avisados no painel.');
        redirecionar('saidas.php?ver=' . $id);
    }
    redirecionar('saida_cupom.php?id=' . $id . '&imprimir=1');
}

/* ---------- Coordenação: confirmar ou recusar um pedido ---------- */
if (eh_post() && in_array($_POST['acao'] ?? '', ['confirmar', 'recusar'], true) && $autoriza) {
    csrf_verificar();
    $s = q_um("SELECT s.*, a.nome FROM saidas s JOIN alunos a ON a.id = s.aluno_id WHERE s.id = ? AND s.status = 'pendente'", [(int)$_POST['id']]);
    if (!$s) { flash('warning', 'Este pedido já foi respondido por outra pessoa.'); redirecionar('saidas.php#pendentes'); }
    $u = usuario();
    if ($_POST['acao'] === 'confirmar') {
        q("UPDATE saidas SET status = 'autorizada', autorizado_por = ?, autorizado_nome = ?, autorizado_cargo = ?, criado_em = ? WHERE id = ?", [uid(), $u['nome'], cargo_usuario($u), date('Y-m-d H:i:s'), $s['id']]);
        if ($s['solicitado_por']) notificar(['usuarios' => [(int)$s['solicitado_por']]], 'Liberação autorizada: ' . nome_proprio($s['nome']),
            primeiro_ultimo($u['nome']) . ' autorizou e imprimiu o cupom (' . autorizacao_nome($s) . ').', 'resposta', 'saidas.php?ver=' . $s['id'] . '#meus');
        registrar_log('autorizacao', 'Pedido confirmado: ' . $s['motivo'], (int)$s['aluno_id']);
        redirecionar('saida_cupom.php?id=' . $s['id'] . '&imprimir=1');
    }
    $motivo = trim((string)($_POST['recusa_motivo'] ?? ''));
    q("UPDATE saidas SET status = 'recusada', recusa_motivo = ?, autorizado_por = ?, autorizado_nome = ?, autorizado_cargo = ? WHERE id = ?", [$motivo ?: null, uid(), $u['nome'], cargo_usuario($u), $s['id']]);
    if ($s['solicitado_por']) notificar(['usuarios' => [(int)$s['solicitado_por']]], 'Liberação recusada: ' . nome_proprio($s['nome']),
        primeiro_ultimo($u['nome']) . ' não autorizou' . ($motivo ? ': ' . $motivo : '.'), 'resposta', 'saidas.php?ver=' . $s['id'] . '#meus', true);
    registrar_log('autorizacao_recusada', $motivo, (int)$s['aluno_id']);
    flash('success', 'Pedido recusado. O professor foi avisado.');
    redirecionar('saidas.php#pendentes');
}

/* ---------- Exclusão (gestão) ---------- */
if (eh_post() && ($_POST['acao'] ?? '') === 'excluir' && pode('saidas.excluir')) {
    csrf_verificar();
    $s = q_um('SELECT * FROM saidas WHERE id = ?', [(int)$_POST['id']]);
    if ($s) {
        q('DELETE FROM saidas WHERE id = ?', [$s['id']]);
        registrar_log('autorizacao_excluida', 'Código ' . $s['codigo'], (int)$s['aluno_id']);
        flash('success', 'Autorização cancelada. O cupom impresso deixa de ser válido.');
    }
    redirecionar('saidas.php');
}

/* ---------- Consulta ---------- */
[$seg, $sex] = semana_param();
$f_tipo = isset(TIPOS_AUTORIZACAO[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$f_aluno = (int)($_GET['aluno'] ?? 0);
$f_busca = trim((string)($_GET['q'] ?? ''));
$ver = (int)($_GET['ver'] ?? 0);
$busca_codigo = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)($_GET['codigo'] ?? '')));
$aluno_f = $f_aluno ? aluno_buscar($f_aluno) : null;
if ($busca_codigo) {
    $base = autorizacoes_lista('s.codigo = ?', [$busca_codigo]);
} elseif ($aluno_f) {
    $base = autorizacoes_lista('s.aluno_id = ?', [$f_aluno], 500);
} elseif ($f_busca !== '') {
    $base = autorizacoes_lista('a.nome LIKE ?', ['%' . $f_busca . '%'], 300);
} else {
    $base = autorizacoes_lista('s.data_hora BETWEEN ? AND ?', ["$seg 00:00:00", "$sex 23:59:59"]);
}
$cont_tipos = ['saida' => 0, 'tablet' => 0, 'outra' => 0];
foreach ($base as $s) $cont_tipos[$s['tipo'] ?? 'saida']++;
$lista = $f_tipo ? array_values(array_filter($base, fn($s) => ($s['tipo'] ?? 'saida') === $f_tipo)) : $base;
$tablets_hoje = array_values(array_filter(autorizacoes_lista("s.tipo = 'tablet' AND s.data_hora <= ?", [date('Y-m-d 23:59:59')], 500), fn($s) => autorizacao_vigente($s)));
usort($tablets_hoje, fn($a, $b) => strcmp(nome_proprio($a['nome']), nome_proprio($b['nome'])));
$fim_ano = ($b = bimestres()) ? end($b)[1] : '';
$saidas_hoje = count(autorizacoes_lista("s.tipo = 'saida' AND s.data_hora BETWEEN ? AND ?", [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')], 500));
$pendentes = $autoriza ? autorizacoes_lista("s.status = 'pendente'", [], 100) : [];
$meus = !$autoriza ? autorizacoes_lista("s.solicitado_por = ? AND s.status <> ''", [uid()], 30) : [];
$form = $_SESSION['saida_form'] ?? [];
$erro_form = $_SESSION['saida_erro'] ?? '';
unset($_SESSION['saida_form'], $_SESSION['saida_erro']);
$abrir = isset(TIPOS_AUTORIZACAO[$_GET['novo'] ?? '']) ? $_GET['novo'] : '';
$aluno_pre = (int)($form['aluno_id'] ?? ($abrir && $aluno_f ? $aluno_f['id'] : 0));
$aulas = aulas();
$horario_almoco = 'Horário do almoço (' . ($aulas[5][1] ?? '11:50') . ' às ' . ($aulas[6][0] ?? '13:20') . ')';
$fim_periodo = periodo_atual()['bimestre'] ? periodo_atual()['fim'] : '';
$qs = fn(array $m) => http_build_query(array_filter(array_merge(['semana' => $seg, 'tipo' => $f_tipo, 'aluno' => $f_aluno ?: null, 'q' => $f_busca ?: null], $m), fn($v) => $v !== null && $v !== ''));

$pode_saida = pode_emitir_saida();
$titulo = 'Autorizações';
$subtitulo = 'Saída da escola, porte de tablet pessoal para estudo e outras autorizações, com cupom assinado digitalmente.';
$menu = 'saidas';
require __DIR__ . '/../includes/layout_topo.php';

/** Campo de busca do aluno (igual nos três formulários) */
function campo_aluno(string $f): string
{
    return '<input type="hidden" name="aluno_id" class="aut-aluno-id">
        <div class="aut-busca coleta-busca"><i class="bi bi-search"></i>
            <input type="search" class="form-control aut-busca-input" id="' . $f . '_busca" placeholder="Digite o nome do aluno" role="combobox" aria-expanded="false" aria-label="Aluno">
            <div class="busca-resultados aut-busca-lista" hidden></div></div>
        <div class="aut-aluno-card" hidden></div>';
}
/** Pílulas de escolha única */
function pilulas(string $nome, array $opcoes, ?string $atual, bool $obrig = false): string
{
    $h = '<div class="pilulas">';
    foreach ($opcoes as $o) $h .= '<label class="pilula"><input type="radio" name="' . $nome . '" value="' . e($o) . '"' . ($atual === $o ? ' checked' : '') . ($obrig ? ' required' : '') . '><span>' . e($o) . '</span></label>';
    return $h . '</div>';
}
$assina = $autoriza
    ? '<div class="aut-assina"><span class="aut-selo"><i class="bi bi-patch-check-fill"></i></span><div><small>Será assinada digitalmente por</small><strong>' . e(usuario()['nome']) . '</strong><span>' . e(cargo_usuario()) . '</span></div></div>'
    : '<div class="aut-assina"><span class="aut-selo"><i class="bi bi-send-fill"></i></span><div><small>O pedido vai para a coordenação</small><strong>' . e(usuario()['nome']) . '</strong><span>a coordenação confirma e imprime o cupom</span></div></div>';
?>

<!-- Começar uma autorização -->
<section class="aut-topo mb-4">
    <div class="aut-novos">
        <?php foreach ([
            'saida'  => ['Saída da escola', 'O responsável autorizou o aluno a sair antes do horário.', $saidas_hoje . ' hoje'],
            'tablet' => ['Porte de tablet', 'O aluno traz o próprio tablet para estudar no horário do almoço.', count($tablets_hoje) . ' autorizado(s)'],
            'outra'  => ['Autorização interna', 'O aluno sai da sala para o laboratório, biblioteca ou outro setor. Todos os professores são avisados.', ''],
        ] as $k => [$rot, $desc, $info]): [, $ic] = TIPOS_AUTORIZACAO[$k];
            if ($k === 'saida' && !$pode_saida): ?>
            <div class="aut-novo aut-saida aut-bloqueado" title="Só o(a) diretor(a) de turma ou o(a) coordenador(a) do curso">
                <span class="an-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                <span class="an-txt"><strong><?= e($rot) ?></strong><small>Emitida pelo(a) diretor(a) de turma ou pelo(a) coordenador(a) do curso do aluno.</small></span>
                <span class="an-rodape"><span class="an-info"><?= e($info) ?></span><span class="an-mais"><i class="bi bi-lock"></i> Restrita</span></span>
            </div>
            <?php continue; endif; ?>
            <button type="button" class="aut-novo aut-<?= $k ?>" data-bs-toggle="modal" data-bs-target="#modal_<?= $k ?>">
                <span class="an-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                <span class="an-txt"><strong><?= e($rot) ?></strong><small><?= e($desc) ?></small></span>
                <span class="an-rodape"><?= $info ? '<span class="an-info">' . e($info) . '</span>' : '<span></span>' ?><span class="an-mais"><i class="bi bi-plus-lg"></i> Nova</span></span>
            </button>
        <?php endforeach; ?>
    </div>
    <form class="aut-consulta" method="get">
        <span class="ac-ic"><i class="bi bi-shield-check"></i></span>
        <strong>Conferir um cupom</strong>
        <small>Digite o código de autenticação impresso no cupom.</small>
        <div class="d-flex gap-2 mt-2">
            <input class="form-control font-monospace text-uppercase" name="codigo" value="<?= e($busca_codigo ? implode('-', str_split($busca_codigo, 4)) : '') ?>" placeholder="XXXX-XXXX-XXXX" aria-label="Código de autenticação" maxlength="14">
            <button class="btn btn-dark" type="submit">Conferir</button>
        </div>
    </form>
</section>

<?php if ($busca_codigo): $a0 = $base[0] ?? null; ?>
    <div class="aut-verificacao <?= $a0 ? 'ok' : 'falha' ?> mb-4">
        <i class="bi bi-<?= $a0 ? 'patch-check-fill' : 'x-octagon-fill' ?>"></i>
        <div class="flex-grow-1">
            <?php if ($a0): ?><strong>Cupom autêntico</strong><span><?= e(autorizacao_nome($a0)) ?> de <?= e(nome_proprio($a0['nome'])) ?> · <?= e(turma_nome($a0)) ?> · emitido por <?= e($a0['autorizado_nome']) ?> em <?= data_hora_br($a0['criado_em']) ?><?= ($a0['tipo'] ?? 'saida') !== 'saida' ? ' · ' . (autorizacao_vigente($a0) ? 'válida hoje' : 'fora da validade') : '' ?></span>
            <?php else: ?><strong>Código não encontrado</strong><span>Nenhuma autorização com o código <?= e(implode('-', str_split($busca_codigo, 4))) ?>. O cupom pode não ser verdadeiro.</span><?php endif; ?>
        </div>
        <?php if ($a0 && $autoriza): ?><a class="btn btn-light" href="saida_cupom.php?id=<?= $a0['id'] ?>" target="_blank"><i class="bi bi-receipt me-1"></i>Ver cupom</a><?php endif; ?>
        <a class="btn btn-light" href="saidas.php">Fechar</a>
    </div>
<?php endif; ?>

<?php if ($autoriza): ?>
<section class="card aut-pendentes mb-4<?= $pendentes ? ' tem' : '' ?>" id="pendentes">
    <div class="card-header"><h2><i class="bi bi-inbox me-1"></i>Pedidos dos professores</h2>
        <span class="etiqueta <?= $pendentes ? 'ambar' : 'cinza' ?>"><?= count($pendentes) ?> aguardando</span>
        <span class="small text-secondary ms-auto d-none d-md-inline">Atualiza sozinho quando chega um pedido novo</span></div>
    <?php if ($pendentes): ?>
    <div class="aut-lista p-3">
        <?php foreach ($pendentes as $s): $t = $s['tipo'] ?? 'saida'; [, $ic] = tipo_autorizacao($s); ?>
        <article class="aut-item aut-pend aut-<?= $t ?><?= $ver === (int)$s['id'] ? ' destaque' : '' ?>" id="aut<?= $s['id'] ?>">
            <div class="ai-tipo"><i class="bi bi-<?= $ic ?>"></i></div>
            <div class="ai-quem">
                <?= avatar(['id' => $s['aluno_id'], 'nome' => $s['nome'], 'foto' => $s['foto']], 'aluno', 'avatar-sm') ?>
                <div class="min-w-0"><a class="nome-link" href="aluno.php?id=<?= $s['aluno_id'] ?>"><?= e(nome_proprio($s['nome'])) ?></a><small><?= e($s['serie'] . 'º ' . $s['letra'] . ' · ' . $s['curso']) ?></small></div>
            </div>
            <div class="ai-oque">
                <strong><?= e(autorizacao_nome($s)) ?></strong>
                <small><?= e($s['motivo']) ?><?= $s['responsavel_nome'] ? ' · resp.: ' . e($s['responsavel_nome']) . ($s['responsavel_parentesco'] ? ' (' . e($s['responsavel_parentesco']) . ')' : '') : '' ?></small>
                <small class="pedido-por"><i class="bi bi-person-raised-hand me-1"></i><?= e(primeiro_ultimo((string)$s['solicitado_nome'])) ?> · <?= e(quando((string)$s['solicitado_em'])) ?><?= $t === 'saida' ? ' · saída às ' . date('H:i', strtotime($s['data_hora'])) : '' ?></small>
            </div>
            <div class="ai-acoes">
                <form method="post" target="_self"><?= csrf_campo() ?><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-imprimir" type="submit"><i class="bi bi-printer"></i><span>Autorizar e imprimir</span></button></form>
                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="modal" data-bs-target="#modalRecusar" data-id="<?= $s['id'] ?>" data-nome="<?= e(nome_proprio($s['nome'])) ?>">Recusar</button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?><div class="vazio py-3"><i class="bi bi-inbox"></i>Nenhum pedido aguardando. Quando um professor pedir uma liberação, ela aparece aqui com um aviso sonoro.</div><?php endif; ?>
</section>
<?php elseif ($meus): ?>
<section class="card mb-4" id="meus">
    <div class="card-header"><h2><i class="bi bi-send me-1"></i>Meus pedidos</h2><span class="small text-secondary">A coordenação confirma e imprime o cupom</span></div>
    <ul class="meus-pedidos">
        <?php foreach ($meus as $s): [$rs, $cs, $is] = STATUS_AUTORIZACAO[$s['status']] ?? STATUS_AUTORIZACAO['autorizada']; ?>
        <li class="<?= $ver === (int)$s['id'] ? 'destaque' : '' ?>" id="aut<?= $s['id'] ?>">
            <?= avatar(['id' => $s['aluno_id'], 'nome' => $s['nome'], 'foto' => $s['foto']], 'aluno', 'avatar-sm') ?>
            <div class="flex-grow-1 min-w-0"><strong><?= e(nome_proprio($s['nome'])) ?></strong> <small class="text-secondary"><?= e($s['serie'] . 'º ' . $s['letra']) ?></small>
                <small class="d-block text-secondary text-truncate"><?= e(autorizacao_nome($s)) ?> · <?= e($s['motivo']) ?></small>
                <?php if ($s['status'] === 'recusada' && $s['recusa_motivo']): ?><small class="d-block text-danger">Motivo: <?= e($s['recusa_motivo']) ?></small><?php endif; ?>
                <?php if ($s['status'] !== 'pendente'): ?><small class="d-block text-secondary"><?= e(primeiro_ultimo($s['autorizado_nome'])) ?><?= $s['status'] === 'autorizada' ? ' · código ' . e(implode('-', str_split($s['codigo'], 4))) : '' ?></small><?php endif; ?>
            </div>
            <div class="text-end"><span class="etiqueta <?= $cs ?>"><i class="bi bi-<?= $is ?> me-1"></i><?= e($rs) ?></span><small class="d-block text-secondary mt-1"><?= e(quando((string)($s['solicitado_em'] ?: $s['criado_em']))) ?></small></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($aluno_f): ?>
            <section class="aut-aluno-filtro mb-3">
                <?= avatar($aluno_f, 'aluno', 'avatar-md') ?>
                <div class="flex-grow-1"><small>Autorizações de</small><strong><?= e(nome_proprio($aluno_f['nome'])) ?></strong><span><?= e(turma_nome($aluno_f)) ?></span></div>
                <a class="btn btn-light btn-sm" href="aluno.php?id=<?= $f_aluno ?>#autorizacoes"><i class="bi bi-person-vcard me-1"></i>Ficha</a>
                <a class="btn btn-light btn-sm" href="saidas.php" aria-label="Ver todas"><i class="bi bi-x-lg"></i></a>
            </section>
        <?php endif; ?>
        <div class="aut-barra mb-3">
            <div class="segmentado" role="tablist">
                <a class="<?= !$f_tipo ? 'ativo' : '' ?>" href="?<?= $qs(['tipo' => '']) ?>">Todas <span><?= array_sum($cont_tipos) ?></span></a>
                <?php foreach (TIPOS_AUTORIZACAO as $k => [$rot, $ic]): ?><a class="<?= $f_tipo === $k ? 'ativo' : '' ?>" href="?<?= $qs(['tipo' => $k]) ?>"><i class="bi bi-<?= $ic ?>"></i><?= e(['saida' => 'Saídas', 'tablet' => 'Tablets', 'outra' => 'Internas'][$k]) ?> <span><?= $cont_tipos[$k] ?></span></a><?php endforeach; ?>
            </div>
            <?php if (!$aluno_f && $f_busca === '' && !$busca_codigo): ?>
            <div class="semana-nav">
                <a href="?<?= $qs(['semana' => date('Y-m-d', strtotime($seg . ' -7 days'))]) ?>" aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></a>
                <span><?= e(semana_rotulo($seg, $sex)) ?></span>
                <a href="?<?= $qs(['semana' => date('Y-m-d', strtotime($seg . ' +7 days'))]) ?>" aria-label="Próxima semana"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
            <form method="get" class="busca-rapida ms-auto"><i class="bi bi-search"></i><input class="form-control form-control-sm" name="q" value="<?= e($f_busca) ?>" placeholder="Buscar aluno" aria-label="Buscar aluno"></form>
        </div>

        <?php if ($lista): ?>
        <div class="aut-lista">
            <?php foreach ($lista as $s): $t = $s['tipo'] ?? 'saida'; [$rot, $ic] = tipo_autorizacao($s); $vig = $t !== 'saida' && autorizacao_vigente($s); ?>
            <article class="aut-item aut-<?= $t ?><?= $ver === (int)$s['id'] ? ' destaque' : '' ?>" id="aut<?= $s['id'] ?>">
                <div class="ai-tipo"><i class="bi bi-<?= $ic ?>"></i></div>
                <div class="ai-quem">
                    <?= avatar(['id' => $s['aluno_id'], 'nome' => $s['nome'], 'foto' => $s['foto']], 'aluno', 'avatar-sm') ?>
                    <div class="min-w-0"><a class="nome-link" href="?aluno=<?= $s['aluno_id'] ?>"><?= e(nome_proprio($s['nome'])) ?></a><small><?= e($s['serie'] . 'º ' . $s['letra'] . ' · ' . $s['curso']) ?></small></div>
                </div>
                <div class="ai-oque">
                    <strong><?= e(autorizacao_nome($s)) ?><?= $vig ? ' <span class="etiqueta">válida hoje</span>' : '' ?></strong>
                    <small><?= e($s['motivo']) ?><?= $t === 'saida' && $s['responsavel_nome'] ? ' · resp.: ' . e($s['responsavel_nome']) . ($s['responsavel_parentesco'] ? ' (' . e($s['responsavel_parentesco']) . ')' : '') : '' ?></small>
                </div>
                <div class="ai-quando">
                    <?php if ($t === 'saida'): ?><strong><?= date('H:i', strtotime($s['data_hora'])) ?></strong><small><?= data_br($s['data_hora']) ?></small>
                    <?php else: ?><strong><?= date('d/m', strtotime($s['data_hora'])) ?><?= $s['validade_fim'] && $s['validade_fim'] !== substr($s['data_hora'], 0, 10) ? ' a ' . date('d/m', strtotime($s['validade_fim'])) : '' ?></strong><small><?= $s['validade_fim'] && $s['validade_fim'] !== substr($s['data_hora'], 0, 10) ? 'validade' : 'só no dia' ?></small><?php endif; ?>
                </div>
                <div class="ai-acoes">
                    <?php if ($autoriza): ?><a class="btn btn-sm btn-imprimir" href="saida_cupom.php?id=<?= $s['id'] ?>&imprimir=1" target="_blank"><i class="bi bi-printer"></i><span>Imprimir</span></a><?php endif; ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-mais" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mais ações"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <?php if ($autoriza): ?>
                            <li><a class="dropdown-item" href="saida_cupom.php?id=<?= $s['id'] ?>" target="_blank"><i class="bi bi-receipt me-2"></i>Ver cupom</a></li>
                            <li><a class="dropdown-item" href="saida_cupom.php?id=<?= $s['id'] ?>&largura=58&imprimir=1" target="_blank"><i class="bi bi-printer me-2"></i>Imprimir em papel 58 mm</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="aluno.php?id=<?= $s['aluno_id'] ?>#autorizacoes"><i class="bi bi-person-vcard me-2"></i>Ficha do aluno</a></li>
                            <li><span class="dropdown-item-text small text-secondary"><i class="bi bi-pen me-2"></i><?= e(primeiro_ultimo($s['autorizado_nome'])) ?> · <span class="font-monospace"><?= e(implode('-', str_split($s['codigo'], 4))) ?></span></span></li>
                            <?php if (pode('saidas.excluir')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><form method="post" data-confirmar="Cancelar esta autorização? O cupom impresso deixará de ser válido." data-botao="Cancelar autorização">
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-x-circle me-2"></i>Cancelar autorização</button></form></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="card"><div class="vazio py-5"><i class="bi bi-patch-check"></i><strong><?= $aluno_f ? 'Este aluno ainda não tem autorizações' : 'Nenhuma autorização aqui' ?></strong>Use os cartões acima para emitir uma nova.</div></div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="card mb-3">
            <div class="card-header"><h2><i class="bi bi-tablet me-1"></i>Podem portar tablet</h2><span class="etiqueta azul"><?= count($tablets_hoje) ?></span></div>
            <?php if ($tablets_hoje): ?>
            <div class="px-3 pt-2"><input type="search" class="form-control form-control-sm" id="filtroTablet" placeholder="Conferir um aluno…" aria-label="Conferir se o aluno pode portar tablet"></div>
            <ul class="lista-tablets" id="listaTablet">
                <?php foreach ($tablets_hoje as $t): ?>
                    <li data-nome="<?= e(mb_strtolower(nome_proprio($t['nome']))) ?>"><?= avatar(['id' => $t['aluno_id'], 'nome' => $t['nome'], 'foto' => $t['foto']], 'aluno', 'avatar-xs') ?>
                        <div class="min-w-0 flex-grow-1"><a class="nome-link" href="?aluno=<?= $t['aluno_id'] ?>&ver=<?= $t['id'] ?>#aut<?= $t['id'] ?>"><?= e(primeiro_ultimo(nome_proprio($t['nome']))) ?></a><small class="d-block"><?= e($t['serie'] . 'º ' . $t['letra']) ?><?= $t['item'] ? ' · ' . e($t['item']) : '' ?></small></div>
                        <small>até <?= date('d/m', strtotime($t['validade_fim'] ?: $t['data_hora'])) ?></small></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><div class="vazio py-4"><i class="bi bi-tablet"></i>Nenhum aluno com autorização de tablet válida hoje.</div><?php endif; ?>
        </div>
        <?php
        $rk = [];
        foreach ($base as $s) if (($s['tipo'] ?? 'saida') === 'saida') { $k = $s['serie'] . 'º ' . $s['letra'] . ' · ' . $s['curso']; $rk[$k] = ($rk[$k] ?? 0) + 1; }
        arsort($rk); $mx = max(1, ...array_values($rk) ?: [1]);
        if ($rk && !$aluno_f): ?>
        <div class="card"><div class="card-header"><h2>Saídas por turma</h2><span class="small text-secondary"><?= $f_busca === '' ? 'nesta semana' : 'na busca' ?></span></div>
            <div class="card-body py-2"><ul class="lista-linhas"><?php foreach (array_slice($rk, 0, 8, true) as $k => $n): ?>
                <li><span style="width:170px" class="text-truncate"><?= e($k) ?></span><span class="barra-prop"><span style="width:<?= round($n / $mx * 100) ?>%"></span></span><strong style="width:24px;text-align:right"><?= $n ?></strong></li>
            <?php endforeach; ?></ul></div></div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ Formulários ============ -->
<?php foreach (['saida', 'tablet', 'outra'] as $k): if ($k === 'saida' && !$pode_saida) continue; [$rot_k, $ic_k] = TIPOS_AUTORIZACAO[$k]; $fv = ($form['tipo'] ?? '') === $k ? $form : [];
    $direto = $k !== 'tablet' || $autoriza; ?>
<div class="modal fade aut-modal" id="modal_<?= $k ?>" tabindex="-1" aria-labelledby="titulo_<?= $k ?>" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><form class="modal-content aut-form aut-<?= $k ?>" method="post" autocomplete="off">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="emitir"><input type="hidden" name="tipo" value="<?= $k ?>">
    <div class="modal-header"><span class="an-ic me-2"><i class="bi bi-<?= $ic_k ?>"></i></span><h5 class="modal-title" id="titulo_<?= $k ?>">Nova autorização · <?= e($rot_k) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <?php if ($erro_form && $fv): ?><div class="alert alert-warning d-flex gap-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($erro_form) ?></span></div><?php endif; ?>
        <?php if ($k === 'saida' && (usuario()['perfil'] ?? '') !== 'admin'): ?><div class="aut-aviso"><i class="bi bi-shield-lock"></i><span>Você pode liberar os alunos <?= e(implode(' e ', array_filter([turmas_dirigidas() ? 'da(s) turma(s) que dirige' : '', cursos_coordenados() ? 'do(s) curso(s) que coordena' : '']))) ?>.</span></div><?php endif; ?>
        <?php if ($k === 'outra'): ?><div class="aut-aviso"><i class="bi bi-bell"></i><span>Vale na hora, sem passar pela coordenação. <strong>Todos os professores</strong> recebem o aviso no painel de que o aluno está fora da sala com a sua autorização.</span></div><?php endif; ?>
        <div class="aut-bloco"><span class="aut-n">1</span><div class="flex-grow-1"><label class="form-label obrigatorio" for="<?= $k ?>_busca">Aluno</label><?= campo_aluno($k) ?></div></div>

        <?php if ($k === 'saida'): ?>
        <div class="aut-bloco"><span class="aut-n">2</span><div class="flex-grow-1">
            <label class="form-label obrigatorio">Motivo da saída</label>
            <?= pilulas('motivo', MOTIVOS_SAIDA, $fv['motivo'] ?? null, true) ?>
            <input class="form-control mt-2" name="detalhe" maxlength="150" value="<?= e($fv['detalhe'] ?? '') ?>" placeholder="Detalhes (ex.: dor de cabeça e febre)" aria-label="Detalhes do motivo">
        </div></div>
        <div class="aut-bloco"><span class="aut-n">3</span><div class="flex-grow-1 row g-3">
            <div class="col-sm-7"><label class="form-label obrigatorio" for="s_resp">Responsável que autorizou</label><input class="form-control aut-resp" id="s_resp" name="responsavel_nome" maxlength="150" value="<?= e($fv['responsavel_nome'] ?? '') ?>" required placeholder="Quem autorizou ou veio buscar"></div>
            <div class="col-sm-5"><label class="form-label" for="s_par">Parentesco</label><input class="form-control aut-par" id="s_par" name="responsavel_parentesco" maxlength="40" list="parentescos" value="<?= e($fv['responsavel_parentesco'] ?? '') ?>"></div>
            <div class="col-sm-7"><label class="form-label obrigatorio" for="s_dh">Data e hora da saída</label><input class="form-control" type="datetime-local" id="s_dh" name="data_hora" value="<?= e($fv['data_hora'] ?? date('Y-m-d\TH:i')) ?>" required></div>
        </div></div>
        <?php elseif ($k === 'tablet'): ?>
        <div class="aut-aviso"><i class="bi bi-info-circle"></i><span>O tablet é <strong>do próprio aluno</strong>. O cupom serve de identificação: quando um professor perguntar, o aluno mostra o cupom.</span></div>
        <div class="aut-bloco"><span class="aut-n">2</span><div class="flex-grow-1">
            <label class="form-label" for="t_item">Aparelho</label>
            <input class="form-control" id="t_item" name="item" maxlength="60" value="<?= e($fv['item'] ?? '') ?>" placeholder="Marca, modelo e cor (ex.: Samsung Galaxy Tab A7, capa azul)">
            <div class="form-text">Ajuda a reconhecer o aparelho na hora da conferência.</div>
        </div></div>
        <div class="aut-bloco"><span class="aut-n">3</span><div class="flex-grow-1">
            <label class="form-label obrigatorio">Para que</label>
            <?= pilulas('finalidade', FINALIDADES_TABLET, $fv['finalidade'] ?? FINALIDADES_TABLET[0], true) ?>
            <input class="form-control mt-2" name="detalhe_tablet" maxlength="120" value="<?= e($fv['detalhe_tablet'] ?? '') ?>" placeholder="O que vai estudar (ex.: revisão de Matemática)" aria-label="O que vai estudar">
        </div></div>
        <?php else: ?>
        <div class="aut-bloco"><span class="aut-n">2</span><div class="flex-grow-1">
            <label class="form-label obrigatorio" for="o_tit">Para onde o aluno vai</label>
            <input class="form-control" id="o_tit" name="titulo" maxlength="80" value="<?= e($fv['titulo'] ?? '') ?>" required placeholder="Ex.: Laboratório de Informática">
            <div class="sugestoes mt-2"><?php foreach (['Laboratório de Informática', 'Laboratório de Ciências', 'Laboratório de Enfermagem', 'Centro de Multimeios', 'Secretaria', 'Coordenação', 'Enfermaria'] as $sg): ?><button type="button" class="sugestao" data-alvo="o_tit"><?= e($sg) ?></button><?php endforeach; ?></div>
            <label class="form-label mt-3" for="o_desc">O que vai fazer</label>
            <textarea class="form-control" id="o_desc" name="descricao" rows="2" maxlength="255" placeholder="Ex.: terminar a atividade prática da disciplina de Redes"><?= e($fv['descricao'] ?? '') ?></textarea>
        </div></div>
        <?php endif; ?>

        <?php if ($k !== 'saida'): ?>
        <div class="aut-bloco"><span class="aut-n"><?= $k === 'tablet' ? 4 : 3 ?></span><div class="flex-grow-1">
            <label class="form-label">Validade</label>
            <div class="validade-op">
                <label class="pilula"><input type="radio" name="_val" value="hoje"<?= $k !== 'tablet' || !$fim_periodo ? ' checked' : '' ?>><span>Só hoje</span></label>
                <?php if ($fim_periodo): ?><label class="pilula"><input type="radio" name="_val" value="periodo"<?= $k === 'tablet' ? ' checked' : '' ?>><span>Até o fim do período (<?= date('d/m', strtotime($fim_periodo)) ?>)</span></label><?php endif; ?>
                <?php if ($fim_ano && $fim_ano !== $fim_periodo): ?><label class="pilula"><input type="radio" name="_val" value="ano"><span>Até o fim do ano letivo (<?= date('d/m', strtotime($fim_ano)) ?>)</span></label><?php endif; ?>
                <label class="pilula"><input type="radio" name="_val" value="data"><span>Escolher datas</span></label>
            </div>
            <div class="row g-2 mt-1 val-datas" hidden>
                <div class="col-6"><label class="form-label small" for="<?= $k ?>_dia">De</label><input class="form-control" type="date" id="<?= $k ?>_dia" name="data" value="<?= e($fv['data'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label small" for="<?= $k ?>_ate">Até</label><input class="form-control" type="date" id="<?= $k ?>_ate" name="validade_fim" value="<?= e($fv['validade_fim'] ?? ($k === 'tablet' ? $fim_periodo : '')) ?>"></div>
            </div>
            <details class="mt-2 ajuste"<?= $k === 'outra' ? ' open' : '' ?>><summary><?= $k === 'tablet' ? 'Uso permitido: ' . e('Centro de Multimeios · ' . $horario_almoco) : 'Horário (opcional)' ?></summary>
                <div class="row g-2 mt-1">
                    <div class="col-sm-6"><input class="form-control" name="local" maxlength="60" value="<?= e($fv['local'] ?? ($k === 'tablet' ? 'Centro de Multimeios' : '')) ?>" placeholder="Local" aria-label="Local"></div>
                    <div class="col-sm-6"><input class="form-control" name="horario" maxlength="60" value="<?= e($fv['horario'] ?? ($k === 'tablet' ? $horario_almoco : '')) ?>" placeholder="Horário" aria-label="Horário"></div>
                </div></details>
            <?php if ($k !== 'saida'): ?>
            <div class="row g-2 mt-2">
                <div class="col-sm-7"><input class="form-control aut-resp" name="responsavel_nome" maxlength="150" value="<?= e($fv['responsavel_nome'] ?? '') ?>" placeholder="Responsável ciente (opcional)" aria-label="Responsável ciente"></div>
                <div class="col-sm-5"><input class="form-control aut-par" name="responsavel_parentesco" maxlength="40" list="parentescos" value="<?= e($fv['responsavel_parentesco'] ?? '') ?>" placeholder="Parentesco" aria-label="Parentesco"></div>
            </div>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <?= $direto ? '<div class="aut-assina"><span class="aut-selo"><i class="bi bi-patch-check-fill"></i></span><div><small>Será assinada digitalmente por</small><strong>' . e(usuario()['nome']) . '</strong><span>' . e(cargo_usuario()) . '</span></div></div>' : $assina ?>
        <button class="btn btn-primary btn-lg ms-auto" type="submit"><?= $k === 'outra' ? '<i class="bi bi-check2-circle me-2"></i>Autorizar e avisar os professores' : ($direto ? '<i class="bi bi-printer me-2"></i>Emitir e imprimir' : '<i class="bi bi-send me-2"></i>Enviar para a coordenação') ?></button>
    </div>
</form></div></div>
<?php endforeach; ?>
<?php if ($autoriza): ?>
<div class="modal fade" id="modalRecusar" tabindex="-1" aria-labelledby="tRecusar" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="recusar"><input type="hidden" name="id" id="recusarId">
    <div class="modal-header"><h5 class="modal-title" id="tRecusar">Recusar pedido</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body"><p class="mb-2">Pedido de <strong id="recusarNome"></strong>. O professor recebe o aviso com o motivo.</p>
        <label class="form-label" for="recusarMotivo">Motivo</label><input class="form-control" id="recusarMotivo" name="recusa_motivo" maxlength="255" placeholder="Ex.: responsável não confirmou por telefone"></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Voltar</button><button class="btn btn-danger" type="submit">Recusar pedido</button></div>
</form></div></div>
<?php endif; ?>
<datalist id="parentescos"><option>Mãe</option><option>Pai</option><option>Avó</option><option>Avô</option><option>Tia</option><option>Tio</option><option>Irmã</option><option>Irmão</option><option>Responsável legal</option></datalist>

<?php
$script_pagina = '<script>
(function () {
  const HOJE = ' . json_encode(date('Y-m-d')) . ', FIM_PER = ' . json_encode($fim_periodo) . ', FIM_ANO = ' . json_encode($fim_ano) . ';
  document.querySelectorAll(".aut-form").forEach(form => {
    const id = form.querySelector(".aut-aluno-id"), busca = form.querySelector(".aut-busca-input"), lista = form.querySelector(".aut-busca-lista"), card = form.querySelector(".aut-aluno-card"), caixa = form.querySelector(".aut-busca");
    async function escolher(a) {
      if (!a) return;
      const j = await (await fetch("api.php?acao=aluno_info&id=" + a.id)).json(); if (!j.ok) return;
      const al = j.aluno; id.value = al.id; busca.value = ""; caixa.hidden = true; card.hidden = false;
      card.innerHTML = `${(al.foto || a.foto) ? `<img class="avatar avatar-md avatar-foto" src="${al.foto || a.foto}" alt="">` : `<span class="avatar avatar-md">${esc(al.nome.split(" ").map(p => p[0]).filter((_, i, v) => i === 0 || i === v.length - 1).join(""))}</span>`}
        <div class="flex-grow-1 min-w-0"><strong>${esc(al.nome)}</strong><small>${esc(al.turma)}${al.telefone ? " · resp.: " + esc(al.telefone) : ""}</small>
        ${al.saidas_semana ? `<span class="etiqueta vermelho mt-1">${al.saidas_semana} saída(s) nesta semana</span>` : ""}</div>
        <button type="button" class="btn btn-sm btn-light">Trocar</button>`;
      card.querySelector("button").onclick = () => { id.value = ""; card.hidden = true; caixa.hidden = false; busca.focus(); };
      const r = form.querySelector(".aut-resp"), p = form.querySelector(".aut-par");
      if (r && !r.value && form.classList.contains("aut-saida")) r.value = al.responsavel_nome || "";
      if (p && !p.value && form.classList.contains("aut-saida")) p.value = al.responsavel_parentesco || "";
    }
    form._escolher = escolher;
    const soSaida = form.classList.contains("aut-saida") ? "&saida=1" : "";
    comboBusca({ input: busca, lista, url: q => "api.php?acao=buscar&q=" + encodeURIComponent(q) + soSaida, vazio: soSaida ? "Nenhum aluno das suas turmas ou do seu curso com esse nome." : "Nenhum aluno ativo encontrado.",
      item: a => `${a.foto ? `<img class="avatar avatar-xs" src="${a.foto}" alt="">` : "<i class=\\"bi bi-person\\"></i>"}<span>${esc(a.nome)}</span><span class="turma">${esc(a.turma)}</span>`, escolher });
    /* validade */
    const datas = form.querySelector(".val-datas");
    if (datas) {
      const dia = datas.querySelector("[name=data]"), ate = datas.querySelector("[name=validade_fim]");
      form.querySelectorAll("[name=_val]").forEach(r => r.addEventListener("change", () => {
        datas.hidden = r.value !== "data";
        if (r.value === "hoje") { dia.value = HOJE; ate.value = ""; }
        if (r.value === "periodo") { dia.value = HOJE; ate.value = FIM_PER; }
        if (r.value === "ano") { dia.value = HOJE; ate.value = FIM_ANO; }
      }));
      if (ate.value && ate.value !== FIM_PER) { form.querySelector("[name=_val][value=data]").checked = true; datas.hidden = false; }
    }
    form.querySelectorAll(".sugestao").forEach(b => b.addEventListener("click", () => { const alvo = document.getElementById(b.dataset.alvo); alvo.value = b.textContent; alvo.focus(); }));
    form.addEventListener("submit", e => {
      if (!id.value) { e.preventDefault(); busca.focus(); busca.classList.add("is-invalid"); setTimeout(() => busca.classList.remove("is-invalid"), 1500); }
    });
    form.closest(".modal").addEventListener("shown.bs.modal", () => { if (!id.value) busca.focus(); });
  });
  /* abrir direto (erro de validação, atalho da ficha) */
  const abrir = ' . json_encode($abrir) . ', alunoPre = ' . (int)$aluno_pre . ';
  if (abrir) {
    const m = document.getElementById("modal_" + abrir);
    bootstrap.Modal.getOrCreateInstance(m).show();
    if (alunoPre) m.querySelector(".aut-form")._escolher({ id: alunoPre });
  }
  const ft = document.getElementById("filtroTablet");
  if (ft) ft.addEventListener("input", () => { const q = ft.value.trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    document.querySelectorAll("#listaTablet li").forEach(li => li.hidden = q && !li.dataset.nome.normalize("NFD").replace(/[\u0300-\u036f]/g, "").includes(q)); });
  const mr = document.getElementById("modalRecusar");
  if (mr) mr.addEventListener("show.bs.modal", e => { document.getElementById("recusarId").value = e.relatedTarget.dataset.id; document.getElementById("recusarNome").textContent = e.relatedTarget.dataset.nome; });
  /* chegou pedido novo: recarrega a lista se nenhuma janela estiver aberta */
  document.addEventListener("notif:pedido", () => { if (!document.querySelector(".modal.show")) location.reload(); });
  const d = document.querySelector(".aut-item.destaque, .meus-pedidos li.destaque"); if (d) d.scrollIntoView({ block: "center" });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
