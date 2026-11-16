<?php
/* v59 · Não há mais lançamento manual de notas: elas entram só pela planilha do Power BI (diretor).
   Os professores usam a Nota conceitual. */
require __DIR__ . '/../includes/app.php';
exigir_login();
redirecionar(pode('importar') ? 'importar.php' : 'nota_conceitual.php');
