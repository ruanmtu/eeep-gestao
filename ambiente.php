<?php
/* Página pública de um ambiente da escola (laboratório, quadra, biblioteca…): fotos, o que é e o que se estuda nele */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$id = (int)($_GET['id'] ?? 0);
$todos = ambientes_lista();
$amb = null; foreach ($todos as $a) if ((int)$a['id'] === $id) $amb = $a;
if (!$amb) { header('Location: index.php#escola'); exit; }
$fotos = ambiente_fotos($amb);
$itens = ambiente_itens($amb);
$outros = array_values(array_filter($todos, fn($a) => (int)$a['id'] !== $id));
$pagina_titulo = $amb['titulo'] . ' · ' . $ESCOLA['nome'];
$pagina_descricao = $amb['resumo'] ?: ('Conheça o ambiente ' . $amb['titulo'] . ' da ' . $ESCOLA['nome'] . '.');
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=33" rel="stylesheet">' . ($fotos ? '<meta property="og:image" content="' . h(ambiente_foto_url($amb, 0)) . '">' : '');
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina pa-pagina">
    <div class="largura">
        <nav class="sg-migalha"><a href="index.php#escola"><i class="bi bi-arrow-left"></i>Nossa estrutura</a></nav>
        <div class="sg-carro pa-galeria">
            <div class="pa-hero<?= $fotos ? ' com-foto' : '' ?>">
                <?php if ($fotos): ?>
                <div class="sg-carro-trilho">
                    <?php foreach ($fotos as $k => $f): ?><figure class="sg-carro-item"><img src="<?= h(ambiente_foto_url($amb, $k)) ?>" alt="<?= h($amb['titulo']) ?> · foto <?= $k + 1 ?>" loading="<?= $k ? 'lazy' : 'eager' ?>"></figure><?php endforeach; ?>
                </div>
                <?php if (count($fotos) > 1): ?>
                <button type="button" class="sg-carro-bt ant" aria-label="Foto anterior"><i class="bi bi-chevron-left"></i></button>
                <button type="button" class="sg-carro-bt prox" aria-label="Próxima foto"><i class="bi bi-chevron-right"></i></button>
                <?php endif; ?>
                <?php else: ?><i class="bi bi-<?= h($amb['icone']) ?> pa-hero-ic" aria-hidden="true"></i><?php endif; ?>
                <div class="pa-hero-tx">
                    <span><i class="bi bi-<?= h($amb['icone']) ?>"></i>Ambiente da escola</span>
                    <h1><?= h($amb['titulo']) ?></h1>
                    <?php if ($amb['resumo']): ?><p><?= h($amb['resumo']) ?></p><?php endif; ?>
                </div>
            </div>
            <?php if (count($fotos) > 1): ?>
            <div class="pa-miniaturas"><?php foreach ($fotos as $k => $f): ?><button type="button" aria-label="Ver foto <?= $k + 1 ?>"<?= $k ? '' : ' class="ativo"' ?>><img src="<?= h(ambiente_foto_url($amb, $k, '', true)) ?>" alt="" loading="lazy"></button><?php endforeach; ?></div>
            <?php endif; ?>
        </div>

        <div class="pa-grade">
            <div class="d-grid gap-3" style="display:grid;gap:18px">
                <section class="pa-bloco">
                    <h2><i class="bi bi-info-lg"></i>Sobre o ambiente</h2>
                    <?php if (trim((string)$amb['texto']) !== ''): ?><p class="pa-texto"><?= h($amb['texto']) ?></p>
                    <?php else: ?><p class="pa-vazio">Em breve, mais informações sobre este ambiente.</p><?php endif; ?>
                </section>
                <?php if ($itens): ?>
                <section class="pa-bloco">
                    <h2><i class="bi bi-mortarboard"></i>O que se estuda aqui</h2>
                    <ul class="pa-estuda"><?php foreach ($itens as $it): ?><li><i class="bi bi-check2-circle"></i><span><?= h($it) ?></span></li><?php endforeach; ?></ul>
                </section>
                <?php endif; ?>
            </div>
            <aside class="pa-lado">
                <?php if ($outros): ?>
                <section class="pa-bloco">
                    <h2><i class="bi bi-buildings"></i>Outros ambientes</h2>
                    <ul class="pa-outros"><?php foreach ($outros as $o): $oc = ambiente_foto_url($o, 0, '', true); ?>
                        <li><a href="ambiente.php?id=<?= (int)$o['id'] ?>"><span<?= $oc ? ' style="background-image:url(\'' . h($oc) . '\')"' : '' ?>><?php if (!$oc): ?><i class="bi bi-<?= h($o['icone']) ?>"></i><?php endif; ?></span><?= h($o['titulo']) ?></a></li>
                    <?php endforeach; ?></ul>
                </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/site_rodape.php'; ?>
