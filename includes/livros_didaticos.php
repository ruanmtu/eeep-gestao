<?php
/* =====================================================================
 *  Livros didáticos digitais
 *  A gestão envia o PDF (ex.: manual do professor do PNLD) e os
 *  professores leem dentro do sistema ou baixam. Os PDFs costumam ser
 *  grandes, por isso o envio é feito em partes (não esbarra no limite de
 *  upload da hospedagem) e a leitura usa "faixas" (abre as primeiras
 *  páginas sem baixar o livro inteiro).
 * ===================================================================== */

const TIPOS_LIVRO = [
    'professor' => ['Manual do professor', 'person-workspace'],
    'aluno'     => ['Livro do estudante', 'book'],
    'apoio'     => ['Material de apoio', 'journal-text'],
];
const AREAS_LIVRO = ['Linguagens e suas Tecnologias', 'Matemática e suas Tecnologias', 'Ciências da Natureza e suas Tecnologias',
                     'Ciências Humanas e Sociais Aplicadas', 'Projeto de Vida', 'Educação Profissional'];
/** Tamanho de cada parte do envio: até 8 MB, respeitando o limite de upload da hospedagem */
function livro_parte_max(): int
{
    $bytes = function (string $v): int {
        $v = trim($v); $n = (float)$v; $u = strtolower(substr($v, -1));
        return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
    };
    $lim = min($bytes((string)ini_get('upload_max_filesize')) ?: PHP_INT_MAX, $bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX);
    return (int)max(256 * 1024, min(8 * 1048576, floor($lim * 0.85)));
}
const LIVRO_TAMANHO_MAX = 1024 * 1024 * 1024;     // 1 GB por livro

function livros_lista(string $where = '1 = 1', array $p = []): array
{
    try {
        return q_todos('SELECT l.*, d.nome AS disciplina, u.nome AS por FROM livros_didaticos l
                          LEFT JOIN disciplinas d ON d.id = l.disciplina_id LEFT JOIN usuarios u ON u.id = l.enviado_por
                         WHERE ' . $where . ' ORDER BY l.serie IS NULL, l.serie, COALESCE(d.nome, l.area, l.titulo), l.titulo', $p);
    } catch (PDOException $e) { return []; }
}

function livro_didatico_buscar(int $id): ?array
{
    $l = livros_lista('l.id = ?', [$id]);
    return $l[0] ?? null;
}

/** Disciplinas e séries que o professor leciona (para "Meus livros") */
function livros_do_professor(int $uid): array
{
    try {
        return q_todos('SELECT DISTINCT a.disciplina_id, t.serie FROM atribuicoes a JOIN turmas t ON t.id = a.turma_id WHERE a.usuario_id = ? AND t.ano_letivo = ?', [$uid, ANO_LETIVO]);
    } catch (PDOException $e) { return []; }
}

/** Cor da capa a partir da disciplina (sempre a mesma para a mesma disciplina) */
function livro_cor(array $l): int
{
    return abs(crc32((string)($l['disciplina'] ?? $l['area'] ?? $l['titulo']))) % 6;
}

/** Recebe uma parte do PDF. Devolve o token do envio e, na última parte, 'pronto' */
function livro_receber_parte(array $f, string $token, int $parte, int $total, string $nome, int $tamanho): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new RuntimeException('Envio inválido.');
    if ($tamanho <= 0 || $tamanho > LIVRO_TAMANHO_MAX) throw new RuntimeException('O livro pode ter até 1 GB.');
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    if ((int)$f['size'] > livro_parte_max() + 1024) throw new RuntimeException('Parte do envio maior que o permitido.');
    $envios = $_SESSION['envios_livro'] ?? [];
    if ($parte === 0) {
        if (arquivo_extensao($nome) !== 'pdf') throw new RuntimeException('Envie o livro em PDF.');
        if ((string)file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new RuntimeException('O arquivo não parece ser um PDF válido.');
        $envios[$token] = ['parte' => -1, 'bytes' => 0, 'nome' => mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($nome)), 0, 200), 'tamanho' => $tamanho, 'pronto' => false];
    }
    $e = $envios[$token] ?? null;
    if (!$e || $parte !== $e['parte'] + 1) throw new RuntimeException('As partes do envio chegaram fora de ordem. Tente de novo.');
    $tmp = pasta_dados('tmp') . '/livro_' . uid() . '_' . $token . '.part';
    $in = fopen($f['tmp_name'], 'rb'); $out = fopen($tmp, $parte === 0 ? 'wb' : 'ab');
    if (!$in || !$out) throw new RuntimeException('Não foi possível gravar no servidor.');
    stream_copy_to_stream($in, $out); fclose($in); fclose($out);
    $e['parte'] = $parte; $e['bytes'] = filesize($tmp);
    if ($e['bytes'] > LIVRO_TAMANHO_MAX) { @unlink($tmp); unset($envios[$token]); $_SESSION['envios_livro'] = $envios; throw new RuntimeException('O livro passou de 1 GB.'); }
    if ($parte === $total - 1) {
        if ($e['bytes'] !== $e['tamanho']) { @unlink($tmp); unset($envios[$token]); $_SESSION['envios_livro'] = $envios; throw new RuntimeException('O arquivo chegou incompleto. Tente de novo.'); }
        $e['pronto'] = true;
    }
    $envios[$token] = $e;
    $_SESSION['envios_livro'] = $envios;
    return ['ok' => true, 'pronto' => $e['pronto'], 'bytes' => $e['bytes']];
}

/** Move o PDF montado para a pasta dos livros. Devolve [caminho, tamanho, nome original] */
function livro_finalizar_envio(string $token): array
{
    $e = $_SESSION['envios_livro'][$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$e || !$e['pronto']) throw new RuntimeException('Envie o arquivo PDF do livro e espere chegar a 100%.');
    $tmp = PASTA_DADOS . '/tmp/livro_' . uid() . '_' . $token . '.part';
    if (!is_file($tmp)) throw new RuntimeException('O arquivo enviado não foi encontrado. Envie de novo.');
    $pasta = pasta_dados('livros/' . date('Y'));
    $nome = date('md') . '_' . bin2hex(random_bytes(8)) . '.pdf';
    if (!rename($tmp, "$pasta/$nome")) throw new RuntimeException('Não foi possível guardar o livro no servidor.');
    unset($_SESSION['envios_livro'][$token]);
    return ['livros/' . date('Y') . '/' . $nome, (int)filesize("$pasta/$nome"), $e['nome']];
}

/** Apaga partes de envios abandonados (mais de 1 dia) */
function livros_limpar_tmp(): void
{
    foreach (glob(PASTA_DADOS . '/tmp/livro_*.part') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
}

/** Entrega um arquivo com suporte a "Range" (o leitor abre o livro aos poucos) */
function arquivo_enviar_faixa(string $caminho, string $mime, string $nome, bool $baixar): void
{
    if (!is_file($caminho)) { http_response_code(404); exit('Arquivo não encontrado no servidor.'); }
    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    $tam = filesize($caminho); $ini = 0; $fim = $tam - 1;
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    $seguro = str_replace(['"', "\r", "\n"], '', $nome);
    header('Content-Disposition: ' . ($baixar ? 'attachment' : 'inline') . '; filename="' . $seguro . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
    if (!$baixar && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $ini = max(0, $tam - (int)$m[2]); }
        else { $ini = (int)$m[1]; if ($m[2] !== '') $fim = min((int)$m[2], $tam - 1); }
        if ($ini > $fim || $ini >= $tam) { http_response_code(416); header("Content-Range: bytes */$tam"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $ini-$fim/$tam");
    }
    header('Content-Length: ' . ($fim - $ini + 1));
    $h = fopen($caminho, 'rb'); fseek($h, $ini);
    $resta = $fim - $ini + 1;
    while ($resta > 0 && !feof($h) && !connection_aborted()) { $b = fread($h, (int)min(1048576, $resta)); echo $b; $resta -= strlen($b); flush(); }
    fclose($h);
    exit;
}
