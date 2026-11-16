<?php
require __DIR__ . '/../includes/app.php';
exigir('');

$pode_enviar = pode('notificacoes.enviar');
$admin = (usuario()['perfil'] ?? '') === 'admin';

if (eh_post()) {
    csrf_verificar();
    switch ($_POST['acao'] ?? '') {
        case 'enviar':
            if (!$pode_enviar) break;
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $msg = trim((string)($_POST['mensagem'] ?? ''));
            $tipo = ($_POST['tipo'] ?? '') === 'sistema' && $admin ? 'sistema' : 'aviso';
            $para = ($_POST['para'] ?? 'todos') === 'perfis' ? array_values(array_intersect((array)($_POST['perfis'] ?? []), array_keys(perfis_lista()))) : 'todos';
            if (mb_strlen($titulo) < 3) { flash('warning', 'Escreva o título da notificação.'); break; }
            if ($para !== 'todos' && !$para) { flash('warning', 'Escolha para quem vai a notificação.'); break; }
            notificar($para === 'todos' ? 'todos' : ['perfis' => $para], $titulo, $msg ?: null, $tipo, null, !empty($_POST['importante']));
            registrar_log('notificacao', $titulo);
            flash('success', 'Notificação enviada. Ela aparece no sino de cada pessoa na próxima vez que o painel atualizar.');
            redirecionar('notificacoes.php?aba=enviadas');
        case 'lida':
            notificacao_marcar_lida((int)($_POST['id'] ?? 0));
            break;
        case 'todas':
            notificacoes_marcar_todas();
            flash('success', 'Todas marcadas como lidas.');
            break;
        case 'excluir':
            $n = q_um('SELECT * FROM notificacoes WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            if ($n && ((int)$n['autor_id'] === uid() || $admin)) { q('DELETE FROM notificacoes WHERE id = ?', [$n['id']]); flash('success', 'Notificação apagada para todos.'); }
            redirecionar('notificacoes.php?aba=enviadas');
    }
    redirecionar('notificacoes.php' . (!empty($_GET['aba']) ? '?aba=' . urlencode($_GET['aba']) : ''));
}

$aba = in_array($_GET['aba'] ?? '', ['naolidas', 'enviadas'], true) ? $_GET['aba'] : 'todas';
if ($aba === 'enviadas' && !$pode_enviar) $aba = 'todas';
$lista = $aba === 'enviadas' ? [] : notificacoes_lista(200, $aba === 'naolidas');
$nao_lidas = notificacoes_nao_lidas();
$enviadas = [];
if ($pode_enviar) {
    $usuarios_ativos = q_todos('SELECT id, perfil FROM usuarios WHERE ativo = 1');
    foreach (q_todos("SELECT n.*, (SELECT COUNT(*) FROM notificacao_lidas l WHERE l.notificacao_id = n.id) AS lidas FROM notificacoes n
                      WHERE n.autor_id = ? AND n.tipo IN ('aviso', 'sistema') ORDER BY n.criado_em DESC LIMIT 100", [uid()]) as $n) {
        $n['alcance'] = $n['destino'] === 'todos' ? count($usuarios_ativos)
            : count(array_filter($usuarios_ativos, fn($u) => str_contains($n['destino'], ',' . $u['perfil'] . ',')));
        $enviadas[] = $n;
    }
}

$titulo = 'Notificações';
$subtitulo = 'Avisos da direção e da coordenação, atualizações do sistema e pedidos de liberação.';
$menu = 'notificacoes';
require __DIR__ . '/../includes/layout_topo.php';
?>
<?php if ($pode_enviar): ?><div class="pg-acoes"><?= janela_botao('novaNotif', 'Enviar notificação', 'send') ?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-12">
        <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
            <div class="segmentado">
                <a class="<?= $aba === 'todas' ? 'ativo' : '' ?>" href="?aba=todas">Recebidas</a>
                <a class="<?= $aba === 'naolidas' ? 'ativo' : '' ?>" href="?aba=naolidas">Não lidas <span><?= $nao_lidas ?></span></a>
                <?php if ($pode_enviar): ?><a class="<?= $aba === 'enviadas' ? 'ativo' : '' ?>" href="?aba=enviadas">Enviadas por mim <span><?= count($enviadas) ?></span></a><?php endif; ?>
            </div>
            <?php if ($aba !== 'enviadas' && $nao_lidas): ?>
            <form method="post" class="ms-auto"><?= csrf_campo() ?><input type="hidden" name="acao" value="todas">
                <button class="btn btn-sm btn-light"><i class="bi bi-check2-all me-1"></i>Marcar todas como lidas</button></form>
            <?php endif; ?>
        </div>

        <div class="card">
        <?php if ($aba === 'enviadas'): ?>
            <?php if ($enviadas): foreach ($enviadas as $n): [$rot, $ic] = TIPOS_NOTIFICACAO[$n['tipo']] ?? ['Aviso', 'bell']; ?>
                <article class="notif-card<?= $n['importante'] ? ' importante' : '' ?>">
                    <span class="si-ic t-<?= e($n['tipo']) ?>"><i class="bi bi-<?= $ic ?>"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <h3><?= e($n['titulo']) ?></h3>
                        <div class="meta"><?= e($rot) ?> · para <?= $n['destino'] === 'todos' ? 'todos' : e(implode(', ', array_map('perfil_nome', array_filter(explode(',', $n['destino']))))) ?> · <?= data_hora_br($n['criado_em']) ?></div>
                        <?php if ($n['mensagem']): ?><p><?= e($n['mensagem']) ?></p><?php endif; ?>
                        <div class="mt-2 small"><i class="bi bi-eye me-1"></i><?= (int)$n['lidas'] ?> de <?= (int)$n['alcance'] ?> pessoa(s) já leram</div>
                    </div>
                    <form method="post" data-confirmar="Apagar esta notificação para todos?" data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $n['id'] ?>">
                        <button class="btn btn-sm btn-light" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                </article>
            <?php endforeach; else: ?><div class="vazio py-5"><i class="bi bi-send"></i><strong>Você ainda não enviou notificações</strong>Use o formulário ao lado.</div><?php endif; ?>
        <?php elseif ($lista): foreach ($lista as $n): [$rot, $ic] = TIPOS_NOTIFICACAO[$n['tipo']] ?? ['Aviso', 'bell']; ?>
            <article class="notif-card<?= $n['lida_em'] ? '' : ' nova' ?><?= $n['importante'] ? ' importante' : '' ?>" id="n<?= $n['id'] ?>">
                <span class="si-ic t-<?= e($n['tipo']) ?>"><i class="bi bi-<?= $ic ?>"></i></span>
                <div class="flex-grow-1 min-w-0">
                    <h3><?= e($n['titulo']) ?><?= $n['importante'] ? ' <span class="etiqueta ambar">importante</span>' : '' ?></h3>
                    <div class="meta"><?= e($rot) ?> · <?= e($n['autor_nome'] ?: 'Sistema') ?> · <?= e(quando($n['criado_em'])) ?></div>
                    <?php if ($n['mensagem']): ?><p><?= e($n['mensagem']) ?></p><?php endif; ?>
                    <?php if ($n['link']): ?><a class="btn btn-sm btn-primary mt-2" href="<?= e($n['link']) ?>">Abrir<i class="bi bi-arrow-right ms-1"></i></a><?php endif; ?>
                </div>
                <?php if (!$n['lida_em']): ?>
                <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="lida"><input type="hidden" name="id" value="<?= $n['id'] ?>">
                    <button class="btn btn-sm btn-light text-nowrap" title="Marcar como lida"><i class="bi bi-check2"></i></button></form>
                <?php endif; ?>
            </article>
        <?php endforeach; else: ?>
            <div class="vazio py-5"><i class="bi bi-bell-slash"></i><strong><?= $aba === 'naolidas' ? 'Tudo lido' : 'Nenhuma notificação ainda' ?></strong>Os avisos da direção e da coordenação aparecem aqui e no sino do topo.</div>
        <?php endif; ?>
        </div>
    </div>

</div>

<?php if ($pode_enviar): ?>
<?= janela_abre('novaNotif', 'Enviar notificação', 'send', false, '') ?>
        <form class="card" method="post">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="enviar">
            <div class="card-header"><h2><i class="bi bi-send me-1"></i>Enviar notificação</h2></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label obrigatorio" for="n_tit">Título</label>
                    <input class="form-control" id="n_tit" name="titulo" maxlength="150" required placeholder="Ex.: Reunião pedagógica na sexta às 14h"></div>
                <div class="mb-3"><label class="form-label" for="n_msg">Mensagem</label>
                    <textarea class="form-control" id="n_msg" name="mensagem" rows="4" maxlength="2000" placeholder="Detalhes do aviso"></textarea></div>
                <?php if ($admin): ?>
                <div class="mb-3"><label class="form-label">Tipo</label>
                    <div class="pilulas" style="--c:var(--verde-800)">
                        <label class="pilula"><input type="radio" name="tipo" value="aviso" checked><span>Aviso</span></label>
                        <label class="pilula"><input type="radio" name="tipo" value="sistema"><span>Atualização do sistema</span></label>
                    </div></div>
                <?php endif; ?>
                <div class="mb-3"><label class="form-label">Para quem</label>
                    <div class="pilulas mb-2" style="--c:var(--verde-800)">
                        <label class="pilula"><input type="radio" name="para" value="todos" checked><span>Todos os usuários</span></label>
                        <label class="pilula"><input type="radio" name="para" value="perfis"><span>Escolher grupos</span></label>
                    </div>
                    <div class="destino-op" id="destPerfis" hidden>
                        <?php foreach (perfis_lista() as $k => $pf): ?><label class="pilula" style="--c:var(--azul)"><input type="checkbox" name="perfis[]" value="<?= e($k) ?>"><span><?= e($pf['nome']) ?></span></label><?php endforeach; ?>
                    </div></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="importante" value="1" id="n_imp"><label class="form-check-label" for="n_imp">Importante (toca um aviso sonoro e fica destacada)</label></div>
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1"></i>Enviar</button>
            </div>
        </form>
<?= janela_fecha() ?>
<?php endif; ?>
<?php
$script_pagina = '<script>
document.querySelectorAll("[name=para]").forEach(r => r.addEventListener("change", () => { document.getElementById("destPerfis").hidden = !document.querySelector("[name=para][value=perfis]").checked; }));
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
