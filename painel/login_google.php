<?php
/* Login com Google: credenciais do Google Cloud e passo a passo */
require __DIR__ . '/../includes/app.php';
exigir('usuarios');

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar') {
        $id = preg_replace('/\s+/', '', (string)($_POST['google_id'] ?? ''));
        $seg = preg_replace('/\s+/', '', (string)($_POST['google_segredo'] ?? ''));
        if ($id !== '' && !preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $id)) {
            flash('warning', 'O ID do cliente parece incompleto. Ele termina em ".apps.googleusercontent.com". Copie de novo no Google Cloud.');
            redirecionar('login_google.php');
        }
        if ($seg !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $seg)) {
            flash('warning', 'A chave secreta colada tem caracteres estranhos. Copie de novo (geralmente começa com "GOCSPX-").');
            redirecionar('login_google.php');
        }
        if (!empty($_POST['apagar'])) { config_gravar('google_id', null); config_gravar('google_segredo', null); }
        else {
            if ($id !== '') config_gravar('google_id', $id);
            if ($seg !== '') config_gravar('google_segredo', $seg);
        }
        config_gravar('google_ativo', !empty($_POST['ligado']) ? '1' : '0');
        registrar_log('google_config', 'Configuração do login com Google alterada');
        flash('success', 'Login com Google salvo.');
    }
    redirecionar('login_google.php');
}

$g = google_cfg();
$origem = site_base_url();
$retorno = google_retorno_url();
$https = str_starts_with($origem, 'https://');
$temporario = (bool)preg_match('/hostingersite\.com|localhost|127\.0\.0\.1/i', $origem);
$n_gmail = (int)q_valor("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND (LOWER(email) LIKE '%@gmail.com' OR LOWER(email) LIKE '%@googlemail.com')");
$n_total = (int)q_valor('SELECT COUNT(*) FROM usuarios WHERE ativo = 1');
$seg_mask = $g['segredo'] !== '' ? substr($g['segredo'], 0, 7) . str_repeat('•', 10) . substr($g['segredo'], -3) : '';

$titulo = 'Login com Google';
$subtitulo = 'Deixe professores e gestão entrarem com a conta Google, sem digitar senha.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="row g-3">
    <div class="col-xl-5 order-xl-2">
        <div class="card gg-estado <?= $g['ativo'] ? 'ligado' : '' ?>">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="gg-g"><svg viewBox="0 0 48 48" width="30" height="30" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg></span>
                <div class="flex-grow-1">
                    <strong class="d-block"><?= $g['ativo'] ? 'Ativo na tela de login' : ($g['id'] && $g['segredo'] ? 'Configurado, mas desligado' : 'Ainda não configurado') ?></strong>
                    <small class="text-secondary"><?= $g['ativo'] ? 'O botão "Entrar com Google" já aparece para todos.' : 'Siga o passo a passo e cole as duas informações abaixo.' ?></small>
                </div>
                <?php if ($g['ativo']): ?><a class="btn btn-sm btn-light" href="../login.php" target="_blank" rel="noopener">Ver <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
            </div>
        </div>

        <form method="post" class="card mt-3">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar">
            <div class="card-header"><h2><i class="bi bi-key me-1"></i>Credenciais do Google</h2></div>
            <div class="card-body">
                <?php if ($g['no_config']): ?><div class="alert alert-info small">As credenciais estão definidas no <code>config.php</code> e têm prioridade sobre este formulário.</div><?php endif; ?>
                <label class="form-label" for="gId">ID do cliente</label>
                <input class="form-control mb-3 font-monospace small" id="gId" name="google_id" value="<?= e($g['id']) ?>" placeholder="123456789-abc….apps.googleusercontent.com" autocomplete="off" spellcheck="false">
                <label class="form-label" for="gSeg">Chave secreta do cliente</label>
                <input class="form-control font-monospace small" id="gSeg" name="google_segredo" type="password" placeholder="<?= $seg_mask ? e($seg_mask) . '  (deixe vazio para manter)' : 'GOCSPX-…' ?>" autocomplete="new-password" spellcheck="false">
                <div class="form-text">Trate como senha: não mande em grupos nem por mensagem.</div>
                <label class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="ligado" value="1"<?= $g['ligado'] ? ' checked' : '' ?>> Mostrar o botão "Entrar com Google" na tela de login</label>
                <?php if ($g['id'] || $g['segredo']): ?><label class="form-check small mt-2 text-danger"><input class="form-check-input" type="checkbox" name="apagar" value="1"> Apagar as credenciais salvas</label><?php endif; ?>
                <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-check2 me-1"></i>Salvar</button>
            </div>
        </form>

        <div class="card mt-3">
            <div class="card-header"><h2><i class="bi bi-people me-1"></i>Quem consegue entrar</h2></div>
            <div class="card-body small">
                <p class="mb-2">Só entra quem já tem usuário <strong>ativo</strong> no sistema com o <strong>mesmo e-mail</strong> da conta Google. Ninguém novo é cadastrado sozinho.</p>
                <p class="mb-0 text-secondary"><?= $n_gmail ?> de <?= $n_total ?> usuários ativos usam e-mail do Gmail. Os outros (ex.: e-mail da escola) só entram pelo Google se aquele e-mail também for uma conta Google. Ajuste o e-mail de cada um em <a href="usuarios.php">Usuários e permissões</a>.</p>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-list-ol me-1"></i>Passo a passo no Google Cloud</h2></div>
            <div class="card-body">
                <?php if (!$https || $temporario): ?>
                <div class="alert alert-warning small d-flex gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div><?= !$https ? 'O site não está abrindo com <strong>https://</strong>. O Google só aceita endereços seguros.' : 'Você está usando um endereço <strong>provisório</strong>. Funciona, mas quando trocar para o domínio definitivo será preciso cadastrar o novo endereço no Google (passo 4).' ?></div></div>
                <?php endif; ?>
                <ol class="gg-passos">
                    <li><b>Abra o Google Cloud</b><span>Entre em <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com</a> com a conta Google da escola e crie um projeto (ex.: <em>Site EEEP Venceslau</em>).</span></li>
                    <li><b>Tela de consentimento</b><span>Menu › <em>APIs e serviços</em> › <em>Tela de consentimento OAuth</em> (ou <em>Google Auth Platform</em>). Tipo de usuário: <strong>Externo</strong>. Preencha o nome do app (<em><?= e($ESCOLA['nome']) ?></em>), o e-mail de suporte e, em domínios autorizados, o domínio do site. Depois, em <em>Público</em>, clique em <strong>Publicar app</strong> (senão só as contas de teste conseguem entrar).</span></li>
                    <li><b>Criar a credencial</b><span><em>APIs e serviços</em> › <em>Credenciais</em> › <strong>Criar credenciais</strong> › <strong>ID do cliente OAuth</strong> › tipo <strong>Aplicativo da Web</strong>.</span></li>
                    <li><b>Cole estes dois endereços</b>
                        <span>Em <em>Origens JavaScript autorizadas</em>:</span>
                        <div class="gg-copiar"><code id="cOrigem"><?= e($origem) ?></code><button type="button" class="btn btn-sm btn-light" data-copiar="cOrigem"><i class="bi bi-copy"></i> Copiar</button></div>
                        <span>Em <em>URIs de redirecionamento autorizados</em>:</span>
                        <div class="gg-copiar"><code id="cRetorno"><?= e($retorno) ?></code><button type="button" class="btn btn-sm btn-light" data-copiar="cRetorno"><i class="bi bi-copy"></i> Copiar</button></div>
                    </li>
                    <li><b>Copie o ID e a chave secreta</b><span>Ao clicar em <strong>Criar</strong>, o Google mostra o <em>ID do cliente</em> e a <em>Chave secreta do cliente</em>. Cole os dois aqui ao lado e salve.</span></li>
                    <li><b>Teste</b><span>Abra a tela de login numa janela anônima e toque em <strong>Entrar com Google</strong> com a conta de um usuário cadastrado.</span></li>
                </ol>
            </div>
        </div>
    </div>
</div>
<?php
$script_pagina = '<script>
document.querySelectorAll("[data-copiar]").forEach(b => b.addEventListener("click", () => {
    const t = document.getElementById(b.dataset.copiar).textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => { b.innerHTML = "<i class=\"bi bi-check2\"></i> Copiado"; setTimeout(() => b.innerHTML = "<i class=\"bi bi-copy\"></i> Copiar", 1800); }).catch(() => { const r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.copiar)); getSelection().removeAllRanges(); getSelection().addRange(r); });
}));
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
