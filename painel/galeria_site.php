<?php
/* Galeria do site: álbuns de fotos que aparecem na página da escola */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');
/* A galeria saiu do site: a página fica só para consulta de álbuns antigos */
if (!isset($_GET['album']) && !eh_post()) { flash('info', 'A galeria de fotos foi desativada. As fotos agora vão junto com as notícias.'); redirecionar('site.php'); }

$aid = (int)($_GET['album'] ?? $_POST['album'] ?? 0);
$album = $aid ? q_um('SELECT * FROM site_albuns WHERE id = ?', [$aid]) : null;
if ($aid && !$album) { flash('warning', 'Álbum não encontrado.'); redirecionar('galeria_site.php'); }

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $json = !empty($_POST['ajax']);
    $resp = function (array $r) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; };
    try {
        switch ($acao) {
            case 'album_salvar':
                $t = trim((string)($_POST['titulo'] ?? '')); $d = data_valida($_POST['data_evento'] ?? '') ? $_POST['data_evento'] : null;
                $desc = trim((string)($_POST['descricao'] ?? ''));
                if (mb_strlen($t) < 3) { flash('warning', 'Dê um título ao álbum.'); redirecionar($album ? 'galeria_site.php?album=' . $aid : 'galeria_site.php'); }
                if ($album) {
                    q('UPDATE site_albuns SET titulo = ?, data_evento = ?, descricao = ?, publicado = ? WHERE id = ?', [mb_substr($t, 0, 140), $d, $desc ? mb_substr($desc, 0, 600) : null, !empty($_POST['publicado']) ? 1 : 0, $aid]);
                    flash('success', 'Álbum atualizado.');
                } else {
                    q('INSERT INTO site_albuns (titulo, data_evento, descricao, publicado, criado_por, criado_em) VALUES (?, ?, ?, 1, ?, ?)', [mb_substr($t, 0, 140), $d, $desc ? mb_substr($desc, 0, 600) : null, uid(), date('Y-m-d H:i:s')]);
                    $aid = (int)db()->lastInsertId();
                    flash('success', 'Álbum criado. Agora envie as fotos.');
                }
                registrar_log('site_album', $t);
                redirecionar('galeria_site.php?album=' . $aid);
            case 'foto_add':
                if (!$album) $resp(['ok' => false, 'msg' => 'Álbum não encontrado.']);
                [$arq, $w, $h] = site_img_salvar($_FILES['foto'] ?? [], 'albuns/' . $aid, 'f');
                $ordem = (int)q_valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM site_fotos WHERE album_id = ?', [$aid]);
                q('INSERT INTO site_fotos (album_id, arquivo, largura, altura, ordem, criado_em) VALUES (?, ?, ?, ?, ?, ?)', [$aid, $arq, $w, $h, $ordem, date('Y-m-d H:i:s')]);
                $fid = (int)db()->lastInsertId();
                $resp(['ok' => true, 'id' => $fid, 'mini' => site_img_url('f', $fid, $arq, true, RAIZ)]);
            case 'legenda':
                q('UPDATE site_fotos SET legenda = ? WHERE id = ? AND album_id = ?', [mb_substr(trim((string)($_POST['legenda'] ?? '')), 0, 200) ?: null, (int)$_POST['foto'], $aid]);
                $resp(['ok' => true]);
            case 'capa':
                q('UPDATE site_albuns SET capa_id = ? WHERE id = ?', [(int)$_POST['foto'], $aid]);
                $json ? $resp(['ok' => true]) : redirecionar('galeria_site.php?album=' . $aid);
            case 'ordem':
                $ids = array_map('intval', explode(',', (string)($_POST['ids'] ?? '')));
                foreach ($ids as $k => $f) q('UPDATE site_fotos SET ordem = ? WHERE id = ? AND album_id = ?', [$k + 1, $f, $aid]);
                $resp(['ok' => true]);
            case 'foto_apagar':
                $f = q_um('SELECT * FROM site_fotos WHERE id = ? AND album_id = ?', [(int)$_POST['foto'], $aid]);
                if ($f) { site_img_apagar($f['arquivo']); q('DELETE FROM site_fotos WHERE id = ?', [$f['id']]); if ((int)$album['capa_id'] === (int)$f['id']) q('UPDATE site_albuns SET capa_id = NULL WHERE id = ?', [$aid]); }
                $json ? $resp(['ok' => true]) : redirecionar('galeria_site.php?album=' . $aid);
            case 'album_apagar':
                foreach (site_fotos_album($aid) as $f) site_img_apagar($f['arquivo']);
                q('DELETE FROM site_fotos WHERE album_id = ?', [$aid]); q('DELETE FROM site_albuns WHERE id = ?', [$aid]);
                registrar_log('site_album_apagado', $album['titulo']);
                flash('success', 'Álbum "' . $album['titulo'] . '" apagado.');
                redirecionar('galeria_site.php');
        }
    } catch (RuntimeException $e) {
        if ($json) $resp(['ok' => false, 'msg' => $e->getMessage()]);
        flash('warning', $e->getMessage());
    }
    redirecionar($album ? 'galeria_site.php?album=' . $aid : 'galeria_site.php');
}

$titulo = $album ? $album['titulo'] : 'Galeria de fotos';
$subtitulo = $album ? 'Álbum da galeria do site' : 'Álbuns que aparecem na página da escola. Crie um álbum para cada evento ou tema e envie as fotos pelo celular ou computador.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';

if (!$album):
    $albuns = site_albuns(false); ?>
<div class="gl-topo">
    <div class="cf-resumo"><span><b><?= count($albuns) ?></b> álbuns</span><span><b><?= array_sum(array_column($albuns, 'n_fotos')) ?></b> fotos</span></div>
    <a class="btn btn-light btn-sm ms-auto" href="<?= RAIZ ?>galeria.php" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#novoAlbum"><i class="bi bi-plus-lg me-1"></i>Novo álbum</button>
</div>
<div class="collapse mb-3<?= $albuns ? '' : ' show' ?>" id="novoAlbum"><form method="post" class="card gl-novo"><div class="card-body row g-3">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="album_salvar">
    <div class="col-md-6"><label class="form-label obrigatorio" for="alT">Título do álbum</label><input class="form-control" id="alT" name="titulo" maxlength="140" required placeholder="Ex.: Feira de Ciências 2026"></div>
    <div class="col-md-3"><label class="form-label" for="alD">Data do evento</label><input class="form-control" type="date" id="alD" name="data_evento" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2 me-1"></i>Criar e enviar fotos</button></div>
    <div class="col-12"><label class="form-label" for="alDesc">Texto do álbum <span class="text-secondary fw-normal">· opcional</span></label><textarea class="form-control" id="alDesc" name="descricao" rows="2" maxlength="600" placeholder="Conte em poucas linhas o que aconteceu"></textarea></div>
</div></form></div>
<?php if ($albuns): ?>
<div class="gl-albuns">
    <?php foreach ($albuns as $a): ?>
    <a class="gl-album<?= $a['publicado'] ? '' : ' rascunho' ?>" href="?album=<?= $a['id'] ?>">
        <div class="gl-capa"><?php if ($a['capa_arquivo']): ?><img src="<?= e(site_img_url('f', (int)$a['capa_foto'], $a['capa_arquivo'], true, RAIZ)) ?>" alt="" loading="lazy"><?php else: ?><i class="bi bi-images"></i><?php endif; ?>
            <span class="gl-n"><i class="bi bi-camera"></i><?= (int)$a['n_fotos'] ?></span><?php if (!$a['publicado']): ?><span class="gl-rasc">não publicado</span><?php endif; ?></div>
        <strong><?= e($a['titulo']) ?></strong><small><?= $a['data_evento'] ? data_curta($a['data_evento']) : 'sem data' ?></small>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?><div class="card"><div class="vazio"><i class="bi bi-images"></i><strong>Nenhum álbum ainda</strong>Crie o primeiro álbum acima: as fotos aparecem na página da escola.</div></div><?php endif; ?>

<?php else:
    $fotos = site_fotos_album($aid); ?>
<div class="gl-topo">
    <a class="btn btn-light btn-sm" href="galeria_site.php"><i class="bi bi-arrow-left me-1"></i>Álbuns</a>
    <span class="etiqueta <?= $album['publicado'] ? '' : 'ambar' ?>"><?= $album['publicado'] ? 'Publicado no site' : 'Não publicado' ?></span>
    <a class="btn btn-light btn-sm ms-auto" href="<?= RAIZ ?>album.php?id=<?= $aid ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a>
    <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editAlbum"><i class="bi bi-pencil me-1"></i>Editar álbum</button>
</div>
<div class="collapse mb-3" id="editAlbum"><form method="post" class="card"><div class="card-body row g-3">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="album_salvar"><input type="hidden" name="album" value="<?= $aid ?>">
    <div class="col-md-6"><label class="form-label obrigatorio" for="eT">Título</label><input class="form-control" id="eT" name="titulo" maxlength="140" required value="<?= e($album['titulo']) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="eD">Data do evento</label><input class="form-control" type="date" id="eD" name="data_evento" value="<?= e((string)$album['data_evento']) ?>"></div>
    <div class="col-md-3 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="publicado" value="1"<?= $album['publicado'] ? ' checked' : '' ?>> Publicado no site</label></div>
    <div class="col-12"><label class="form-label" for="eDesc">Texto do álbum</label><textarea class="form-control" id="eDesc" name="descricao" rows="2" maxlength="600"><?= e((string)$album['descricao']) ?></textarea></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Salvar</button></div>
</div></form>
<form method="post" class="mt-2 text-end" data-confirmar="Apagar o álbum &quot;<?= e($album['titulo']) ?>&quot; e todas as <?= count($fotos) ?> fotos? Não dá para desfazer." data-botao="Apagar álbum">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="album_apagar"><input type="hidden" name="album" value="<?= $aid ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Apagar álbum</button></form></div>

<?php if ($album['descricao']): ?><p class="gl-desc"><?= nl2br(e($album['descricao'])) ?></p><?php endif; ?>

<label class="gl-envio" id="glEnvio" for="glArquivos">
    <input type="file" id="glArquivos" accept="image/*" multiple hidden>
    <span class="gl-envio-ic"><i class="bi bi-cloud-arrow-up"></i></span>
    <strong>Toque para escolher as fotos</strong><small>ou arraste aqui · pode escolher várias de uma vez · as fotos são reduzidas antes de enviar</small>
    <div class="gl-prog" id="glProg" hidden><i id="glBarra"></i><span id="glTxt"></span></div>
</label>

<div class="gl-fotos" id="glFotos" data-album="<?= $aid ?>" data-capa="<?= (int)$album['capa_id'] ?>">
    <?php foreach ($fotos as $f): ?>
    <figure class="gl-foto<?= (int)$album['capa_id'] === (int)$f['id'] ? ' capa' : '' ?>" data-id="<?= $f['id'] ?>">
        <img src="<?= e(site_img_url('f', (int)$f['id'], $f['arquivo'], true, RAIZ)) ?>" alt="" loading="lazy">
        <div class="gl-acoes">
            <button type="button" data-a="capa" title="Usar como capa" aria-label="Usar como capa"><i class="bi bi-star-fill"></i></button>
            <button type="button" data-a="esq" title="Mover para antes" aria-label="Mover para antes"><i class="bi bi-arrow-left"></i></button>
            <button type="button" data-a="dir" title="Mover para depois" aria-label="Mover para depois"><i class="bi bi-arrow-right"></i></button>
            <button type="button" data-a="apagar" class="perigo" title="Apagar foto" aria-label="Apagar foto"><i class="bi bi-trash"></i></button>
        </div>
        <span class="gl-selo-capa"><i class="bi bi-star-fill"></i>capa</span>
        <input class="gl-leg" type="text" maxlength="200" placeholder="Legenda (opcional)" value="<?= e((string)$f['legenda']) ?>" aria-label="Legenda">
    </figure>
    <?php endforeach; ?>
</div>
<p class="vazio-gl text-secondary small mt-2" id="glVazio"<?= $fotos ? ' hidden' : '' ?>>Ainda sem fotos neste álbum.</p>

<?php
$script_pagina = '<script>
(function () {
  const CSRF = document.querySelector("meta[name=csrf]").content, AID = ' . $aid . ';
  const grade = document.getElementById("glFotos"), env = document.getElementById("glEnvio"), inp = document.getElementById("glArquivos");
  const prog = document.getElementById("glProg"), barra = document.getElementById("glBarra"), txt = document.getElementById("glTxt");
  const post = async (dados, arquivo) => { const fd = new FormData(); fd.append("csrf", CSRF); fd.append("ajax", "1"); fd.append("album", AID);
    for (const k in dados) fd.append(k, dados[k]); if (arquivo) fd.append("foto", arquivo, "foto.jpg");
    const r = await fetch("galeria_site.php?album=" + AID, { method: "POST", body: fd }); return r.json(); };
  /* reduz a foto no próprio aparelho antes de enviar (mais rápido no 4G) */
  const reduzir = f => new Promise(ok => {
    const img = new Image(); img.onload = () => { const m = 1800, e = Math.min(1, m / Math.max(img.width, img.height));
      const c = document.createElement("canvas"); c.width = Math.round(img.width * e); c.height = Math.round(img.height * e);
      c.getContext("2d").drawImage(img, 0, 0, c.width, c.height); c.toBlob(b => ok(b || f), "image/jpeg", .85); URL.revokeObjectURL(img.src); };
    img.onerror = () => ok(f); img.src = URL.createObjectURL(f); });
  const cartao = (id, mini) => { const fg = document.createElement("figure"); fg.className = "gl-foto novo"; fg.dataset.id = id;
    fg.innerHTML = `<img src="${mini}" alt=""><div class="gl-acoes"><button type="button" data-a="capa" title="Usar como capa"><i class="bi bi-star-fill"></i></button><button type="button" data-a="esq" title="Mover para antes"><i class="bi bi-arrow-left"></i></button><button type="button" data-a="dir" title="Mover para depois"><i class="bi bi-arrow-right"></i></button><button type="button" data-a="apagar" class="perigo" title="Apagar foto"><i class="bi bi-trash"></i></button></div><span class="gl-selo-capa"><i class="bi bi-star-fill"></i>capa</span><input class="gl-leg" type="text" maxlength="200" placeholder="Legenda (opcional)" aria-label="Legenda">`;
    return fg; };
  async function enviar(lista) {
    lista = [...lista].filter(f => f.type.startsWith("image/")); if (!lista.length) return;
    prog.hidden = false; env.classList.add("enviando"); let ok = 0, erros = [];
    for (let i = 0; i < lista.length; i++) {
      txt.textContent = `Enviando ${i + 1} de ${lista.length}…`; barra.style.width = (i / lista.length * 100) + "%";
      try { const b = await reduzir(lista[i]); const j = await post({ acao: "foto_add" }, b);
        if (j.ok) { grade.appendChild(cartao(j.id, j.mini)); ok++; } else erros.push(lista[i].name + ": " + j.msg); }
      catch (e) { erros.push(lista[i].name + ": falha de conexão"); }
    }
    barra.style.width = "100%"; txt.textContent = `${ok} foto(s) enviada(s)` + (erros.length ? ` · ${erros.length} com erro` : "");
    if (erros.length) alert(erros.join("\\n"));
    document.getElementById("glVazio").hidden = grade.children.length > 0;
    env.classList.remove("enviando"); inp.value = ""; setTimeout(() => { prog.hidden = true; barra.style.width = "0"; }, 2500);
  }
  inp.addEventListener("change", () => enviar(inp.files));
  ["dragover", "dragenter"].forEach(ev => env.addEventListener(ev, e => { e.preventDefault(); env.classList.add("sobre"); }));
  ["dragleave", "drop"].forEach(ev => env.addEventListener(ev, e => { e.preventDefault(); env.classList.remove("sobre"); }));
  env.addEventListener("drop", e => enviar(e.dataTransfer.files));
  const salvarOrdem = () => post({ acao: "ordem", ids: [...grade.children].map(f => f.dataset.id).join(",") });
  grade.addEventListener("click", async e => {
    const b = e.target.closest("[data-a]"); if (!b) return; const fg = b.closest(".gl-foto"), id = fg.dataset.id;
    if (b.dataset.a === "capa") { await post({ acao: "capa", foto: id }); grade.querySelectorAll(".gl-foto").forEach(x => x.classList.toggle("capa", x === fg)); }
    if (b.dataset.a === "esq" && fg.previousElementSibling) { grade.insertBefore(fg, fg.previousElementSibling); salvarOrdem(); }
    if (b.dataset.a === "dir" && fg.nextElementSibling) { grade.insertBefore(fg.nextElementSibling, fg); salvarOrdem(); }
    if (b.dataset.a === "apagar" && confirm("Apagar esta foto do álbum?")) { await post({ acao: "foto_apagar", foto: id }); fg.remove(); document.getElementById("glVazio").hidden = grade.children.length > 0; }
  });
  grade.addEventListener("change", e => { if (e.target.classList.contains("gl-leg")) { post({ acao: "legenda", foto: e.target.closest(".gl-foto").dataset.id, legenda: e.target.value }); e.target.classList.add("salvo"); setTimeout(() => e.target.classList.remove("salvo"), 1200); } });
})();
</script>';
endif;
require __DIR__ . '/../includes/layout_rodape.php';
