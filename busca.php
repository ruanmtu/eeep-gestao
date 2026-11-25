<?php
/* Busca do site: páginas, informes, álbuns e equipe (sem acentos e sem diferenciar maiúsculas) */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

function bs_norm(string $t, bool $junta = true): string
{
    $t = mb_strtolower($t, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'î' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    return $junta ? preg_replace('/\s+/', ' ', $t) : $t;
}
/** Pontua o texto: todas as palavras precisam aparecer; título vale mais */
function bs_nota(array $termos, string $titulo, string $resto = ''): int
{
    $t = bs_norm($titulo); $r = bs_norm($resto); $n = 0;
    foreach ($termos as $w) {
        if (str_contains($t, $w)) $n += 3;
        elseif (str_contains($r, $w)) $n += 1;
        else return 0;
    }
    return $n;
}
/** Trecho do texto em volta do termo, com destaque */
function bs_trecho(string $texto, array $termos, int $tam = 150): string
{
    $texto = trim(preg_replace('/\s+/', ' ', $texto));
    $pos = 0; $n = bs_norm($texto);
    foreach ($termos as $w) { $p = mb_strpos($n, $w); if ($p !== false) { $pos = $p; break; } }
    $ini = max(0, $pos - 50);
    $tr = ($ini > 0 ? '…' : '') . mb_substr($texto, $ini, $tam) . (mb_strlen($texto) > $ini + $tam ? '…' : '');
    return bs_marcar($tr, $termos);
}
function bs_marcar(string $texto, array $termos): string
{
    $saida = h($texto);
    $n = bs_norm($texto, false);
    $marcas = [];
    foreach ($termos as $w) { $off = 0; while (($p = mb_strpos($n, $w, $off)) !== false) { $marcas[] = [$p, mb_strlen($w)]; $off = $p + 1; } }
    if (!$marcas) return $saida;
    usort($marcas, fn($a, $b) => $b[0] <=> $a[0]);
    $out = $texto; $ult = PHP_INT_MAX; $partes = [];
    foreach ($marcas as [$p, $l]) { if ($p + $l > $ult) continue; $partes[] = [$p, $l]; $ult = $p; }
    $res = ''; $cur = 0;
    foreach (array_reverse($partes) as [$p, $l]) { $res .= h(mb_substr($out, $cur, $p - $cur)) . '<mark>' . h(mb_substr($out, $p, $l)) . '</mark>'; $cur = $p + $l; }
    return $res . h(mb_substr($out, $cur));
}

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$busca_termo = $q;
$termos = array_values(array_filter(array_unique(explode(' ', bs_norm($q))), fn($w) => mb_strlen($w) >= 2 && !in_array($w, ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'as', 'os', 'em', 'no', 'na', 'para', 'com', 'um', 'uma', 'como', 'que', 'qual', 'onde', 'quero', 'ver'], true)));

/* Páginas e seções do site */
$paginas = [
    ['person-plus', 'Matrícula', 'Como entrar na escola: vagas, documentos e período de matrícula.', 'index.php#informes', 'matricula matriculas vaga vagas inscricao inscricoes selecao seletivo entrar estudar 1 ano primeiro ano documentos'],
    ['mortarboard', 'Cursos técnicos', 'Informática, Desenvolvimento de Sistemas, Enfermagem e Administração.', 'index.php#cursos', 'curso cursos tecnico tecnicos profissao profissional informatica sistemas enfermagem administracao ds adm enf'],
    ['megaphone', 'Informes', 'Avisos, eventos e conquistas da escola.', 'index.php#informes', 'informe informes aviso avisos noticia noticias evento eventos comunicado'],
    ['building', 'A escola', 'Estrutura, laboratórios, tempo integral e estágio.', 'index.php#escola', 'escola estrutura laboratorio laboratorios biblioteca quadra refeitorio tempo integral estagio sobre'],
    ['clock-history', 'Nossa história', 'De 2009 até hoje.', 'index.php#historia', 'historia 2009 david vieira da silva fundacao inauguracao formatura trajetoria'],
    ['people', 'Gestão escolar', 'Diretor e coordenadores da escola.', 'index.php#gestao', 'gestao diretor diretora coordenador coordenadora coordenacao direcao'],
    ['person-vcard', 'Nossa equipe', 'Professores e funcionários.', 'index.php#equipe', 'equipe professores professor professora funcionarios servidores'],
    ['geo-alt', 'Endereço e como chegar', $ESCOLA['endereco'] . ' · ' . $ESCOLA['cidade'], 'index.php#contato', 'endereco localizacao mapa chegar onde fica rua boa viagem'],
    ['envelope', 'E-mail da escola', $ESCOLA['email'], 'mailto:' . $ESCOLA['email'], 'email e-mail contato falar mensagem'],
    ['clock', 'Horário de atendimento', $ESCOLA['atendimento'] ?? 'Segunda a sexta', 'index.php#contato', 'horario horarios atendimento secretaria funcionamento abre fecha'],
    ['instagram', 'Instagram', '@' . $ESCOLA['instagram'], 'https://instagram.com/' . rawurlencode($ESCOLA['instagram']), 'instagram rede social redes sociais insta'],
    ['person-circle', 'Entrar no sistema', 'Acesso de professores, gestão e equipe.', 'login.php', 'login entrar painel sistema acesso senha professor gestao conta'],
    ['ui-checks-grid', 'Corrigir avaliações', 'Correção de provas pelo celular (professores).', 'painel/provas.php', 'corrigir correcao prova provas avaliacao avaliacoes gabarito'],
    ['shield-exclamation', 'Registro de ocorrências', 'Para professores e gestão.', 'painel/ocorrencias.php', 'ocorrencia ocorrencias registro disciplina'],
    ['journal-bookmark', 'Livros didáticos', 'Entrega e devolução de livros.', 'painel/livros_didaticos.php', 'livro livros didatico didaticos pnld'],
];
foreach ($CURSOS_SITE ?? [] as $c) $paginas[] = ['mortarboard', 'Técnico em ' . $c['nome'], $c['resumo'], 'index.php#cursos', 'curso tecnico ' . implode(' ', $c['aprende'] ?? []) . ' ' . ($c['atuacao'] ?? '')];

$res = ['paginas' => [], 'informes' => [], 'albuns' => [], 'equipe' => []];
if ($termos) {
    foreach ($paginas as $p) { $n = bs_nota($termos, $p[1], $p[2] . ' ' . $p[4]); if ($n) $res['paginas'][] = $p + ['n' => $n]; }
    foreach (site_informes(true, 300) as $i) { $n = bs_nota($termos, $i['titulo'], materia_texto((string)$i['texto']) . ' ' . (CATEGORIAS_INFORME[$i['categoria']][0] ?? '')); if ($n) $res['informes'][] = $i + ['n' => $n]; }
    foreach (site_albuns(true, 300) as $a) { if ($a['n_fotos'] < 1) continue; $n = bs_nota($termos, $a['titulo'], (string)$a['descricao']); if ($n) $res['albuns'][] = $a + ['n' => $n]; }
    try { foreach (equipe_lista() as $e) { $n = bs_nota($termos, $e['nome'], (string)($e['funcao'] ?? '')); if ($n) $res['equipe'][] = $e + ['n' => $n]; } } catch (Throwable $e) {}
    foreach ($res as &$lista) usort($lista, fn($a, $b) => $b['n'] <=> $a['n']);
    unset($lista);
}
$total = array_sum(array_map('count', $res));
$sugestoes = ['Matrícula', 'Cursos', 'Fotos', 'Horário de atendimento', 'Endereço', 'Enfermagem', 'Informática', 'História'];

$pagina_titulo = ($q !== '' ? 'Busca: ' . $q : 'Buscar') . ' · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=36" rel="stylesheet"><meta name="robots" content="noindex">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina bs-pagina">
    <div class="largura sg-leitura">
        <?php if ($q === ''): ?>
            <header class="sg-album-cab"><span class="sg-sobre">Buscar</span><h1>O que você precisa?</h1><p>Digite na caixa de busca lá em cima ou toque numa sugestão.</p></header>
        <?php else: ?>
            <header class="sg-album-cab"><span class="sg-sobre">Resultado da busca</span><h1>“<?= h($q) ?>”</h1><p><?= $total ? ($total === 1 ? '1 resultado encontrado.' : $total . ' resultados encontrados.') : 'Nada encontrado com essas palavras.' ?></p></header>
        <?php endif; ?>

        <?php if ($res['paginas']): ?>
        <section class="bs-grupo"><h2><i class="bi bi-signpost-split"></i>No site</h2>
            <?php foreach (array_slice($res['paginas'], 0, 6) as [$ic, $tit, $desc, $url]): ?>
            <a class="bs-item" href="<?= h($url) ?>"<?= str_starts_with($url, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><span class="bs-ic"><i class="bi bi-<?= $ic ?>"></i></span><div><b><?= bs_marcar($tit, $termos) ?></b><small><?= bs_marcar($desc, $termos) ?></small></div><i class="bi bi-chevron-right"></i></a>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($res['informes']): ?>
        <section class="bs-grupo"><h2><i class="bi bi-megaphone"></i>Informes</h2>
            <?php foreach (array_slice($res['informes'], 0, 10) as $i): [$crot, $cic] = CATEGORIAS_INFORME[$i['categoria']] ?? CATEGORIAS_INFORME['aviso']; ?>
            <a class="bs-item c-<?= h($i['categoria']) ?>" href="informe.php?id=<?= $i['id'] ?>">
                <?php if ($i['imagem']): ?><img class="bs-img" src="<?= h(site_img_url('i', (int)$i['id'], $i['imagem'], true)) ?>" alt="" loading="lazy"><?php else: ?><span class="bs-ic"><i class="bi bi-<?= $cic ?>"></i></span><?php endif; ?>
                <div><span class="bs-meta"><span class="sg-cat"><?= $crot ?></span><?= data_curta($i['data_publicacao']) ?></span><b><?= bs_marcar($i['titulo'], $termos) ?></b><small><?= bs_trecho(materia_texto((string)$i['texto']), $termos) ?></small></div>
            </a>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($res['albuns']): ?>
        <section class="bs-grupo"><h2><i class="bi bi-images"></i>Álbuns de fotos</h2>
            <div class="sg-albuns-linha">
            <?php foreach (array_slice($res['albuns'], 0, 12) as $a): ?>
                <a class="sg-album" href="album.php?id=<?= $a['id'] ?>"><img src="<?= h(site_img_url('f', (int)$a['capa_foto'], (string)$a['capa_arquivo'], true)) ?>" alt="" loading="lazy"><span class="sg-album-n"><i class="bi bi-images"></i><?= (int)$a['n_fotos'] ?></span><div class="sg-album-txt"><?php if ($a['data_evento']): ?><time><?= data_curta($a['data_evento']) ?></time><?php endif; ?><h3><?= h($a['titulo']) ?></h3></div></a>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($res['equipe']): ?>
        <section class="bs-grupo"><h2><i class="bi bi-people"></i>Equipe</h2>
            <?php foreach (array_slice($res['equipe'], 0, 8) as $e): $src = equipe_foto_src($e); ?>
            <a class="bs-item" href="index.php#equipe"><?php if ($src): ?><img class="bs-img redonda" src="<?= h($src) ?>" alt="" loading="lazy"><?php else: ?><span class="bs-ic"><i class="bi bi-person"></i></span><?php endif; ?><div><b><?= bs_marcar($e['nome'], $termos) ?></b><small><?= bs_marcar((string)($e['funcao'] ?? ''), $termos) ?></small></div><i class="bi bi-chevron-right"></i></a>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="bs-grupo"><h2><i class="bi bi-lightbulb"></i><?= $q !== '' && !$total ? 'Tente buscar por' : 'Buscas comuns' ?></h2>
            <div class="bs-sugestoes"><?php foreach ($sugestoes as $s): ?><a href="busca.php?q=<?= rawurlencode($s) ?>"><?= h($s) ?></a><?php endforeach; ?></div>
        </section>
    </div>
</main>
<?php $rodape_extra = '<script src="assets/js/inicio.js?v=8" defer></script>'; require __DIR__ . '/includes/site_rodape.php'; ?>
