<?php
require __DIR__ . '/../includes/app.php';
exigir_login();

$u = usuario();
$erro_senha = '';
$erro_dados = '';
if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? 'senha';
    $hash = (string)q_valor('SELECT senha_hash FROM usuarios WHERE id = ?', [uid()]);

    /* valida e grava a nova senha (sem pedir a senha antiga: a pessoa já está logada) */
    $trocar_senha = function (string $nova, string $conf) use ($hash): string {
        if (mb_strlen($nova) < 8) return 'A nova senha precisa ter pelo menos 8 caracteres.';
        if (!preg_match('/[A-Za-z]/', $nova) || !preg_match('/\d/', $nova)) return 'Use letras e números na nova senha.';
        if ($nova !== $conf) return 'As duas senhas não são iguais.';
        if (password_verify($nova, $hash)) return 'A nova senha precisa ser diferente da senha temporária.';
        q('UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), uid()]);
        return '';
    };
    $validar_email = function (string $email): string {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Informe um e-mail válido.';
        if (q_valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, uid()])) return 'Esse e-mail já é usado por outra pessoa.';
        return '';
    };
    $salvar_foto = function () use ($u): void {
        if (!empty($_FILES['foto']['name'])) {
            try { q('UPDATE usuarios SET foto = ? WHERE id = ?', [foto_salvar($_FILES['foto'], 'usuarios', uid(), $u['foto'] ?? null), uid()]); }
            catch (RuntimeException $e) { flash('warning', 'A foto não foi salva: ' . $e->getMessage()); }
        }
    };

    if ($acao === 'primeiro') {
        /* primeiro acesso: nome de exibição, e-mail e senha de uma vez */
        $exib = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nome_exibicao'] ?? '')));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $erro_senha = $validar_email($email);
        if (!$erro_senha) {
            $erro_senha = $trocar_senha((string)($_POST['nova'] ?? ''), (string)($_POST['confirmacao'] ?? ''));
            if (!$erro_senha) {
                q('UPDATE usuarios SET nome_exibicao = ?, email = ? WHERE id = ?', [$exib !== '' ? mb_substr($exib, 0, 60) : null, $email, uid()]);
                $salvar_foto();
                registrar_log('primeiro_acesso', 'Confirmou os dados e criou a senha');
                session_regenerate_id(true);
                flash('success', 'Tudo pronto! Use a sua nova senha nos próximos acessos.');
                redirecionar('index.php');
            }
        }
    } elseif ($acao === 'dados') {
        $nome = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nome'] ?? '')));
        $exib = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nome_exibicao'] ?? '')));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $cargo = trim((string)($_POST['cargo'] ?? ''));
        if (mb_strlen($nome) < 3) $erro_dados = 'Informe o nome completo.';
        elseif ($e = $validar_email($email)) $erro_dados = $e;
        else {
            q('UPDATE usuarios SET nome = ?, nome_exibicao = ?, email = ?, cargo = ? WHERE id = ?',
              [mb_substr($nome, 0, 120), $exib !== '' ? mb_substr($exib, 0, 60) : null, $email, $cargo !== '' ? mb_substr($cargo, 0, 80) : null, uid()]);
            $salvar_foto();
            if (empty($_FILES['foto']['name']) && !empty($_POST['remover_foto']) && !empty($u['foto'])) { foto_apagar($u['foto']); q('UPDATE usuarios SET foto = NULL WHERE id = ?', [uid()]); }
            registrar_log('conta_alterada', 'Atualizou os próprios dados');
            flash('success', 'Seus dados foram atualizados.');
            redirecionar('perfil.php');
        }
    } else {
        $erro_senha = $trocar_senha((string)($_POST['nova'] ?? ''), (string)($_POST['confirmacao'] ?? ''));
        if (!$erro_senha) {
            registrar_log('senha_alterada', 'Alterou a própria senha');
            session_regenerate_id(true);
            flash('success', 'Senha alterada. Use a nova senha no próximo acesso.');
            redirecionar('perfil.php');
        }
    }
}

$u = q_um('SELECT * FROM usuarios WHERE id = ?', [uid()]) ?: $u;
$primeiro = !empty($u['trocar_senha']);
$titulo = $primeiro ? 'Primeiro acesso' : 'Minha conta';
$subtitulo = 'Seus dados, sua foto e sua senha.';
$menu = '';
require __DIR__ . '/../includes/layout_topo.php';
?>
<?php if ($primeiro): $pnome = explode(' ', trim(nome_exibicao($u)))[0]; ?>
<form method="post" enctype="multipart/form-data" class="pa-wrap" id="formPrimeiro" autocomplete="off">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="primeiro">
    <aside class="pa-lado">
        <span class="pa-passo"><i class="bi bi-stars"></i>Primeiro acesso</span>
        <h1>Bem-vindo(a), <em><?= e($pnome) ?></em>!<br>Vamos deixar sua conta pronta.</h1>
        <p>Confira seus dados e crie a sua senha pessoal. Depois disso, a senha temporária deixa de funcionar.</p>
        <div class="pa-quem">
            <div class="pa-foto"><span id="previaFoto"><?= avatar($u, 'usuario', 'avatar-lg') ?></span><label for="foto" title="Adicionar foto"><i class="bi bi-camera-fill"></i></label>
                <input type="file" class="d-none" id="foto" name="foto" data-recorte="1:1" data-saida="800" data-recorte-titulo="Enquadrar a sua foto" accept="image/jpeg,image/png,image/webp"></div>
            <div class="min-w-0"><b><?= e($u['nome']) ?></b><small><?= e($u['email']) ?></small></div>
        </div>
        <div class="pa-nivel"><i class="bi bi-shield-check"></i><div><small>Nível de acesso</small><b><?= e(perfil_nome($u['perfil'])) ?></b></div></div>
        <ol class="pa-etapas">
            <li><span><i class="bi bi-check-lg"></i></span>Entrou com a senha temporária</li>
            <li><span>2</span>Confirme seu nome e e-mail</li>
            <li><span>3</span>Crie sua senha</li>
        </ol>
    </aside>
    <div class="pa-form">
        <?php if ($erro_senha): ?><div class="alert alert-danger mb-0"><i class="bi bi-exclamation-circle me-1"></i><?= e($erro_senha) ?></div><?php endif; ?>
        <div class="pa-bloco">
            <h2><span><i class="bi bi-person"></i></span>Seus dados</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="p_exib">Nome de exibição</label><div class="pa-campo"><i class="bi bi-person-badge"></i><input class="form-control" id="p_exib" name="nome_exibicao" maxlength="60" value="<?= e($_POST['nome_exibicao'] ?? ($u['nome_exibicao'] ?: primeiro_ultimo(nome_proprio($u['nome'])))) ?>"></div><div class="form-text">Como seu nome aparece no sistema.</div></div>
                <div class="col-md-6"><label class="form-label obrigatorio" for="p_email">E-mail</label><div class="pa-campo"><i class="bi bi-envelope"></i><input class="form-control" type="email" id="p_email" name="email" maxlength="160" required value="<?= e($_POST['email'] ?? $u['email']) ?>"></div><div class="form-text">É com ele que você entra no sistema.</div></div>
            </div>
        </div>
        <div class="pa-bloco">
            <h2><span><i class="bi bi-shield-lock"></i></span>Crie sua senha</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label obrigatorio" for="nova">Nova senha</label><div class="pa-campo campo-senha"><i class="bi bi-key"></i><input class="form-control" type="password" id="nova" name="nova" autocomplete="new-password" minlength="8" required><button type="button" class="ver-senha" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button></div>
                    <div class="pa-forca"><i id="forcaBarra"></i></div></div>
                <div class="col-md-6"><label class="form-label obrigatorio" for="conf">Repita a nova senha</label><div class="pa-campo campo-senha"><i class="bi bi-key-fill"></i><input class="form-control" type="password" id="conf" name="confirmacao" autocomplete="new-password" required><button type="button" class="ver-senha" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button></div>
                    <div class="pa-igual" id="paIgual" hidden></div></div>
            </div>
            <div class="pa-regras" id="paRegras"><span data-r="tam"><i class="bi bi-dot"></i>8 caracteres ou mais</span><span data-r="let"><i class="bi bi-dot"></i>letras</span><span data-r="num"><i class="bi bi-dot"></i>números</span><span data-r="sim"><i class="bi bi-dot"></i>símbolo (opcional)</span></div>
        </div>
        <div class="pa-salvar"><button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i>Salvar e entrar no sistema</button><small>Você não precisa digitar a senha temporária de novo.</small></div>
    </div>
</form>
<?php else: ?>

<section class="conta-capa mb-3">
    <div class="conta-foto">
        <?= avatar($u, 'usuario', 'avatar-xl') ?>
        <label for="foto" class="conta-camera" title="Trocar foto"><i class="bi bi-camera-fill"></i></label>
    </div>
    <div class="conta-quem">
        <h2><?= e(nome_exibicao($u)) ?></h2>
        <p><?= e(cargo_usuario($u)) ?> · <?= e($u['email']) ?></p>
        <span class="etiqueta claro"><i class="bi bi-shield-check"></i>Nível de acesso: <?= e(perfil_nome($u['perfil'])) ?></span>
        <?php if ($u['ultimo_acesso']): ?><span class="etiqueta claro"><i class="bi bi-clock-history"></i>último acesso <?= data_hora_br($u['ultimo_acesso']) ?></span><?php endif; ?>
    </div>
    <?php $n_ag = 0; try { $n_ag = (int)q_valor('SELECT COUNT(*) FROM agenda_notas WHERE usuario_id = ?', [uid()]); } catch (PDOException $e) {} ?>
    <div class="conta-numeros">
        <div><b><?= $n_ag ?></b><small>dias na agenda</small></div>
        <div><b><?= notificacoes_nao_lidas() ?></b><small>avisos novos</small></div>
    </div>
</section>

<div class="row g-3">
    <div class="col-xl-7">
        <form method="post" enctype="multipart/form-data" class="card" id="formDados">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="dados">
            <div class="card-header"><h2><i class="bi bi-person-vcard me-1"></i>Meus dados</h2></div>
            <div class="card-body">
                <?php if ($erro_dados): ?><div class="alert alert-danger"><?= e($erro_dados) ?></div><?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 d-flex gap-3 align-items-center foto-escolha">
                        <span id="previaFoto"><?= avatar($u, 'usuario', 'avatar-lg') ?></span>
                        <div>
                            <label class="btn btn-light btn-sm mb-1" for="foto"><i class="bi bi-upload me-1"></i>Escolher foto</label>
                            <input type="file" class="d-none" id="foto" name="foto" data-recorte="1:1" data-saida="800" data-recorte-titulo="Enquadrar a sua foto" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text mt-0">JPG, PNG ou WEBP. A foto é cortada quadrada automaticamente.</div>
                            <?php if (!empty($u['foto'])): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remover_foto" value="1" id="rmFoto"><label class="form-check-label small" for="rmFoto">Remover a foto atual</label></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-7"><label class="form-label obrigatorio" for="c_nome">Nome completo</label>
                        <input class="form-control" id="c_nome" name="nome" value="<?= e($_POST['nome'] ?? $u['nome']) ?>" maxlength="120" required></div>
                    <div class="col-md-5"><label class="form-label" for="c_exib">Nome de exibição</label>
                        <input class="form-control" id="c_exib" name="nome_exibicao" value="<?= e($_POST['nome_exibicao'] ?? ($u['nome_exibicao'] ?? '')) ?>" maxlength="60" placeholder="<?= e(primeiro_ultimo($u['nome'])) ?>">
                        <div class="form-text">Como seu nome aparece no sistema e na saudação.</div></div>
                    <div class="col-md-7"><label class="form-label obrigatorio" for="c_email">E-mail de acesso</label>
                        <input class="form-control" type="email" id="c_email" name="email" value="<?= e($_POST['email'] ?? $u['email']) ?>" maxlength="160" required></div>
                    <div class="col-md-5"><label class="form-label" for="c_cargo">Função</label>
                        <input class="form-control" id="c_cargo" name="cargo" value="<?= e($_POST['cargo'] ?? ($u['cargo'] ?? '')) ?>" maxlength="80" placeholder="Ex.: Professor de Informática">
                        <div class="form-text">Sai na assinatura dos documentos.</div></div>
                </div>
            </div>
            <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-check2 me-1"></i>Salvar meus dados</button></div>
        </form>
    </div>
    <div class="col-xl-5">
        <div class="card conta-nivel"><div class="card-body">
            <span class="cn-ic"><i class="bi bi-shield-check"></i></span>
            <small>Nível de acesso</small><b><?= e(perfil_nome($u['perfil'])) ?></b>
            <p>Você acessa tudo o que o perfil <?= e(perfil_nome($u['perfil'])) ?> pode usar. Precisa de algo a mais? Fale com a gestão.</p>
        </div></div>
    </div>
    <div class="col-xl-7" id="senha">
        <form method="post" class="card">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="senha">
            <div class="card-header"><h2><i class="bi bi-shield-lock me-1"></i>Alterar senha</h2></div>
            <div class="card-body">
                <?php if ($erro_senha): ?><div class="alert alert-danger"><?= e($erro_senha) ?></div><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="nova">Nova senha</label><div class="campo-senha"><input class="form-control" type="password" id="nova" name="nova" autocomplete="new-password" minlength="8" required><button type="button" class="ver-senha" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button></div><div class="forca" aria-hidden="true"><span id="forcaBarra"></span></div><small class="form-text" id="forcaTxt"></small></div>
                    <div class="col-md-6"><label class="form-label" for="conf">Repita a nova senha</label><div class="campo-senha"><input class="form-control" type="password" id="conf" name="confirmacao" autocomplete="new-password" required><button type="button" class="ver-senha" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button></div></div>
                    <div class="col-12 form-text mt-1">Pelo menos 8 caracteres, com letras e números.</div>
                </div>
            </div>
            <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit">Salvar nova senha</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php
$script_pagina = '<script>
(function () {
  const f = document.getElementById("foto"), prev = document.getElementById("previaFoto");
  if (f && prev) f.addEventListener("change", () => {
    const a = f.files[0]; if (!a) return;
    const url = URL.createObjectURL(a);
    prev.innerHTML = `<img class="avatar avatar-lg" src="${url}" alt="">`;
    const capa = document.querySelector(".conta-foto .avatar"); if (capa) capa.outerHTML = `<img class="avatar avatar-xl" src="${url}" alt="">`;
  });
  document.querySelectorAll(".ver-senha").forEach(b => b.addEventListener("click", () => {
    const i = b.previousElementSibling, m = i.type === "password"; i.type = m ? "text" : "password";
    b.innerHTML = m ? "<i class=\"bi bi-eye-slash\"></i>" : "<i class=\"bi bi-eye\"></i>"; b.setAttribute("aria-label", m ? "Ocultar senha" : "Mostrar senha");
  }));
  const nova = document.getElementById("nova"), conf = document.getElementById("conf"), fb = document.getElementById("forcaBarra"), ft = document.getElementById("forcaTxt");
  const igual = document.getElementById("paIgual"), regras = document.getElementById("paRegras");
  const atual = () => {
    const v = nova.value; let p = 0;
    if (v.length >= 8) p++; if (v.length >= 12) p++; if (/[a-z]/i.test(v) && /\d/.test(v)) p++; if (/[^a-z0-9]/i.test(v)) p++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) p++;
    const n = [["", "#e6ece7"], ["Fraca", "#c0261b"], ["Razoável", "#e0a106"], ["Boa", "#5dbb63"], ["Forte", "#1e9a2b"], ["Muito forte", "#13692a"]][Math.min(5, p)];
    if (fb) { fb.style.width = (Math.min(5, p) * 20) + "%"; fb.style.background = n[1]; }
    if (ft) ft.textContent = v ? "Senha " + n[0].toLowerCase() : "";
    if (regras) { const ok = { tam: v.length >= 8, let: /[a-z]/i.test(v), num: /\d/.test(v), sim: /[^a-z0-9]/i.test(v) };
      regras.querySelectorAll("span").forEach(s => { const o = ok[s.dataset.r]; s.classList.toggle("ok", o); s.querySelector("i").className = "bi bi-" + (o ? "check2" : "dot"); }); }
    if (igual && conf) { const t = conf.value !== ""; igual.hidden = !t; const eq = conf.value === v;
      igual.className = "pa-igual" + (eq ? "" : " nao"); igual.innerHTML = eq ? "<i class=\"bi bi-check-circle-fill\"></i>As duas senhas são iguais" : "<i class=\"bi bi-x-circle-fill\"></i>As senhas ainda não são iguais"; }
  };
  if (nova) { nova.addEventListener("input", atual); if (conf) conf.addEventListener("input", atual); }
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
