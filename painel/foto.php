<?php
/* Entrega a foto de um aluno (t=a) ou de um usuário (t=u) para quem está logado */
require __DIR__ . '/../includes/app.php';
exigir_login();
$id = (int)($_GET['id'] ?? 0);
$foto = ($_GET['t'] ?? '') === 'u'
    ? q_valor('SELECT foto FROM usuarios WHERE id = ?', [$id])
    : q_valor('SELECT foto FROM alunos WHERE id = ?', [$id]);
if (!$foto || !is_file(PASTA_DADOS . '/' . $foto)) { http_response_code(404); exit; }
header('Cache-Control: private, max-age=604800');
arquivo_enviar($foto, 'image/jpeg', 'foto.jpg', false);
