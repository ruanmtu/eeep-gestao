<?php
/* Leva a pessoa para a tela de login do Google */
require __DIR__ . '/includes/app.php';

$cfg = google_cfg();
if (!$cfg['ativo']) { flash('warning', 'O login com Google ainda não foi ativado pela escola.'); redirecionar('login.php'); }

$_SESSION['google_oauth'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 't' => time()];
$dica = trim((string)($_GET['email'] ?? ''));
$params = [
    'client_id' => $cfg['id'],
    'redirect_uri' => google_retorno_url(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $_SESSION['google_oauth']['state'],
    'nonce' => $_SESSION['google_oauth']['nonce'],
    'prompt' => 'select_account',
];
if (filter_var($dica, FILTER_VALIDATE_EMAIL)) $params['login_hint'] = $dica;
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
