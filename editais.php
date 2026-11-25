<?php
/* v79 · Todos os editais de seleção publicados no site */
require __DIR__ . '/includes/app.php';
$editais = editais_lista();
$pagina_titulo = 'Editais · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=38" rel="stylesheet">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina nl-pagina">
    <section class="nl-hero">
        <div class="largura">
            <nav class="sg-migalha"><a href="index.php#documentos"><i class="bi bi-arrow-left"></i>Início</a></nav>
            <span class="sg-sobre">Seleção</span>
            <h1>Editais</h1>
            <p>Editais de seleção da EEEP Venceslau Vieira Batista: prazos, inscrição e resultados.</p>
            <span class="nl-total"><i class="bi bi-megaphone"></i><?= count($editais) ?> <?= count($editais) === 1 ? 'edital' : 'editais' ?></span>
        </div>
    </section>
    <div class="largura">
        <section class="dc2 ed2-pagina">
            <?php if (!$editais): ?><div class="sg-vazio"><i class="bi bi-megaphone"></i>Nenhum edital publicado no momento.</div><?php endif; ?>
            <div class="ed2-grade"><?php foreach ($editais as $x) echo edital_cartao($x); ?></div>
            <div class="dcv" id="dcVisor" hidden role="dialog" aria-modal="true" aria-labelledby="dcvTit">
                <div class="dcv-caixa">
                    <header class="dcv-cab">
                        <button type="button" class="dcv-x" data-fechar aria-label="Fechar"><i class="bi bi-arrow-left"></i></button>
                        <span class="dc-doc" id="dcvDoc"><i class="bi"></i><b></b></span>
                        <div class="dcv-txt"><b id="dcvTit"></b><small id="dcvMeta"></small></div>
                        <a class="dcv-baixar" id="dcvBaixar" href="#"><i class="bi bi-download"></i><span>Baixar</span></a>
                        <button type="button" class="dcv-x dcv-x2" data-fechar aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
                    </header>
                    <div class="dcv-corpo" id="dcvCorpo"></div>
                </div>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/site_rodape.php'; ?>
