<?php
require __DIR__ . '/../includes/app.php';
exigir('usuarios');

function senha_provisoria(): string
{
    $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s . random_int(1, 9);
}
const CORES_PERFIL = ['verde' => 'Verde', 'azul' => 'Azul', 'ambar' => 'Âmbar', 'roxo' => 'Roxo', 'vermelho' => 'Vermelho', 'cinza' => 'Cinza', 'escura' => 'Escura'];

$aba = ($_GET['aba'] ?? '') === 'perfis' ? 'perfis' : 'pessoas';
$perfis = perfis_lista();

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar') {
        $nome = trim((string)$_POST['nome']);
        $email = mb_strtolower(trim((string)$_POST['email']));
        $perfil = (string)$_POST['perfil'];
        $cargo = trim((string)($_POST['cargo'] ?? ''));
        if (mb_strlen($nome) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($perfis[$perfil])) {
            flash('warning', 'Preencha nome, um e-mail válido e o perfil.');
        } elseif (q_valor('SELECT id FROM usuarios WHERE email = ?', [$email])) {
            flash('warning', 'Já existe um usuário com o e-mail ' . $email . '.');
        } else {
            $senha = senha_provisoria();
            q('INSERT INTO usuarios (nome, email, senha_hash, perfil, cargo, ativo, trocar_senha) VALUES (?, ?, ?, ?, ?, 1, 1)',
              [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, $cargo ?: null]);
            $novo = (int)db()->lastInsertId();
            registrar_log('usuario_criado', "$nome ($email) como " . perfil_nome($perfil));
            $_SESSION['senha_mostrar'] = [$nome, $email, $senha];
            redirecionar('usuario.php?id=' . $novo);
        }
        redirecionar('usuarios.php');
    }

    if ($acao === 'matriz') {
        $marcadas = (array)($_POST['perm'] ?? []);
        $validas = permissoes_todas();
        db()->beginTransaction();
        foreach ($perfis as $chave => $p) {
            if ($chave === 'admin') continue;
            $lista = array_values(array_intersect($validas, array_map('strval', (array)($marcadas[$chave] ?? []))));
            q('DELETE FROM perfil_permissoes WHERE perfil = ?', [$chave]);
            foreach ($lista as $perm) q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$chave, $perm]);
            q('UPDATE perfis SET personalizado = 1 WHERE chave = ?', [$chave]);
        }
        db()->commit();
        registrar_log('permissoes', 'Permissões dos perfis atualizadas');
        flash('success', 'Permissões dos perfis salvas. Já valem a partir do próximo clique de cada pessoa.');
        redirecionar('usuarios.php?aba=perfis');
    }

    if ($acao === 'perfil_perms') {
        $chave = (string)($_POST['chave'] ?? '');
        if ($chave !== 'admin' && isset($perfis[$chave])) {
            $lista = array_values(array_intersect(permissoes_todas(), array_map('strval', (array)($_POST['perm'] ?? []))));
            db()->beginTransaction();
            q('DELETE FROM perfil_permissoes WHERE perfil = ?', [$chave]);
            foreach ($lista as $perm) q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$chave, $perm]);
            q('UPDATE perfis SET personalizado = 1 WHERE chave = ?', [$chave]);
            db()->commit();
            registrar_log('permissoes', 'Perfil ' . $perfis[$chave]['nome'] . ': ' . count($lista) . ' acessos');
            flash('success', 'Acessos do perfil "' . $perfis[$chave]['nome'] . '" salvos (' . count($lista) . '). Já valem no próximo clique de cada pessoa.');
        }
        redirecionar('usuarios.php?aba=perfis&perfil=' . urlencode($chave));
    }

    if ($acao === 'perfil_salvar') {
        $chave = (string)($_POST['chave'] ?? '');
        $nome = trim((string)($_POST['nome'] ?? ''));
        $desc = trim((string)($_POST['descricao'] ?? ''));
        $cor = isset(CORES_PERFIL[$_POST['cor'] ?? '']) ? $_POST['cor'] : 'cinza';
        if (mb_strlen($nome) < 3) { flash('warning', 'Dê um nome ao perfil.'); redirecionar('usuarios.php?aba=perfis'); }
        if ($chave !== '' && isset($perfis[$chave])) {
            q('UPDATE perfis SET nome = ?, descricao = ?, cor = ? WHERE chave = ?', [mb_substr($nome, 0, 80), $desc ?: null, $cor, $chave]);
            flash('success', 'Perfil "' . $nome . '" atualizado.');
        } else {
            $base = trim(preg_replace('/[^a-z0-9]+/', '_', norm($nome)), '_') ?: 'perfil';
            $chave = substr($base, 0, 30); $i = 2;
            while (isset($perfis[$chave])) $chave = substr($base, 0, 27) . '_' . $i++;
            q('INSERT INTO perfis (chave, nome, descricao, cor, sistema, personalizado, ordem) VALUES (?, ?, ?, ?, 0, 1, 60)', [$chave, mb_substr($nome, 0, 80), $desc ?: null, $cor]);
            $copiar = (string)($_POST['copiar'] ?? '');
            if ($copiar !== '' && isset($perfis[$copiar])) foreach (permissoes_perfil($copiar) as $perm) q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$chave, $perm]);
            flash('success', 'Perfil "' . $nome . '" criado. Marque abaixo o que ele pode acessar.');
        }
        registrar_log('perfil', $nome);
        redirecionar('usuarios.php?aba=perfis#matriz');
    }

    if ($acao === 'perfil_excluir') {
        /* v69: qualquer perfil pode ser excluído, menos o Administrador. Quem usa o perfil passa para outro. */
        $chave = (string)($_POST['chave'] ?? '');
        $p = $perfis[$chave] ?? null;
        $destino = (string)($_POST['mover_para'] ?? '');
        $n = $p ? (int)q_valor('SELECT COUNT(*) FROM usuarios WHERE perfil = ?', [$chave]) : 0;
        if (!$p || $chave === 'admin') flash('warning', 'O perfil Administrador não pode ser excluído.');
        elseif ($n && ($destino === $chave || !isset($perfis[$destino]))) flash('warning', 'Escolha para qual perfil vão as ' . $n . ' pessoa(s) que usam "' . $p['nome'] . '".');
        else {
            db()->beginTransaction();
            if ($n) q('UPDATE usuarios SET perfil = ? WHERE perfil = ?', [$destino, $chave]);
            q('DELETE FROM perfil_permissoes WHERE perfil = ?', [$chave]);
            q('DELETE FROM perfis WHERE chave = ?', [$chave]);
            db()->commit();
            registrar_log('perfil', 'Excluído: ' . $p['nome'] . ($n ? " ($n pessoa(s) para " . $perfis[$destino]['nome'] . ')' : ''));
            flash('success', 'Perfil "' . $p['nome'] . '" excluído.' . ($n ? ' ' . $n . ' pessoa(s) agora estão em "' . $perfis[$destino]['nome'] . '".' : ''));
            redirecionar('usuarios.php?aba=perfis');
        }
        redirecionar('usuarios.php?aba=perfis&perfil=' . urlencode($chave));
    }

    if ($acao === 'usuario_excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $alvo = q_um('SELECT id, nome, email, perfil FROM usuarios WHERE id = ?', [$id]);
        if (!$alvo) flash('warning', 'Usuário não encontrado.');
        elseif ($id === uid()) flash('warning', 'Você não pode excluir a sua própria conta.');
        elseif ($alvo['perfil'] === 'admin' && (int)q_valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1") <= 1) flash('warning', 'Este é o único administrador ativo: não pode ser excluído.');
        else {
            try {
                db()->beginTransaction();
                foreach (['usuario_permissoes', 'atribuicoes', 'agenda_notas', 'agenda_lembretes'] as $tb) { try { q("DELETE FROM $tb WHERE usuario_id = ?", [$id]); } catch (PDOException $e) {} }
                q('DELETE FROM usuarios WHERE id = ?', [$id]);
                db()->commit();
                registrar_log('usuario_excluido', $alvo['nome'] . ' (' . $alvo['email'] . ')');
                flash('success', '"' . $alvo['nome'] . '" foi excluído. O que ele registrou (faltas, ocorrências, notas) continua no histórico.');
            } catch (PDOException $e) {
                if (db()->inTransaction()) db()->rollBack();
                /* tem registros presos a ele no banco: em vez de apagar, desativa e libera o e-mail */
                q('UPDATE usuarios SET ativo = 0, email = ? WHERE id = ?', ['excluido.' . $id . '.' . time() . '@removido.local', $id]);
                registrar_log('usuario_excluido', $alvo['nome'] . ' (desativado: tem registros)');
                flash('success', '"' . $alvo['nome'] . '" foi removido do acesso. Como tem registros no histórico, a conta ficou desativada e o e-mail ' . $alvo['email'] . ' ficou livre para um novo cadastro.');
            }
        }
        redirecionar('usuarios.php');
    }
    redirecionar('usuarios.php');
}

$usuarios = q_todos('SELECT u.*, (SELECT COUNT(*) FROM atribuicoes a WHERE a.usuario_id = u.id) AS atribuicoes,
                            (SELECT COUNT(*) FROM usuario_permissoes p WHERE p.usuario_id = u.id AND (p.validade IS NULL OR p.validade >= ?)) AS excecoes
                       FROM usuarios u ORDER BY u.ativo DESC, u.nome', [date('Y-m-d')]);
$por_perfil = array_count_values(array_column(array_filter($usuarios, fn($u) => $u['ativo']), 'perfil'));

$titulo = 'Usuários e permissões';
$subtitulo = 'Quem entra no sistema, com qual perfil, e o que cada perfil ou pessoa pode acessar.';
$menu = 'usuarios';
require __DIR__ . '/../includes/layout_topo.php';
?>

<ul class="nav nav-abas mb-3">
    <li class="nav-item"><a class="nav-link<?= $aba === 'pessoas' ? ' active' : '' ?>" href="?aba=pessoas"><i class="bi bi-people me-1"></i>Pessoas<span class="contador"><?= count($usuarios) ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $aba === 'perfis' ? ' active' : '' ?>" href="?aba=perfis"><i class="bi bi-shield-lock me-1"></i>Perfis e permissões<span class="contador"><?= count($perfis) ?></span></a></li>
</ul>

<?php if ($aba === 'pessoas'):
    $n_ativos = count(array_filter($usuarios, fn($x) => $x['ativo'])); $n_nunca = count(array_filter($usuarios, fn($x) => $x['ativo'] && !$x['ultimo_acesso']));
    $n_off = count($usuarios) - $n_ativos; $n_exc = count(array_filter($usuarios, fn($x) => $x['excecoes'])); ?>
    <div class="us-topo">
        <div class="us-num"><span class="us-ic verde"><i class="bi bi-person-check"></i></span><b><?= $n_ativos ?></b><small>com acesso ativo</small></div>
        <div class="us-num"><span class="us-ic ambar"><i class="bi bi-hourglass-split"></i></span><b><?= $n_nunca ?></b><small>ainda não entraram</small></div>
        <div class="us-num"><span class="us-ic azul"><i class="bi bi-shield-check"></i></span><b><?= count(array_unique(array_column($usuarios, 'perfil'))) ?></b><small>perfis em uso</small></div>
        <div class="us-num"><span class="us-ic cinza"><i class="bi bi-person-slash"></i></span><b><?= $n_off ?></b><small>desativados</small></div>
        <div class="us-ajuda"><strong><i class="bi bi-lightbulb me-1"></i>Como funciona</strong>
            <ol><li>Cada pessoa tem um <b>perfil</b> (Professor, Coordenação…) que define o que ela vê.</li>
                <li>O que cada perfil acessa é definido em <b>Perfis e permissões</b>.</li>
                <li>Use <b>Editar</b> para trocar dados e perfil, ou <b>Excluir</b> para tirar o acesso: o histórico continua guardado.</li></ol></div>
    </div>
    <div class="filtros d-flex flex-wrap gap-2 align-items-center">
        <div class="busca-rapida"><i class="bi bi-search"></i><input class="form-control" id="buscaPessoa" placeholder="Buscar por nome, e-mail ou função" aria-label="Buscar usuário"></div>
        <div class="chips-filtro" id="chipsPerfil">
            <button type="button" class="chip ativo" data-perfil="">Todos <span><?= count($usuarios) ?></span></button>
            <?php foreach ($perfis as $k => $p): ?><button type="button" class="chip" data-perfil="<?= e($k) ?>"><?= e($p['nome']) ?> <span><?= $por_perfil[$k] ?? 0 ?></span></button><?php endforeach; ?>
        </div>
        <button class="btn btn-primary ms-auto" type="button" data-bs-toggle="modal" data-bs-target="#modalNovo"><i class="bi bi-person-plus me-1"></i>Novo usuário</button>
    </div>

    <div class="grade-pessoas" id="gradePessoas">
        <?php foreach ($usuarios as $u): $eu = (int)$u['id'] === uid(); $cor = $perfis[$u['perfil']]['cor'] ?? 'cinza'; ?>
            <div class="cartao-pessoa cp2<?= $u['ativo'] ? '' : ' inativo' ?>" data-perfil="<?= e($u['perfil']) ?>"
               data-busca="<?= e(norm($u['nome'] . ' ' . $u['email'] . ' ' . ($u['cargo'] ?? '') . ' ' . ($u['nome_exibicao'] ?? ''))) ?>">
                <a class="cp2-link" href="usuario.php?id=<?= $u['id'] ?>" aria-label="Abrir <?= e($u['nome']) ?>"></a>
                <?= avatar($u, 'usuario', 'avatar-lg') ?>
                <div class="cp-info">
                    <strong><?= e($u['nome']) ?><?= $eu ? ' <span class="etiqueta cinza">você</span>' : '' ?></strong>
                    <span class="cp-cargo"><?= e(cargo_usuario($u)) ?></span>
                    <span class="cp-email"><?= e($u['email']) ?></span>
                    <div class="cp-tags">
                        <span class="etiqueta perfil-<?= e($cor) ?>"><?= e(perfil_nome($u['perfil'])) ?></span>
                        <?php if (!$u['ativo']): ?><span class="etiqueta vermelho">Desativado</span><?php endif; ?>

                        <?php if ($u['atribuicoes']): ?><span class="etiqueta cinza"><?= $u['atribuicoes'] ?> aula(s)</span><?php endif; ?>
                    </div>
                </div>
                <div class="cp-rodape"><span class="cp2-quando"><i class="bi bi-clock-history me-1"></i><?= $u['ultimo_acesso'] ? 'Último acesso ' . data_hora_br($u['ultimo_acesso']) : ($u['trocar_senha'] ? 'Ainda não entrou' : 'Sem acesso registrado') ?></span>
                    <span class="cp2-acoes">
                        <a class="cp2-bt" href="usuario.php?id=<?= $u['id'] ?>" title="Editar"><i class="bi bi-pencil-square"></i><span>Editar</span></a>
                        <?php if (!$eu): ?><button type="button" class="cp2-bt perigo" title="Excluir" data-bs-toggle="modal" data-bs-target="#modalExcluir" data-id="<?= $u['id'] ?>" data-nome="<?= e($u['nome']) ?>" data-email="<?= e($u['email']) ?>" data-perfil="<?= e(perfil_nome($u['perfil'])) ?>" data-aulas="<?= (int)$u['atribuicoes'] ?>"><i class="bi bi-trash3"></i><span>Excluir</span></button><?php endif; ?>
                    </span></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="vazio" id="semPessoa" hidden><i class="bi bi-search"></i>Ninguém encontrado com esse filtro.</div>

    <div class="modal fade" id="modalExcluir" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content ux-modal">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="usuario_excluir"><input type="hidden" name="id" id="exId">
        <div class="ux-topo perigo"><span class="ux-ic"><i class="bi bi-person-x"></i></span><div><small>Excluir usuário</small><h5 id="exNome">—</h5><span id="exInfo"></span></div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <ul class="ux-lista">
                <li><i class="bi bi-door-closed"></i><span><b>Perde o acesso na hora</b>não consegue mais entrar no sistema.</span></li>
                <li><i class="bi bi-calendar2-x"></i><span><b>As aulas atribuídas saem</b><em id="exAulas"></em></span></li>
                <li class="ok"><i class="bi bi-archive"></i><span><b>O histórico continua</b>faltas, ocorrências e notas que ele lançou não são apagadas.</span></li>
            </ul>
            <label class="ux-conf"><input type="checkbox" id="exOk" required> Entendi, quero excluir esta pessoa.</label>
            <p class="small text-secondary mb-0 mt-2"><i class="bi bi-lightbulb me-1"></i>Se a pessoa só vai se afastar por um tempo, prefira <b>desativar</b> o acesso na página dela.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" type="submit"><i class="bi bi-trash3 me-1"></i>Excluir usuário</button></div>
    </form></div></div>

    <div class="modal fade" id="modalNovo" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="criar">
        <div class="modal-header"><h5 class="modal-title">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12"><label class="form-label obrigatorio" for="n_nome">Nome completo</label><input class="form-control" id="n_nome" name="nome" required></div>
            <div class="col-12"><label class="form-label obrigatorio" for="n_email">E-mail</label><input class="form-control" type="email" id="n_email" name="email" required></div>
            <div class="col-md-6"><label class="form-label obrigatorio" for="n_perfil">Perfil de acesso</label><select class="form-select" id="n_perfil" name="perfil">
                <?php foreach ($perfis as $k => $p): ?><option value="<?= e($k) ?>"<?= $k === 'professor' ? ' selected' : '' ?>><?= e($p['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="n_cargo">Função</label><input class="form-control" id="n_cargo" name="cargo" maxlength="80" placeholder="Ex.: Professor de Informática"></div>
            <div class="col-12 form-text mt-1">O sistema gera uma senha provisória, mostrada uma única vez. Depois você pode ajustar foto e acessos individuais.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Criar acesso</button></div>
    </form></div></div>

<?php else:
    $sel = isset($perfis[$_GET['perfil'] ?? '']) ? $_GET['perfil'] : (isset($perfis['professor']) ? 'professor' : array_key_first($perfis));
    $tem_sel = array_flip(permissoes_perfil($sel)); $total_perm = count(permissoes_todas()); $ps = $perfis[$sel]; $adm = $sel === 'admin';
    $n_sel = (int)($por_perfil[$sel] ?? 0) + count(array_filter($usuarios, fn($x) => !$x['ativo'] && $x['perfil'] === $sel)); ?>
    <div class="px-wrap" id="matriz">
        <!-- lista de perfis -->
        <aside class="px-lista">
            <div class="px-lista-cab"><div><h2>Perfis</h2><small><?= count($perfis) ?> perfis · clique para editar</small></div>
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#modalPerfil" data-chave="" data-nome="" data-descricao="" data-cor="verde"><i class="bi bi-plus-lg me-1"></i>Novo</button></div>
            <?php foreach ($perfis as $k => $p): $np = $k === 'admin' ? $total_perm : count(permissoes_perfil($k)); $pc = round($np / max(1, $total_perm) * 100); ?>
            <a class="px-perfil perfil-borda-<?= e($p['cor'] ?: 'cinza') ?><?= $k === $sel ? ' ativo' : '' ?>" href="?aba=perfis&amp;perfil=<?= e($k) ?>#matriz">
                <span class="px-bola perfil-<?= e($p['cor'] ?: 'cinza') ?>"><i class="bi bi-<?= $k === 'admin' ? 'shield-fill-check' : 'shield-lock' ?>"></i></span>
                <span class="px-tx"><strong><?= e($p['nome']) ?><?= $k === 'admin' ? ' <i class="bi bi-lock-fill px-cad" title="Protegido"></i>' : '' ?></strong>
                    <small><i class="bi bi-people"></i><?= $por_perfil[$k] ?? 0 ?> · <i class="bi bi-key"></i><?= $k === 'admin' ? 'tudo' : $np . ' acessos' ?></small>
                    <span class="px-barra"><i style="width:<?= $pc ?>%"></i></span></span>
                <i class="bi bi-chevron-right px-seta"></i>
            </a>
            <?php endforeach; ?>
        </aside>

        <!-- perfil escolhido -->
        <form method="post" class="px-det" id="formPerms">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="perfil_perms"><input type="hidden" name="chave" value="<?= e($sel) ?>">
            <div class="px-cab perfil-borda-<?= e($ps['cor'] ?: 'cinza') ?>">
                <span class="px-bola grande perfil-<?= e($ps['cor'] ?: 'cinza') ?>"><i class="bi bi-<?= $adm ? 'shield-fill-check' : 'shield-lock' ?>"></i></span>
                <div class="px-cab-tx"><small>Perfil de acesso<?= (int)$ps['sistema'] ? ' · padrão do sistema' : ' · criado pela escola' ?></small><h2><?= e($ps['nome']) ?></h2><p><?= e($ps['descricao'] ?: 'Sem descrição') ?></p>
                    <div class="px-chips"><span><i class="bi bi-people-fill"></i><b><?= $por_perfil[$sel] ?? 0 ?></b> pessoa(s)</span><span><i class="bi bi-key-fill"></i><b id="peN"><?= $adm ? $total_perm : count($tem_sel) ?></b> de <?= $total_perm ?> acessos</span></div></div>
                <div class="px-cab-acoes">
                    <?php if ($adm): ?>
                    <span class="px-protegido"><i class="bi bi-lock-fill"></i>Protegido<small>tem acesso a tudo · não pode ser editado nem excluído</small></span>
                    <?php else: ?>
                    <button class="btn btn-light" type="button" data-bs-toggle="modal" data-bs-target="#modalPerfil" data-chave="<?= e($sel) ?>" data-nome="<?= e($ps['nome']) ?>" data-descricao="<?= e($ps['descricao'] ?? '') ?>" data-cor="<?= e($ps['cor'] ?? '') ?>"><i class="bi bi-pencil me-1"></i>Editar</button>
                    <button class="btn btn-light" type="button" data-bs-toggle="modal" data-bs-target="#modalPerfil" data-chave="" data-nome="Cópia de <?= e($ps['nome']) ?>" data-descricao="<?= e($ps['descricao'] ?? '') ?>" data-cor="<?= e($ps['cor'] ?? '') ?>" data-copiar="<?= e($sel) ?>"><i class="bi bi-copy me-1"></i>Duplicar</button>
                    <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#modalExPerfil"><i class="bi bi-trash3 me-1"></i>Excluir</button>
                    <?php endif; ?>
                </div>
                <div class="px-prog"><i id="peBarra" style="width:<?= round(($adm ? $total_perm : count($tem_sel)) / max(1, $total_perm) * 100) ?>%"></i></div>
            </div>

            <div class="px-ferr">
                <div class="busca-rapida"><i class="bi bi-search"></i><input class="form-control" id="pxBusca" placeholder="Procurar acesso… (ex.: notas, faltas, biblioteca)" aria-label="Procurar acesso"></div>
                <div class="px-filtro" role="group"><button type="button" class="ativo" data-f="">Todos</button><button type="button" data-f="on">Liberados</button><button type="button" data-f="off">Bloqueados</button></div>
            </div>

            <div class="px-grupos">
            <?php foreach (PERMISSOES_CATALOGO as $grupo => $itens_perm): $on_g = count(array_filter(array_keys($itens_perm), fn($pp) => $adm || isset($tem_sel[$pp]))); ?>
                <section class="px-grupo">
                    <header><h3><?= e($grupo) ?></h3><span class="px-g-n"><b><?= $on_g ?></b>/<?= count($itens_perm) ?></span>
                        <?php if (!$adm): ?><label class="px-todos" title="Liberar ou bloquear o grupo todo"><span>todos</span><span class="pc-switch"><input type="checkbox" data-grupo<?= $on_g === count($itens_perm) ? ' checked' : '' ?>><i></i></span></label><?php endif; ?></header>
                    <?php foreach ($itens_perm as $perm => [$rot, $exp]): $on = $adm || isset($tem_sel[$perm]); ?>
                        <label class="px-item<?= $on ? ' on' : '' ?>" data-b="<?= e(norm($rot . ' ' . $exp . ' ' . $grupo)) ?>"><span class="px-st"><i class="bi bi-<?= $on ? 'check-lg' : 'x-lg' ?>"></i></span><span class="min-w-0"><strong><?= e($rot) ?></strong><?php if ($exp): ?><small><?= e($exp) ?></small><?php endif; ?></span>
                            <span class="pc-switch"><input type="checkbox" name="perm[]" value="<?= e($perm) ?>"<?= $on ? ' checked' : '' ?><?= $adm ? ' disabled' : '' ?>><i></i></span></label>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>
            <div class="vazio" id="pxVazio" hidden><i class="bi bi-search"></i>Nenhum acesso com esse nome.</div>
            <?php if (!$adm): ?>
            <div class="pe-salvar px-salvar"><span><i class="bi bi-info-circle me-1"></i>Vale para todas as pessoas com o perfil <b><?= e($ps['nome']) ?></b>.</span>
                <span class="pe-mudou" id="peMudou" hidden>alterações não salvas</span>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2-all me-1"></i>Salvar acessos</button></div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$adm): ?>
    <div class="modal fade" id="modalExPerfil" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content ux-modal">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="perfil_excluir"><input type="hidden" name="chave" value="<?= e($sel) ?>">
        <div class="ux-topo perigo"><span class="ux-ic"><i class="bi bi-shield-x"></i></span><div><small>Excluir perfil</small><h5><?= e($ps['nome']) ?></h5><span><?= count($tem_sel) ?> acessos · <?= $n_sel ?> pessoa(s)</span></div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <?php if ($n_sel): ?>
            <p class="mb-2"><b><?= $n_sel ?> pessoa(s)</b> usam este perfil. Para onde elas vão?</p>
            <select class="form-select" name="mover_para" required><option value="">Escolha o novo perfil…</option>
                <?php foreach ($perfis as $k => $p): if ($k === $sel) continue; ?><option value="<?= e($k) ?>"><?= e($p['nome']) ?> (<?= $k === 'admin' ? 'tudo' : count(permissoes_perfil($k)) . ' acessos' ?>)</option><?php endforeach; ?></select>
            <?php else: ?><p class="mb-0">Ninguém usa este perfil. Ele e os <?= count($tem_sel) ?> acessos marcados serão apagados.</p><?php endif; ?>
            <?php if ((int)$ps['sistema']): ?><p class="small text-secondary mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>É um perfil padrão do sistema. Depois de excluído, ele não volta sozinho.</p><?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" type="submit"><i class="bi bi-trash3 me-1"></i>Excluir perfil</button></div>
    </form></div></div>
    <?php endif; ?>

    <div class="modal fade" id="modalPerfil" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="perfil_salvar"><input type="hidden" name="chave" id="pChave">
        <div class="modal-header"><h5 class="modal-title" id="pTitulo">Perfil</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12"><label class="form-label obrigatorio" for="pNome">Nome do perfil</label><input class="form-control" id="pNome" name="nome" maxlength="80" required placeholder="Ex.: Assistente financeiro"></div>
            <div class="col-12"><label class="form-label" for="pDesc">Descrição</label><input class="form-control" id="pDesc" name="descricao" maxlength="200" placeholder="O que essa pessoa faz na escola"></div>
            <div class="col-md-6"><label class="form-label" for="pCor">Cor</label><select class="form-select" id="pCor" name="cor"><?php foreach (CORES_PERFIL as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6" id="boxCopiar"><label class="form-label" for="pCopiar">Começar com os acessos de</label><select class="form-select" id="pCopiar" name="copiar"><option value="">Nenhum (vazio)</option>
                <?php foreach ($perfis as $k => $p): ?><option value="<?= e($k) ?>"><?= e($p['nome']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar perfil</button></div>
    </form></div></div>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  const busca = document.getElementById("buscaPessoa");
  if (busca) {
    let perfil = "";
    const norm = s => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
    const filtrar = () => {
      const q = norm(busca.value.trim()); let n = 0;
      document.querySelectorAll(".cartao-pessoa").forEach(c => {
        const ok = (!perfil || c.dataset.perfil === perfil) && (!q || c.dataset.busca.includes(q));
        c.hidden = !ok; if (ok) n++;
      });
      semPessoa.hidden = n > 0;
    };
    busca.addEventListener("input", filtrar);
    document.querySelectorAll("#chipsPerfil .chip").forEach(b => b.addEventListener("click", () => {
      document.querySelectorAll("#chipsPerfil .chip").forEach(x => x.classList.toggle("ativo", x === b));
      perfil = b.dataset.perfil; filtrar();
    }));
  }
  const fp = document.getElementById("formPerms");
  if (fp) {
    const tot = ' . count(permissoes_todas()) . ';
    const conta = () => { const n = fp.querySelectorAll("input[name=\'perm[]\']:checked").length; peN.textContent = n; peBarra.style.width = Math.round(n / tot * 100) + "%";
      fp.querySelectorAll(".px-item").forEach(l => { const on = l.querySelector("input").checked; l.classList.toggle("on", on); l.querySelector(".px-st i").className = "bi bi-" + (on ? "check-lg" : "x-lg"); });
      fp.querySelectorAll(".px-grupo").forEach(g => { const cs = [...g.querySelectorAll("input[name=\'perm[]\']")], k = cs.filter(c => c.checked).length; g.querySelector(".px-g-n b").textContent = k; const b = g.querySelector("[data-grupo]"); if (b) b.checked = k === cs.length; }); };
    fp.addEventListener("change", () => { conta(); if (window.peMudou) peMudou.hidden = false; });
    fp.querySelectorAll("[data-grupo]").forEach(b => b.addEventListener("change", e => { e.stopPropagation(); b.closest(".px-grupo").querySelectorAll("input[name=\'perm[]\']").forEach(c => c.checked = b.checked); conta(); if (window.peMudou) peMudou.hidden = false; }));
    let fx = ""; const bx = document.getElementById("pxBusca");
    const filtra = () => { const q = norm2(bx.value.trim()); let vis = 0;
      fp.querySelectorAll(".px-grupo").forEach(g => { let n = 0; g.querySelectorAll(".px-item").forEach(l => { const on = l.querySelector("input").checked; const ok = (!q || l.dataset.b.includes(q)) && (!fx || (fx === "on") === on); l.hidden = !ok; if (ok) n++; }); g.hidden = !n; vis += n; });
      pxVazio.hidden = vis > 0; };
    const norm2 = s => s.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    if (bx) bx.addEventListener("input", filtra);
    fp.querySelectorAll(".px-filtro button").forEach(b => b.onclick = () => { fp.querySelectorAll(".px-filtro button").forEach(x => x.classList.toggle("ativo", x === b)); fx = b.dataset.f; filtra(); });
    conta();
  }
  const mp = document.getElementById("modalPerfil");
  if (mp) mp.addEventListener("show.bs.modal", e => {
    const b = e.relatedTarget;
    pChave.value = b.dataset.chave; pNome.value = b.dataset.nome; pDesc.value = b.dataset.descricao; pCor.value = b.dataset.cor || "verde";
    pTitulo.textContent = b.dataset.chave ? "Editar perfil" : "Novo perfil";
    boxCopiar.hidden = !!b.dataset.chave; pCopiar.value = b.dataset.copiar || "";
    if (b.dataset.copiar) pTitulo.textContent = "Duplicar perfil";
  });
  const mx = document.getElementById("modalExcluir");
  if (mx) mx.addEventListener("show.bs.modal", e => { const b = e.relatedTarget; exId.value = b.dataset.id; exNome.textContent = b.dataset.nome; exInfo.textContent = b.dataset.perfil + " · " + b.dataset.email;
    exAulas.textContent = +b.dataset.aulas ? b.dataset.aulas + " aula(s) ficam sem professor até você atribuir outra pessoa." : "ele não tem aulas atribuídas."; exOk.checked = false; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
