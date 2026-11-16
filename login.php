<?php
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

if (usuario()) redirecionar('painel/index.php');

$aviso = $_SESSION['login_aviso'] ?? null; unset($_SESSION['login_aviso']);
$email = '';

if (eh_post()) {
    csrf_verificar();
    $email = trim((string)($_POST['email'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');

    // trava simples contra tentativas repetidas
    $tent = $_SESSION['tentativas'] ?? ['n' => 0, 't' => time()];
    if (time() - $tent['t'] > 900) $tent = ['n' => 0, 't' => time()];

    if ($tent['n'] >= 6) {
        $aviso = ['erro', 'Muitas tentativas seguidas. Aguarde 15 minutos e tente novamente.'];
    } else {
        $u = q_um('SELECT * FROM usuarios WHERE email = ?', [$email]);
        if ($u && $u['ativo'] && password_verify($senha, $u['senha_hash'])) {
            if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
            }
            $destino = login_destino();
            login_iniciar_sessao($u, 'senha');
            redirecionar($destino);
        }
        $tent['n']++;
        $_SESSION['tentativas'] = $tent;
        usleep(400000);
        $aviso = ['erro', ($u && !$u['ativo']) ? 'Este acesso está desativado. Procure a coordenação.' : 'E-mail ou senha incorretos. Confira e tente de novo.'];
    }
}
$google = google_cfg();
$avisos_flash = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
$G = '<svg viewBox="0 0 48 48" width="22" height="22" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Entrar · <?= h($ESCOLA['nome']) ?></title>
    <meta name="theme-color" content="#062a14">
    <meta name="robots" content="noindex">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link href="assets/css/fontes.css?v=3" rel="stylesheet">
    <link href="assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/login.css?v=16" rel="stylesheet">
</head>
<body>
<main class="lx">
    <!-- lado da escola -->
    <section class="lx-lado">
        <span class="lx-canto c1"></span><span class="lx-canto c2"></span><span class="lx-canto c3"></span><span class="lx-canto c4"></span>
        <a class="lx-marca" href="index.php"><img src="<?= h($ESCOLA['logo_branca'] ?? 'assets/img/logo-branca.png') ?>" alt="<?= h($ESCOLA['nome']) ?>" width="1400" height="451"></a>
        <div class="lx-texto">
            <h1>Tudo da escola num só lugar</h1>
            <p>Entre na sua conta para fazer a chamada, lançar notas, corrigir avaliações e acompanhar cada aluno.</p>
            <ul class="lx-recursos">
                <li><span><i class="bi bi-calendar2-check"></i></span><div><b>Coleta de faltas</b><small>Chamada rápida pelo celular</small></div></li>
                <li><span><i class="bi bi-ui-checks-grid"></i></span><div><b>Correção de provas</b><small>Gabarito lido pela câmera</small></div></li>
                <li><span><i class="bi bi-person-vcard"></i></span><div><b>Ficha do aluno</b><small>Notas, frequência e ocorrências</small></div></li>
            </ul>
        </div>
    </section>

    <!-- formulário -->
    <section class="lx-form">
        <a class="lx-marca-cel" href="index.php"><img src="<?= h($ESCOLA['logo_branca'] ?? 'assets/img/logo-branca.png') ?>" alt="<?= h($ESCOLA['nome']) ?>" width="1400" height="451"></a>
        <div class="lx-topo-cel" aria-hidden="true">
            <span class="lx-tc-c c1"></span><span class="lx-tc-c c2"></span><span class="lx-tc-c c3"></span><span class="lx-tc-c c4"></span>
            <span class="lx-tc-marca"><img src="<?= h($ESCOLA['logo_branca'] ?? 'assets/img/logo-branca.png') ?>" alt="" width="1400" height="451"></span>
            <span class="lx-tc-selo"><i class="bi bi-shield-lock-fill"></i>Área da equipe escolar</span>
            <p class="lx-tc-tit">Tudo da escola<br>num só lugar</p>
            <div class="lx-tc-ics"><span><i class="bi bi-calendar2-check"></i>Chamada</span><span><i class="bi bi-ui-checks-grid"></i>Provas</span><span><i class="bi bi-person-vcard"></i>Alunos</span></div>
        </div>
        <div class="lx-caixa" id="telaEntrar">
            <img class="lx-logo" src="assets/img/logo-escola-2026.png" alt="<?= h($ESCOLA['nome']) ?>" width="1367" height="438">
            <h2>Entrar na conta</h2>
            <p class="lx-sub">Use o e-mail e a senha cadastrados pela coordenação.</p>
            <?php foreach ($avisos_flash as $f): $ruim = in_array($f[0], ['danger', 'warning'], true); ?><div class="lx-aviso <?= $ruim ? 'atencao' : 'ok' ?>" role="status"><i class="bi bi-<?= $ruim ? 'info-circle-fill' : 'check-circle-fill' ?>"></i><span><?= !empty($f[2]) ? $f[1] : h($f[1]) ?></span></div><?php endforeach; ?>
            <?php if ($aviso): ?><div class="lx-aviso erro" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><span><?= h($aviso[1]) ?></span></div><?php endif; ?>
            <form method="post" novalidate id="formLogin">
                <?= csrf_campo() ?>
                <label for="email">E-mail</label>
                <div class="lx-campo"><i class="bi bi-envelope"></i><input type="email" id="email" name="email" value="<?= h($email) ?>" autocomplete="username" inputmode="email" placeholder="seu@email.com" required></div>
                <label for="senha">Senha</label>
                <div class="lx-campo"><i class="bi bi-lock"></i><input type="password" id="senha" name="senha" autocomplete="current-password" placeholder="Digite sua senha" required>
                    <button type="button" id="verSenha" class="lx-ver" aria-label="Mostrar senha" aria-pressed="false"><i class="bi bi-eye-fill"></i></button></div>
                <p class="lx-caps" id="avisoCaps" hidden><i class="bi bi-capslock-fill"></i>Caps Lock ligado</p>
                <div class="lx-linha"><a href="#esqueci" id="linkEsqueci">Esqueceu sua senha?</a></div>
                <button class="lx-entrar" type="submit" id="btEntrar"><i class="bi bi-box-arrow-in-right"></i><span>Entrar</span></button>
            </form>
            <div class="lx-ou"><span>ou</span></div>
            <a class="lx-google" href="google_entrar.php" id="btGoogle"><?= $G ?><span>Entrar com Google</span></a>
            <p class="lx-nota">Use a conta Google do mesmo e-mail cadastrado na escola.</p>
            <a class="lx-voltar" href="index.php"><i class="bi bi-arrow-left"></i>Voltar para o site</a>
        </div>

        <div class="lx-caixa" id="telaEsqueci" hidden>
            <span class="lx-icone"><i class="bi bi-key-fill"></i></span>
            <h2>Esqueceu a senha?</h2>
            <p class="lx-sub">A troca de senha é feita pela <strong>gestão da escola</strong>. A coordenação gera uma senha provisória e, no primeiro acesso, você cria a sua.</p>
            <ul class="lx-contatos">
                <?php if ($ESCOLA['email']): ?><li><span><i class="bi bi-envelope"></i></span><div><small>E-mail da escola</small><a href="mailto:<?= h($ESCOLA['email']) ?>?subject=<?= rawurlencode('Recuperar acesso ao sistema escolar') ?>"><?= h($ESCOLA['email']) ?></a></div></li><?php endif; ?>
                <?php if (!empty($ESCOLA['telefone'])): ?><li><span><i class="bi bi-telephone"></i></span><div><small>Telefone</small><a href="tel:<?= h(preg_replace('/\D/', '', $ESCOLA['telefone'])) ?>"><?= h($ESCOLA['telefone']) ?></a></div></li><?php endif; ?>
                <li><span><i class="bi bi-clock"></i></span><div><small>Atendimento</small><?= h($ESCOLA['atendimento'] ?? '') ?></div></li>
            </ul>
            <button type="button" class="lx-secundario" id="voltarLogin"><i class="bi bi-arrow-left"></i>Voltar para o login</button>
        </div>
    </section>
</main>
<script>
(function () {
    const s = document.getElementById("senha"), v = document.getElementById("verSenha"), em = document.getElementById("email");
    v.addEventListener("click", () => { const m = s.type === "password"; s.type = m ? "text" : "password"; v.innerHTML = m ? '<i class="bi bi-eye-slash-fill"></i>' : '<i class="bi bi-eye-fill"></i>'; v.setAttribute("aria-label", m ? "Ocultar senha" : "Mostrar senha"); v.setAttribute("aria-pressed", m); s.focus(); });
    s.addEventListener("keyup", e => { document.getElementById("avisoCaps").hidden = !(e.getModifierState && e.getModifierState("CapsLock")); });
    try { const g = localStorage.getItem("login_email"); if (g && !em.value) em.value = g; } catch (e) {}
    const f = document.getElementById("formLogin"), bt = document.getElementById("btEntrar");
    f.addEventListener("submit", e => {
        if (!em.value.trim() || !s.value) { e.preventDefault(); const c = em.value.trim() ? s : em; c.focus(); c.parentElement.classList.add("falta"); return; }
        try { localStorage.setItem("login_email", em.value); } catch (e) {}
        bt.disabled = true; bt.classList.add("enviando"); bt.querySelector("span").textContent = "Entrando…";
    });
    [em, s].forEach(c => c.addEventListener("input", () => c.parentElement.classList.remove("falta")));
    const g = document.getElementById("btGoogle"); if (g) g.addEventListener("click", () => { g.classList.add("enviando"); g.querySelector("span").textContent = "Abrindo o Google…"; });
    const tE = document.getElementById("telaEntrar"), tR = document.getElementById("telaEsqueci");
    const mostrar = q => { tE.hidden = q; tR.hidden = !q; (q ? document.getElementById("voltarLogin") : em).focus(); };
    document.getElementById("linkEsqueci").addEventListener("click", e => { e.preventDefault(); mostrar(true); });
    document.getElementById("voltarLogin").addEventListener("click", () => mostrar(false));
    if (location.hash === "#esqueci") mostrar(true);
    addEventListener("pageshow", e => { if (e.persisted) { bt.disabled = false; bt.classList.remove("enviando"); bt.querySelector("span").textContent = "Entrar"; } });
})();
</script>
</body>
</html>
