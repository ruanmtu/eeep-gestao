<?php
/* Os atos administrativos agora fazem parte do Registro de ocorrências */
require __DIR__ . '/../includes/app.php';
exigir_login();
redirecionar('ocorrencias.php');
