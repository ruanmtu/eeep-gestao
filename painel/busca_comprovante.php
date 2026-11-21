<?php
/* v74 · Abre o comprovante anexado a uma providência da busca ativa (só para quem acompanha o aluno) */
require __DIR__ . '/../includes/app.php';
exigir_login();
$b = q_um('SELECT b.*, a.turma_id, t.curso_id FROM busca_ativa b JOIN alunos a ON a.id = b.aluno_id LEFT JOIN turmas t ON t.id = a.turma_id WHERE b.id = ?', [(int)($_GET['id'] ?? 0)]);
$pode = $b && (pode('busca_ativa') || eh_gestao() || dt_do_aluno($b) || in_array((int)$b['curso_id'], cursos_coordenados(), true) || (int)$b['registrado_por'] === uid());
if (!$b || !$pode || empty($b['comprovante']) || !preg_match('#^busca_ativa/\d{4}/[\w.-]+$#', $b['comprovante'])) { http_response_code(404); exit('Comprovante não encontrado.'); }
$ext = arquivo_extensao($b['comprovante']);
arquivo_enviar($b['comprovante'], EXT_COMPROVANTE[$ext] ?? 'application/octet-stream', $b['comprovante_nome'] ?: basename($b['comprovante']), !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'mp3', 'm4a', 'ogg', 'opus'], true));
