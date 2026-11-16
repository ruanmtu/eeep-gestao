<?php
/* Abas das páginas do "Site" no painel (incluir logo depois do layout_topo) */
$_st_atual = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_st_abas = [
    ['site.php', 'grid-1x2', 'Visão geral'],
    ['conteudo_site.php', 'building', 'A escola e contato'],
    ['ambientes_site.php', 'buildings', 'Ambientes'],
    ['banners_site.php', 'card-image', 'Banners'],
    ['informes_site.php', 'newspaper', 'Notícias'],
    ['agenda_site.php', 'calendar-event', 'Próximos eventos'],
    ['arquivos_site.php', 'folder2-open', 'Arquivos'],
    ['gestao_site.php', 'person-workspace', 'Gestão escolar'],
    ['equipe.php', 'people', 'Equipe no site'],
    ['estudantes_site.php', 'images', 'Fotos dos estudantes'],
];
if (pode('ia.config')) $_st_abas[] = ['assistente_ia.php', 'stars', 'Sofia · chave da IA'];
if (pode('usuarios')) $_st_abas[] = ['login_google.php', 'google', 'Login com Google'];
?>
<nav class="st-abas" aria-label="Seções do site">
    <?php foreach ($_st_abas as [$_h, $_i, $_r]): ?><a href="<?= $_h ?>" class="<?= $_st_atual === $_h ? 'ativo' : '' ?>"<?= $_st_atual === $_h ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= $_i ?>"></i><?= $_r ?></a><?php endforeach; ?>
    <a class="st-ver" href="<?= RAIZ ?>index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Ver o site</a>
</nav>
