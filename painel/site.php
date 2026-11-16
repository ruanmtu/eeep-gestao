<?php
/* Site da escola: central com tudo o que aparece na página pública */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$conta = function (string $sql, array $p = []) { try { return (int)q_valor($sql, $p); } catch (PDOException $e) { return 0; } };
$hoje = date('Y-m-d');
$n_banners = $conta('SELECT COUNT(*) FROM site_banners WHERE ativo = 1 AND (inicio IS NULL OR inicio <= ?) AND (fim IS NULL OR fim >= ?)', [$hoje, $hoje]);
$n_noticias = $conta('SELECT COUNT(*) FROM site_informes WHERE publicado = 1');
$n_rasc = $conta('SELECT COUNT(*) FROM site_informes WHERE publicado = 0');
$n_eventos = $conta('SELECT COUNT(*) FROM eventos WHERE publico = 1 AND COALESCE(data_fim, data_inicio) >= ?', [$hoje]);
$n_gestao = $conta("SELECT COUNT(*) FROM equipe_site WHERE grupo = 'gestao' AND ativo = 1");
$n_equipe = $conta('SELECT COUNT(*) FROM equipe_site WHERE ativo = 1');
$ultima = null; try { $ultima = q_um('SELECT id, titulo, data_publicacao FROM site_informes ORDER BY id DESC LIMIT 1'); } catch (PDOException $e) {}
$cards = [
    ['conteudo_site.php', 'building', 'A escola e contato', 'Carrossel de fotos da escola, texto de apresentação, destaques e os dados de contato.', count(site_escola_fotos()), 'fotos no carrossel'],
    ['ambientes_site.php', 'buildings', 'Ambientes da escola', 'Laboratórios, quadra, biblioteca… Cada um tem a sua página com fotos e o que se estuda.', count(ambientes_lista()), 'ambientes'],
    ['banners_site.php', 'card-image', 'Banners da página inicial', 'As artes que passam no topo do site (1920 × 400 e 1600 × 900).', $n_banners, 'no ar'],
    ['informes_site.php', 'newspaper', 'Notícias', 'Publique o que acontece na escola, com foto, data e PDFs.', $n_noticias, 'publicadas' . ($n_rasc ? ' · ' . $n_rasc . ' rascunho(s)' : '')],
    ['agenda_site.php', 'calendar-event', 'Próximos eventos', 'Feriados, sábados letivos e eventos que aparecem no site.', $n_eventos, 'próximos'],
    ['gestao_site.php', 'person-workspace', 'Gestão escolar', 'Os 4 cartões de "Quem conduz a escola": foto, nome e texto.', min(4, $n_gestao ?: 4), 'cartões'],
    ['equipe.php', 'people', 'Equipe no site', 'Professores e funcionários da galeria da equipe, com formação e biografia.', $n_equipe, 'pessoas'],
    ['arquivos_site.php', 'folder2-open', 'Arquivos do site', 'Regimento, matriz curricular e outros documentos que qualquer visitante pode abrir ou baixar.', count(site_arquivos()), 'arquivos'],
    ['estudantes_site.php', 'images', 'Fotos dos estudantes', 'As fotos de "Quem faz a escola acontecer": trocar, enquadrar e remover.', count(site_estudantes($ESTUDANTES ?? [])), 'fotos'],
];
if (pode('ia.config')) { $ia_ok = ia_ativa(); $cards[] = ['assistente_ia.php', 'stars', 'Sofia · assistente de IA', 'Chave da API do Google, modelo e cota diária de perguntas.', null, $ia_ok ? 'Chave ativa' : 'Sem chave']; }
if (pode('usuarios')) { $g_ok = google_cfg()['ativo']; $cards[] = ['login_google.php', 'google', 'Login com Google', 'Credenciais do Google para entrar com a conta da escola.', null, $g_ok ? 'Ativo' : 'Desligado']; }

$titulo = 'Site';
$subtitulo = 'Tudo o que aparece na página pública da escola, o login com Google e a Sofia.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="st-capa">
    <i class="bi bi-globe2"></i>
    <div><b>Página da escola</b><span><?= $ultima ? 'Última notícia: ' . e(mb_strimwidth($ultima['titulo'], 0, 60, '…')) . ' · ' . data_curta($ultima['data_publicacao']) : 'Nenhuma notícia publicada ainda.' ?></span></div>
    <a href="informes_site.php"><i class="bi bi-plus-lg"></i>Nova notícia</a>
</div>
<div class="st-grade">
    <?php foreach ($cards as [$h, $ic, $tit, $txt, $n, $rot]): ?>
    <a class="st-card" href="<?= $h ?>">
        <span class="st-ic"><i class="bi bi-<?= $ic ?>"></i></span>
        <h3><?= e($tit) ?></h3>
        <p><?= e($txt) ?></p>
        <span class="st-num"><span><?php if ($n !== null): ?><b><?= (int)$n ?></b> <?php endif; ?><?= e($rot) ?></span><i class="bi bi-arrow-right"></i></span>
    </a>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
