<?php
require __DIR__ . '/../includes/app.php';
exigir('livros_didaticos');
$l = livro_didatico_buscar((int)($_GET['id'] ?? 0));
if (!$l) { flash('warning', 'Livro não encontrado.'); redirecionar('livros_didaticos.php'); }
try { q('UPDATE livros_didaticos SET acessos = acessos + 1 WHERE id = ?', [$l['id']]); } catch (PDOException $e) {}
[$tr, $ti] = TIPOS_LIVRO[$l['tipo']] ?? TIPOS_LIVRO['professor'];
$nd = $l['disciplina'] ?? $l['area'] ?? '';

$titulo = $l['titulo'];
$subtitulo = $tr . ' · ' . $nd . ($l['serie'] ? ' · ' . $l['serie'] . 'º ano' : '') . ($l['colecao'] ? ' · ' . $l['colecao'] : '') . ($l['edicao'] ? ' · ' . $l['edicao'] : '');
$menu = 'livros_didaticos';
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <a class="btn btn-light" href="livros_didaticos.php"><i class="bi bi-arrow-left me-1"></i>Livros didáticos</a>
    <span class="etiqueta"><i class="bi bi-<?= $ti ?> me-1"></i><?= e($tr) ?></span>
    <span class="small text-secondary"><?= tamanho_fmt((int)$l['tamanho']) ?><?= $l['autores'] ? ' · ' . e($l['autores']) : '' ?></span>
    <a class="btn btn-primary ms-auto" href="livro_arquivo.php?id=<?= $l['id'] ?>&baixar=1"><i class="bi bi-download me-1"></i>Baixar PDF</a>
    <a class="btn btn-light" href="livro_arquivo.php?id=<?= $l['id'] ?>" target="_blank" rel="noopener" title="Abrir no leitor do navegador"><i class="bi bi-box-arrow-up-right"></i></a>
</div>
<?php if ($l['descricao']): ?><p class="mb-3"><?= nl2br(e($l['descricao'])) ?></p><?php endif; ?>
<div class="visualizador-pdf alto livro" data-url="livro_arquivo.php?id=<?= $l['id'] ?>" data-memoria="livro<?= $l['id'] ?>" data-leve="1"></div>
<p class="small text-secondary mt-2"><i class="bi bi-bookmark me-1"></i>O sistema lembra a página em que você parou neste aparelho.</p>
<?php
$script_pagina = '<script type="module" src="../assets/js/visualizador.js?v=10"></script>';
require __DIR__ . '/../includes/layout_rodape.php';
