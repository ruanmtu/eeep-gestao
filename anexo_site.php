<?php
/* PDF anexado a uma notícia publicada: ?id=ID (abrir) ou ?id=ID&baixar=1 */
require __DIR__ . '/includes/app.php';
$a = null;
try {
    $a = q_um('SELECT x.*, i.titulo AS informe FROM site_informe_anexos x JOIN site_informes i ON i.id = x.informe_id
                WHERE x.id = ? AND ((i.publicado = 1 AND i.data_publicacao <= ?) OR ? = 1)', [(int)($_GET['id'] ?? 0), date('Y-m-d'), usuario() ? 1 : 0]);
} catch (PDOException $e) {}
if (!$a || !preg_match('#^site/anexos/[\w.-]+\.pdf$#', (string)$a['arquivo'])) { http_response_code(404); exit('Arquivo não encontrado.'); }
$nome = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', (string)$a['titulo']), ' ._') ?: 'documento';
arquivo_enviar((string)$a['arquivo'], 'application/pdf', $nome . '.pdf', !empty($_GET['baixar']));
