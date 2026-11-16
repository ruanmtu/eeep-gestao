<?php
/* DIAGNÓSTICO: mostra o erro exato do site. Apague depois de usar. */
ini_set('display_errors', '1');
error_reporting(E_ALL);
echo '<div style="font:14px system-ui;background:#fff8e1;border:1px solid #e0c060;padding:10px 14px;margin:10px;border-radius:8px">'
   . '<b>Diagnóstico</b> · PHP ' . PHP_VERSION . ' · config.php: ' . (is_file(__DIR__ . '/includes/config.php') ? 'encontrado' : '<b style="color:#c00">NÃO ENCONTRADO</b>')
   . ' · escola.php: ' . (is_file(__DIR__ . '/includes/escola.php') ? 'ok' : '<b style="color:#c00">faltando</b>')
   . ' · app.php: ' . (is_file(__DIR__ . '/includes/app.php') ? 'ok' : '<b style="color:#c00">faltando</b>')
   . '<br>Se aparecer um erro em vermelho/preto abaixo, mande um print dele.</div>';
require __DIR__ . '/index.php';
