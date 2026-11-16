<?php
/* =====================================================================
 *  Conteúdo do site da escola, publicado pelo painel:
 *  álbuns de fotos (galeria) e informes (avisos, eventos, conquistas).
 *  As imagens ficam em dados/site/ e são entregues por foto_site.php.
 * ===================================================================== */

const CATEGORIAS_INFORME = [
    'aviso'     => ['Aviso', 'megaphone'],
    'evento'    => ['Evento', 'calendar-event'],
    'conquista' => ['Conquista', 'trophy'],
    'matricula' => ['Matrícula', 'person-plus'],
];
const SITE_FOTO_MAX = 1800;   // lado maior da foto grande (px)
const SITE_FOTO_MINI = 640;   // lado maior da miniatura (px)

/**
 * Salva uma imagem do site em duas versões: grande e miniatura (_p).
 * Devolve [caminho relativo, largura, altura].
 */
function site_img_salvar(array $f, string $sub, string $prefixo): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    if ($f['size'] > 25 * 1048576) throw new RuntimeException('A foto passa de 25 MB.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Envie uma imagem JPG, PNG ou WEBP.');
    $pasta = pasta_dados('site/' . $sub);
    $nome = $prefixo . '_' . bin2hex(random_bytes(6));
    if (!function_exists('imagecreatefromstring')) {
        if ($info[2] !== IMAGETYPE_JPEG) throw new RuntimeException('Este servidor só aceita fotos em JPG.');
        move_uploaded_file($f['tmp_name'], "$pasta/$nome.jpg");
        copy("$pasta/$nome.jpg", "$pasta/{$nome}_p.jpg");
        return ["site/$sub/$nome.jpg", $info[0], $info[1]];
    }
    $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$img) throw new RuntimeException('Não foi possível ler a imagem.');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        $giro = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($giro) { $r = imagerotate($img, $giro, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $w = imagesx($img); $h = imagesy($img);
    $grava = function (int $max, string $arq, int $q) use ($img, $w, $h): array {
        $esc = min(1, $max / max($w, $h)); $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
        $d = imagecreatetruecolor($nw, $nh);
        imagefill($d, 0, 0, imagecolorallocate($d, 255, 255, 255));
        imagecopyresampled($d, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($d, true);
        imagejpeg($d, $arq, $q); imagedestroy($d);
        return [$nw, $nh];
    };
    [$gw, $gh] = $grava(SITE_FOTO_MAX, "$pasta/$nome.jpg", 82);
    $grava(SITE_FOTO_MINI, "$pasta/{$nome}_p.jpg", 78);
    imagedestroy($img);
    return ["site/$sub/$nome.jpg", $gw, $gh];
}

function site_img_apagar(?string $rel): void
{
    if (!$rel || !preg_match('#^site/[\w/.-]+\.jpg$#', $rel) || str_contains($rel, '..')) return;
    foreach ([$rel, preg_replace('/\.jpg$/', '_p.jpg', $rel)] as $r) if (is_file(PASTA_DADOS . '/' . $r)) @unlink(PASTA_DADOS . '/' . $r);
}

/** Endereço público de uma imagem: tipo f (foto de álbum) ou i (informe) */
function site_img_url(string $tipo, int $id, string $arquivo, bool $mini = false, string $raiz = ''): string
{
    return $raiz . 'foto_site.php?' . $tipo . '=' . $id . ($mini ? '&p=1' : '') . '&v=' . substr(md5($arquivo), 0, 8);
}

function site_albuns(bool $so_publicados = true, int $limite = 100): array
{
    try {
        return q_todos('SELECT a.*, (SELECT COUNT(*) FROM site_fotos f WHERE f.album_id = a.id) AS n_fotos,
                               COALESCE((SELECT f.id FROM site_fotos f WHERE f.id = a.capa_id), (SELECT MIN(f.id) FROM site_fotos f WHERE f.album_id = a.id)) AS capa_foto,
                               (SELECT f.arquivo FROM site_fotos f WHERE f.id = COALESCE(a.capa_id, (SELECT MIN(f2.id) FROM site_fotos f2 WHERE f2.album_id = a.id))) AS capa_arquivo
                          FROM site_albuns a' . ($so_publicados ? ' WHERE a.publicado = 1' : '') . '
                         ORDER BY COALESCE(a.data_evento, a.criado_em) DESC, a.id DESC LIMIT ' . (int)$limite);
    } catch (PDOException $e) { return []; }
}

function site_fotos_album(int $album_id): array
{
    try { return q_todos('SELECT * FROM site_fotos WHERE album_id = ? ORDER BY ordem, id', [$album_id]); }
    catch (PDOException $e) { return []; }
}

function site_informes(bool $so_publicados = true, int $limite = 30): array
{
    try {
        try {
            return q_todos('SELECT i.*, u.nome AS autor_nome, u.nome_exibicao AS autor_exib, u.cargo AS autor_cargo, u.perfil AS autor_perfil,
                                   (SELECT COUNT(*) FROM site_informe_anexos x WHERE x.informe_id = i.id) AS n_anexos
                              FROM site_informes i LEFT JOIN usuarios u ON u.id = i.criado_por' . ($so_publicados ? ' WHERE i.publicado = 1 AND i.data_publicacao <= ?' : ' WHERE 1 = 1 OR ? = ?') . '
                             ORDER BY i.fixado DESC, i.data_publicacao DESC, i.id DESC LIMIT ' . (int)$limite, $so_publicados ? [date('Y-m-d')] : [1, 1]);
        } catch (PDOException $e) {   // banco ainda sem a tabela de anexos
            return q_todos('SELECT * FROM site_informes' . ($so_publicados ? ' WHERE publicado = 1 AND data_publicacao <= ?' : ' WHERE 1 = 1 OR ? = ?') . '
                             ORDER BY fixado DESC, data_publicacao DESC, id DESC LIMIT ' . (int)$limite, $so_publicados ? [date('Y-m-d')] : [1, 1]);
        }
    } catch (PDOException $e) { return []; }
}

/** Data por extenso curta: 12 mar 2026 */
function data_curta(?string $d): string
{
    if (!$d) return '';
    $m = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $t = strtotime($d);
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t);
}

/* ---------------------------------------------------------------------
 *  Banners do carrossel da página inicial (artes 1600 × 900)
 * --------------------------------------------------------------------- */
function site_banners(bool $so_no_ar = true): array
{
    try {
        $sql = 'SELECT * FROM site_banners';
        $p = [];
        if ($so_no_ar) { $sql .= ' WHERE ativo = 1 AND (inicio IS NULL OR inicio <= ?) AND (fim IS NULL OR fim >= ?)'; $p = [date('Y-m-d'), date('Y-m-d')]; }
        return q_todos($sql . ' ORDER BY ordem, id DESC', $p);
    } catch (PDOException $e) { return []; }
}

/** Banners para mostrar no site: os do painel; se não houver nenhum cadastrado, os padrões da escola */
function site_banners_para_mostrar(): array
{
    global $BANNERS_PADRAO;
    $lista = [];
    foreach (site_banners(true) as $b) $lista[] = ['src' => site_img_url('b', (int)$b['id'], $b['imagem']), 'larga' => !empty($b['imagem_larga']) ? site_img_url('b', (int)$b['id'], $b['imagem_larga']) . '&l=1' : '', 'titulo' => $b['titulo'], 'link' => (string)$b['link']];
    $cadastrados = 0;
    try { $cadastrados = (int)q_valor('SELECT COUNT(*) FROM site_banners'); } catch (PDOException $e) {}
    if (!$lista && !$cadastrados) {
        foreach ($BANNERS_PADRAO ?? [] as $b) {
            if (!empty($b['fim']) && $b['fim'] < date('Y-m-d')) continue;
            if (is_file(dirname(__DIR__) . '/' . $b['imagem'])) $lista[] = ['src' => $b['imagem'], 'larga' => !empty($b['larga']) && is_file(dirname(__DIR__) . '/' . $b['larga']) ? $b['larga'] : '', 'titulo' => $b['titulo'], 'link' => $b['link']];
        }
    }
    return $lista;
}

/** Link seguro para o banner: páginas do site, âncoras ou endereços http(s) */
function banner_link_valido(string $l): string
{
    $l = trim($l);
    if ($l === '') return '';
    if (preg_match('#^https?://[^\s<>"]+$#i', $l)) return mb_substr($l, 0, 255);
    if (preg_match('#^(\#[\w-]+|[\w-]+\.php(\?[\w=&%.-]*)?(\#[\w-]+)?)$#', $l)) return $l;
    return '';
}

/* ---------------------------------------------------------------------
 *  Agenda pública: calendário letivo (eventos marcados "no site")
 *  + informes com dia do evento
 * --------------------------------------------------------------------- */
const AGENDA_TIPOS = [
    'evento'        => ['Evento', '#1d5fb0'],
    'feriado'       => ['Feriado', '#c0261b'],
    'recesso'       => ['Recesso / férias', '#d4650a'],
    'sabado_letivo' => ['Sábado letivo', '#13692a'],
    'reuniao'       => ['Reunião', '#6b3fa0'],
    'periodo'       => ['Período letivo', '#5b6670'],
    'informe'       => ['Evento', '#1d5fb0'],
];

function site_agenda(string $ini, string $fim): array
{
    $itens = [];
    try {
        try { $evs = q_todos('SELECT * FROM eventos WHERE publico = 1 AND data_inicio <= ? AND COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio', [$fim, $ini]); }
        catch (PDOException $e) { $evs = q_todos("SELECT * FROM eventos WHERE tipo <> 'reuniao' AND data_inicio <= ? AND COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio", [$fim, $ini]); }
        foreach ($evs as $e) $itens[] = ['d' => $e['data_inicio'], 'f' => $e['data_fim'] ?: null, 't' => $e['titulo'], 'k' => isset(AGENDA_TIPOS[$e['tipo']]) ? $e['tipo'] : 'evento', 'l' => ''];
    } catch (PDOException $e) {}
    try {
        foreach (q_todos('SELECT id, titulo, data_evento FROM site_informes WHERE publicado = 1 AND data_evento IS NOT NULL AND data_evento BETWEEN ? AND ?', [$ini, $fim]) as $i)
            $itens[] = ['d' => $i['data_evento'], 'f' => null, 't' => $i['titulo'], 'k' => 'informe', 'l' => 'informe.php?id=' . $i['id']];
    } catch (PDOException $e) {}
    usort($itens, fn($a, $b) => strcmp($a['d'], $b['d']));
    return $itens;
}

/** Eventos anunciados sem dia definido ("data a confirmar") */
function site_eventos_a_confirmar(): array
{
    try {
        return q_todos("SELECT id, titulo FROM site_informes WHERE publicado = 1 AND categoria = 'evento' AND data_evento IS NULL AND data_publicacao <= ? AND data_publicacao >= ? ORDER BY data_publicacao DESC LIMIT 3",
                       [date('Y-m-d'), date('Y-m-d', strtotime('-150 days'))]);
    } catch (PDOException $e) { return []; }
}

/** Cartão do calendário (página inicial e página de informes) */
function agenda_cartao(): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ini = date('Y-m-d'); $fim = date('Y-m-d', strtotime('+12 months'));
    $itens = site_agenda($ini, $fim);
    $hoje = date('Y-m-d');
    $prox = array_values(array_filter($itens, fn($e) => ($e['f'] ?: $e['d']) >= $hoje));
    $confirmar = site_eventos_a_confirmar();
    $meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $tipos = array_map(fn($t) => ['n' => $t[0], 'c' => $t[1]], AGENDA_TIPOS);
    ob_start(); ?>
    <div class="ag-cartao" id="agenda">
        <div class="ag-topo"><h3><i class="bi bi-calendar-event"></i>Próximos eventos</h3></div>
        <ul class="ag-lista">
            <?php foreach (array_slice($prox, 0, 6) as $e): $t = AGENDA_TIPOS[$e['k']]; $tag = $e['l'] ? 'a' : 'div'; ?>
            <li><<?= $tag ?> class="ag-item"<?= $e['l'] ? ' href="' . $h($e['l']) . '"' : '' ?>><span class="ag-data" style="--c:<?= $t[1] ?>"><b><?= (int)substr($e['d'], 8, 2) ?></b><?= $meses[(int)substr($e['d'], 5, 2)] ?></span><span class="ag-txt"><strong><?= $h($e['t']) ?></strong><small><?= $t[0] ?><?= $e['f'] ? ' · até ' . (int)substr($e['f'], 8, 2) . ' ' . $meses[(int)substr($e['f'], 5, 2)] : '' ?><?= $e['d'] <= $hoje ? ' · <b>hoje</b>' : '' ?></small></span></<?= $tag ?>></li>
            <?php endforeach; ?>
            <?php foreach ($confirmar as $c): ?>
            <li><a class="ag-item" href="informe.php?id=<?= (int)$c['id'] ?>"><span class="ag-data breve"><i class="bi bi-hourglass-split"></i></span><span class="ag-txt"><strong><?= $h($c['titulo']) ?></strong><small>Em breve · data a confirmar</small></span></a></li>
            <?php endforeach; ?>
            <?php if (!$prox && !$confirmar): ?><li class="ag-vazio">Nenhum evento marcado nos próximos meses.</li><?php endif; ?>
        </ul>
    </div>
    <?php return (string)ob_get_clean();
}

/** Enquadramento da foto de capa de um informe (object-position seguro) */
function informe_foco(array $i): string
{
    $f = (string)($i['imagem_foco'] ?? '');
    return preg_match('/^(\d{1,3})% (\d{1,3})%$/', $f, $m) && $m[1] <= 100 && $m[2] <= 100 ? 'object-position:' . $f : 'object-position:50% 40%';
}


/* ---------------------------------------------------------------------
 *  Autor da notícia e PDFs anexados
 * --------------------------------------------------------------------- */
/** Nome curto de quem publicou (primeiro e último nome) e função */
function informe_autor(array $i): array
{
    $n = trim((string)(($i['autor_exib'] ?? '') ?: ($i['autor_nome'] ?? '')));
    if ($n === '') return ['Equipe da escola', 'EEEP Venceslau', 'EV'];
    $p = preg_split('/\s+/', $n);
    $curto = count($p) > 2 ? $p[0] . ' ' . end($p) : $n;
    $ini = mb_strtoupper(mb_substr($p[0], 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
    $cargo = trim((string)($i['autor_cargo'] ?? '')) ?: (function_exists('perfil_nome') ? perfil_nome((string)($i['autor_perfil'] ?? '')) : '');
    return [$curto, $cargo ?: 'Equipe da escola', $ini];
}

/** "1 nov 2026 · 14:32" */
function informe_quando(array $i): string
{
    $d = data_curta((string)$i['data_publicacao']);
    $h = !empty($i['criado_em']) && substr((string)$i['criado_em'], 0, 10) === (string)$i['data_publicacao'] ? date('H:i', strtotime((string)$i['criado_em'])) : '';
    return $d . ($h ? ' · ' . $h : '');
}

function informe_anexos(int $informe_id): array
{
    try { return q_todos('SELECT * FROM site_informe_anexos WHERE informe_id = ? ORDER BY ordem, id', [$informe_id]); }
    catch (PDOException $e) { return []; }
}

/** Salva um PDF anexado a uma notícia (dados/site/anexos/) */
function informe_anexo_salvar(int $informe_id, array $f, string $titulo): void
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    if ($f['size'] > 30 * 1048576) throw new RuntimeException('O PDF "' . $f['name'] . '" passa de 30 MB.');
    if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'pdf' || (string)file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-')
        throw new RuntimeException('"' . $f['name'] . '" não é um PDF. Só é possível anexar arquivos PDF.');
    $pasta = pasta_dados('site/anexos');
    $nome = 'a' . $informe_id . '_' . bin2hex(random_bytes(6)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], "$pasta/$nome")) throw new RuntimeException('Não foi possível salvar o PDF no servidor.');
    $titulo = trim($titulo) !== '' ? trim($titulo) : preg_replace('/\.pdf$/i', '', basename((string)$f['name']));
    $ordem = (int)q_valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM site_informe_anexos WHERE informe_id = ?', [$informe_id]);
    q('INSERT INTO site_informe_anexos (informe_id, titulo, arquivo, tamanho, ordem, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
      [$informe_id, mb_substr($titulo, 0, 160), "site/anexos/$nome", (int)$f['size'], $ordem, date('Y-m-d H:i:s')]);
}

function informe_anexo_apagar(array $a): void
{
    if (preg_match('#^site/anexos/[\w.-]+\.pdf$#', (string)$a['arquivo']) && is_file(PASTA_DADOS . '/' . $a['arquivo'])) @unlink(PASTA_DADOS . '/' . $a['arquivo']);
    q('DELETE FROM site_informe_anexos WHERE id = ?', [(int)$a['id']]);
}


/** Próximos eventos para a grade da página inicial (computador) */
function site_proximos_eventos(int $n = 8): array
{
    $hoje = date('Y-m-d');
    $itens = array_values(array_filter(site_agenda($hoje, date('Y-m-d', strtotime('+12 months'))), fn($e) => ($e['f'] ?: $e['d']) >= $hoje));
    $itens = array_slice($itens, 0, $n);
    foreach (site_eventos_a_confirmar() as $c) if (count($itens) < $n) $itens[] = ['d' => '', 'f' => null, 't' => $c['titulo'], 'k' => 'breve', 'l' => 'informe.php?id=' . (int)$c['id']];
    return $itens;
}

/** "30 de outubro de 2026" */
function data_extenso_curta(string $d): string
{
    $m = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $t = strtotime($d);
    return $t ? (int)date('j', $t) . ' de ' . $m[(int)date('n', $t)] . ' de ' . date('Y', $t) : '';
}

/** Texto da matéria: parágrafos, intertítulos (##), listas (-) e **negrito**, sempre escapado */
function informe_materia(string $t): string
{
    if (materia_eh_html($t)) return materia_limpar($t);
    $t = str_replace(["\r\n", "\r"], "\n", trim($t));
    $inl = fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
    $out = '';
    foreach (preg_split('/\n\s*\n/', $t) as $bloco) {
        $linhas = array_values(array_filter(array_map('rtrim', explode("\n", $bloco)), fn($l) => trim($l) !== ''));
        if (!$linhas) continue;
        $buf = []; $lista = [];
        $solta = function () use (&$buf, &$out, $inl) { if ($buf) { $out .= '<p>' . implode('<br>', array_map($inl, $buf)) . '</p>'; $buf = []; } };
        $fecha = function () use (&$lista, &$out, $inl) { if ($lista) { $out .= '<ul>' . implode('', array_map(fn($x) => '<li>' . $inl($x) . '</li>', $lista)) . '</ul>'; $lista = []; } };
        foreach ($linhas as $l) {
            if (preg_match('/^\s*#{1,4}\s+(.+)$/u', $l, $m)) { $solta(); $fecha(); $out .= '<h2>' . $inl($m[1]) . '</h2>'; }
            elseif (preg_match('/^\s*[-•*]\s+(.+)$/u', $l, $m)) { $solta(); $lista[] = $m[1]; }
            else { $fecha(); $buf[] = trim($l); }
        }
        $solta(); $fecha();
    }
    return $out;
}

/** Subtítulo da notícia; sem subtítulo, usa o começo da matéria */
function informe_resumo(array $i, int $max = 150): string
{
    $s = trim((string)($i['subtitulo'] ?? ''));
    if ($s === '') {
        $s = materia_texto((string)($i['texto'] ?? ''));
    }
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/* ---------- Fotos da seção "Nossos estudantes" (editáveis no painel) ---------- */
const ESTUDANTES_MAX = 9;

/** Lista guardada no painel: [['a' => 'site/estudantes/x.jpg' ou 'assets/img/...', 'l' => legenda], ...]; null = ainda usa a padrão */
function estudantes_lista(): ?array
{
    $j = config_ler('site_estudantes');
    if ($j === null || $j === '') return null;
    $v = json_decode($j, true);
    return is_array($v) ? array_values(array_filter($v, fn($x) => is_array($x) && !empty($x['a']))) : null;
}

/** Para o site: [['imagem' => url, 'legenda' => ...]] */
function site_estudantes(array $padrao, string $raiz = ''): array
{
    $lista = estudantes_lista();
    $existe = fn($c) => $c && !str_contains($c, '..') && is_file(dirname(__DIR__) . '/' . $c);
    if ($lista === null) return array_values(array_filter($padrao, fn($g) => $existe($g['imagem'])));
    $out = [];
    foreach ($lista as $k => $x) {
        if (str_starts_with($x['a'], 'site/')) $url = $raiz . 'foto_site.php?e=' . $k . '&v=' . substr(md5($x['a']), 0, 8);
        elseif ($existe($x['a'])) $url = $raiz . $x['a'];
        else continue;
        $out[] = ['imagem' => $url, 'legenda' => (string)($x['l'] ?? '')];
    }
    return $out;
}

/* ---------- Arquivos públicos do site (regimento, matriz curricular…) ---------- */
const ARQ_SITE_CATEGORIAS = ['Documentos da escola', 'Matriz curricular', 'Informática', 'Desenvolvimento de Sistemas', 'Enfermagem', 'Administração', 'Editais e seleção', 'Outros'];
const ARQ_SITE_TIPOS = [
    'pdf' => ['application/pdf', 'file-earmark-pdf', 'PDF', 'vermelho'],
    'doc' => ['application/msword', 'file-earmark-word', 'Word', 'azul'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'file-earmark-word', 'Word', 'azul'],
    'odt' => ['application/vnd.oasis.opendocument.text', 'file-earmark-text', 'Texto', 'azul'],
    'xls' => ['application/vnd.ms-excel', 'file-earmark-excel', 'Planilha', 'verde'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file-earmark-excel', 'Planilha', 'verde'],
    'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'file-earmark-spreadsheet', 'Planilha', 'verde'],
    'ppt' => ['application/vnd.ms-powerpoint', 'file-earmark-ppt', 'Slides', 'laranja'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'file-earmark-ppt', 'Slides', 'laranja'],
    'jpg' => ['image/jpeg', 'file-earmark-image', 'Imagem', 'roxo'],
    'jpeg' => ['image/jpeg', 'file-earmark-image', 'Imagem', 'roxo'],
    'png' => ['image/png', 'file-earmark-image', 'Imagem', 'roxo'],
];

function site_arquivos(bool $so_ativos = true): array
{
    try { return q_todos('SELECT * FROM site_arquivos' . ($so_ativos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, id DESC'); }
    catch (PDOException $e) { return []; }
}

function tamanho_legivel(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}

/** Guarda o arquivo enviado e devolve [caminho relativo, extensão, tamanho] */
function site_arquivo_guardar(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    if ($f['size'] > 40 * 1048576) throw new RuntimeException('O arquivo "' . $f['name'] . '" passa de 40 MB.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!isset(ARQ_SITE_TIPOS[$ext])) throw new RuntimeException('Tipo de arquivo não aceito. Envie PDF, Word, Excel, PowerPoint, OpenOffice, JPG ou PNG.');
    $ini = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = match (true) {
        $ext === 'pdf' => str_starts_with($ini, '%PDF-'),
        in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods'], true) => str_starts_with($ini, "PK\x03\x04"),
        in_array($ext, ['doc', 'xls', 'ppt'], true) => str_starts_with($ini, "\xD0\xCF\x11\xE0"),
        in_array($ext, ['jpg', 'jpeg'], true) => str_starts_with($ini, "\xFF\xD8"),
        $ext === 'png' => str_starts_with($ini, "\x89PNG"),
        default => false,
    };
    if (!$ok) throw new RuntimeException('"' . $f['name'] . '" não parece ser um arquivo ' . strtoupper($ext) . ' válido.');
    $pasta = pasta_dados('site/arquivos');
    $nome = 'd' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$pasta/$nome")) throw new RuntimeException('Não foi possível salvar o arquivo no servidor.');
    return ["site/arquivos/$nome", $ext, (int)$f['size']];
}

function site_arquivo_apagar_disco(?string $rel): void
{
    if ($rel && preg_match('#^site/arquivos/[\w.-]+$#', $rel) && is_file(PASTA_DADOS . '/' . $rel)) @unlink(PASTA_DADOS . '/' . $rel);
}

/* ---------- Matéria das notícias com formatação (editor do painel) ---------- */
const MATERIA_FONTES = [
    'padrao' => ['Padrão', '"Plus Jakarta Sans", Arial, sans-serif'],
    'titulo' => ['Destaque', '"Bricolage Grotesque", "Plus Jakarta Sans", sans-serif'],
    'serifa' => ['Serifada', 'Georgia, "Times New Roman", serif'],
    'mono'   => ['Máquina', '"Courier New", monospace'],
];

/** É matéria escrita no editor (HTML)? */
function materia_eh_html(string $t): bool { return (bool)preg_match('/^\s*<(p|h[23]|ul|ol|blockquote|div|span|b|strong|i|em|u|mark|br)\b/i', $t); }

/** Limpa o HTML do editor: só tags e estilos permitidos (nada de script, eventos, iframes…) */
function materia_limpar(string $html): string
{
    if (trim($html) === '') return '';
    $permitidas = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'mark', 'span', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'hr', 'sub', 'sup'];
    $remover_tudo = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta', 'head', 'title', 'img', 'video', 'audio'];
    $fontes = array_map(fn($f) => strtolower($f[1]), MATERIA_FONTES);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $raiz = $doc->getElementById('raiz');
    if (!$raiz) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');

    $estilo_ok = function (string $css) use ($fontes): string {
        $ok = [];
        foreach (explode(';', $css) as $dec) {
            if (!str_contains($dec, ':')) continue;
            [$p, $v] = array_map('trim', explode(':', $dec, 2));
            $p = strtolower($p); $vl = strtolower($v);
            if (preg_match('/url\s*\(|expression|javascript|@import|\\\\/i', $v)) continue;
            $cor = '/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)|transparent|[a-z]{3,20})$/';
            $valido = match ($p) {
                'color', 'background-color' => (bool)preg_match($cor, $vl),
                'font-size' => (bool)preg_match('/^(x-small|small|medium|large|x-large|xx-large|(1[0-9]|2[0-9]|3[0-6])px|(0\.[6-9]|1(\.\d+)?|2(\.[0-4])?)em)$/', $vl),
                'font-family' => in_array(str_replace("'", '"', $vl), $fontes, true) || (bool)preg_match('/^("?[a-z ]+"?\s*,?\s*)+$/', $vl),
                'font-weight' => in_array($vl, ['bold', 'bolder', 'normal', '400', '600', '700', '800'], true),
                'font-style' => in_array($vl, ['italic', 'normal'], true),
                'text-decoration', 'text-decoration-line' => (bool)preg_match('/^(underline|line-through|none)( (underline|line-through))?$/', $vl),
                'text-align' => in_array($vl, ['left', 'center', 'right', 'justify'], true),
                default => false,
            };
            if ($valido) $ok[] = "$p: $v";
        }
        return implode('; ', $ok);
    };

    $limpar = function (DOMNode $no) use (&$limpar, $doc, $permitidas, $remover_tudo, $estilo_ok) {
        foreach (iterator_to_array($no->childNodes) as $f) {
            if ($f instanceof DOMComment) { $no->removeChild($f); continue; }
            if (!$f instanceof DOMElement) continue;
            $tag = strtolower($f->nodeName);
            if (in_array($tag, $remover_tudo, true)) { $no->removeChild($f); continue; }
            $limpar($f);
            if ($tag === 'div') $tag = 'p';
            if ($tag === 'h1' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') $tag = $tag === 'h1' ? 'h2' : 'h3';
            if ($tag === 'font') {   // <font color/face> vira <span style>
                $css = [];
                if ($f->getAttribute('color')) $css[] = 'color: ' . $f->getAttribute('color');
                if ($f->getAttribute('face')) $css[] = 'font-family: ' . $f->getAttribute('face');
                $novo = $doc->createElement('span');
                while ($f->firstChild) $novo->appendChild($f->firstChild);
                if ($css) $novo->setAttribute('style', implode('; ', $css));
                $no->replaceChild($novo, $f); $f = $novo; $tag = 'span';
            }
            if (!in_array($tag, $permitidas, true)) {          // tag desconhecida: fica só o conteúdo
                while ($f->firstChild) $no->insertBefore($f->firstChild, $f);
                $no->removeChild($f); continue;
            }
            if ($tag !== strtolower($f->nodeName)) {
                $novo = $doc->createElement($tag);
                while ($f->firstChild) $novo->appendChild($f->firstChild);
                foreach (iterator_to_array($f->attributes) as $at) $novo->setAttribute($at->name, $at->value);
                $no->replaceChild($novo, $f); $f = $novo;
            }
            $href = $tag === 'a' ? trim($f->getAttribute('href')) : '';
            $style = $f->getAttribute('style');
            foreach (iterator_to_array($f->attributes) as $at) $f->removeAttribute($at->name);
            if ($tag === 'a') {
                if (preg_match('#^(https?://|mailto:)#i', $href)) { $f->setAttribute('href', $href); $f->setAttribute('target', '_blank'); $f->setAttribute('rel', 'noopener nofollow'); }
                else { while ($f->firstChild) $no->insertBefore($f->firstChild, $f); $no->removeChild($f); continue; }
            }
            if ($style !== '' && in_array($tag, ['span', 'p', 'h2', 'h3', 'li', 'mark', 'b', 'strong', 'i', 'em', 'u'], true) && ($css = $estilo_ok($style)) !== '') $f->setAttribute('style', $css);
            if ($tag === 'span' && !$f->hasAttribute('style')) { while ($f->firstChild) $no->insertBefore($f->firstChild, $f); $no->removeChild($f); }
        }
    };
    $limpar($raiz);
    $out = '';
    foreach ($raiz->childNodes as $c) $out .= $doc->saveHTML($c);
    $out = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $out);
    return trim((string)$out);
}

/** Texto puro da matéria (para resumos, busca e cartões) */
function materia_texto(string $t): string
{
    if (materia_eh_html($t)) $t = html_entity_decode(strip_tags(preg_replace('#</(p|h2|h3|li|blockquote)>|<br\s*/?>#i', "\n", $t)), ENT_QUOTES, 'UTF-8');
    else $t = preg_replace(['/^\s*#{1,4}\s+/m', '/^\s*[-•*]\s+/m', '/\*\*(.+?)\*\*/u'], ['', '', '$1'], $t);
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

/* =====================================================================
 *  v59 · Conteúdo editável do site: contato, textos da escola, carrossel,
 *        ambientes (cada um com a sua página) e biografia da equipe
 * ===================================================================== */

/** Textos editáveis no painel (Site › A escola e contato). Vazio = usa o texto padrão de includes/escola.php */
function site_textos(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $t = [];
    try { $v = json_decode((string)config_ler('site_textos'), true); if (is_array($v)) $t = $v; } catch (Throwable $e) {}
    return $t;
}

/** Aplica o contato editado no painel em $ESCOLA (vale para o site e para os documentos impressos) */
function site_aplicar_contato(array &$escola): void
{
    $GLOBALS['ESCOLA_PADRAO'] ??= $escola;
    foreach (['endereco', 'cidade', 'telefone', 'email', 'instagram', 'atendimento', 'mapa'] as $k) {
        $v = site_textos()['contato'][$k] ?? null;
        if (is_string($v) && trim($v) !== '') $escola[$k] = trim($v);
        elseif ($v === '' && in_array($k, ['telefone', 'instagram'], true)) $escola[$k] = '';
    }
}

const PILARES_PADRAO = [
    ['clock', 'Tempo integral', 'Manhã e tarde, com refeições'],
    ['book', 'Ensino médio', 'Base comum completa e ENEM'],
    ['award', 'Diploma técnico', 'Ao concluir os três anos'],
    ['briefcase', 'Estágio', 'Em empresas parceiras'],
];

function site_pilares(): array
{
    $ed = site_textos()['pilares'] ?? null;
    $out = [];
    foreach (PILARES_PADRAO as $i => [$ic, $t, $x]) $out[] = ['icone' => $ic, 'titulo' => trim((string)($ed[$i]['titulo'] ?? '')) ?: $t, 'texto' => trim((string)($ed[$i]['texto'] ?? '')) ?: $x];
    return $out;
}

/** Fotos do carrossel da seção "A escola" (a primeira é a foto aérea padrão até ser trocada) */
function escola_fotos_lista(): ?array
{
    try { $j = config_ler('site_escola_fotos'); } catch (Throwable $e) { return null; }
    if ($j === null || $j === '') return null;
    $v = json_decode($j, true);
    return is_array($v) ? array_values(array_filter($v, fn($x) => is_array($x) && !empty($x['a']))) : null;
}

function site_escola_fotos(string $raiz = ''): array
{
    $padrao = [['imagem' => $raiz . 'assets/img/escola-aerea.jpg', 'legenda' => 'Nossa escola vista de cima · Alto da Queiroz, Boa Viagem']];
    $l = escola_fotos_lista();
    if ($l === null) return $padrao;
    $out = [];
    foreach ($l as $k => $x) {
        if (str_starts_with($x['a'], 'site/')) $url = $raiz . 'foto_site.php?c=' . $k . '&v=' . substr(md5($x['a']), 0, 8);
        elseif (!str_contains($x['a'], '..') && is_file(dirname(__DIR__) . '/' . $x['a'])) $url = $raiz . $x['a'];
        else continue;
        $out[] = ['imagem' => $url, 'legenda' => (string)($x['l'] ?? '')];
    }
    return $out ?: $padrao;
}

/* ---------- Ambientes ---------- */
const ICONES_AMBIENTE = ['pc-display' => 'Computador', 'heart-pulse' => 'Saúde', 'lightning-charge' => 'Física', 'eyedropper' => 'Química', 'flower1' => 'Biologia', 'calculator' => 'Matemática',
    'translate' => 'Línguas', 'book' => 'Livros', 'mic-fill' => 'Auditório', 'columns-gap' => 'Anfiteatro', 'dribbble' => 'Esportes', 'cup-hot' => 'Refeitório', 'easel2' => 'Sala de aula',
    'palette' => 'Artes', 'music-note-beamed' => 'Música', 'tree' => 'Área verde', 'tools' => 'Oficina', 'briefcase' => 'Empresa', 'camera-video' => 'Vídeo', 'robot' => 'Robótica', 'building' => 'Prédio', 'globe2' => 'Geografia'];

/** Lista dos ambientes; na primeira vez, cria a partir da lista fixa ($ESTRUTURA) */
function ambientes_lista(bool $so_ativos = true): array
{
    try {
        if (!config_ler('ambientes_semeados') && !(int)q_valor('SELECT COUNT(*) FROM site_ambientes')) {
            $agora = date('Y-m-d H:i:s');
            foreach (($GLOBALS['ESTRUTURA'] ?? []) as $i => $e)
                q('INSERT INTO site_ambientes (titulo, icone, resumo, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)', [$e['titulo'], $e['icone'], $e['texto'], ($i + 1) * 10, $agora]);
            config_gravar('ambientes_semeados', $agora);
        }
        return q_todos('SELECT * FROM site_ambientes' . ($so_ativos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, titulo');
    } catch (Throwable $e) { return []; }
}

function ambiente_fotos(array $a): array
{
    $v = json_decode((string)($a['fotos'] ?? ''), true);
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

function ambiente_foto_url(array $a, int $k, string $raiz = '', bool $mini = false): ?string
{
    $f = ambiente_fotos($a)[$k] ?? null;
    return $f ? $raiz . 'foto_site.php?am=' . (int)$a['id'] . '&n=' . $k . ($mini ? '&p=1' : '') . '&v=' . substr(md5($f), 0, 8) : null;
}

/** "O que se estuda aqui": uma linha por item */
function ambiente_itens(array $a): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($a['estuda'] ?? ''))), 'strlen'));
}
