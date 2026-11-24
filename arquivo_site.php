<?php
/* Arquivo público do site (regimento, matriz curricular…): ?id=ID (abrir) ou ?id=ID&baixar=1 — não precisa de login */
require __DIR__ . '/includes/app.php';
/* v79: PDF do edital (?edital=ID) ou do resultado (?edital=ID&r=1) */
if (isset($_GET['edital'])) {
    $ed = null;
    try { $ed = q_um('SELECT * FROM site_editais WHERE id = ? AND (ativo = 1 OR ? = 1)', [(int)$_GET['edital'], usuario() ? 1 : 0]); } catch (PDOException $e) {}
    $c = !empty($_GET['r']) ? 'resultado' : 'arquivo';
    $ext = (string)($ed[$c === 'resultado' ? 'resultado_ext' : 'arquivo_ext'] ?? '');
    if (!$ed || empty($ed[$c]) || !isset(ARQ_SITE_TIPOS[$ext]) || !preg_match('#^site/arquivos/[\w.-]+$#', (string)$ed[$c])) { http_response_code(404); exit('Arquivo não encontrado.'); }
    $nome = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', ($c === 'resultado' ? 'Resultado - ' : '') . $ed['titulo']), ' ._') ?: 'edital';
    arquivo_enviar((string)$ed[$c], ARQ_SITE_TIPOS[$ext][0], $nome . '.' . $ext, !empty($_GET['baixar']) || !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true));
    exit;
}
$a = null;
try { $a = q_um('SELECT * FROM site_arquivos WHERE id = ? AND (ativo = 1 OR ? = 1)', [(int)($_GET['id'] ?? 0), usuario() ? 1 : 0]); } catch (PDOException $e) {}
$tipo = ARQ_SITE_TIPOS[$a['extensao'] ?? ''] ?? null;
if (!$a || !$tipo || !preg_match('#^site/arquivos/[\w.-]+$#', (string)$a['arquivo'])) { http_response_code(404); exit('Arquivo não encontrado.'); }
$nome = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', (string)$a['titulo']), ' ._') ?: 'documento';
/* PDF e imagem abrem no navegador; os demais sempre baixam */
$abre = in_array($a['extensao'], ['pdf', 'jpg', 'jpeg', 'png'], true);
arquivo_enviar((string)$a['arquivo'], $tipo[0], $nome . '.' . $a['extensao'], !empty($_GET['baixar']) || !$abre);
