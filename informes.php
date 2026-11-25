<?php
/* Notícias e informes da escola (página de notícias) + calendário */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$cat = '';
$todos = site_informes(true, 500);
$lista = $todos;
$por_pag = 9; $total = count($lista); $pags = max(1, (int)ceil($total / $por_pag));
$pag = max(1, min($pags, (int)($_GET['p'] ?? 1)));
$pagina_itens = array_slice($lista, ($pag - 1) * $por_pag, $por_pag);
$url_pag = fn(array $q) => 'informes.php' . (($q = array_filter($q)) ? '?' . http_build_query($q) : '');

$pagina_titulo = 'Notícias e informes · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=34" rel="stylesheet">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina nl-pagina">
    <section class="nl-hero">
        <div class="largura">
            <nav class="sg-migalha"><a href="index.php"><i class="bi bi-arrow-left"></i>Início</a></nav>
            <span class="sg-sobre">Notícias</span>
            <h1>Acontece na escola</h1>
            <p>O dia a dia, os projetos e as conquistas da EEEP Venceslau Vieira Batista.</p>
            <span class="nl-total"><i class="bi bi-newspaper"></i><?= $total ?> <?= $total === 1 ? 'notícia publicada' : 'notícias publicadas' ?></span>
        </div>
    </section>
    <div class="largura">
        <div class="nl">
            <aside class="nl-lado">
                <?= agenda_cartao() ?>
                <a class="nl-cal-link" href="index.php#contato"><i class="bi bi-chat-dots"></i><span><b>Dúvidas sobre um evento?</b><small>Fale com a secretaria da escola</small></span><i class="bi bi-arrow-right"></i></a>
            </aside>

            <div class="nl-noticias">
                <?php if (!$pagina_itens): ?><div class="sg-vazio"><i class="bi bi-newspaper"></i>Nenhuma notícia publicada ainda.</div><?php endif; ?>
                <?php foreach ($pagina_itens as $n => $inf):
                    [$crot, $cic] = CATEGORIAS_INFORME[$inf['categoria']] ?? CATEGORIAS_INFORME['aviso'];
                    [$an, , $ai] = informe_autor($inf);
                    $destaque = $n === 0 && $pag === 1; ?>
                <article class="nl-card<?= $destaque ? ' destaque' : '' ?>">
                    <a class="nl-capa" href="informe.php?id=<?= $inf['id'] ?>" tabindex="-1" aria-hidden="true">
                        <?php if ($inf['imagem']): ?><img src="<?= h(site_img_url('i', (int)$inf['id'], $inf['imagem'], !$destaque)) ?>" alt="" loading="lazy" style="<?= h(informe_foco($inf)) ?>"><?php else: ?><span class="nl-semfoto"><img src="assets/img/brasao-128.png" alt=""></span><?php endif; ?>
                        <span class="nl-cat"><i class="bi bi-<?= h($cic) ?>"></i><?= h($crot) ?></span>
                        <?php if (!empty($inf['n_anexos'])): ?><span class="nl-pdf"><i class="bi bi-file-earmark-pdf-fill"></i>PDF</span><?php endif; ?>
                    </a>
                    <div class="nl-corpo">
                        <time datetime="<?= h(substr($inf['data_publicacao'], 0, 10)) ?>"><i class="bi bi-calendar3"></i><?= h(data_curta(substr($inf['data_publicacao'], 0, 10))) ?></time>
                        <h2><a href="informe.php?id=<?= $inf['id'] ?>"><?= h($inf['titulo']) ?></a></h2>
                        <?php if (($res = informe_resumo($inf, $destaque ? 220 : 140)) !== ''): ?><p><?= h($res) ?></p><?php endif; ?>
                        <div class="nl-pe">
                            <span class="nl-autor"><span><?= h($ai) ?></span><?= h($an) ?></span>
                            <a class="nl-saiba" href="informe.php?id=<?= $inf['id'] ?>">Saiba mais <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php if ($pags > 1): ?>
                <nav class="nt-paginas nl-paginas" aria-label="Páginas">
                    <?php for ($i = 1; $i <= $pags; $i++): ?><a class="<?= $i === $pag ? 'ativo' : '' ?>" href="<?= h($url_pag(['p' => $i > 1 ? $i : null])) ?>"><?= $i ?></a><?php endfor; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php $rodape_extra = '<script src="assets/js/inicio.js?v=8" defer></script>'; require __DIR__ . '/includes/site_rodape.php'; ?>
