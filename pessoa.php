<?php
/* Página pública de uma pessoa da equipe: foto, função, formação e uma breve biografia */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$id = (int)($_GET['id'] ?? 0);
$equipe = equipe_lista();
$p = null; foreach ($equipe as $e) if ((int)$e['id'] === $id) $p = $e;
if (!$p) { header('Location: index.php#equipe'); exit; }
$src = equipe_foto_src($p);
$iniciais = mb_strtoupper(implode('', array_map(fn($x) => mb_substr($x, 0, 1), array_slice(preg_split('/\s+/', trim($p['nome'])), 0, 2))));
$mesmos = array_slice(array_values(array_filter($equipe, fn($e) => $e['grupo'] === $p['grupo'] && (int)$e['id'] !== $id)), 0, 12);
$icones = ['gestao' => 'star-fill', 'professores' => 'mortarboard-fill', 'secretaria' => 'folder-fill', 'multimeios' => 'book-fill', 'apoio' => 'tools'];
$pagina_titulo = $p['nome'] . ' · ' . $ESCOLA['nome'];
$pagina_descricao = $p['funcao'] . ' na ' . $ESCOLA['nome'] . '.';
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=26" rel="stylesheet">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina pa-pagina">
    <div class="largura">
        <nav class="sg-migalha"><a href="index.php#<?= $p['grupo'] === 'gestao' ? 'gestao' : 'equipe' ?>"><i class="bi bi-arrow-left"></i>Nossa equipe</a></nav>
        <article class="pe-cartao">
            <figure class="pe-foto"><?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h($p['nome']) ?>"><?php else: ?><span><?= h($iniciais) ?></span><?php endif; ?></figure>
            <div class="pe-info">
                <span class="pe-grupo"><i class="bi bi-<?= $icones[$p['grupo']] ?? 'person-fill' ?>"></i><?= h($GRUPOS_EQUIPE[$p['grupo']] ?? 'Equipe') ?></span>
                <h1><?= h($p['nome']) ?></h1>
                <p class="pe-funcao"><?= h($p['funcao']) ?></p>
                <?php if (trim((string)($p['formacao'] ?? '')) !== ''): ?>
                <div class="pe-form"><i class="bi bi-mortarboard"></i><div><small>Formação <sup class="pe-ast" title="Informado pela própria pessoa">*</sup></small><b><?= h($p['formacao']) ?></b></div></div>
                <?php endif; ?>
                <div class="pe-bio">
                    <?php $bio = trim((string)($p['biografia'] ?? '')) ?: trim((string)($p['texto'] ?? '')); ?>
                    <h2>Sobre<?= $bio !== '' ? ' <sup class="pe-ast" title="Informado pela própria pessoa">*</sup>' : '' ?></h2>
                    <?php if ($bio !== ''): ?><p><?= h($bio) ?></p><?php else: ?><p class="pa-vazio">Em breve, uma apresentação de <?= h(explode(' ', $p['nome'])[0]) ?>.</p><?php endif; ?>
                </div>
                <?php if ($bio !== '' || trim((string)($p['formacao'] ?? '')) !== ''): ?>
                <p class="pe-nota"><sup>*</sup> Formação e texto informados pela própria pessoa (<?= h(explode(' ', $p['nome'])[0]) ?>).</p>
                <?php endif; ?>
            </div>
        </article>
        <?php if ($mesmos): ?>
        <section class="pe-mais">
            <h2><?= h($GRUPOS_EQUIPE[$p['grupo']] ?? 'Equipe') ?></h2>
            <ul><?php foreach ($mesmos as $m): $ms = equipe_foto_src($m); ?>
                <li class="g-<?= h($m['grupo']) ?>"><a href="pessoa.php?id=<?= (int)$m['id'] ?>"><div class="eq2-foto"><?php if ($ms): ?><img src="<?= h($ms) ?>" alt="" loading="lazy"><?php else: ?><span class="eq2-ini"><?= h(mb_strtoupper(mb_substr($m['nome'], 0, 1))) ?></span><?php endif; ?></div><strong><?= h($m['nome']) ?></strong><small><?= h($m['funcao']) ?></small></a></li>
            <?php endforeach; ?></ul>
        </section>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/site_rodape.php'; ?>
