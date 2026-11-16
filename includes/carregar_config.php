<?php
/* Carrega includes/config.php ou explica o que fazer se ele não existir */
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configuração</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:28px;border:1px solid #dbe8dc;border-radius:14px;line-height:1.5">'
       . '<h2 style="color:#13692a;margin-top:0">Falta o arquivo de configuração</h2>'
       . '<p>O arquivo <code>includes/config.php</code> não está no servidor. Ele guarda os dados do banco de dados.</p>'
       . '<p><strong>Como resolver:</strong> no Gerenciador de arquivos, abra a pasta <code>includes</code>, renomeie '
       . '<code>config.exemplo.php</code> para <code>config.php</code> e preencha DB_NAME, DB_USER e DB_PASS com os dados do banco da Hostinger.</p></div>');
}
require_once __DIR__ . '/config.php';
