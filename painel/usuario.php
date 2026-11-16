<?php
require __DIR__ . '/../includes/app.php';
exigir('usuarios');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = q_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
if (!$p) { flash('warning', 'Usuário não encontrado.'); redirecionar('usuarios.php'); }
$eu = $id === uid();
$perfis = perfis_lista();

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'dados') {
        $nome = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nome'] ?? '')));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $perfil = (string)($_POST['perfil'] ?? $p['perfil']);
        if (mb_strlen($nome) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('warning', 'Informe nome e um e-mail válido.');
        elseif (q_valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, $id])) flash('warning', 'Esse e-mail já é usado por outra pessoa.');
        else {
            $ativo = !empty($_POST['ativo']) ? 1 : 0;
            if ($eu) { $perfil = $p['perfil']; $ativo = 1; }               // ninguém tira o próprio acesso
            if (!isset($perfis[$perfil])) $perfil = $p['perfil'];
            q('UPDATE usuarios SET nome = ?, nome_exibicao = ?, email = ?, cargo = ?, perfil = ?, ativo = ? WHERE id = ?',
              [mb_substr($nome, 0, 120), trim((string)($_POST['nome_exibicao'] ?? '')) ?: null, $email, trim((string)($_POST['cargo'] ?? '')) ?: null, $perfil, $ativo, $id]);
            if (!empty($_FILES['foto']['name'])) {
                try { q('UPDATE usuarios SET foto = ? WHERE id = ?', [foto_salvar($_FILES['foto'], 'usuarios', $id, $p['foto']), $id]); }
                catch (RuntimeException $e) { flash('warning', 'A foto não foi salva: ' . $e->getMessage()); }
            } elseif (!empty($_POST['remover_foto']) && $p['foto']) { foto_apagar($p['foto']); q('UPDATE usuarios SET foto = NULL WHERE id = ?', [$id]); }
            registrar_log('usuario_editado', "#$id $nome · " . perfil_nome($perfil) . ($ativo ? '' : ' (desativado)'));
            flash('success', 'Dados de ' . $nome . ' salvos.');
        }
        redirecionar("usuario.php?id=$id");
    }

    if ($acao === 'resetar' && !$eu) {
        $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $senha = '';
        for ($i = 0; $i < 10; $i++) $senha .= $c[random_int(0, strlen($c) - 1)];
        $senha .= random_int(1, 9);
        q('UPDATE usuarios SET senha_hash = ?, trocar_senha = 1 WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
        registrar_log('senha_redefinida', $p['email']);
        $_SESSION['senha_mostrar'] = [$p['nome'], $p['email'], $senha];
        redirecionar("usuario.php?id=$id");
    }

    redirecionar("usuario.php?id=$id");
}

$mostrar = $_SESSION['senha_mostrar'] ?? null;
unset($_SESSION['senha_mostrar']);
$atribs = q_todos('SELECT d.nome AS disciplina, t.serie, t.letra, c.nome AS curso FROM atribuicoes a JOIN disciplinas d ON d.id = a.disciplina_id
                    JOIN turmas t ON t.id = a.turma_id JOIN cursos c ON c.id = t.curso_id WHERE a.usuario_id = ? ORDER BY t.serie, t.letra, d.nome', [$id]);
$siglas = []; foreach (q_todos('SELECT nome, sigla FROM cursos') as $c) $siglas[$c['nome']] = $c['sigla'];

$titulo = $p['nome'];
$subtitulo = cargo_usuario($p) . ' · ' . perfil_nome($p['perfil']);
$menu = 'usuarios';
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="d-flex gap-2 mb-3"><a class="btn btn-light" href="usuarios.php"><i class="bi bi-arrow-left me-1"></i>Usuários</a></div>

<?php if ($mostrar): ?>
<div class="alert alert-success">
    <h3 class="h6 mb-2"><i class="bi bi-key-fill me-1"></i>Acesso de <?= e($mostrar[0]) ?></h3>
    Envie estes dados para a pessoa. A senha aparece <strong>só agora</strong> e será trocada no primeiro acesso.
    <div class="d-flex flex-wrap gap-3 mt-2 p-2 bg-white rounded border">
        <span>Endereço: <strong><?= e((isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '') . dirname($_SERVER['SCRIPT_NAME'], 2) . '/login.php') ?></strong></span>
        <span>E-mail: <strong><?= e($mostrar[1]) ?></strong></span>
        <span>Senha provisória: <strong class="font-monospace user-select-all"><?= e($mostrar[2]) ?></strong></span>
    </div>
</div>
<?php endif; ?>

<section class="uf-topo">
    <label class="uf-av" for="foto" title="Trocar a foto"><span id="previaTopo"><?= avatar($p, 'usuario', 'avatar-xl') ?></span><i class="bi bi-camera-fill"></i></label>
    <div class="uf-quem"><h2><?= e($p['nome']) ?></h2><p><?= e(cargo_usuario($p)) ?> · <?= e($p['email']) ?></p>
        <div class="uf-chips">
            <span class="<?= $p['ativo'] ? 'ok' : 'off' ?>"><i class="bi bi-circle-fill"></i><?= $p['ativo'] ? 'Acesso ativo' : 'Acesso desativado' ?></span>
            <span><i class="bi bi-clock-history"></i><?= $p['ultimo_acesso'] ? 'Último acesso ' . data_hora_br($p['ultimo_acesso']) : ($p['trocar_senha'] ? 'Ainda não entrou' : 'Sem acesso registrado') ?></span>
            <?php if ($atribs): ?><span><i class="bi bi-easel2"></i><?= count($atribs) ?> aula<?= count($atribs) > 1 ? 's' : '' ?> atribuída<?= count($atribs) > 1 ? 's' : '' ?></span><?php endif; ?>
        </div></div>
    <div class="uf-nivel"><i class="bi bi-shield-check"></i><div><small>Perfil de acesso</small><b><?= e(perfil_nome($p['perfil'])) ?></b></div></div>
</section>

<div class="uf-grade">
    <form method="post" enctype="multipart/form-data" class="card uf-card">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="dados"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="file" class="d-none" id="foto" name="foto" data-recorte="1:1" data-saida="800" data-recorte-titulo="Enquadrar a foto" accept="image/jpeg,image/png,image/webp">
        <div class="card-header"><h2><i class="bi bi-person-vcard me-1"></i>Dados do usuário</h2>
            <?php if ($p['foto']): ?><div class="form-check ms-auto small"><input class="form-check-input" type="checkbox" name="remover_foto" value="1" id="rm"><label class="form-check-label" for="rm">Remover foto</label></div><?php endif; ?></div>
        <div class="card-body row g-3">
            <div class="col-12"><label class="form-label obrigatorio" for="u_nome">Nome completo</label><div class="uf-campo"><i class="bi bi-person"></i><input class="form-control" id="u_nome" name="nome" value="<?= e($p['nome']) ?>" required></div></div>
            <div class="col-md-6"><label class="form-label" for="u_exib">Nome de exibição</label><div class="uf-campo"><i class="bi bi-person-badge"></i><input class="form-control" id="u_exib" name="nome_exibicao" value="<?= e($p['nome_exibicao'] ?? '') ?>" maxlength="60" placeholder="<?= e(primeiro_ultimo($p['nome'])) ?>"></div></div>
            <div class="col-md-6"><label class="form-label" for="u_cargo">Função (assinatura)</label><div class="uf-campo"><i class="bi bi-pen"></i><input class="form-control" id="u_cargo" name="cargo" value="<?= e($p['cargo'] ?? '') ?>" maxlength="80"></div></div>
            <div class="col-12"><label class="form-label obrigatorio" for="u_email">E-mail de acesso</label><div class="uf-campo"><i class="bi bi-envelope"></i><input class="form-control" type="email" id="u_email" name="email" value="<?= e($p['email']) ?>" required></div></div>
            <div class="col-12">
                <div class="uf-perfil-box">
                    <label class="form-label" for="u_perfil">Perfil de acesso</label>
                    <div class="uf-campo"><i class="bi bi-shield-check"></i><select class="form-select" id="u_perfil" name="perfil"<?= $eu ? ' disabled' : '' ?>>
                        <?php foreach ($perfis as $k => $pf): ?><option value="<?= e($k) ?>"<?= $p['perfil'] === $k ? ' selected' : '' ?>><?= e($pf['nome']) ?></option><?php endforeach; ?></select></div>
                    <p><i class="bi bi-info-circle me-1"></i><?= $eu ? 'Você não pode mudar o seu próprio perfil. ' : '' ?><?= e(explode(' ', $p['nome_exibicao'] ?: $p['nome'])[0]) ?> acessa tudo o que o perfil <b id="perfilNome"><?= e(perfil_nome($p['perfil'])) ?></b> acessa. O que cada perfil pode fazer é definido em <a href="usuarios.php?aba=perfis">Usuários › Perfis e permissões</a>.</p>
                </div>
                <label class="uf-switch" for="u_ativo"><div><b>Acesso ativo</b><small>Desligue para impedir a entrada no sistema, sem apagar o histórico.</small></div>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="u_ativo"<?= $p['ativo'] ? ' checked' : '' ?><?= $eu ? ' disabled' : '' ?>></div></label>
            </div>
        </div>
        <div class="uf-rodape"><small><?= !empty($p['criado_em']) ? 'Cadastrado em ' . data_br(substr((string)$p['criado_em'], 0, 10)) : '' ?></small><button class="btn btn-primary ms-auto px-4" type="submit"><i class="bi bi-check2 me-1"></i>Salvar dados</button></div>
    </form>
    <div class="uf-lado">
        <?php if (!$eu): ?>
        <form method="post" class="card uf-card" data-confirmar="Gerar nova senha temporária para <?= e($p['nome']) ?>? A senha atual deixa de funcionar." data-botao="Gerar senha">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="resetar"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="card-body">
                <div class="uf-senha"><i class="bi bi-key"></i><div class="flex-grow-1"><b>Esqueceu a senha?</b><small>Gera uma senha temporária. No primeiro acesso, a pessoa confirma os dados e cria a senha dela.</small></div></div>
                <button class="btn btn-light w-100 mt-3" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Gerar senha temporária</button>
            </div>
        </form>
        <?php endif; ?>
        <div class="card uf-card"><div class="card-header"><h2><i class="bi bi-easel2 me-1"></i>Aulas atribuídas</h2><a class="ms-auto small" href="configuracoes.php?aba=atribuicoes">Gerenciar</a></div>
            <div class="card-body uf-aulas"><?php if ($atribs): foreach ($atribs as $a): ?><span><?= e($a['serie'] . 'º ' . $a['letra'] . ' ' . ($siglas[$a['curso']] ?? '')) ?> <em>· <?= e($a['disciplina']) ?></em></span><?php endforeach; else: ?><small class="text-secondary">Nenhuma aula atribuída.</small><?php endif; ?></div></div>
    </div>
</div>
<?php
$script_pagina = '<script>
(function () {
  const f = document.getElementById("foto");
  f && f.addEventListener("change", () => { const a = f.files[0]; if (a) previaTopo.innerHTML = `<img class="avatar avatar-xl" src="${URL.createObjectURL(a)}" alt="">`; });
  const sel = document.getElementById("u_perfil"), nome = document.getElementById("perfilNome");
  sel && sel.addEventListener("change", () => { nome.textContent = sel.options[sel.selectedIndex].text; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
