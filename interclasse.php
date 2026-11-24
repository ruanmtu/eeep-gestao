<?php
/* O interclasse tem site próprio: este endereço antigo só leva até ele (link em Painel › Site). */
require __DIR__ . '/includes/app.php';
redirecionar(ic_url() ?: 'index.php');
