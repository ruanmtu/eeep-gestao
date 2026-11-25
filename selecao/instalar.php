<?php
/* Primeiro acesso: cria o administrador da seleção.
 * Só funciona enquanto não existe nenhum usuário, e só para quem é administrador
 * do sistema da escola (confere o e-mail e a senha de lá). */
require __DIR__ . '/includes/base.php';
if (q_valor('SELECT COUNT(*) FROM sel_usuarios')) redirecionar('index.php');
$erro = '';
if (eh_post()) {
    csrf_verificar();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? ''))); $senha = (string)($_POST['senha'] ?? '');
    $x = null;
    try { $x = q_um("SELECT nome, email, senha_hash, perfil, ativo FROM usuarios WHERE email = ?", [$email]); } catch (Throwable $e) {}
    if ($x && $x['ativo'] && in_array($x['perfil'], ['admin', 'dev'], true) && password_verify($senha, $x['senha_hash'])) {
        q('INSERT INTO sel_usuarios (nome, email, senha_hash, papel, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)', [$x['nome'], $email, password_hash($senha, PASSWORD_DEFAULT), 'admin', agora()]);
        session_regenerate_id(true);
        $_SESSION['sel_uid'] = (int)db()->lastInsertId(); $_SESSION['sel_visto'] = time();
        registrar('instalou', $email);
        flash('ok', 'Pronto! Você é o administrador da seleção. Cadastre agora a equipe em Usuários.');
        redirecionar('configuracoes.php');
    }
    $erro = 'Use o e-mail e a senha de um administrador do sistema da escola.';
    usleep(600000);
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Primeiro acesso · Seleção</title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"><link href="assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet"><link href="assets/css/selecao.css?v=<?= SEL_VERSAO ?>" rel="stylesheet"></head>
<body class="sl-login"><div class="sl-login-wrap">
<div class="sl-login-logo"><img src="assets/img/logo-branca.png" alt="EEEP Venceslau Vieira Batista" width="1400" height="451"></div>
<form method="post" class="sl-login-caixa"><?= csrf_campo() ?>
    <h1>Primeiro <b>acesso</b></h1>
    <p class="sl-sub">Para ativar o sistema da seleção, entre com o e-mail e a senha de um <b>administrador do sistema da escola</b>. Essa conta vira a administradora da seleção (com senha própria daqui para frente).</p>
    <?php if ($erro): ?><div class="sl-aviso erro"><i class="bi bi-exclamation-octagon"></i><span><?= e($erro) ?></span></div><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="email">E-mail</label><input class="form-control" id="email" name="email" type="email" required></div>
    <div class="mb-3"><label class="form-label" for="senha">Senha</label><input class="form-control" id="senha" name="senha" type="password" required></div>
    <button class="btn btn-primary w-100 py-2">Ativar</button>
</form></div></body></html>
