<?php
require __DIR__ . '/includes/app.php';

if (usuario() && hash_equals(csrf_token(), (string)($_GET['csrf'] ?? ''))) {
    registrar_log('logout', 'Saiu do sistema');
    $_SESSION = [];
    session_regenerate_id(true);
    flash('success', 'Você saiu do sistema.');
}
redirecionar('login.php');
