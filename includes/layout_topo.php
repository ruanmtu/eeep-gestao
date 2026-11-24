<?php
/* Cabeçalho e menu lateral do painel.
 * Antes de incluir, defina: $titulo (texto da aba e do topo) e $menu (chave do item ativo). */
global $ESCOLA;
$u = usuario();
$menu = $menu ?? '';

$n_alertas = pode('alunos.ver') ? count(alertas_sequencia(true)) : 0;
$n_notif = notificacoes_nao_lidas();
$n_pend = pode('saidas.autorizar') ? saidas_pendentes() : 0;
$itens_menu = [
    'Visão geral' => [
        ['index', 'index.php', 'grid-1x2', 'Painel', '', $n_alertas],
        ['calendario', 'calendario.php', 'calendar3', 'Calendário SIGE', ''],
        ['arquivos', 'arquivos.php', 'folder2-open', 'Central de Arquivos', ''],
        ['notificacoes', 'notificacoes.php', 'bell', 'Notificações', '', $n_notif],
        ['agenda', 'agenda.php', 'journal-richtext', 'Minha agenda', ''],
        ['sofia', 'sofia.php', 'stars', 'Sofia (assistente de IA)', 'ia'],
    ],
    'Minha turma' => eh_diretor_turma() ? [
        ['minha_turma', 'minha_turma.php', 'person-workspace', 'Painel da minha turma', ''],
    ] : [],
    'Alunos' => [
        ['alunos', 'alunos.php', 'people', 'Alunos', 'alunos.ver'],
        ['aluno_novo', 'aluno_form.php', 'person-plus', 'Inserir aluno', 'alunos.editar'],
    ],
    'Diário de classe' => [
        ['frequencia', 'frequencia.php', 'calendar2-check', 'Coleta de faltas', 'frequencia'],
        ['mapa_sala', 'mapa_sala.php', 'grid-3x3', 'Mapa de turma', 'alunos.ver'],
        ['saidas', 'saidas.php', 'patch-check', 'Autorizações', 'saidas', $n_pend],
        ['nota_conceitual', 'nota_conceitual.php', 'clipboard2-check', 'Nota conceitual', 'nota_conceitual'],
        ['notas', 'notas.php', 'journal-check', 'Notas', 'notas'],
        ['provas', 'provas.php', 'ui-checks-grid', 'Correção de provas', 'provas'],
        ['horarios', 'horarios.php', 'calendar3-week', 'Horários', ''],
        ['livros_didaticos', 'livros_didaticos.php', 'journal-bookmark', 'Livros didáticos', 'livros_didaticos'],
    ],
    'Acompanhamento' => [
        ...(eh_gestao() || eh_diretor_turma() ? [['alertas', 'alertas.php', 'exclamation-triangle', 'Alerta de faltas', '']] : []),
        ['ocorrencias', 'ocorrencias.php', 'shield-exclamation', 'Registro de ocorrências', 'ocorrencias'],
        ['busca_ativa', 'busca_ativa.php', 'telephone-outbound', 'Busca ativa', 'busca_ativa'],
        ['resultados', 'resultados.php', 'graph-up-arrow', 'Resultados da escola', 'relatorios'],
        ['relatorios', 'relatorios.php', 'bar-chart-line', 'Relatórios', 'relatorios'],
        ['apresentacao', 'apresentacao.php', 'easel', 'Apresentação', 'apresentacao'],
    ],
    'Escola' => [
        ['reservas', 'reservas.php', 'easel2', 'Reservas', 'reservas'],
        ['biblioteca', 'biblioteca.php', 'book', 'Centro de Multimeios', 'biblioteca', pode('biblioteca') ? contar_atrasados() : 0],
        ['site', 'site.php', 'globe2', 'Site', 'site.publicar'],
    ],
    'Administração' => [
        ['importar', 'importar.php', 'cloud-arrow-up', 'Planilha do Power BI', 'importar'],
        ['config', 'configuracoes.php', 'diagram-3', 'Cursos e turmas', 'config'],
        ['professores', 'professores.php', 'person-video3', 'Professores e componentes', 'config'],
        ['usuarios', 'usuarios.php', 'person-badge', 'Usuários e permissões', 'usuarios'],
    ],
];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= csrf_token() ?>">
    <title><?= e($titulo ?? 'Painel') ?> · <?= e($ESCOLA['nome_curto']) ?></title>
    <link href="<?= RAIZ ?>assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= RAIZ ?>assets/css/fontes.css?v=3" rel="stylesheet">
    <link href="<?= RAIZ ?>assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= RAIZ ?>assets/css/painel.css?v=75" rel="stylesheet">
    <link href="<?= RAIZ ?>assets/css/padrao.css?v=4" rel="stylesheet">
    <link rel="icon" href="<?= RAIZ ?>assets/img/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="<?= RAIZ ?>assets/img/apple-touch-icon.png">
    <meta name="theme-color" content="#0b4a20">
</head>
<body class="painel com-trilho">

<!-- Menu da gestão: abre pelo botão "Menu" do cabeçalho -->
<aside class="offcanvas-lg offcanvas-start menu-gestao" tabindex="-1" id="menuLateral" aria-labelledby="menuTitulo">
    <div class="mg-topo">
        <img src="<?= RAIZ ?>assets/img/logo-branca.png" alt="<?= e($ESCOLA['nome']) ?>" width="1400" height="451">
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fechar menu"></button>
    </div>
    <div class="mg-usuario">
        <?= avatar($u, 'usuario', 'avatar-md') ?>
        <div class="min-w-0"><strong id="menuTitulo"><?= e(nome_exibicao($u)) ?></strong><small><?= e(cargo_usuario($u)) ?></small></div>
    </div>
    <div class="mg-busca"><i class="bi bi-search"></i><input type="search" id="menuFiltro" placeholder="Procurar no menu…" aria-label="Procurar no menu"></div>
    <nav class="mg-nav">
        <?php foreach ($itens_menu as $grupo => $itens):
            $visiveis = array_filter($itens, fn($i) => pode($i[4]));
            if (!$visiveis) continue; ?>
            <div class="mg-grupo"><?= e($grupo) ?></div>
            <div class="mg-itens">
            <?php foreach ($visiveis as $item): [$chave, $href, $icone, $rotulo] = $item; $cont = $item[5] ?? 0; ?>
                <a href="<?= $href ?>" title="<?= e($rotulo) ?>" class="mg-item<?= $menu === $chave ? ' ativo' : '' ?>"<?= $menu === $chave ? ' aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= $icone ?>"></i><span><?= e($rotulo) ?></span>
                    <?php if ($cont): ?><span class="contagem" data-contagem="<?= $chave ?>" title="<?= ['biblioteca' => 'Empréstimos atrasados', 'notificacoes' => 'Não lidas', 'saidas' => 'Pedidos de liberação aguardando'][$chave] ?? 'Alunos com 3 faltas seguidas' ?>"><?= $cont ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="mg-rodape">
        <a href="<?= RAIZ ?>index.php" title="Site da escola"><i class="bi bi-globe2"></i><span>Site da escola</span></a>
        <a href="<?= RAIZ ?>logout.php?csrf=<?= csrf_token() ?>" title="Sair"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
        <small class="mg-versao" title="Versão do sistema">versão <?= SISTEMA_VERSAO ?></small>
    </div>
</aside>

<header class="pn-cab">
    <div class="pn-barra">
        <div class="pn-largura pn-barra-linha">
            <a class="pn-logo" href="index.php" aria-label="Painel inicial"><img src="<?= RAIZ ?>assets/img/logo-branca.png" alt="<?= e($ESCOLA['nome']) ?>" width="1400" height="451"></a>
            <nav class="pn-atalhos" aria-label="Atalhos">
                <?php foreach ([['index', 'index.php', 'house-door', 'Início', ''], ['alunos', 'alunos.php', 'people', 'Alunos', 'alunos.ver'], ['frequencia', 'frequencia.php', 'calendar2-check', 'Coleta', 'frequencia'],
                                ['saidas', 'saidas.php', 'patch-check', 'Autorizações', 'saidas'], ['ocorrencias', 'ocorrencias.php', 'shield-exclamation', 'Ocorrências', 'ocorrencias']] as [$k, $h, $ic, $r, $pm]):
                    if (!pode($pm)) continue; ?>
                    <a href="<?= $h ?>" class="<?= $menu === $k ? 'ativo' : '' ?>"><i class="bi bi-<?= $ic ?>"></i><span><?= $r ?></span><?php if ($k === 'saidas' && $n_pend): ?><b class="pn-cont" data-contagem="saidas"><?= $n_pend ?></b><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="dropdown ms-auto">
                <button class="btn sino-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificações" id="sinoBtn">
                    <i class="bi bi-bell"></i><span class="sino-cont" id="sinoCont"<?= $n_notif ? '' : ' hidden' ?>><?= $n_notif ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow sino-menu" id="sinoMenu">
                    <div class="sino-topo"><strong>Notificações</strong><button type="button" class="btn btn-link btn-sm p-0" id="sinoLerTodas">Marcar todas como lidas</button></div>
                    <?php if ($n_pend): ?><a class="sino-pend" href="saidas.php#pendentes"><i class="bi bi-box-arrow-right"></i><span><b id="sinoPend"><?= $n_pend ?></b> pedido(s) de liberação aguardando</span><i class="bi bi-chevron-right ms-auto"></i></a><?php endif; ?>
                    <div class="sino-lista" id="sinoLista"><div class="text-center text-secondary small py-4">Carregando…</div></div>
                    <a class="sino-todas" href="notificacoes.php">Ver todas</a>
                </div>
            </div>
            <button class="pn-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral" aria-label="Abrir o menu completo">
                <span class="pn-menu-ic"><i class="bi bi-grid-3x3-gap-fill"></i></span><span class="pn-menu-txt">Menu</span><?php if ($n_pend || $n_notif): ?><span class="pn-ponto"></span><?php endif; ?>
            </button>
            <div class="dropdown">
                <button class="btn usuario-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Minha conta">
                    <?= avatar($u, 'usuario', 'avatar-sm') ?>
                    <span class="usuario-txt">
                        <strong><?= e(nome_exibicao($u)) ?></strong>
                        <small><?= e(cargo_usuario($u)) ?></small>
                    </span>
                    <i class="bi bi-chevron-down usuario-seta"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end usuario-menu um2">
                    <div class="um2-capa">
                        <span class="um2-deco" aria-hidden="true"></span>
                        <span class="um2-foto"><?= avatar($u, 'usuario', 'avatar-lg') ?><i class="um2-on" title="Conectado"></i></span>
                        <div class="um2-quem"><strong><?= e(nome_exibicao($u)) ?></strong><span class="um2-cargo"><?= e(cargo_usuario($u)) ?></span><small><?= e($u['email']) ?></small></div>
                    </div>
                    <div class="um2-nivel"><i class="bi bi-shield-check"></i><span>Nível de acesso</span><b><?= e(perfil_nome($u['perfil'] ?? '')) ?></b></div>
                    <nav class="um2-itens">
                        <a href="perfil.php"><i class="bi bi-person-circle"></i><span>Minha conta<small>Foto, nome de exibição e e-mail</small></span><em class="bi bi-chevron-right"></em></a>
                        <a href="perfil.php#senha"><i class="bi bi-key"></i><span>Trocar senha<small>Segurança do acesso</small></span><em class="bi bi-chevron-right"></em></a>
                        <a href="agenda.php"><i class="bi bi-journal-text"></i><span>Minha agenda<small>Anotações e lembretes</small></span><em class="bi bi-chevron-right"></em></a>
                        <a href="notificacoes.php"><i class="bi bi-bell"></i><span>Notificações<small><?= $n_notif ? $n_notif . ' não lida(s)' : 'Tudo em dia' ?></small></span><?= $n_notif ? '<b class="um2-badge">' . $n_notif . '</b>' : '<em class="bi bi-chevron-right"></em>' ?></a>
                    </nav>
                    <a class="um2-sair" href="<?= RAIZ ?>logout.php?csrf=<?= csrf_token() ?>"><i class="bi bi-box-arrow-right"></i>Sair do sistema</a>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="conteudo">
    <?php if (false): /* v44: sem barra de título, a página ativa aparece no menu */ ?>
    <div class="topo pn-titulo">
        <div class="pn-largura topo-titulo">
            <h1><?= e($titulo ?? 'Painel') ?></h1>
            <?php if (!empty($subtitulo)): ?><p><?= e($subtitulo) ?></p><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <main class="area<?= !empty($classe_area) ? ' ' . e($classe_area) : '' ?>">
        <h1 class="visually-hidden"><?= e($titulo ?? 'Painel') ?></h1>
        <?php if (!empty($com_ano)): ?><div class="ano-faixa"><?= ano_campo() ?><?php if (vendo_outro_ano()): ?><span class="ano-nota"><i class="bi bi-clock-history"></i>Esta página está mostrando os registros de <?= ANO_VER ?>.</span><?php endif; ?></div><?php endif; ?>
        <?= flash_exibir() ?>
