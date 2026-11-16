    </main>
    <?php require_once __DIR__ . '/rodape_padrao.php'; echo rodape_padrao(RAIZ); ?>
</div>

<script src="<?= RAIZ ?>assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php if (!empty($usa_graficos)): ?>
<script src="<?= RAIZ ?>assets/vendor/chartjs/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= RAIZ ?>assets/js/painel.js?v=13"></script>
<script src="<?= RAIZ ?>assets/js/recorte.js?v=3"></script>
<script src="<?= RAIZ ?>assets/js/lote.js?v=1"></script>
<script src="<?= RAIZ ?>assets/js/janela.js?v=1"></script>
<script src="<?= RAIZ ?>assets/js/resultados.js?v=3" defer></script>
<?php if (!empty($script_pagina)) echo $script_pagina; ?>
</body>
</html>
