<?php
/* =====================================================================
 *  Arquivos guardados na hospedagem (fotos, Central de Arquivos,
 *  Calendário SIGE), avatares e configurações gerais (bimestres).
 *  Tudo fica na pasta "dados/", bloqueada para acesso direto: os
 *  arquivos só são entregues pelo sistema, para quem está logado.
 * ===================================================================== */

const PASTA_DADOS = __DIR__ . '/../dados';

const EXTENSOES_ARQUIVOS = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'pps' => 'application/vnd.ms-powerpoint', 'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv',
    'odt' => 'application/vnd.oasis.opendocument.text', 'odp' => 'application/vnd.oasis.opendocument.presentation', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'txt' => 'text/plain',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'zip' => 'application/zip',
];

/** Pasta dentro de dados/ (cria e protege) */
function pasta_dados(string $sub = ''): string
{
    $raiz = PASTA_DADOS;
    if (!is_dir($raiz)) @mkdir($raiz, 0750, true);
    if (!is_file("$raiz/.htaccess")) @file_put_contents("$raiz/.htaccess", "Require all denied\nDeny from all\n");
    if (!is_file("$raiz/index.html")) @file_put_contents("$raiz/index.html", '');
    $p = $sub === '' ? $raiz : "$raiz/$sub";
    if (!is_dir($p)) @mkdir($p, 0750, true);
    return $p;
}

/** Maior arquivo aceito pelo servidor, em bytes */
function limite_upload(): int
{
    $conv = function (string $v): int {
        $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
    };
    $lim = array_filter([$conv((string)ini_get('upload_max_filesize')), $conv((string)ini_get('post_max_size'))]);
    return $lim ? min($lim) : 2097152;
}

function tamanho_fmt(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
    return $b . ' B';
}

function erro_upload(int $codigo): string
{
    return [UPLOAD_ERR_INI_SIZE => 'O arquivo passa do limite do servidor (' . tamanho_fmt(limite_upload()) . ').',
            UPLOAD_ERR_FORM_SIZE => 'O arquivo é grande demais.', UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Tente de novo.',
            UPLOAD_ERR_NO_FILE => 'Escolha um arquivo.'][$codigo] ?? 'Não foi possível receber o arquivo.';
}

/* ---------------------------------------------------------------------
 *  Fotos (alunos e servidores)
 * --------------------------------------------------------------------- */

/**
 * Recebe a foto enviada, corta quadrada, reduz para 480px e grava em JPEG.
 * Devolve o caminho relativo (ex.: fotos/alunos/12_ab12cd.jpg).
 */
function foto_salvar(array $f, string $tipo, int $id, ?string $antiga = null): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    if ($f['size'] > 12 * 1048576) throw new RuntimeException('A foto passa de 12 MB.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new RuntimeException('Envie uma imagem JPG, PNG ou WEBP.');
    $pasta = pasta_dados('fotos/' . $tipo);
    $nome = $id . '_' . bin2hex(random_bytes(5)) . '.jpg';

    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if (!$img) throw new RuntimeException('Não foi possível ler a imagem.');
        /* gira conforme o celular gravou (EXIF) */
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
            $giro = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($giro) { $r = imagerotate($img, $giro, 0); if ($r) { imagedestroy($img); $img = $r; } }
        }
        $w = imagesx($img); $h = imagesy($img); $lado = min($w, $h);
        if ($tipo === 'equipe') {
            /* fotos do site: já chegam enquadradas pela tela de recorte, então só diminui (sem cortar de novo) */
            $esc = min(1, 1000 / max($w, $h)); $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
            $dest = imagecreatetruecolor($nw, $nh);
            imagefill($dest, 0, 0, imagecolorallocate($dest, 255, 255, 255));
            imagecopyresampled($dest, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        } else {
            $dest = imagecreatetruecolor(480, 480);
            imagefill($dest, 0, 0, imagecolorallocate($dest, 255, 255, 255));
            imagecopyresampled($dest, $img, 0, 0, (int)(($w - $lado) / 2), (int)(($h - $lado) / 2.6), 480, 480, $lado, $lado);
        }
        imagejpeg($dest, "$pasta/$nome", 86);
        imagedestroy($img); imagedestroy($dest);
    } else {
        if ($info[2] !== IMAGETYPE_JPEG) throw new RuntimeException('Este servidor só aceita fotos em JPG.');
        move_uploaded_file($f['tmp_name'], "$pasta/$nome");
    }
    if ($antiga) foto_apagar($antiga);
    return "fotos/$tipo/$nome";
}

function foto_apagar(?string $rel): void
{
    if ($rel && preg_match('#^fotos/(alunos|usuarios|equipe)/[\w.-]+$#', $rel) && is_file(PASTA_DADOS . '/' . $rel)) @unlink(PASTA_DADOS . '/' . $rel);
}

function foto_url(string $tipo, int $id, ?string $foto): ?string
{
    if (!$foto) return null;
    return RAIZ . 'painel/foto.php?t=' . ($tipo === 'aluno' ? 'a' : 'u') . '&id=' . $id . '&v=' . substr(md5($foto), 0, 8);
}

/** Avatar: foto, se houver; senão as iniciais */
function avatar(array $p, string $tipo = 'aluno', string $classe = 'avatar-sm'): string
{
    $nome = (string)($p['nome'] ?? '');
    $url = foto_url($tipo, (int)($p['id'] ?? 0), $p['foto'] ?? null);
    if ($url) return '<img class="avatar ' . $classe . ' avatar-foto" src="' . e($url) . '" alt="" loading="lazy" data-ini="' . e(iniciais($nome)) . '"'
        . ' onerror="var s=document.createElement(\'span\');s.className=this.className.replace(\' avatar-foto\',\'\');s.textContent=this.dataset.ini;s.setAttribute(\'aria-hidden\',\'true\');this.replaceWith(s)">';
    return '<span class="avatar ' . $classe . '" aria-hidden="true">' . e(iniciais($nome)) . '</span>';
}

/* ---------------------------------------------------------------------
 *  Arquivos da Central e do Calendário
 * --------------------------------------------------------------------- */

function arquivo_extensao(string $nome): string { return strtolower(pathinfo($nome, PATHINFO_EXTENSION)); }

function arquivo_icone(string $ext): array
{
    return match (true) {
        $ext === 'pdf' => ['file-earmark-pdf', 'vermelho', 'PDF'],
        in_array($ext, ['ppt', 'pptx', 'pps', 'ppsx', 'odp'], true) => ['file-earmark-slides', 'laranja', 'Slides'],
        in_array($ext, ['doc', 'docx', 'odt', 'txt'], true) => ['file-earmark-word', 'azul', 'Documento'],
        in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => ['file-earmark-spreadsheet', 'verde', 'Planilha'],
        in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => ['file-earmark-image', 'roxo', 'Imagem'],
        $ext === 'mp4' => ['file-earmark-play', 'roxo', 'Vídeo'],
        $ext === 'mp3' => ['file-earmark-music', 'roxo', 'Áudio'],
        $ext === 'zip' => ['file-earmark-zip', 'cinza', 'Compactado'],
        default => ['file-earmark', 'cinza', 'Arquivo'],
    };
}

/** Grava o arquivo enviado em dados/arquivos e devolve [caminho, mime, tamanho, nome_original] */
function arquivo_receber(array $f, ?array $so_extensoes = null): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    $ext = arquivo_extensao((string)$f['name']);
    $permitidas = $so_extensoes ?? array_keys(EXTENSOES_ARQUIVOS);
    if (!in_array($ext, $permitidas, true)) throw new RuntimeException('Tipo de arquivo não aceito (.' . e($ext) . '). Aceitos: ' . implode(', ', $permitidas) . '.');
    if ($ext === 'pdf' && (string)file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new RuntimeException('O arquivo não parece ser um PDF válido.');
    $pasta = pasta_dados('arquivos/' . date('Y'));
    $nome = date('md') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$pasta/$nome")) throw new RuntimeException('Não foi possível salvar o arquivo no servidor.');
    $original = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string)$f['name']));
    return ['arquivos/' . date('Y') . '/' . $nome, EXTENSOES_ARQUIVOS[$ext] ?? 'application/octet-stream', (int)$f['size'], mb_substr($original, 0, 200)];
}

function arquivo_apagar_disco(?string $rel): void
{
    if ($rel && preg_match('#^arquivos/\d{4}/[\w.-]+$#', $rel) && is_file(PASTA_DADOS . '/' . $rel)) @unlink(PASTA_DADOS . '/' . $rel);
}

/** Entrega um arquivo de dados/ (para ver no navegador ou baixar) */
function arquivo_enviar(string $rel, string $mime, string $nome, bool $baixar): void
{
    $caminho = PASTA_DADOS . '/' . $rel;
    if (!is_file($caminho)) { http_response_code(404); exit('Arquivo não encontrado no servidor.'); }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($caminho));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    $seguro = str_replace(['"', "\r", "\n"], '', $nome);
    header('Content-Disposition: ' . ($baixar ? 'attachment' : 'inline') . '; filename="' . $seguro . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
    readfile($caminho);
    exit;
}

/* ---------------------------------------------------------------------
 *  Configurações e calendário letivo
 * --------------------------------------------------------------------- */

function config_ler(string $chave, ?string $padrao = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        try { $cache = array_column(q_todos('SELECT chave, valor FROM configuracoes'), 'valor', 'chave'); }
        catch (PDOException $e) { $cache = []; }
    }
    return $cache[$chave] ?? $padrao;
}

function config_gravar(string $chave, ?string $valor): void
{
    if (q_valor('SELECT chave FROM configuracoes WHERE chave = ?', [$chave])) q('UPDATE configuracoes SET valor = ? WHERE chave = ?', [$valor, $chave]);
    else q('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)', [$chave, $valor]);
}

/* Datas padrão (mês-dia) até a escola cadastrar as do calendário SIGE */
const BIMESTRES_PADRAO = [1 => ['02-02', '04-17'], 2 => ['04-22', '06-30'], 3 => ['08-03', '10-09'], 4 => ['10-13', '12-30']];   // calendário SIGE 2026

/** Início e fim de cada bimestre do ano: [n => [inicio, fim]] */
function bimestres(int $ano = ANO_VER): array
{
    $b = [];
    foreach (BIMESTRES_PADRAO as $n => [$i, $f]) {
        $ini = config_ler("bimestre_{$ano}_{$n}_inicio");
        $fim = config_ler("bimestre_{$ano}_{$n}_fim");
        $b[$n] = [data_valida($ini) ? $ini : "$ano-$i", data_valida($fim) ? $fim : "$ano-$f"];
    }
    return $b;
}

function bimestres_configurados(int $ano = ANO_VER): bool { return config_ler("bimestre_{$ano}_1_inicio") !== null; }

/**
 * Situação de hoje no calendário letivo:
 * ['bimestre' => n|null, 'rotulo' => '4º bimestre'|'Recesso'|..., 'inicio', 'fim', 'progresso' (0-100), 'dias_restantes', 'proximo' => n|null]
 */
function periodo_atual(?string $hoje = null): array
{
    $hoje ??= date('Y-m-d');
    $ano = (int)substr($hoje, 0, 4);
    $bs = bimestres($ano === ANO_VER ? ANO_VER : $ano);
    foreach ($bs as $n => [$i, $f]) {
        if ($hoje >= $i && $hoje <= $f) {
            $tot = max(1, (strtotime($f) - strtotime($i)) / 86400);
            $feito = (strtotime($hoje) - strtotime($i)) / 86400;
            return ['bimestre' => $n, 'rotulo' => $n . 'º período', 'inicio' => $i, 'fim' => $f, 'progresso' => (int)round($feito / $tot * 100),
                    'dias_restantes' => (int)round((strtotime($f) - strtotime($hoje)) / 86400), 'proximo' => $n < 4 ? $n + 1 : null];
        }
    }
    foreach ($bs as $n => [$i, $f]) {
        if ($hoje < $i) return ['bimestre' => null, 'rotulo' => $n === 1 ? 'Antes do início das aulas' : 'Recesso escolar', 'inicio' => $i, 'fim' => $f, 'progresso' => 0,
                                'dias_restantes' => (int)round((strtotime($i) - strtotime($hoje)) / 86400), 'proximo' => $n];
    }
    return ['bimestre' => null, 'rotulo' => 'Ano letivo encerrado', 'inicio' => $bs[4][0], 'fim' => $bs[4][1], 'progresso' => 100, 'dias_restantes' => 0, 'proximo' => null];
}

/* ---------------------------------------------------------------------
 *  Eventos do calendário (feriados, recessos, reuniões, sábados letivos)
 * --------------------------------------------------------------------- */
const TIPOS_EVENTO = [
    'periodo'       => ['Período letivo', 'flag', 'escura'],
    'feriado'       => ['Feriado', 'star', 'vermelho'],
    'recesso'       => ['Recesso / férias', 'cup-hot', 'ambar'],
    'reuniao'       => ['Reunião', 'people', 'azul'],
    'sabado_letivo' => ['Sábado letivo', 'calendar-plus', 'verde'],
    'evento'        => ['Evento', 'calendar-event', 'cinza'],
];

function eventos_entre(string $ini, string $fim): array
{
    try {
        return q_todos('SELECT * FROM eventos WHERE data_inicio <= ? AND COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio, id', [$fim, $ini]);
    } catch (PDOException $e) { return []; }
}

/** Próximos eventos a partir de hoje (inclui os que estão acontecendo) */
function proximos_eventos(int $limite = 5): array
{
    try {
        return q_todos('SELECT * FROM eventos WHERE COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio, id LIMIT ' . (int)$limite, [date('Y-m-d')]);
    } catch (PDOException $e) { return []; }
}
