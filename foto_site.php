<?php
/* Fotos públicas do site (álbuns publicados e informes publicados). ?f=ID (foto), ?i=ID (informe) ou ?b=ID (banner); &p=1 = miniatura */
require __DIR__ . '/includes/app.php';
$arq = null;
if (isset($_GET['f'])) $arq = q_valor('SELECT f.arquivo FROM site_fotos f JOIN site_albuns a ON a.id = f.album_id WHERE f.id = ? AND (a.publicado = 1 OR ? = 1)', [(int)$_GET['f'], usuario() ? 1 : 0]);
elseif (isset($_GET['b'])) $arq = q_valor('SELECT ' . (!empty($_GET['l']) ? 'imagem_larga' : 'imagem') . ' FROM site_banners WHERE id = ? AND (ativo = 1 OR ? = 1)', [(int)$_GET['b'], usuario() ? 1 : 0]);
elseif (isset($_GET['g'])) $arq = config_ler('site_gestao_foto');
elseif (isset($_GET['dq'])) $arq = config_ler('site_destaques_foto');   // v80: foto do card de alunos destaque
elseif (isset($_GET['e'])) $arq = (estudantes_lista() ?? [])[(int)$_GET['e']]['a'] ?? null;
elseif (isset($_GET['c'])) $arq = (escola_fotos_lista() ?? [])[(int)$_GET['c']]['a'] ?? null;
elseif (isset($_GET['am'])) { $am = q_um('SELECT id, fotos FROM site_ambientes WHERE id = ? AND (ativo = 1 OR ? = 1)', [(int)$_GET['am'], usuario() ? 1 : 0]); $arq = $am ? (ambiente_fotos($am)[(int)($_GET['n'] ?? 0)] ?? null) : null; }
elseif (isset($_GET['i'])) $arq = q_valor('SELECT imagem FROM site_informes WHERE id = ? AND (publicado = 1 OR ? = 1)', [(int)$_GET['i'], usuario() ? 1 : 0]);
if ($arq && !empty($_GET['p'])) { $mini = preg_replace('/\.jpg$/', '_p.jpg', $arq); if (is_file(PASTA_DADOS . '/' . $mini)) $arq = $mini; }
if (!$arq || !preg_match('#^site/[\w/.-]+\.jpg$#', $arq) || str_contains($arq, '..') || !is_file(PASTA_DADOS . '/' . $arq)) { http_response_code(404); exit; }
while (ob_get_level()) ob_end_clean();
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize(PASTA_DADOS . '/' . $arq));
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
readfile(PASTA_DADOS . '/' . $arq);
