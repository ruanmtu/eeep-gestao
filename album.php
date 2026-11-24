<?php
/* Página pública de um álbum da galeria */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$id = (int)($_GET['id'] ?? 0);
$album = null; try { $album = q_um('SELECT * FROM site_albuns WHERE id = ? AND (publicado = 1 OR ? = 1)', [$id, usuario() ? 1 : 0]); } catch (Throwable $e) {}
if (!$album) { http_response_code(404); header('Location: informes.php'); exit; }
$fotos = site_fotos_album($id);
$outros = array_values(array_filter(site_albuns(true, 7), fn($a) => (int)$a['id'] !== $id && $a['n_fotos'] > 0));
$pagina_titulo = $album['titulo'] . ' · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=32" rel="stylesheet">' . ($fotos ? '<meta property="og:image" content="' . h(site_img_url('f', (int)$fotos[0]['id'], $fotos[0]['arquivo'])) . '">' : '');
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina">
    <div class="largura">
        <nav class="sg-migalha"><a href="informes.php"><i class="bi bi-arrow-left"></i>Notícias</a></nav>
        <header class="sg-album-cab">
            <?php if ($album['data_evento']): ?><time><i class="bi bi-calendar3"></i><?= data_curta($album['data_evento']) ?></time><?php endif; ?>
            <h1><?= h($album['titulo']) ?></h1>
            <?php if ($album['descricao']): ?><p><?= nl2br(h($album['descricao'])) ?></p><?php endif; ?>
            <div class="sg-album-acoes"><span><i class="bi bi-images"></i><?= count($fotos) ?> foto<?= count($fotos) == 1 ? '' : 's' ?></span>
                <button type="button" class="sg-bt claro" id="compartilhar"><i class="bi bi-share"></i>Compartilhar</button></div>
        </header>
        <?php if ($fotos): ?>
        <div class="sg-mosaico" id="mosaico">
            <?php foreach ($fotos as $k => $f): ?>
                <button type="button" class="sg-mf" data-i="<?= $k ?>" data-src="<?= h(site_img_url('f', (int)$f['id'], $f['arquivo'])) ?>" data-leg="<?= h((string)$f['legenda']) ?>" aria-label="Ampliar foto <?= $k + 1 ?>">
                    <img src="<?= h(site_img_url('f', (int)$f['id'], $f['arquivo'], true)) ?>" alt="<?= h($f['legenda'] ?: $album['titulo']) ?>" loading="lazy"<?= $f['largura'] ? ' width="' . (int)$f['largura'] . '" height="' . (int)$f['altura'] . '"' : '' ?>>
                    <?php if ($f['legenda']): ?><span><?= h($f['legenda']) ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="sg-vazio"><i class="bi bi-images"></i>Este álbum ainda não tem fotos.</p><?php endif; ?>

        <?php if ($outros): ?>
        <h2 class="sg-sub">Outros álbuns</h2>
        <div class="sg-albuns-linha">
            <?php foreach (array_slice($outros, 0, 6) as $a): ?>
            <a class="sg-album" href="album.php?id=<?= $a['id'] ?>"><img src="<?= h(site_img_url('f', (int)$a['capa_foto'], (string)$a['capa_arquivo'], true)) ?>" alt="" loading="lazy">
                <span class="sg-album-n"><i class="bi bi-images"></i><?= (int)$a['n_fotos'] ?></span><div class="sg-album-txt"><h3><?= h($a['titulo']) ?></h3></div></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="sg-luz" id="luz" hidden role="dialog" aria-modal="true" aria-label="Foto ampliada">
        <div class="sg-luz-topo"><span id="luzCont"></span><a id="luzBaixar" download aria-label="Baixar foto"><i class="bi bi-download"></i></a><button type="button" data-luz="fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button></div>
        <button type="button" class="sg-luz-nav ant" data-luz="-1" aria-label="Foto anterior"><i class="bi bi-chevron-left"></i></button>
        <figure><img id="luzImg" alt=""><figcaption id="luzLeg"></figcaption></figure>
        <button type="button" class="sg-luz-nav prox" data-luz="1" aria-label="Próxima foto"><i class="bi bi-chevron-right"></i></button>
    </div>
</main>
<?php $rodape_extra = '<script src="assets/js/inicio.js?v=7" defer></script>'; require __DIR__ . '/includes/site_rodape.php'; ?>
