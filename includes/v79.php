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
