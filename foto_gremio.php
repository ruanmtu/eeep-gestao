<?php
/* v79 · Foto pública de quem está no Grêmio estudantil do site (só pessoas ativas) */
require __DIR__ . '/includes/app.php';
$foto = null;
try { $foto = q_valor('SELECT foto FROM site_gremio WHERE id = ? AND ativo = 1', [(int)($_GET['id'] ?? 0)]); } catch (PDOException $e) {}
if (!$foto || !preg_match('#^fotos/gremio/[\w.-]+$#', $foto) || !is_file(PASTA_DADOS . '/' . $foto)) { http_response_code(404); exit; }
header('Cache-Control: public, max-age=604800');
arquivo_enviar($foto, 'image/jpeg', 'foto.jpg', false);
