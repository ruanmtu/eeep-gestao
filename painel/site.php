<?php
/* Site da escola: central com tudo o que aparece na página pública */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

/* v79: link do site do interclasse (aparece no menu do site enquanto estiver preenchido) */
if (eh_post() && ($_POST['acao'] ?? '') === 'links') {
    csrf_verificar();
    $u = trim((string)($_POST['interclasse_url'] ?? ''));
    if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    if ($u !== '' && !filter_var($u, FILTER_VALIDATE_URL)) { flash('danger', 'O endereço do site do interclasse não parece válido.'); redirecionar('site.php'); }
    config_gravar('interclasse_url', mb_substr($u, 0, 255));
    flash('success', $u ? 'Link do interclasse salvo. Ele aparece no atalho da página inicial.' : 'Link do interclasse retirado do site.');
    redirecionar('site.php');
}

$conta = function (string $sql, array $p = []) { try { return (int)q_valor($sql, $p); } catch (PDOException $e) { return 0; } };
$hoje = date('Y-m-d');
$n_banners = $conta('SELECT COUNT(*) FROM site_banners WHERE ativo = 1 AND (inicio IS NULL OR inicio <= ?) AND (fim IS NULL OR fim >= ?)', [$hoje, $hoje]);
$n_noticias = $conta('SELECT COUNT(*) FROM site_informes WHERE publicado = 1');
$n_rasc = $conta('SELECT COUNT(*) FROM site_informes WHERE publicado = 0');
$n_eventos = $conta('SELECT COUNT(*) FROM eventos WHERE publico = 1 AND COALESCE(data_fim, data_inicio) >= ?', [$hoje]);
$n_gestao = $conta("SELECT COUNT(*) FROM equipe_site WHERE grupo = 'gestao' AND ativo = 1");
$n_equipe = $conta('SELECT COUNT(*) FROM equipe_site WHERE ativo = 1');
$ultima = null; try { $ultima = q_um('SELECT id, titulo, data_publicacao FROM site_informes ORDER BY id DESC LIMIT 1'); } catch (PDOException $e) {}
/* v82: números de cada seção (aparecem como uma etiqueta pequena na lista) */
$num = [
    'conteudo_site.php' => count(site_escola_fotos()) . ' fotos', 'ambientes_site.php' => count(ambientes_lista()) . ' ambientes',
    'banners_site.php' => $n_banners . ' no ar', 'informes_site.php' => $n_noticias . ' publicadas' . ($n_rasc ? ' · ' . $n_rasc . ' rascunho' . ($n_rasc > 1 ? 's' : '') : ''),
    'agenda_site.php' => $n_eventos . ' próximos', 'gestao_site.php' => min(4, $n_gestao ?: 4) . ' cartões', 'equipe.php' => $n_equipe . ' pessoas',
    'arquivos_site.php' => count(site_arquivos()) . ' arquivos', 'editais_site.php' => count(editais_lista()) . ' editais', 'gremio_site.php' => count(gremio_lista()) . ' membros',
    'estudantes_site.php' => count(site_estudantes($ESTUDANTES ?? [])) . ' fotos',
];
if (pode('ia.config')) $num['assistente_ia.php'] = ia_ativa() ? 'Ativa' : 'Sem chave';
if (pode('usuarios')) $num['login_google.php'] = google_cfg()['ativo'] ? 'Ativo' : 'Desligado';
$acoes = [
    ['informes_site.php', 'newspaper', 'Publicar uma notícia', 'laranja'],
    ['banners_site.php', 'card-image', 'Trocar um banner', 'verde'],
    ['editais_site.php', 'megaphone', 'Publicar um edital', 'azul'],
    ['agenda_site.php', 'calendar-plus', 'Adicionar um evento', 'roxo'],
];

$titulo = 'Site';
$subtitulo = 'Escolha o que quer mudar na página da escola. Tudo o que você salvar aparece no site na hora.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="st-ola">
    <div class="st-ola-txt"><span>Página pública da escola</span><b>O que você quer fazer hoje?</b>
        <small><?= $ultima ? 'Última notícia: ' . e(mb_strimwidth($ultima['titulo'], 0, 60, '…')) . ' · ' . data_curta($ultima['data_publicacao']) : 'Nenhuma notícia publicada ainda.' ?></small></div>
    <a class="st-ola-ver" href="<?= RAIZ ?>index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Ver o site</a>
</div>
<div class="st-acoes">
    <?php foreach ($acoes as [$h, $ic, $rot, $cor]): ?><a class="st-acao <?= $cor ?>" href="<?= $h ?>"><i class="bi bi-<?= $ic ?>"></i><span><?= e($rot) ?></span></a><?php endforeach; ?>
</div>
<?php foreach (site_secoes_painel() as $g): ?>
<section class="st-grupo">
    <h2><?= e($g['titulo']) ?></h2>
    <div class="st-lista">
        <?php foreach ($g['itens'] as [$h, $ic, $rot, $txt]): ?>
        <a class="st-item" href="<?= $h ?>"><span class="st-item-ic"><i class="bi bi-<?= $ic ?>"></i></span><span class="st-item-t"><b><?= e($rot) ?></b><small><?= e($txt) ?></small></span><?php if (isset($num[$h])): ?><span class="st-item-n"><?= e($num[$h]) ?></span><?php endif; ?><i class="bi bi-chevron-right st-item-seta"></i></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<form method="post" class="st-grupo st-links"><?= csrf_campo() ?><input type="hidden" name="acao" value="links">
    <h2>Links externos</h2>
    <div class="st-links-caixa">
        <label class="form-label" for="icUrl"><i class="bi bi-trophy me-1"></i>Site do interclasse</label>
        <div class="input-group"><input class="form-control" id="icUrl" name="interclasse_url" type="text" inputmode="url" autocomplete="url" maxlength="255" placeholder="https://…" value="<?= e(config_ler('interclasse_url') ?: '') ?>"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar</button></div>
        <div class="form-text">Com o endereço preenchido, o atalho “Eventos” da página inicial vira “Interclasse”, abrindo o site do interclasse. Deixe vazio para tirar o link.</div>
    </div>
</form>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
