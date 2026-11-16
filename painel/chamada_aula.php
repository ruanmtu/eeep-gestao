<?php
/* v59 · A chamada é feita no sistema Professor Online. Aqui fica só a coleta de faltas do dia (com relatório para imprimir). */
require __DIR__ . '/../includes/app.php';
exigir('frequencia');
$d = (string)($_GET['data'] ?? '');
redirecionar('frequencia.php' . (data_valida($d) ? '?data=' . $d : ''));
