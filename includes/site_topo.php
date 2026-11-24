<?php
/* Cabeçalho público (site e tela de login).
 * Antes de incluir: $ESCOLA, $ATALHOS; opcionais: $pagina_titulo, $pagina_classe ("pagina-interna"), $prefixo (links do menu) */
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$prefixo = $prefixo ?? '';
$logo_branca = $ESCOLA['logo_branca'] ?? 'assets/img/logo-branca.png';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pagina_titulo ?? ($ESCOLA['nome'] . ' · ' . $ESCOLA['cidade'])) ?></title>
    <meta name="description" content="<?= h($ESCOLA['nome_oficial']) ?>, em <?= h($ESCOLA['cidade']) ?>. Ensino médio integrado à educação profissional.">
    <meta name="theme-color" content="#0b4a20">
    <meta property="og:title" content="<?= h($ESCOLA['nome']) ?>">
    <meta property="og:image" content="assets/img/escola-aerea.jpg">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="preload" as="image" href="assets/img/escola-aerea.jpg">
    <link href="assets/css/fontes.css?v=3" rel="stylesheet">
    <link href="assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/site.css?v=29" rel="stylesheet">
    <link href="assets/css/topo.css?v=4" rel="stylesheet">
    <?= $cabeca_extra ?? '' ?>
    <script>document.documentElement.classList.add('js')</script>
</head>
<body class="tem-topo <?= h($pagina_classe ?? '') ?>">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>

<?php
$_links = [['house-door', 'Início', $prefixo . '#inicio'], ['megaphone', 'Notícias e agenda', 'informes.php'], ['building', 'A escola', $prefixo . '#escola'],
           ['mortarboard', 'Cursos', $prefixo . '#cursos'], ['folder2-open', 'Editais e documentos', $prefixo . '#documentos'], ['clock-history', 'História', $prefixo . '#historia'], ['people', 'Gestão e equipe', $prefixo . '#gestao'], ['geo-alt', 'Contato', $prefixo . '#contato']];
$_ic = function_exists('ic_url') ? ic_url() : '';   // v79: interclasse tem site próprio; o menu só leva até ele
if (function_exists('gremio_lista') && gremio_lista()) $_links[] = ['flag', 'Grêmio estudantil', $prefixo . '#gremio'];   // v79
if ($_ic) $_links[] = ['trophy', 'Interclasse', $_ic];
$_rapido = [['ui-checks-grid', 'Corrigir avaliações', 'painel/provas.php'], ['shield-exclamation', 'Registro de ocorrências', 'painel/ocorrencias.php'], ['journal-bookmark', 'Livros didáticos', 'painel/livros_didaticos.php']];
?>
<header class="tp" id="cabecalho">
    <div class="largura tp-linha">
        <button class="tp-menu-bt" type="button" aria-expanded="false" aria-controls="menu" aria-label="Abrir menu"><i class="bi bi-list"></i></button>
        <a class="tp-logo" href="<?= $prefixo ?: '#' ?>inicio" aria-label="<?= h($ESCOLA['nome']) ?>, página inicial">
            <img src="<?= h($logo_branca) ?>" alt="<?= h($ESCOLA['nome']) ?>" width="1400" height="451">
        </a>
        <nav class="tp-links" aria-label="Principal">
            <?php foreach ($_ic ? [$_links[0], $_links[2], $_links[3], end($_links), $_links[7]] : [$_links[0], $_links[2], $_links[3], $_links[5], $_links[7]] as [, $rot, $url]): ?><a href="<?= h($url) ?>"<?= $_ic && $url === $_ic ? ' class="tp-destaque" target="_blank" rel="noopener"' : '' ?>><?= h($rot) ?></a><?php endforeach; ?>
        </nav>
        <button class="tp-lupa" type="button" aria-label="Buscar no site" aria-expanded="false" aria-controls="tpBuscaPainel"><i class="bi bi-search"></i></button>
        <a class="tp-entrar" href="login.php"><i class="bi bi-person-circle"></i><span>Entrar</span></a>
    </div>
    <div class="tp-busca-painel" id="tpBuscaPainel" hidden>
        <div class="largura">
            <form class="tp-busca" action="busca.php" method="get" role="search" id="tpBusca">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= h($busca_termo ?? '') ?>" placeholder="O que você precisa?" aria-label="Buscar no site" autocomplete="off" enterkeyhint="search" maxlength="80">
                <button class="tp-mic" type="button" aria-label="Buscar falando" title="Buscar falando" hidden><i class="bi bi-mic-fill"></i></button>
                <button class="tp-busca-ir" type="submit" aria-label="Buscar"><i class="bi bi-arrow-right"></i></button>
            </form>
            <div class="tp-sugestoes"><span>Buscas comuns:</span><?php foreach (['Matrícula', 'Cursos', 'Fotos', 'Horário de atendimento', 'Endereço'] as $_s): ?><a href="busca.php?q=<?= rawurlencode($_s) ?>"><?= h($_s) ?></a><?php endforeach; ?></div>
        </div>
    </div>
</header>

<div class="tp-gaveta" id="menu" hidden>
    <div class="tp-gaveta-fundo" data-fechar></div>
    <aside class="tp-gaveta-painel" aria-label="Menu">
        <div class="tp-gaveta-topo">
            <img src="<?= h($logo_branca) ?>" alt="<?= h($ESCOLA['nome']) ?>" width="1400" height="451">
            <button type="button" class="tp-fechar" data-fechar aria-label="Fechar menu"><i class="bi bi-x-lg"></i></button>
        </div>
        <nav class="tp-gaveta-nav">
            <?php foreach ($_links as [$ic, $rot, $url]): ?><a href="<?= h($url) ?>"><span><i class="bi bi-<?= $ic ?>"></i></span><?= h($rot) ?><i class="bi bi-chevron-right"></i></a><?php endforeach; ?>
        </nav>
        <p class="tp-gaveta-rot">Para professores e gestão</p>
        <div class="tp-gaveta-rapido">
            <?php foreach ($_rapido as [$ic, $rot, $url]): ?><a href="<?= h($url) ?>"><i class="bi bi-<?= $ic ?>"></i><?= h($rot) ?></a><?php endforeach; ?>
        </div>
        <a class="tp-gaveta-entrar" href="login.php"><i class="bi bi-person-circle"></i>Entrar</a>
    </aside>
</div>
