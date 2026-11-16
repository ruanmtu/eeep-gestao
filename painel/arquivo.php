<?php
/* Entrega um arquivo da Central de Arquivos ou do Calendário (para quem está logado) */
require __DIR__ . '/../includes/app.php';
exigir_login();
$a = q_um('SELECT * FROM arquivos WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$a) { http_response_code(404); exit('Arquivo não encontrado.'); }
$baixar = !empty($_GET['baixar']);
if ($baixar) {
    q('UPDATE arquivos SET downloads = downloads + 1 WHERE id = ?', [$a['id']]);
    registrar_log('arquivo_baixado', $a['titulo']);
}
arquivo_enviar($a['caminho'], $a['mime'] ?: 'application/octet-stream', $a['nome_original'], $baixar);
