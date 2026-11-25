<?php
/* Página pública da escola: uma galeria da escola (fotos, informes, cursos, história e equipe), pensada primeiro para o celular */
require __DIR__ . '/includes/app.php';

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function img_existe(?string $c): bool { return $c && is_file(__DIR__ . '/' . $c); }

/* Números ao vivo (se o banco estiver fora, a página continua funcionando) */
$numeros = ['alunos' => null, 'turmas' => null];
try {
    $numeros['alunos'] = (int)q_valor("SELECT COUNT(*) FROM alunos WHERE situacao = 'ativo'");
    $numeros['turmas'] = (int)q_valor('SELECT COUNT(*) FROM turmas WHERE ativo = 1 AND ano_letivo = ?', [ano_hoje()]);
} catch (Throwable $e) {}

/* Conteúdo publicado pelo painel */
$albuns = site_albuns(true, 30);
$albuns = array_values(array_filter($albuns, fn($a) => $a['n_fotos'] > 0));
$informes = site_informes(true, 41);
$equipe = []; try { $equipe = equipe_lista(); } catch (Throwable $e) {}
$foto_eq = fn($e) => equipe_foto_src($e);
$gestores_site = array_values(array_filter($equipe, fn($e) => $e['grupo'] === 'gestao'));
if (!$gestores_site) foreach ($GESTORES as $g) $gestores_site[] = ['nome' => $g['nome'], 'funcao' => $g['cargo'], 'texto' => $g['texto'], 'src' => img_existe($g['foto']) ? $g['foto'] : null];
else $gestores_site = array_map(fn($e) => $e + ['src' => $foto_eq($e)], $gestores_site);
$iniciais = fn($n) => mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($n)), 0, 2))));

/* Fotos da capa: as capas dos álbuns mais recentes; sem álbuns, as fotos da escola */
$capa = [];
foreach (array_slice($albuns, 0, 6) as $a) $capa[] = ['src' => site_img_url('f', (int)$a['capa_foto'], (string)$a['capa_arquivo']), 'legenda' => $a['titulo'], 'link' => 'album.php?id=' . $a['id']];
if (count($capa) < 3) {
    foreach (array_merge([['imagem' => $HERO[0]['imagem'], 'legenda' => 'Nossa escola']], $ESTUDANTES ?? []) as $g)
        if (img_existe($g['imagem']) && count($capa) < 5) $capa[] = ['src' => $g['imagem'], 'legenda' => $g['legenda'], 'link' => 'galeria.php'];
}
$estudantes = site_estudantes($ESTUDANTES ?? []);
$galeria_fixa = array_values(array_filter($GALERIA, fn($g) => img_existe($g['imagem'])));
$icones_cursos = ['Informática' => 'pc-display', 'Desenvolvimento de Sistemas' => 'code-slash', 'Enfermagem' => 'heart-pulse', 'Administração' => 'briefcase'];
$insta = $ESCOLA['instagram'] ? 'https://instagram.com/' . rawurlencode($ESCOLA['instagram']) : '';
$mapa_link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ESCOLA['mapa']);
$ano_inicio = 2009;   // começou como EEEP David Vieira da Silva

/* Banners do topo e atalhos */
$banners = site_banners_para_mostrar();
$dq = destaques_cfg(); if (!empty($dq['ativo']) && $dq['alunos']) array_unshift($banners, ['dq' => $dq, 'titulo' => $dq['titulo']]);   // v80: card dos alunos destaque
$inf_matricula = null; foreach ($informes as $i) if ($i['categoria'] === 'matricula') { $inf_matricula = $i; break; }
$atalhos = [   /* só atalhos de uso público (os do painel ficam no login) */
    editais_lista() ? ['person-check', 'Seleção', 'editais.php'] : ['person-check', 'Como entrar', '#contato'],   // v86: para quem quer uma vaga na escola
    ['megaphone', 'Notícias', 'informes.php'],
    ic_url() ? ['trophy', 'Interclasse', ic_url()] : ['calendar-event', 'Eventos', '#agendaGrade'],   // v80: o interclasse só aparece neste atalho
    ['mortarboard', 'Cursos', '#cursos'],
    ['people', 'Equipe', $equipe ? '#equipe' : '#gestao'],
    ['building', 'A escola', '#escola'],
    site_arquivos() || editais_lista() ? ['folder2-open', 'Documentos', '#documentos'] : ['geo-alt', 'Como chegar', '#contato'],
    gremio_lista() ? ['flag', 'Grêmio estudantil', '#gremio'] : ['person-badge', 'Gestão escolar', '#gestao'],
    ['clock-history', 'História', '#historia'],
    ['chat-dots', 'Fale conosco', '#contato'],
];

$cabeca_extra = '<link href="assets/css/inicio.css?v=36" rel="stylesheet">';
$pagina_classe = 'inicio-galeria';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo">
<h1 class="so-leitor"><?= h($ESCOLA['nome_oficial']) ?></h1>

<!-- ================= CAPA: fotos da escola ================= -->
<!-- ================= BANNERS ================= -->
<?php if ($banners): ?>
<section class="sg-banners" id="inicio" aria-roledescription="carrossel" aria-label="Destaques da escola">
    <div class="sg-bn-trilha" id="bnTrilha" tabindex="0">
        <?php foreach ($banners as $i => $b): if (!empty($b['dq'])) { echo destaques_slide($b['dq'], $i, count($banners)); continue; } $tag = $b['link'] ? 'a' : 'div'; ?>
        <<?= $tag ?> class="sg-bn"<?= $b['link'] ? ' href="' . h($b['link']) . '"' . (str_starts_with($b['link'], 'http') ? ' target="_blank" rel="noopener"' : '') : '' ?> aria-roledescription="slide" aria-label="<?= $i + 1 ?> de <?= count($banners) ?>: <?= h($b['titulo']) ?>">
            <picture><?php if (!empty($b['larga'])): ?><source media="(min-width: 992px)" srcset="<?= h($b['larga']) ?>"><?php endif; ?><img src="<?= h($b['src']) ?>" alt="<?= h($b['titulo']) ?>" width="1600" height="900"<?= $i > 1 ? ' loading="lazy"' : ' fetchpriority="high"' ?>></picture>
        </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
    <?php if (count($banners) > 1): ?>
    <div class="sg-bn-ctrl largura">
        <button type="button" class="sg-bn-seta" data-bn="-1" aria-label="Banner anterior"><i class="bi bi-chevron-left"></i></button>
        <div class="sg-bn-pontos" id="bnPontos"><?php foreach ($banners as $i => $b): ?><button type="button" class="<?= $i === 0 ? 'ativo' : '' ?>" data-ir="<?= $i ?>" aria-label="Ir para o banner <?= $i + 1 ?>"></button><?php endforeach; ?></div>
        <button type="button" class="sg-bn-seta" data-bn="1" aria-label="Próximo banner"><i class="bi bi-chevron-right"></i></button>
    </div>
    <?php endif; ?>
</section>
<?php else: ?><span id="inicio"></span><?php endif; ?>

<!-- ================= ATALHOS ================= -->
<nav class="sg-atalhos" aria-label="Atalhos">
    <ul class="largura">
        <?php $sep = false; foreach ($atalhos as [$ic, $rot, $url]): $restrito = str_starts_with($url, 'painel/'); if ($restrito && !$sep): $sep = true; ?><li class="at-sep" aria-hidden="true"></li><?php endif; ?>
        <li<?= $restrito ? ' class="at-equipe"' : '' ?>><a href="<?= h($url) ?>"<?= $restrito ? ' title="Para professores e gestão: pede login"' : '' ?><?= str_starts_with($url, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><span><i class="bi bi-<?= $ic ?>"></i><?= $restrito ? '<b class="at-cad" aria-hidden="true"><i class="bi bi-lock-fill"></i></b>' : '' ?></span><?= h($rot) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- ================= NOTÍCIAS E EVENTOS (computador) ================= -->
<section class="nd2" id="noticias">
    <div class="largura">
        <?php if ($informes): ?>
        <div class="nd2-cab"><h2>Últimas notícias</h2><a href="informes.php">Ver todas <i class="bi bi-arrow-right"></i></a></div>
        <div class="nd2-grade">
            <?php foreach (array_slice($informes, 0, 4) as $inf): ?>
            <a class="nd2-card" href="informe.php?id=<?= $inf['id'] ?>">
                <div class="nd2-capa"><?php if ($inf['imagem']): ?><img src="<?= h(site_img_url('i', (int)$inf['id'], $inf['imagem'], true)) ?>" alt="" loading="lazy" style="<?= h(informe_foco($inf)) ?>"><?php else: ?><span class="nb-semfoto"><img src="assets/img/brasao-128.png" alt=""></span><?php endif; ?><?php if (!empty($inf['n_anexos'])): ?><span class="nb-pdf"><i class="bi bi-file-earmark-pdf-fill"></i>PDF</span><?php endif; ?></div>
                <div class="nd2-corpo">
                    <time><i class="bi bi-calendar3"></i><?= h(data_extenso_curta((string)$inf['data_publicacao'])) ?></time>
                    <h3><?= h($inf['titulo']) ?></h3>
                    <p<?= !empty($inf['subtitulo']) ? ' class="nd2-sub"' : '' ?>><?= h(!empty($inf['subtitulo']) ? $inf['subtitulo'] : mb_strimwidth(materia_texto((string)$inf['texto']), 0, 110, '…')) ?></p>
                    <span class="nd2-ler">Ler mais <i class="bi bi-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php $proximos = site_proximos_eventos(8); if ($proximos): $mc = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']; ?>
        <div class="nd2-cab" id="agendaGrade"><h2>Próximos eventos</h2></div>
        <div class="nd2-eventos">
            <?php foreach ($proximos as $e): $breve = $e['k'] === 'breve'; $cor = $breve ? '#d4650a' : (AGENDA_TIPOS[$e['k']][1] ?? '#1d5fb0'); $tag = $e['l'] ? 'a' : 'div'; ?>
            <<?= $tag ?> class="nd2-ev" style="--c:<?= $cor ?>"<?= $e['l'] ? ' href="' . h($e['l']) . '"' : '' ?>>
                <span class="nd2-data"><?php if ($breve): ?><i class="bi bi-hourglass-split"></i><small>breve</small><?php else: ?><b><?= (int)substr($e['d'], 8, 2) ?></b><small><?= $mc[(int)substr($e['d'], 5, 2)] ?></small><?php endif; ?></span>
                <span class="nd2-ev-txt"><strong><?= h($e['t']) ?></strong><small><?= $breve ? 'Data a confirmar' : h(AGENDA_TIPOS[$e['k']][0] ?? 'Evento') ?><?= !$breve && $e['f'] ? ' · até ' . (int)substr($e['f'], 8, 2) . ' ' . $mc[(int)substr($e['f'], 5, 2)] : '' ?></small></span>
            </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ================= NOTÍCIAS E CALENDÁRIO ================= -->
<section class="sg-secao nt-secao" id="informes">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Notícias</span><h2>Acontece na escola</h2></div><a class="sg-mais" href="informes.php">Todas <i class="bi bi-arrow-right"></i></a></div>
        <div class="nt-topo">
            <?php if ($informes): $inf0 = $informes[0]; ?>
            <a class="nd-card" href="informe.php?id=<?= $inf0['id'] ?>">
                <?php if ($inf0['imagem']): ?><img class="nd-foto" src="<?= h(site_img_url('i', (int)$inf0['id'], $inf0['imagem'])) ?>" alt="" style="<?= h(informe_foco($inf0)) ?>"><?php else: ?><span class="nd-foto nd-semfoto"><img src="assets/img/brasao-128.png" alt=""></span><?php endif; ?>
                <div class="nd-txt">
                    <h3><?= h($inf0['titulo']) ?></h3>
                    <p><?= h(mb_strimwidth(materia_texto((string)$inf0['texto']), 0, 170, '…')) ?></p>
                    <time class="nd-quando"><?= date('d/m/Y', strtotime($inf0['data_publicacao'])) ?></time>
                </div>
            </a>
            <?php else: ?>
            <div class="sg-vazio"><i class="bi bi-newspaper"></i>Em breve, as notícias da escola aparecem aqui.</div>
            <?php endif; ?>
            <aside class="nt-lado"><?= agenda_cartao() ?></aside>
        </div>
        <?php $mais_noticias = array_slice($informes, 1, 6); if ($mais_noticias): ?>
        <div class="nt-mais">
            <h3 class="nt-mais-tit">Mais notícias</h3>
            <div class="nb-lista" id="nbLista">
                <?php foreach ($mais_noticias as $inf): ?>
                <a class="nb-card" href="informe.php?id=<?= $inf['id'] ?>">
                    <div class="nb-capa"><?php if ($inf['imagem']): ?><img src="<?= h(site_img_url('i', (int)$inf['id'], $inf['imagem'], true)) ?>" alt="" loading="lazy" style="<?= h(informe_foco($inf)) ?>"><?php else: ?><span class="nb-semfoto"><img src="assets/img/brasao-128.png" alt=""></span><?php endif; ?>
                        <?php if (!empty($inf['n_anexos'])): ?><span class="nb-pdf"><i class="bi bi-file-earmark-pdf-fill"></i>PDF</span><?php endif; ?></div>
                    <div class="nb-corpo">
                        <h3><?= h($inf['titulo']) ?></h3>
                        <?php if (!empty($inf['subtitulo'])): ?><p class="nb-sub"><?= h($inf['subtitulo']) ?></p><?php endif; ?>
                        <time class="nb-data"><i class="bi bi-calendar3"></i><?= h(data_curta(substr($inf['data_publicacao'], 0, 10))) ?></time>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <a class="nb-mais-bt" href="informes.php">Mais notícias <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php endif; ?>
    </div>
</section>



<!-- ================= A ESCOLA ================= -->
<section class="sg-secao" id="escola">
    <div class="largura">
        <?php $fotos_escola = site_escola_fotos(); ?>
        <div class="sg-aerea sg-carro" id="escolaCarro" data-n="<?= count($fotos_escola) ?>" aria-roledescription="carrossel" aria-label="Fotos da escola">
            <div class="sg-carro-trilho">
                <?php foreach ($fotos_escola as $i => $f): ?>
                <figure class="sg-carro-item" aria-roledescription="slide" aria-label="<?= $i + 1 ?> de <?= count($fotos_escola) ?>">
                    <img src="<?= h($f['imagem']) ?>" alt="<?= h($f['legenda'] ?: 'Foto da escola') ?>" width="1280" height="720" loading="<?= $i ? 'lazy' : 'eager' ?>">
                    <?php if ($f['legenda'] !== ''): ?><figcaption><i class="bi bi-geo-alt-fill"></i><?= h($f['legenda']) ?></figcaption><?php endif; ?>
                </figure>
                <?php endforeach; ?>
            </div>
            <?php if (count($fotos_escola) > 1): ?>
            <button type="button" class="sg-carro-bt ant" aria-label="Foto anterior"><i class="bi bi-chevron-left"></i></button>
            <button type="button" class="sg-carro-bt prox" aria-label="Próxima foto"><i class="bi bi-chevron-right"></i></button>
            <div class="sg-carro-pontos"><?php foreach ($fotos_escola as $i => $f): ?><button type="button" aria-label="Foto <?= $i + 1 ?>"<?= $i ? '' : ' class="ativo"' ?>></button><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
        <div class="sg-escola">
            <div>
                <span class="sg-sobre">A escola</span>
                <?php $tx = site_textos()['escola'] ?? []; ?>
                <h2><?= !empty($tx['titulo']) ? h($tx['titulo']) : 'Formando para a <em>vida</em> e para o <em>trabalho</em>' ?></h2>
                <p class="sg-texto"><?= h(!empty($tx['texto']) ? $tx['texto'] : $HISTORIA_INTRO) ?></p>
            </div>
            <ul class="sg-numeros">
                <li><b><?= date('Y') - $ano_inicio ?></b><span>anos de história</span></li>
                <?php if ($numeros['alunos']): ?><li><b><?= $numeros['alunos'] ?></b><span>estudantes</span></li><?php endif; ?>
                <li><b><?= count($CURSOS_SITE) ?></b><span>cursos técnicos</span></li>
                <?php if ($numeros['turmas']): ?><li><b><?= $numeros['turmas'] ?></b><span>turmas</span></li><?php endif; ?>
            </ul>
        </div>
        <ul class="sg-pilares">
            <?php foreach (site_pilares() as $pl): ?><li><i class="bi bi-<?= h($pl['icone']) ?>"></i><b><?= h($pl['titulo']) ?></b><span><?= h($pl['texto']) ?></span></li><?php endforeach; ?>
        </ul>
        <?php $ambientes = ambientes_lista(); if ($ambientes): ?>
        <h3 class="sg-sub">Nossa estrutura</h3>
        <ul class="sg-estrutura">
            <?php foreach ($ambientes as $am): ?><li><a class="sg-amb" href="ambiente.php?id=<?= (int)$am['id'] ?>"><span><i class="bi bi-<?= h($am['icone'] ?: 'building') ?>"></i></span><div><b><?= h($am['titulo']) ?></b><small><?= h($am['resumo'] ?? '') ?></small></div><i class="bi bi-arrow-up-right sg-amb-ir"></i></a></li><?php endforeach; ?>
        </ul>
        <?php elseif (!empty($ESTRUTURA)): ?>
        <h3 class="sg-sub">Nossa estrutura</h3>
        <ul class="sg-estrutura">
            <?php foreach ($ESTRUTURA as $es): ?><li><span><i class="bi bi-<?= h($es['icone']) ?>"></i></span><div><b><?= h($es['titulo']) ?></b><small><?= h($es['texto']) ?></small></div></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</section>

<!-- ================= CURSOS ================= -->
<section class="sg-secao sg-verde" id="cursos">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Cursos técnicos</span><h2>Escolha a sua profissão</h2></div></div>
        <p class="sg-texto claro">O curso é escolhido na matrícula do 1º ano. Ao final dos três anos, o aluno conclui o ensino médio e recebe o diploma de técnico.</p>
        <div class="sg-cursos">
            <?php foreach ($CURSOS_SITE as $i => $c): ?>
            <article class="sg-curso" style="--cc:<?= cor_curso($c['nome']) ?>">
                <?php if (img_existe($c['imagem'] ?? null)): ?><img src="<?= h($c['imagem']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="sg-curso-topo"><span class="sg-curso-ic"><i class="bi bi-<?= $icones_cursos[$c['nome']] ?? 'mortarboard' ?>"></i></span><h3><small>Técnico em</small><?= h($c['nome']) ?></h3></div>
                <p><?= h($c['resumo']) ?></p>
                <details><summary>O que se aprende <i class="bi bi-chevron-down"></i></summary>
                    <ul><?php foreach ($c['aprende'] as $ap): ?><li><?= h($ap) ?></li><?php endforeach; ?></ul>
                    <p class="sg-curso-at"><i class="bi bi-briefcase"></i><?= h($c['atuacao']) ?></p></details>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php $docs = site_arquivos(); $editais = editais_lista(); if ($docs || $editais): ?>
<!-- ================= EDITAIS E DOCUMENTOS ================= -->
<section class="sg-secao dc2" id="documentos">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Documentos</span><h2><?= $editais ? 'Editais e documentos' : 'Documentos da escola' ?></h2><p class="dc-sub">Abra aqui no site ou baixe, sem precisar de cadastro.</p></div></div>
        <?php if ($editais): ?>
        <div class="dc2-bloco">
            <div class="dc2-tit"><h3><i class="bi bi-megaphone"></i>Editais de seleção</h3><?php if (count($editais) > 3): ?><a href="editais.php">Todos os editais (<?= count($editais) ?>) <i class="bi bi-arrow-right"></i></a><?php endif; ?></div>
            <div class="ed2-grade"><?php foreach (array_slice($editais, 0, 3) as $x) echo edital_cartao($x); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($docs): ?>
        <div class="dc2-bloco">
            <div class="dc2-tit"><h3><i class="bi bi-folder2-open"></i>Documentos da escola</h3><span class="dc2-tit-n"><?= count($docs) ?> arquivo<?= count($docs) > 1 ? 's' : '' ?></span></div>
            <ul class="dc2-lista">
                <?php foreach ($docs as $d): [, $ic, $rot, $cor] = ARQ_SITE_TIPOS[$d['extensao']] ?? ['', 'file-earmark', 'Arquivo', 'cinza'];
                    $u = 'arquivo_site.php?id=' . (int)$d['id']; $ext = strtoupper($d['extensao']); $meta = $rot . ' · ' . tamanho_legivel((int)$d['tamanho']); ?>
                <li class="dc-item" data-id="<?= (int)$d['id'] ?>" data-ext="<?= h($d['extensao']) ?>" data-titulo="<?= h($d['titulo']) ?>" data-meta="<?= h($meta) ?>" data-cor="<?= $cor ?>" data-ic="<?= h($ic) ?>">
                    <a class="dc2-link" href="<?= $u ?>" target="_blank" rel="noopener" data-ver>
                        <span class="dc2-ic <?= $cor ?>"><i class="bi bi-<?= h($ic) ?>"></i></span>
                        <span class="dc2-txt"><b><?= h($d['titulo']) ?></b><small><?= h($d['categoria']) ?> · <?= h($ext === 'JPEG' ? 'JPG' : $ext) ?> · <?= h(tamanho_legivel((int)$d['tamanho'])) ?></small></span>
                        <span class="dc2-abrir">Abrir</span>
                    </a>
                    <a class="dc2-baixar" href="<?= $u ?>&amp;baixar=1" title="Baixar" aria-label="Baixar <?= h($d['titulo']) ?>"><i class="bi bi-download"></i></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <div class="dcv" id="dcVisor" hidden role="dialog" aria-modal="true" aria-labelledby="dcvTit">
        <div class="dcv-caixa">
            <header class="dcv-cab">
                <button type="button" class="dcv-x" data-fechar aria-label="Fechar"><i class="bi bi-arrow-left"></i></button>
                <span class="dc-doc" id="dcvDoc"><i class="bi"></i><b></b></span>
                <div class="dcv-txt"><b id="dcvTit"></b><small id="dcvMeta"></small></div>
                <a class="dcv-baixar" id="dcvBaixar" href="#"><i class="bi bi-download"></i><span>Baixar</span></a>
                <button type="button" class="dcv-x dcv-x2" data-fechar aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
            </header>
            <div class="dcv-corpo" id="dcvCorpo"></div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($estudantes): ?>
<!-- ================= ESTUDANTES ================= -->
<section class="sg-secao" id="estudantes">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Nossos estudantes</span><h2>Quem faz a escola acontecer</h2></div></div>
        <div class="est-bento" id="estBento">
            <?php foreach ($estudantes as $i => $g): ?>
                <button type="button" class="est-item" data-i="<?= $i ?>" aria-label="Ampliar foto: <?= h($g['legenda']) ?>">
                    <img src="<?= h($g['imagem']) ?>" alt="<?= h($g['legenda']) ?>" loading="lazy">
                    <span class="est-leg"><b><?= sprintf('%02d', $i + 1) ?></b><?= h($g['legenda']) ?><i class="bi bi-arrows-angle-expand"></i></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="est-luz" id="estLuz" hidden role="dialog" aria-modal="true" aria-label="Foto ampliada">
        <button type="button" class="est-fechar" data-luz="fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
        <button type="button" class="est-nav ant" data-luz="-1" aria-label="Foto anterior"><i class="bi bi-chevron-left"></i></button>
        <figure><img id="estLuzImg" alt=""><figcaption id="estLuzLeg"></figcaption></figure>
        <button type="button" class="est-nav prox" data-luz="1" aria-label="Próxima foto"><i class="bi bi-chevron-right"></i></button>
    </div>
</section>
<?php endif; ?>

<?php $gremio = gremio_lista(); if ($gremio): ?>
<!-- ================= GRÊMIO ESTUDANTIL ================= -->
<section class="sg-secao gr2" id="gremio">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Grêmio estudantil</span><h2>A voz dos estudantes</h2><p class="dc-sub">Quem representa os alunos da escola.</p></div></div>
        <ul class="gr2-grade">
            <?php foreach ($gremio as $g): $src = gremio_foto_src($g); ?>
            <li><figure class="eq2 gr2-pessoa">
                <div class="eq2-foto"><?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h($g['nome']) ?>" loading="lazy"><?php else: ?><span class="eq2-ini"><?= h($iniciais($g['nome'])) ?></span><?php endif; ?></div>
                <figcaption><strong><?= h($g['nome']) ?></strong><span class="gr2-cargo"><?= h($g['funcao']) ?></span><?php if ($g['curso']): ?><small><i class="bi bi-mortarboard"></i><?= h($g['curso']) ?></small><?php endif; ?></figcaption>
            </figure></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<!-- ================= HISTÓRIA ================= -->
<section class="sg-secao sg-creme" id="historia">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Nossa história</span><h2>De <?= $ano_inicio ?> até hoje</h2></div></div>
        <ol class="sg-tempo" id="linhaTempo">
            <?php foreach ($HISTORIA as $k => $m): ?>
            <li<?= $k > 4 ? ' class="sg-tempo-extra"' : '' ?>>
                <span class="sg-tempo-ic"><i class="bi bi-<?= h($m['icone'] ?? 'dot') ?>"></i></span>
                <div class="sg-tempo-card"><span class="sg-ano"><?= h($m['ano']) ?></span><h3><?= h($m['titulo']) ?></h3><p><?= h($m['texto']) ?></p></div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php if (count($HISTORIA) > 5): ?><button type="button" class="sg-ver-mais" id="tempoMais"><span>Ver a história completa</span><i class="bi bi-chevron-down"></i></button><?php endif; ?>
    </div>
</section>

<!-- ================= GESTÃO ================= -->
<section class="sg-secao gs3" id="gestao">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Gestão escolar</span><h2>Quem conduz a escola</h2></div></div>
        <?php $g_foto = config_ler('site_gestao_foto'); $g_leg = config_ler('site_gestao_legenda') ?: 'Equipe gestora · EEEP Venceslau Vieira Batista'; ?>
        <figure class="gs3-equipe">
            <img src="<?= $g_foto ? h('foto_site.php?g=1&v=' . substr(md5($g_foto), 0, 8)) : 'assets/img/gestao-equipe.jpg' ?>" alt="Foto da equipe gestora da escola" loading="lazy" width="1800" height="901">
            <figcaption><i class="bi bi-people-fill"></i><?= h($g_leg) ?></figcaption>
        </figure>
        <div class="gs3-grade">
            <?php foreach (array_slice($gestores_site, 0, 4) as $k => $g): ?>
            <article class="gs3-card<?= $k === 0 ? ' diretor' : '' ?>"><?php if (!empty($g['id'])): ?><a class="gs3-link" href="pessoa.php?id=<?= (int)$g['id'] ?>" aria-label="Conhecer <?= h($g['nome']) ?>"></a><?php endif; ?>
                <div class="gs3-foto"><?php if ($g['src']): ?><img src="<?= h($g['src']) ?>" alt="<?= h($g['nome']) ?>" loading="lazy"><?php else: ?><span><?= h($iniciais($g['nome'])) ?></span><?php endif; ?></div>
                <div class="gs3-info">
                    <h3><?= h($g['nome']) ?></h3>
                    <span class="gs3-cargo"><?= h($g['funcao']) ?></span>
                    <p><?= h(($g['texto'] ?? '') ?: ($k === 0 ? 'Coordena a escola como um todo: pessoas, recursos, resultados e o diálogo com as famílias.' : 'Faz parte da equipe gestora da EEEP Venceslau Vieira Batista.')) ?></p>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($equipe): ?>
<!-- ================= NOSSA EQUIPE ================= -->
<section class="sg-secao sg-creme" id="equipe">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Nossa equipe</span><h2>As pessoas da nossa escola</h2></div></div>
        <div class="eqs-barra">
            <div class="eqs-filtros" role="tablist" aria-label="Filtrar por grupo">
                <button type="button" class="ativo" data-grupo="">Todos <b><?= count($equipe) ?></b></button>
                <?php $cont = array_count_values(array_column($equipe, 'grupo')); foreach ($GRUPOS_EQUIPE as $k => $gn): if (empty($cont[$k])) continue; ?>
                    <button type="button" data-grupo="<?= h($k) ?>"><?= h($gn) ?> <b><?= $cont[$k] ?></b></button>
                <?php endforeach; ?>
            </div>
            <label class="eqs-busca"><i class="bi bi-search"></i><input type="search" id="eqsBusca" placeholder="Procurar pelo nome ou função" aria-label="Procurar na equipe"></label>
        </div>
        <ul class="eqs-grade" id="eqsGrade">
            <?php foreach ($equipe as $e): $src = $foto_eq($e); ?>
            <li data-grupo="<?= h($e['grupo']) ?>" data-busca="<?= h(mb_strtolower($e['nome'] . ' ' . $e['funcao'])) ?>" class="g-<?= h($e['grupo']) ?>">
                <a class="eq2-link" href="pessoa.php?id=<?= (int)$e['id'] ?>" aria-label="Conhecer <?= h($e['nome']) ?>">
                <figure class="eq2">
                    <div class="eq2-foto"><?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h($e['nome']) ?>" loading="lazy"><?php else: ?><span class="eq2-ini"><?= h($iniciais($e['nome'])) ?></span><?php endif; ?>
                        <span class="eq2-grupo" title="<?= h($GRUPOS_EQUIPE[$e['grupo']] ?? '') ?>"><i class="bi bi-<?= ['gestao' => 'star-fill', 'professores' => 'mortarboard-fill', 'secretaria' => 'folder-fill', 'multimeios' => 'book-fill', 'apoio' => 'tools'][$e['grupo']] ?? 'person-fill' ?>"></i></span></div>
                    <figcaption><strong><?= h($e['nome']) ?></strong><small><?= h($e['funcao']) ?></small></figcaption>
                </figure>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="eqs-nada" id="eqsNada" hidden>Ninguém encontrado.</p>
        <nav class="eqs-paginas" id="eqsPaginas" aria-label="Ver toda a equipe"></nav>
    </div>
</section>
<?php endif; ?>

<!-- ================= CONTATO ================= -->
<section class="sg-secao" id="contato">
    <div class="largura">
        <div class="sg-cab"><div><span class="sg-sobre">Contato</span><h2>Venha conhecer a escola</h2></div></div>
        <div class="sg-contato-grade">
        <div class="sg-contato">
            <a class="sg-ct" href="<?= h($mapa_link) ?>" target="_blank" rel="noopener"><span><i class="bi bi-geo-alt-fill"></i></span><div><small>Endereço</small><b><?= h($ESCOLA['endereco']) ?></b></div><i class="bi bi-chevron-right"></i></a>
            <?php if ($ESCOLA['telefone']): ?><a class="sg-ct" href="tel:<?= h(preg_replace('/\D/', '', $ESCOLA['telefone'])) ?>"><span><i class="bi bi-telephone-fill"></i></span><div><small>Telefone</small><b><?= h($ESCOLA['telefone']) ?></b></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
            <?php if ($ESCOLA['email']): ?><a class="sg-ct" href="mailto:<?= h($ESCOLA['email']) ?>"><span><i class="bi bi-envelope-fill"></i></span><div><small>E-mail</small><b><?= h($ESCOLA['email']) ?></b></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
            <?php if ($insta): ?><a class="sg-ct" href="<?= h($insta) ?>" target="_blank" rel="noopener"><span><i class="bi bi-instagram"></i></span><div><small>Instagram</small><b>@<?= h($ESCOLA['instagram']) ?></b></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
            <div class="sg-ct"><span><i class="bi bi-clock-fill"></i></span><div><small>Atendimento ao público</small><b><?= h($ESCOLA['atendimento']) ?></b></div></div>
        </div>
        <div class="sg-mapa-aberto">
            <iframe title="Mapa com a localização da escola" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://maps.google.com/maps?q=<?= rawurlencode($ESCOLA['mapa']) ?>&z=16&output=embed" allowfullscreen></iframe>
            <a class="sg-mapa-ir" href="https://www.google.com/maps/dir/?api=1&destination=<?= rawurlencode($ESCOLA['mapa']) ?>" target="_blank" rel="noopener"><i class="bi bi-sign-turn-right-fill"></i>Como chegar</a>
        </div>
        </div>
    </div>
</section>
</main>

<?php $rodape_extra = '<script src="assets/js/inicio.js?v=8" defer></script>'; require __DIR__ . '/includes/site_rodape.php'; ?>
