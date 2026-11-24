<?php
/* Rodapé público (site e tela de login) */
$prefixo = $prefixo ?? '';
$insta = $ESCOLA['instagram'] ? 'https://instagram.com/' . rawurlencode($ESCOLA['instagram']) : '';
$mapa_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ESCOLA['mapa']);
?>
<?php require_once __DIR__ . '/rodape_padrao.php'; echo rodape_padrao(''); ?>
<a href="#inicio" class="topo" aria-label="Voltar ao topo"><i class="bi bi-arrow-up"></i></a>
<script src="assets/js/site.js?v=23"></script>
<?= $rodape_extra ?? '' ?>
</body>
</html>
