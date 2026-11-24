<?php
/* Página pública de um informe */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$id = (int)($_GET['id'] ?? 0);
$inf = null; try { $inf = q_um('SELECT i.*, u.nome AS autor_nome, u.nome_exibicao AS autor_exib, u.cargo AS autor_cargo, u.perfil AS autor_perfil FROM site_informes i LEFT JOIN usuarios u ON u.id = i.criado_por WHERE i.id = ? AND ((i.publicado = 1 AND i.data_publicacao <= ?) OR ? = 1)', [$id, date('Y-m-d'), usuario() ? 1 : 0]); } catch (Throwable $e) {}
$anexos = $inf ? informe_anexos((int)$inf['id']) : [];
if (!$inf) { header('Location: index.php#informes'); exit; }
[$crot, $cic] = CATEGORIAS_INFORME[$inf['categoria']] ?? CATEGORIAS_INFORME['aviso'];
$mais = array_values(array_filter(site_informes(true, 6), fn($x) => (int)$x['id'] !== $id));
$pagina_titulo = $inf['titulo'] . ' · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=27" rel="stylesheet">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina nm-pagina">
    <div class="largura">
        <nav class="sg-migalha"><a href="informes.php"><i class="bi bi-arrow-left"></i>Notícias</a></nav>
        <?php [$an, $ac, $ai] = informe_autor($inf); ?>
        <div class="nm c-<?= h($inf['categoria']) ?>">
            <aside class="nm-lado">
                <div class="nm-cartao">
                    <?php if ($inf['imagem']): ?>
                    <figure class="nm-foto"><img src="<?= h(site_img_url('i', (int)$inf['id'], $inf['imagem'])) ?>" alt="" style="<?= h(informe_foco($inf)) ?>"></figure>
                    <?php else: ?>
                    <figure class="nm-foto sem"><img src="assets/img/brasao-128.png" alt=""></figure>
                    <?php endif; ?>
                    <div class="nm-info">
                        <span class="nm-cat"><i class="bi bi-<?= h($cic) ?>"></i><?= h($crot) ?></span>
                        <h1><?= h($inf['titulo']) ?></h1>
                        <?php if (!empty($inf['subtitulo'])): ?><p class="nm-sub"><?= h($inf['subtitulo']) ?></p><?php endif; ?>
                        <div class="nm-autor">
                            <span class="nm-av"><?= h($ai) ?></span>
                            <div><small>Enviado por</small><b><?= h($an) ?></b><em><?= h($ac) ?></em></div>
                        </div>
                        <ul class="nm-dados">
                            <li><i class="bi bi-calendar3"></i><span><small>Publicado em</small><?= h(informe_quando($inf)) ?></span></li>
                            <?php if (!empty($inf['data_evento']) || $inf['categoria'] === 'evento'): ?><li><i class="bi bi-calendar-event"></i><span><small>Quando acontece</small><?= !empty($inf['data_evento']) ? h(data_curta($inf['data_evento'])) : 'Data a confirmar' ?></span></li><?php endif; ?>
                            <?php if ($anexos): ?><li><i class="bi bi-paperclip"></i><span><small>Documentos</small><a href="#documentos"><?= count($anexos) ?> PDF<?= count($anexos) > 1 ? 's' : '' ?> anexado<?= count($anexos) > 1 ? 's' : '' ?></a></span></li><?php endif; ?>
                        </ul>
                        <button type="button" class="sg-bt claro nm-comp" id="compartilhar"><i class="bi bi-share"></i>Compartilhar</button>
                    </div>
                </div>
            </aside>

            <article class="nm-materia">
                <div class="nm-texto"><?= informe_materia((string)$inf['texto']) ?></div>
                <?php if ($anexos): ?>
                <section class="ni-anexos" id="documentos" aria-label="Documentos anexados">
                    <h2><i class="bi bi-paperclip"></i>Documentos</h2>
                    <?php foreach ($anexos as $a): ?>
                    <div class="ni-anexo">
                        <span class="ni-pdf"><i class="bi bi-file-earmark-pdf-fill"></i><small>PDF</small></span>
                        <div class="ni-anexo-txt"><b><?= h($a['titulo']) ?></b><small><?= $a['tamanho'] >= 1048576 ? number_format($a['tamanho'] / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($a['tamanho'] / 1024)) . ' KB' ?> · PDF</small></div>
                        <div class="ni-anexo-bt">
                            <a class="sg-bt claro" href="anexo_site.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i>Abrir PDF</a>
                            <a class="sg-bt laranja" href="anexo_site.php?id=<?= (int)$a['id'] ?>&amp;baixar=1"><i class="bi bi-download"></i>Baixar</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>
                <?php $enviado = strtotime((string)($inf['criado_em'] ?? $inf['data_publicacao'])); $pub = strtotime((string)$inf['data_publicacao']); ?>
                <footer class="nm-rodape2">
                    <span><i class="bi bi-calendar3"></i>Publicada em <?= date('d/m/Y', $pub) ?> às <?= date('H:i', $enviado) ?></span>
                    <span><i class="bi bi-person"></i>Por <?= h($an) ?></span>
                    <span><i class="bi bi-tag"></i><?= h($crot) ?></span>
                </footer>
            </article>
        </div>
        <?php if ($mais): ?>
        <h2 class="sg-sub">Outras notícias <a class="nt-todas-link" href="informes.php">ver todas <i class="bi bi-arrow-right"></i></a></h2>
        <div class="nb-grade">
            <?php foreach (array_slice($mais, 0, 4) as $x): ?>
            <a class="nb-card" href="informe.php?id=<?= $x['id'] ?>">
                <div class="nb-capa"><?php if ($x['imagem']): ?><img src="<?= h(site_img_url('i', (int)$x['id'], $x['imagem'], true)) ?>" alt="" loading="lazy" style="<?= h(informe_foco($x)) ?>"><?php else: ?><span class="nb-semfoto"><img src="assets/img/brasao-128.png" alt=""></span><?php endif; ?></div>
                <div class="nb-corpo"><h3><?= h($x['titulo']) ?></h3><?php if (!empty($x['subtitulo'])): ?><p class="nb-sub"><?= h($x['subtitulo']) ?></p><?php endif; ?><time><?= date('d/m/Y', strtotime($x['data_publicacao'])) ?></time></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php $rodape_extra = '<script src="assets/js/inicio.js?v=7" defer></script>'; require __DIR__ . '/includes/site_rodape.php'; ?>
