<?php
/* Volta do Google: confere a resposta e entra com o usuário de mesmo e-mail */
require __DIR__ . '/includes/app.php';

$cfg = google_cfg();
$sess = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);

try {
    if (!$cfg['ativo']) throw new RuntimeException('O login com Google ainda não foi ativado pela escola.');
    if (isset($_GET['error'])) throw new RuntimeException($_GET['error'] === 'access_denied' ? 'Login com Google cancelado.' : 'O Google não concluiu o login. Tente de novo.');
    $state = (string)($_GET['state'] ?? '');
    if (!$sess || $state === '' || !hash_equals($sess['state'], $state) || time() - $sess['t'] > 600) {
        throw new RuntimeException('O pedido de login expirou ou não é válido. Toque em "Entrar com Google" de novo.');
    }
    $codigo = (string)($_GET['code'] ?? '');
    if ($codigo === '') throw new RuntimeException('O Google não devolveu o código de acesso.');

    $t = google_trocar_codigo($codigo, $cfg);
    if (!hash_equals($sess['nonce'], (string)($t['nonce'] ?? ''))) throw new RuntimeException('Resposta do Google inválida (nonce).');
    $email = mb_strtolower(trim((string)($t['email'] ?? '')));
    if ($email === '' || empty($t['email_verified'])) throw new RuntimeException('Essa conta Google não tem um e-mail confirmado.');

    $u = q_um('SELECT * FROM usuarios WHERE LOWER(email) = ?', [$email]);
    if (!$u) {
        registrar_log('login_google_negado', $email);
        throw new RuntimeException('A conta ' . $email . ' não está cadastrada no sistema. Peça à coordenação para cadastrar esse e-mail no seu usuário.');
    }
    if (!$u['ativo']) throw new RuntimeException('Este acesso está desativado. Procure a coordenação.');

    $destino = login_destino();
    login_iniciar_sessao($u, 'google');
    redirecionar($destino);
} catch (RuntimeException $e) {
    $_SESSION['login_aviso'] = ['erro', $e->getMessage()];
    redirecionar('login.php');
}
