<?php
/* ======================================================================
 *  v79 — Editais de seleção e Grêmio estudantil no site
 * ====================================================================== */

function v79_migrar(): void
{
    if (config_ler('migracao_v79')) return;
    try {
        $pdo = db();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_editais (id $id, titulo VARCHAR(180) NOT NULL, numero VARCHAR(40) NULL, descricao TEXT NULL,
                    inscricao_inicio DATE NULL, inscricao_fim DATE NULL, link_inscricao VARCHAR(255) NULL,
                    arquivo VARCHAR(255) NULL, arquivo_ext VARCHAR(8) NULL, arquivo_tam $int NULL,
                    resultado VARCHAR(255) NULL, resultado_ext VARCHAR(8) NULL, resultado_tam $int NULL,
                    ativo TINYINT NOT NULL DEFAULT 1, criado_por $int NULL, criado_em DATETIME NOT NULL)$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_gremio (id $id, nome VARCHAR(120) NOT NULL, funcao VARCHAR(80) NOT NULL, curso VARCHAR(120) NULL,
                    foto VARCHAR(255) NULL, ordem SMALLINT NOT NULL DEFAULT 100, ativo TINYINT NOT NULL DEFAULT 1, criado_em DATETIME NOT NULL)$fim");
        config_gravar('migracao_v79', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* tenta de novo no próximo acesso */ }
}

/* ---------------- Editais ---------------- */
function editais_lista(bool $so_ativos = true): array
{
    try {
        $l = q_todos('SELECT * FROM site_editais' . ($so_ativos ? ' WHERE ativo = 1' : '')
                     . ' ORDER BY CASE WHEN inscricao_fim IS NULL OR inscricao_fim >= ? THEN 0 ELSE 1 END, COALESCE(inscricao_inicio, criado_em) DESC, id DESC', [date('Y-m-d')]);
        // inscrições abertas primeiro, depois as que vão abrir, depois o resto
        $pri = ['aberto' => 0, 'breve' => 1, 'publicado' => 2, 'resultado' => 3, 'encerrado' => 4];
        $pos = array_flip(array_keys($l));
        uksort($l, fn($a, $b) => [$pri[edital_status($l[$a])[0]], $pos[$a]] <=> [$pri[edital_status($l[$b])[0]], $pos[$b]]);
        return array_values($l);
    } catch (PDOException $e) { return []; }
}

/** Situação do edital: [chave, rótulo, detalhe] */
function edital_status(array $e): array
{
    $hoje = date('Y-m-d');
    $d = fn($x) => date('d/m/Y', strtotime($x));
    if (!empty($e['resultado'])) return ['resultado', 'Resultado publicado', ''];
    if (!empty($e['inscricao_inicio']) && $e['inscricao_inicio'] > $hoje) return ['breve', 'Em breve', 'Inscrições a partir de ' . $d($e['inscricao_inicio'])];
    if (!empty($e['inscricao_fim']) && $e['inscricao_fim'] < $hoje) return ['encerrado', 'Inscrições encerradas', 'Encerradas em ' . $d($e['inscricao_fim'])];
    if (!empty($e['inscricao_inicio']) || !empty($e['inscricao_fim'])) return ['aberto', 'Inscrições abertas', !empty($e['inscricao_fim']) ? 'Até ' . $d($e['inscricao_fim']) : ''];
    return ['publicado', 'Publicado', ''];
}

/** Guarda o PDF do edital ou do resultado (mesma regra dos arquivos do site) */
function edital_arquivo_guardar(array $f): array
{
    [$rel, $ext, $tam] = site_arquivo_guardar($f);
    return [$rel, $ext, $tam];
}

/* ---------------- Grêmio estudantil ---------------- */
function gremio_lista(bool $so_ativos = true): array
{
    try { return q_todos('SELECT * FROM site_gremio' . ($so_ativos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome'); }
    catch (PDOException $e) { return []; }
}
function gremio_foto_src(array $g, string $raiz = ''): ?string
{
    return !empty($g['foto']) ? $raiz . 'foto_gremio.php?id=' . (int)$g['id'] . '&v=' . substr(md5((string)$g['foto']), 0, 8) : null;
}
function gremio_foto_apagar(?string $rel): void
{
    if ($rel && preg_match('#^fotos/gremio/[\w.-]+$#', $rel) && is_file(PASTA_DADOS . '/' . $rel)) @unlink(PASTA_DADOS . '/' . $rel);
}

/** Cartão do edital no site (página inicial e página de editais). Abre o PDF no visor do site. */
function edital_cartao(array $x): string
{
    [$k, $rot, $det] = edital_status($x);
    $u = 'arquivo_site.php?edital=' . (int)$x['id'];
    $ext = (string)($x['arquivo_ext'] ?: 'pdf');
    $meta = 'Edital' . ($x['numero'] ? ' nº ' . $x['numero'] : '') . ($x['arquivo_tam'] ? ' · ' . tamanho_legivel((int)$x['arquivo_tam']) : '');
    $h = '<article class="ed2-card dc-item st-' . $k . '" data-id="e' . (int)$x['id'] . '" data-url="' . e($u) . '" data-ext="' . e($ext) . '" data-titulo="' . e($x['titulo']) . '" data-meta="' . e($meta) . '" data-cor="vermelho" data-ic="file-earmark-pdf">'
       . '<div class="ed2-topo"><span class="ed2-st ' . $k . '">' . ($k === 'aberto' ? '<i></i>' : '') . e($rot) . '</span>' . ($x['numero'] ? '<span class="ed2-num">Nº ' . e($x['numero']) . '</span>' : '') . '</div>'
       . '<h4>' . e($x['titulo']) . '</h4>'
       . ($det ? '<p class="ed2-prazo"><i class="bi bi-calendar-event"></i>' . e($det) . '</p>' : '')
       . ($x['descricao'] ? '<p class="ed2-desc">' . e($x['descricao']) . '</p>' : '')
       . '<div class="ed2-bt"><a class="ed2-ver" href="' . e($u) . '" target="_blank" rel="noopener" data-ver><i class="bi bi-file-earmark-text"></i>Ver edital</a>'
       . ($k === 'aberto' && $x['link_inscricao'] ? '<a class="ed2-insc" href="' . e($x['link_inscricao']) . '" target="_blank" rel="noopener"><i class="bi bi-pencil-square"></i>Inscrever-se</a>' : '')
       . ($x['resultado'] ? '<a class="ed2-res" href="' . e($u) . '&amp;r=1" target="_blank" rel="noopener"><i class="bi bi-trophy"></i>Resultado</a>' : '')
       . '</div></article>';
    return $h;
}

/* ======================================================================
 *  v80 — Card "Alunos destaque" no carrossel da página inicial
 *  Fica em configuracoes (site_destaques), editado em Painel › Site › Banners.
 * ====================================================================== */
function destaques_cfg(): array
{
    $c = json_decode((string)config_ler('site_destaques'), true);
    if (!is_array($c)) {
        // primeira vez: os destaques do 3º período de 2026
        $c = ['ativo' => 1, 'titulo' => 'Alunos destaque', 'periodo' => '3º período · 2026', 'alunos' => [
            ['nome' => 'Emile Vieira Texeira Fragoso', 'nota' => '9,94', 'turma' => ''],
            ['nome' => 'Lara Emanuelly Vieira Medeiros', 'nota' => '9,82', 'turma' => ''],
            ['nome' => 'Rian Rilare Feitoza da Silva', 'nota' => '9,67', 'turma' => ''],
        ]];
        config_gravar('site_destaques', json_encode($c, JSON_UNESCAPED_UNICODE));
    }
    $c['alunos'] = array_values(array_filter(array_slice((array)($c['alunos'] ?? []), 0, 3), fn($a) => trim((string)($a['nome'] ?? '')) !== ''));
    return $c;
}

/** Foto (em pé) de cada aluno do card: config site_destaques_foto_0..2 */
function destaques_foto(int $k): string { return (string)config_ler('site_destaques_foto_' . $k); }

/** Slide do card de destaques (mesmo tamanho dos banners) */
function destaques_slide(array $c, int $i, int $total): string
{
    /* v84 · banner animado no modelo do esboço: logo + título à esquerda; foto em pé, lugar, nome, turma e média de cada aluno */
    $tit = trim((string)$c['titulo']) ?: 'Alunos destaque';
    $pal = preg_split('/\s+/', $tit, 2);
    $logo = $GLOBALS['ESCOLA']['logo_branca'] ?? 'assets/img/logo-branca.png';
    $h = '<div class="sg-bn sg-bn-dq2" aria-roledescription="slide" aria-label="' . ($i + 1) . ' de ' . $total . ': ' . e($tit) . '">'
       . '<div class="dq2-fundo" aria-hidden="true"><i class="dq2-bolha b1"></i><i class="dq2-bolha b2"></i><i class="dq2-bolha b3"></i><span class="dq2-raios"></span>'
       . str_repeat('<b class="dq2-confete"></b>', 14) . '</div>'
       . '<div class="dq2-intro"><img class="dq2-logo" src="' . e($logo) . '" alt="" width="1400" height="451">'
       . '<div class="dq2-txtbox"><h3 class="dq2-tit"><span>' . e($pal[0]) . '</span>' . (isset($pal[1]) ? '<em>' . e($pal[1]) . '</em>' : '') . '</h3>'
       . '<p class="dq2-per"><i class="bi bi-stars"></i>' . e($c['periodo']) . '</p></div></div><ol class="dq2-lista">';
    foreach ($c['alunos'] as $k => $a) {
        if ($k > 2) break;
        $f = destaques_foto($k);
        $ini = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($a['nome'])), 0, 2))));
        $h .= '<li class="dq2-al p' . ($k + 1) . '" style="--k:' . $k . '">'
            . '<div class="dq2-foto">' . ($f ? '<img src="' . e(site_img_url('dq', $k, $f)) . '" alt="' . e($a['nome']) . '"' . ($i > 1 ? ' loading="lazy"' : '') . '>' : '<span class="dq2-ini">' . e($ini) . '</span>')
            . '<span class="dq2-luz"></span><span class="dq2-med"><i class="bi bi-trophy-fill"></i>' . ($k + 1) . 'º</span></div>'
            . '<div class="dq2-txt"><span class="dq2-lugar">' . ($k + 1) . 'º Lugar</span><b class="dq2-nome">' . e($a['nome']) . '</b>'
            . (!empty($a['turma']) ? '<small class="dq2-turma">' . e($a['turma']) . '</small>' : '')
            . '<strong class="dq2-nota" data-nota="' . e($a['nota']) . '">' . e($a['nota']) . '</strong></div></li>';
    }
    return $h . '</ol></div>';
}

/* ======================================================================
 *  v82 — Seções do "Site" no painel, em linguagem simples e agrupadas
 *  [arquivo, ícone, nome, o que é]
 * ====================================================================== */
function site_secoes_painel(): array
{
    $g = [
        ['titulo' => 'Página inicial', 'itens' => [
            ['banners_site.php', 'card-image', 'Banners e alunos destaque', 'As imagens que passam no topo do site'],
            ['informes_site.php', 'newspaper', 'Notícias', 'Publicar o que acontece na escola'],
            ['agenda_site.php', 'calendar-event', 'Próximos eventos', 'Feriados, sábados letivos e eventos'],
            ['editais_site.php', 'megaphone', 'Editais', 'Editais de seleção, inscrição e resultado'],
            ['arquivos_site.php', 'folder2-open', 'Documentos', 'Regimento, matrizes e outros arquivos'],
        ]],
        ['titulo' => 'Sobre a escola', 'itens' => [
            ['conteudo_site.php', 'building', 'A escola e contato', 'Apresentação, fotos da escola e contatos'],
            ['ambientes_site.php', 'buildings', 'Ambientes', 'Laboratórios, quadra, biblioteca…'],
            ['gestao_site.php', 'person-workspace', 'Gestão escolar', 'Os cartões de quem conduz a escola'],
            ['equipe.php', 'people', 'Professores e funcionários', 'A galeria da equipe'],
            ['gremio_site.php', 'flag', 'Grêmio estudantil', 'Membros do grêmio com foto e função'],
            ['estudantes_site.php', 'images', 'Fotos dos estudantes', '"Quem faz a escola acontecer"'],
        ]],
    ];
    $cfg = [];
    if (pode('ia.config')) $cfg[] = ['assistente_ia.php', 'stars', 'Sofia (assistente de IA)', 'Chave e limite de perguntas'];
    if (pode('usuarios')) $cfg[] = ['login_google.php', 'google', 'Login com Google', 'Entrar com a conta da escola'];
    if ($cfg) $g[] = ['titulo' => 'Configurações', 'itens' => $cfg];
    return $g;
}
