<?php
/* Foto pública de quem aparece na equipe do site (galeria de gestores e de funcionários).
 * Só entrega fotos de pessoas cadastradas e ativas em "Equipe no site". */
require __DIR__ . '/includes/app.php';
$id = (int)($_GET['id'] ?? 0);
$foto = q_valor('SELECT foto FROM equipe_site WHERE id = ? AND ativo = 1', [$id]);
if (!$foto || !preg_match('#^fotos/(equipe|usuarios)/[\w.-]+$#', $foto) || !is_file(PASTA_DADOS . '/' . $foto)) { http_response_code(404); exit; }
header('Cache-Control: public, max-age=604800');
arquivo_enviar($foto, 'image/jpeg', 'foto.jpg', false);
