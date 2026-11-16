<?php
/* =====================================================================
 *  Login com Google (OAuth 2.0 / OpenID Connect)
 *  - O administrador cola o "ID do cliente" e a "Chave secreta" em
 *    Painel › Administração › Login com Google (ou define GOOGLE_CLIENT_ID e
 *    GOOGLE_CLIENT_SECRET no config.php).
 *  - Só entra quem já tem usuário ativo com o MESMO e-mail da conta Google.
 * ===================================================================== */

function google_cfg(): array
{
    $id = defined('GOOGLE_CLIENT_ID') ? (string)GOOGLE_CLIENT_ID : (string)config_ler('google_id', '');
    $seg = defined('GOOGLE_CLIENT_SECRET') ? (string)GOOGLE_CLIENT_SECRET : (string)config_ler('google_segredo', '');
    $ligado = config_ler('google_ativo', '1') === '1';
    return ['id' => trim($id), 'segredo' => trim($seg), 'ligado' => $ligado, 'no_config' => defined('GOOGLE_CLIENT_ID'),
            'ativo' => $ligado && $id !== '' && $seg !== ''];
}

/** Endereço do site (https://dominio/pasta) */
function site_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $raiz = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));
    $doc = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $pasta = ($doc !== '' && str_starts_with($raiz, $doc)) ? substr($raiz, strlen($doc)) : '';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($pasta, '/');
}

function google_retorno_url(): string { return site_base_url() . '/google_retorno.php'; }

/** Inicia a sessão do usuário (usado no login por senha e pelo Google) */
function login_iniciar_sessao(array $u, string $como): void
{
    session_regenerate_id(true);
    unset($_SESSION['tentativas'], $_SESSION['google_oauth']);
    $_SESSION['usuario'] = $u;
    $_SESSION['ultimo_uso'] = time();
    q('UPDATE usuarios SET ultimo_acesso = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    registrar_log('login', $como === 'google' ? 'Entrou no sistema com a conta Google' : 'Entrou no sistema');
}

/** Para onde ir depois de entrar: a página do painel que a pessoa tentou abrir, ou o início do painel */
function login_destino(): string
{
    $v = (string)($_SESSION['voltar'] ?? ''); unset($_SESSION['voltar']);
    return preg_match('#^painel/[a-z0-9_]+\.php(\?[\w=&%.\-]{0,200})?$#', $v) && is_file(dirname(__DIR__) . '/' . strtok($v, '?')) ? $v : 'painel/index.php';
}

/** Lê o conteúdo (payload) de um JWT */
function jwt_payload(string $jwt): ?array
{
    $p = explode('.', $jwt);
    if (count($p) !== 3) return null;
    $dados = json_decode((string)base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4)), true);
    return is_array($dados) ? $dados : null;
}

/** Troca o código pelo token e devolve os dados confirmados da conta Google */
function google_trocar_codigo(string $codigo, array $cfg): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('O servidor não tem a extensão cURL do PHP ativa.');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query(['code' => $codigo, 'client_id' => $cfg['id'], 'client_secret' => $cfg['segredo'],
                                                'redirect_uri' => google_retorno_url(), 'grant_type' => 'authorization_code']),
    ]);
    $resp = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Não foi possível falar com o Google (' . $err . ').');
    $j = json_decode((string)$resp, true) ?: [];
    if ($st !== 200 || empty($j['id_token'])) {
        $e = (string)($j['error'] ?? '');
        if ($e === 'invalid_client') throw new RuntimeException('O ID do cliente ou a chave secreta do Google estão incorretos. Confira em Administração › Login com Google.');
        if ($e === 'redirect_uri_mismatch') throw new RuntimeException('O endereço de retorno não está autorizado no Google Cloud. Copie o endereço mostrado em Administração › Login com Google.');
        throw new RuntimeException('O Google recusou o login (' . ($e ?: 'erro ' . $st) . '). Tente de novo.');
    }
    // O token veio direto do Google por HTTPS com a nossa chave secreta; ainda assim conferimos tudo.
    $t = jwt_payload((string)$j['id_token']);
    if (!$t) throw new RuntimeException('Resposta do Google inválida.');
    if (!in_array($t['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) throw new RuntimeException('Resposta do Google inválida (emissor).');
    if (($t['aud'] ?? '') !== $cfg['id']) throw new RuntimeException('Resposta do Google inválida (cliente).');
    if ((int)($t['exp'] ?? 0) < time() - 60) throw new RuntimeException('O login com o Google expirou. Tente de novo.');
    return $t;
}
