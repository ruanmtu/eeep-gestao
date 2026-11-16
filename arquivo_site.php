<?php
/* Arquivo público do site (regimento, matriz curricular…): ?id=ID (abrir) ou ?id=ID&baixar=1 — não precisa de login */
require __DIR__ . '/includes/app.php';
$a = null;
try { $a = q_um('SELECT * FROM site_arquivos WHERE id = ? AND (ativo = 1 OR ? = 1)', [(int)($_GET['id'] ?? 0), usuario() ? 1 : 0]); } catch (PDOException $e) {}
$tipo = ARQ_SITE_TIPOS[$a['extensao'] ?? ''] ?? null;
if (!$a || !$tipo || !preg_match('#^site/arquivos/[\w.-]+$#', (string)$a['arquivo'])) { http_response_code(404); exit('Arquivo não encontrado.'); }
$nome = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', (string)$a['titulo']), ' ._') ?: 'documento';
/* PDF e imagem abrem no navegador; os demais sempre baixam */
$abre = in_array($a['extensao'], ['pdf', 'jpg', 'jpeg', 'png'], true);
arquivo_enviar((string)$a['arquivo'], $tipo[0], $nome . '.' . $a['extensao'], !empty($_GET['baixar']) || !$abre);
