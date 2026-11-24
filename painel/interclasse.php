<?php
/* Desativado: o interclasse agora tem site próprio. O link fica em Site › Links externos. */
require __DIR__ . '/../includes/app.php';
exigir_login();
redirecionar('site.php');
