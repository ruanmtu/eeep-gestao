<?php
/* v77 · Súmula em papel (foto/PDF) de um jogo do interclasse.
 * Aberta para todos só quando quem alimenta marcou "mostrar no site"; senão, só para quem alimenta o interclasse. */
require __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/arquivos.php';
$g = q_um('SELECT sumula_arquivo, sumula_nome, sumula_publica FROM ic_jogos WHERE id = ?', [(int)($_GET['jogo'] ?? 0)]);
if (!$g || !$g['sumula_arquivo'] || !preg_match('#^interclasse/\d{4}/[\w.-]+$#', $g['sumula_arquivo']) || (!$g['sumula_publica'] && !pode('interclasse'))) {
    http_response_code(404); exit('Súmula não encontrada.');
}
$ext = arquivo_extensao($g['sumula_arquivo']);
arquivo_enviar($g['sumula_arquivo'], IC_EXT_SUMULA[$ext] ?? 'application/octet-stream', $g['sumula_nome'] ?: basename($g['sumula_arquivo']), false);
