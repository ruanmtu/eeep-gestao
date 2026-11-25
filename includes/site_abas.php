<?php
/* Cabeçalho das páginas do "Site" no painel (incluir logo depois do layout_topo).
 * v82: em vez de uma faixa com 14 abas, um cabeçalho simples: voltar, onde estou, "Ir para…" e "Ver no site". */
$_st_atual = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_st_grupos = site_secoes_painel();
$_st_aqui = null;
foreach ($_st_grupos as $_g) foreach ($_g['itens'] as $_x) if ($_x[0] === $_st_atual) $_st_aqui = $_x;
?>
<?php if ($_st_atual !== 'site.php'): ?>
<nav class="st-nav" aria-label="Gerenciar o site">
    <a class="st-nav-voltar" href="site.php"><i class="bi bi-arrow-left"></i><span>Site</span></a>
    <?php if ($_st_aqui): ?><span class="st-nav-aqui"><i class="bi bi-<?= $_st_aqui[1] ?>"></i><?= e($_st_aqui[2]) ?></span><?php endif; ?>
    <div class="st-nav-dir">
        <?= $st_acoes ?? '' /* botões da própria página (ex.: "Novo banner"), na mesma linha */ ?>
        <div class="dropdown">
            <button class="btn st-nav-ir" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-list"></i><span>Ir para…</span></button>
            <div class="dropdown-menu dropdown-menu-end shadow st-nav-menu">
                <?php foreach ($_st_grupos as $_g): ?>
                <h6 class="dropdown-header"><?= e($_g['titulo']) ?></h6>
                <?php foreach ($_g['itens'] as [$_h, $_i, $_r]): ?><a class="dropdown-item<?= $_st_atual === $_h ? ' active' : '' ?>" href="<?= $_h ?>"><i class="bi bi-<?= $_i ?>"></i><?= e($_r) ?></a><?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <a class="btn st-nav-ver" href="<?= RAIZ ?>index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span>Ver no site</span></a>
    </div>
</nav>
<?php endif; ?>
