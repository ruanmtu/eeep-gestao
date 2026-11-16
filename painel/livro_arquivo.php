<?php
/* Entrega o PDF do livro didático (com "Range": o leitor abre aos poucos) */
require __DIR__ . '/../includes/app.php';
exigir('livros_didaticos');
$l = livro_didatico_buscar((int)($_GET['id'] ?? 0));
if (!$l || !preg_match('#^livros/\d{4}/[\w.-]+\.pdf$#', $l['caminho'])) { http_response_code(404); exit('Livro não encontrado.'); }
$baixar = !empty($_GET['baixar']);
if ($baixar) { try { q('UPDATE livros_didaticos SET acessos = acessos + 1 WHERE id = ?', [$l['id']]); } catch (PDOException $e) {} registrar_log('livro_baixado', $l['titulo']); }
session_write_close();      // não trava as outras abas enquanto o livro é baixado
arquivo_enviar_faixa(PASTA_DADOS . '/' . $l['caminho'], 'application/pdf', $l['nome_original'] ?: 'livro.pdf', $baixar);
