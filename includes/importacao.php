<?php
/* =====================================================================
 *  Importação em massa: lê a planilha (CSV ou Excel .xlsx) que alimenta
 *  o Power BI, reconhece as colunas e grava alunos, faltas e notas.
 * ===================================================================== */

const IMPORT_GRUPOS = ['Aluno', 'Frequência', 'Notas'];

/* chave => [rótulo, grupo, nomes de coluna que costumam aparecer] */
const IMPORT_CAMPOS = [
    'nome'                 => ['Nome do aluno', 'Aluno', ['nome', 'aluno', 'nome do aluno', 'nome aluno', 'estudante', 'nome do estudante', 'discente', 'nome completo', 'alunos']],
    'matricula'            => ['Matrícula / código do aluno', 'Aluno', ['matricula', 'mat', 'n matricula', 'numero matricula', 'codigo', 'cod', 'cod aluno', 'codigo aluno', 'id aluno', 'ra', 'inep', 'id inep', 'codigo inep']],
    'turma'                => ['Turma (ex.: 2º A, 2A Informática)', 'Aluno', ['turma', 'classe', 'sala', 'serie turma', 'turma serie', 'ano turma', 'enturmacao']],
    'serie'                => ['Série / ano', 'Aluno', ['serie', 'ano', 'ano serie', 'serie ano', 'ano escolar']],
    'letra'                => ['Letra da turma', 'Aluno', ['letra', 'turma letra']],
    'curso'                => ['Curso', 'Aluno', ['curso', 'curso tecnico', 'eixo', 'habilitacao']],
    'data_nascimento'      => ['Data de nascimento', 'Aluno', ['nascimento', 'data nascimento', 'data de nascimento', 'dt nascimento', 'dn', 'data nasc']],
    'sexo'                 => ['Sexo', 'Aluno', ['sexo', 'genero']],
    'cpf'                  => ['CPF', 'Aluno', ['cpf', 'cpf aluno']],
    'nis'                  => ['NIS', 'Aluno', ['nis']],
    'telefone'             => ['Telefone do aluno', 'Aluno', ['telefone', 'celular', 'fone', 'contato', 'whatsapp', 'telefone aluno']],
    'email'                => ['E-mail', 'Aluno', ['email', 'e mail']],
    'responsavel_nome'     => ['Nome do responsável', 'Aluno', ['responsavel', 'nome responsavel', 'nome do responsavel', 'responsavel legal']],
    'responsavel_telefone' => ['Telefone do responsável', 'Aluno', ['telefone responsavel', 'telefone do responsavel', 'fone responsavel', 'contato responsavel', 'celular responsavel']],
    'nome_mae'             => ['Nome da mãe', 'Aluno', ['mae', 'nome mae', 'nome da mae', 'filiacao 1']],
    'nome_pai'             => ['Nome do pai', 'Aluno', ['pai', 'nome pai', 'nome do pai', 'filiacao 2']],
    'endereco'             => ['Endereço', 'Aluno', ['endereco', 'rua', 'logradouro']],
    'bairro'               => ['Bairro', 'Aluno', ['bairro', 'comunidade', 'localidade']],
    'cidade'               => ['Cidade', 'Aluno', ['cidade', 'municipio']],
    'situacao'             => ['Situação (ativo, transferido…)', 'Aluno', ['situacao', 'status', 'situacao aluno', 'situacao matricula']],
    'faltas'               => ['Total de faltas', 'Frequência', ['faltas', 'total faltas', 'total de faltas', 'qtd faltas', 'quantidade faltas', 'quantidade de faltas', 'numero de faltas', 'n faltas', 'ausencias', 'infrequencia', 'faltas total', 'qtd de faltas']],
    'data_falta'           => ['Data da falta (uma linha por falta)', 'Frequência', ['data', 'data falta', 'data da falta', 'dia', 'dia da falta', 'data ausencia']],
    'justificada'          => ['Falta justificada? (sim/não)', 'Frequência', ['justificada', 'justificativa', 'abonada', 'falta justificada']],
    'disciplina'           => ['Disciplina (uma linha por nota)', 'Notas', ['disciplina', 'componente', 'componente curricular', 'materia', 'disciplinas']],
    'bimestre'             => ['Bimestre', 'Notas', ['bimestre', 'bim', 'periodo', 'etapa']],
    'nota'                 => ['Nota (uma linha por nota)', 'Notas', ['nota', 'media', 'nota final', 'media final', 'resultado', 'media bimestral', 'nota bimestral', 'notas', 'media do bimestre']],
];

/* Apelidos de disciplinas que aparecem em planilhas */
const IMPORT_APELIDOS_DISC = [
    'portugues' => 'lingua portuguesa', 'port' => 'lingua portuguesa', 'lp' => 'lingua portuguesa', 'l portuguesa' => 'lingua portuguesa', 'lingua portuguesa e literatura' => 'lingua portuguesa',
    'mat' => 'matematica', 'fis' => 'fisica', 'qui' => 'quimica', 'quim' => 'quimica', 'bio' => 'biologia', 'hist' => 'historia', 'geo' => 'geografia',
    'filo' => 'filosofia', 'soc' => 'sociologia', 'socio' => 'sociologia', 'ingles' => 'lingua inglesa', 'ing' => 'lingua inglesa', 'l inglesa' => 'lingua inglesa',
    'espanhol' => 'lingua espanhola', 'esp' => 'lingua espanhola', 'l espanhola' => 'lingua espanhola', 'ed fisica' => 'educacao fisica', 'ef' => 'educacao fisica',
    'educ fisica' => 'educacao fisica', 'artes' => 'arte', 'art' => 'arte',
];

/** Texto em minúsculas, sem acentos e só com letras/números separados por espaço */
function norm(?string $s): string
{
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
                    'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'º' => '', 'ª' => '', '°' => '']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/* ---------------------------------------------------------------------
 *  Leitura do arquivo
 * --------------------------------------------------------------------- */

/** Devolve ['Aba' => [[célula, ...], ...]] */
function planilha_ler(string $caminho, string $ext): array
{
    return $ext === 'xlsx' ? planilha_ler_xlsx($caminho) : ['Planilha' => planilha_ler_csv($caminho)];
}

function planilha_ler_csv(string $caminho): array
{
    $c = (string)file_get_contents($caminho);
    if (str_starts_with($c, "\xEF\xBB\xBF")) $c = substr($c, 3);
    elseif (str_starts_with($c, "\xFF\xFE") || str_starts_with($c, "\xFE\xFF")) $c = mb_convert_encoding($c, 'UTF-8', 'UTF-16');
    if (!mb_check_encoding($c, 'UTF-8')) $c = mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
    $c = str_replace(["\r\n", "\r"], "\n", $c);
    if (preg_match('/^sep=(.)\n/i', $c, $m)) { $sep = $m[1]; $c = substr($c, strlen($m[0])); }
    else {
        $amostra = implode("\n", array_slice(array_filter(explode("\n", $c), 'strlen'), 0, 8));
        $amostra = preg_replace('/"[^"]*"/', '', $amostra);
        $cont = [];
        foreach ([';', ',', "\t", '|'] as $d) $cont[$d] = substr_count($amostra, $d);
        arsort($cont);
        $sep = key($cont);
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $c); rewind($h);
    $linhas = [];
    while (($l = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        if ($l === [null]) { $linhas[] = []; continue; }
        $linhas[] = array_map(fn($v) => trim((string)$v), $l);
    }
    fclose($h);
    return $linhas;
}

function planilha_ler_xlsx(string $caminho): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('O servidor não tem a extensão ZIP do PHP para ler arquivos .xlsx. Salve a planilha como CSV e envie de novo.');
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) throw new RuntimeException('Não foi possível abrir o arquivo Excel. Ele pode estar corrompido ou protegido por senha.');
    $xml = function (string $nome) use ($z) {
        $s = $z->getFromName($nome);
        return $s === false ? null : simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    };
    $comp = [];
    if ($ss = $xml('xl/sharedStrings.xml')) {
        foreach ($ss->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            foreach ($si->r as $r) $t .= (string)$r->t;
            $comp[] = $t;
        }
    }
    $alvos = [];
    if ($rels = $xml('xl/_rels/workbook.xml.rels')) foreach ($rels->Relationship as $r) $alvos[(string)$r['Id']] = (string)$r['Target'];
    $abas = [];
    $wb = $xml('xl/workbook.xml');
    if (!$wb) throw new RuntimeException('Arquivo Excel inválido.');
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $alvo = $alvos[$rid] ?? '';
        $alvo = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
        $folha = $xml($alvo);
        if (!$folha) continue;
        $linhas = [];
        foreach ($folha->sheetData->row as $row) {
            $ri = (int)$row['r'] ?: count($linhas) + 1;
            $cel = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = 0;
                if (preg_match('/^([A-Z]+)/', $ref, $m)) foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col = $col ? $col - 1 : count($cel);
                $t = (string)$c['t'];
                if ($t === 's') $v = $comp[(int)$c->v] ?? '';
                elseif ($t === 'inlineStr') { $v = (string)$c->is->t; foreach ($c->is->r as $r) $v .= (string)$r->t; }
                elseif ($t === 'b') $v = (string)$c->v === '1' ? 'sim' : 'não';
                else $v = (string)$c->v;
                if ($t === '' && is_numeric($v) && str_contains($v, '.')) $v = (string)round((float)$v, 4);
                $cel[$col] = trim($v);
            }
            if ($cel) { $max = max(array_keys($cel)); $cel = array_replace(array_fill(0, $max + 1, ''), $cel); }
            while (count($linhas) < $ri - 1) $linhas[] = [];
            $linhas[] = $cel;
        }
        $abas[(string)$sh['name'] ?: 'Planilha ' . (count($abas) + 1)] = $linhas;
    }
    $z->close();
    if (!$abas) throw new RuntimeException('Não encontrei nenhuma aba com dados no arquivo Excel.');
    return $abas;
}

/** Índice da linha de cabeçalho: a linha (entre as 15 primeiras) com mais textos */
function planilha_cabecalho(array $linhas): int
{
    $melhor = 0; $pontos = -1;
    foreach (array_slice($linhas, 0, 15, true) as $i => $l) {
        $p = count(array_filter($l, fn($v) => $v !== '' && !is_numeric(str_replace(',', '.', $v))));
        if ($p > $pontos) { $pontos = $p; $melhor = $i; }
    }
    return $melhor;
}

/* ---------------------------------------------------------------------
 *  Reconhecimento das colunas
 * --------------------------------------------------------------------- */

function disciplinas_cache(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (q_todos('SELECT id, nome, curso_id FROM disciplinas WHERE ativo = 1') as $r) $d[] = $r + ['n' => norm($r['nome'])];
    }
    return $d;
}

/** Nomes normalizados únicos das disciplinas => nome bonito */
function disciplinas_nomes(): array
{
    $n = [];
    foreach (disciplinas_cache() as $d) $n[$d['n']] = $d['nome'];
    asort($n);
    return $n;
}

/** Acha o nome normalizado da disciplina a partir de um texto da planilha */
function disciplina_nome_reconhecer(string $texto): ?string
{
    $t = norm($texto);
    if ($t === '') return null;
    $t = IMPORT_APELIDOS_DISC[$t] ?? $t;
    $nomes = disciplinas_nomes();
    if (isset($nomes[$t])) return $t;
    if (strlen($t) >= 4) {
        foreach ($nomes as $n => $_) if (str_starts_with($n, $t) || str_starts_with($t, $n)) return $n;
        foreach ($nomes as $n => $_) { similar_text($t, $n, $pct); if ($pct >= 85) return $n; }
    }
    return null;
}

/** Id da disciplina com esse nome, preferindo a do curso da turma */
function disciplina_id_para(string $nome_norm, ?int $curso_id): ?int
{
    $achou = null;
    foreach (disciplinas_cache() as $d) {
        if ($d['n'] !== $nome_norm) continue;
        if ($d['curso_id'] === null || (int)$d['curso_id'] === (int)$curso_id) return (int)$d['id'];
        $achou ??= (int)$d['id'];
    }
    return $achou;
}

/** Bimestre escrito no texto (1º bim, B2, 3 bimestre, IV…) ou null */
function bimestre_de(string $texto): ?int
{
    $t = norm($texto);
    if (preg_match('/^(?:b|bim|bimestre)?\s*([1-4])\s*(?:b|bim|bimestre)?$/', $t, $m)) return (int)$m[1];
    if (preg_match('/\b([1-4])\s*(?:o\s*)?(?:bim|bimestre|b)\b/', $t, $m)) return (int)$m[1];
    if (preg_match('/\b(?:bim|bimestre|b)\s*([1-4])\b/', $t, $m)) return (int)$m[1];
    if (preg_match('/^(i|ii|iii|iv)(?:\s*(?:bim|bimestre))?$/', $t, $m)) return ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4][$m[1]];
    return null;
}

/** Sugere o campo de cada coluna pelo nome do cabeçalho */
function importacao_sugerir(array $cabecalho): array
{
    $mapa = []; $usados = [];
    foreach ($cabecalho as $i => $h) {
        $n = norm($h);
        $mapa[$i] = 'ignorar';
        if ($n === '') continue;
        foreach (IMPORT_CAMPOS as $k => [, , $sin]) {
            if (in_array($n, $sin, true) && !isset($usados[$k])) { $mapa[$i] = $k; $usados[$k] = 1; continue 2; }
        }
        /* coluna com nome de disciplina => nota daquela disciplina (planilha "larga") */
        $bim = bimestre_de($h) ?? 0;
        $sem_bim = trim(preg_replace('/\b([1-4]\s*(o\s*)?(bim|bimestre|b)|(bim|bimestre|b)\s*[1-4]|nota|media|n)\b/', ' ', $n));
        if ($sem_bim !== '' && ($d = disciplina_nome_reconhecer($sem_bim))) { $mapa[$i] = 'nota:' . $d . ':' . $bim; continue; }
        foreach (IMPORT_CAMPOS as $k => [, , $sin]) {
            if (isset($usados[$k])) continue;
            foreach ($sin as $s) if (strlen($s) >= 4 && (str_contains($n, $s))) { $mapa[$i] = $k; $usados[$k] = 1; continue 3; }
        }
    }
    return $mapa;
}

/** Rótulo de um campo (inclusive "nota:disciplina:bimestre") */
function importacao_rotulo(string $k): string
{
    if (str_starts_with($k, 'nota:')) {
        [, $d, $b] = explode(':', $k) + [2 => 0];
        return 'Nota de ' . (disciplinas_nomes()[$d] ?? $d) . ((int)$b ? ' · ' . $b . 'º bim.' : '');
    }
    return IMPORT_CAMPOS[$k][0] ?? 'Não importar';
}

/* ---------------------------------------------------------------------
 *  Conversões de valores
 * --------------------------------------------------------------------- */

function imp_data(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v) && $v > 20000 && $v < 80000) return date('Y-m-d', (int)round(((float)$v - 25569) * 86400)); // data do Excel
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $v, $m)) {
        $a = (int)$m[3]; if ($a < 100) $a += $a > 50 ? 1900 : 2000;
        return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null;
    }
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    return null;
}

function imp_numero(?string $v): ?float
{
    $v = trim(str_replace(' ', '', (string)$v));
    if ($v === '' || $v === '-' || $v === '—') return null;
    if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $v)) $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

function imp_sim(?string $v): bool { return in_array(norm($v), ['sim', 's', '1', 'x', 'yes', 'true', 'verdadeiro', 'justificada', 'abonada'], true); }

/* ---------------------------------------------------------------------
 *  Turma e aluno
 * --------------------------------------------------------------------- */

function turma_reconhecer(string $turma, string $serie, string $letra, string $curso, int $ano): ?int
{
    static $turmas = [], $cursos = null;
    if (!isset($turmas[$ano])) $turmas[$ano] = q_todos('SELECT t.id, t.serie, t.letra, t.curso_id FROM turmas t WHERE t.ano_letivo = ?', [$ano]);
    $cursos ??= q_todos('SELECT id, nome, sigla FROM cursos');
    $tudo = norm("$turma $serie $letra $curso");
    if ($tudo === '') return null;
    $s = null; $l = null; $c = null;
    if (preg_match('/\b([1-3])\b/', norm($serie), $m)) $s = (int)$m[1];
    if (preg_match('/^([a-h])$/', norm($letra), $m)) $l = strtoupper($m[1]);
    $nt = norm($turma);
    if (preg_match('/\b([1-3])\s*(?:ano|serie|s)?\s*([a-h])\b/', $nt, $m) || preg_match('/\b([1-3])([a-h])\b/', $nt, $m)) { $s ??= (int)$m[1]; $l ??= strtoupper($m[2]); }
    if ($s === null && preg_match('/\b([1-3])\b/', $nt, $m)) $s = (int)$m[1];
    foreach ($cursos as $cu) {
        $nc = norm($cu['nome']); $sg = norm($cu['sigla'] ?? '');
        $chave = substr($nc, 0, 6);
        if (str_contains($tudo, $chave) || ($sg !== '' && preg_match('/\b' . preg_quote($sg, '/') . '\b/', $tudo))) { $c = (int)$cu['id']; break; }
    }
    if ($c === null && preg_match('/\b(desenv|sistemas)\b/', $tudo)) foreach ($cursos as $cu) if (str_contains(norm($cu['nome']), 'desenvolvimento')) $c = (int)$cu['id'];
    if ($s === null) return null;
    $cand = array_filter($turmas[$ano], fn($t) => (int)$t['serie'] === $s && ($l === null || $t['letra'] === $l) && ($c === null || (int)$t['curso_id'] === $c));
    if (count($cand) !== 1 && $l !== null) $cand = array_filter($turmas[$ano], fn($t) => (int)$t['serie'] === $s && $t['letra'] === $l);
    return count($cand) === 1 ? (int)reset($cand)['id'] : null;
}

/* ---------------------------------------------------------------------
 *  Processamento (simula ou grava)
 * --------------------------------------------------------------------- */

/**
 * $mapa: índice da coluna => campo
 * $op: ano, bimestre, tipo_nota (media|global|parcial|conceitual), criar (bool),
 *      atualizar (vazios|substituir|nao), turma_padrao (id ou 0)
 */
function importacao_processar(array $linhas, array $mapa, array $op, bool $gravar): array
{
    @set_time_limit(600);
    $ano = (int)$op['ano']; $bim_padrao = (int)$op['bimestre']; $tipo = $op['tipo_nota'];
    $r = ['linhas' => 0, 'ignoradas' => 0, 'alunos' => [], 'novos' => 0, 'atualizados' => [], 'notas' => 0, 'faltas_dias' => 0, 'faltas_total' => 0,
          'problemas' => [], 'disc_desconhecidas' => [], 'avaliacoes_criadas' => 0, 'turmas' => []];
    $prob = function (int $n, string $msg) use (&$r) { if (count($r['problemas']) < 300) $r['problemas'][] = ['linha' => $n, 'msg' => $msg]; };

    $col = []; foreach ($mapa as $i => $k) if ($k !== 'ignorar' && !str_starts_with($k, 'nota:')) $col[$k] = (int)$i;
    $col_notas = []; foreach ($mapa as $i => $k) if (str_starts_with($k, 'nota:')) { [, $d, $b] = explode(':', $k) + [2 => 0]; $col_notas[(int)$i] = [$d, (int)$b]; }
    $campos_aluno = ['data_nascimento', 'sexo', 'cpf', 'nis', 'telefone', 'email', 'responsavel_nome', 'responsavel_telefone', 'nome_mae', 'nome_pai', 'endereco', 'bairro', 'cidade', 'situacao'];

    /* alunos já cadastrados */
    $por_mat = []; $por_nome = [];
    foreach (q_todos('SELECT a.id, a.nome, a.matricula, a.turma_id, t.curso_id FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id') as $a) {
        if ($a['matricula'] !== null && $a['matricula'] !== '') $por_mat[norm($a['matricula'])] = $a;
        $por_nome[norm($a['nome'])][] = $a;
    }
    $curso_turma = []; foreach (q_todos('SELECT id, curso_id FROM turmas') as $t) $curso_turma[(int)$t['id']] = (int)$t['curso_id'];
    $avaliacoes = [];

    $v = fn(array $l, string $k) => isset($col[$k]) ? trim((string)($l[$col[$k]] ?? '')) : '';

    $gravar_nota = function (array $al, string $disc_norm, int $bim, float $nota, int $n) use (&$r, &$avaliacoes, $ano, $tipo, $curso_turma, $prob) {
        $did = disciplina_id_para($disc_norm, $curso_turma[(int)$al['turma_id']] ?? null);
        if (!$did) { $r['disc_desconhecidas'][$disc_norm] = ($r['disc_desconhecidas'][$disc_norm] ?? 0) + 1; return; }
        if ($nota < 0 || $nota > 10) { $prob($n, 'Nota ' . num($nota) . ' fora de 0 a 10 (' . (disciplinas_nomes()[$disc_norm] ?? $disc_norm) . ').'); return; }
        $nota = round($nota, 2);
        if ($tipo === 'media') {
            $ex = q_valor('SELECT id FROM notas WHERE aluno_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ?', [$al['id'], $did, $ano, $bim]);
            if ($ex) q('UPDATE notas SET nota = ?, lancado_por = ?, atualizado_em = ? WHERE id = ?', [$nota, uid(), date('Y-m-d H:i:s'), $ex]);
            else q('INSERT INTO notas (aluno_id, disciplina_id, ano_letivo, bimestre, nota, lancado_por) VALUES (?, ?, ?, ?, ?, ?)', [$al['id'], $did, $ano, $bim, $nota, uid()]);
        } else {
            if (!$al['turma_id']) { $prob($n, 'Aluno sem turma: a nota precisa de uma turma para virar avaliação.'); return; }
            $chave = "{$al['turma_id']}:$did:$bim";
            if (!isset($avaliacoes[$chave])) {
                $titulo = TIPOS_AVALIACAO[$tipo] . ' (planilha)';
                $id = q_valor('SELECT id FROM avaliacoes WHERE turma_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ? AND tipo = ? AND titulo = ?', [$al['turma_id'], $did, $ano, $bim, $tipo, $titulo]);
                if (!$id) {
                    q('INSERT INTO avaliacoes (turma_id, disciplina_id, ano_letivo, bimestre, tipo, titulo, lote, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$al['turma_id'], $did, $ano, $bim, $tipo, $titulo, 'planilha', uid()]);
                    $id = db()->lastInsertId(); $r['avaliacoes_criadas']++;
                }
                $avaliacoes[$chave] = (int)$id;
            }
            $ex = q_valor('SELECT id FROM avaliacao_notas WHERE avaliacao_id = ? AND aluno_id = ?', [$avaliacoes[$chave], $al['id']]);
            if ($ex) q('UPDATE avaliacao_notas SET nota = ?, lancado_por = ?, atualizado_em = ? WHERE id = ?', [$nota, uid(), date('Y-m-d H:i:s'), $ex]);
            else q('INSERT INTO avaliacao_notas (avaliacao_id, aluno_id, nota, lancado_por) VALUES (?, ?, ?, ?)', [$avaliacoes[$chave], $al['id'], $nota, uid()]);
            recalcular_media((int)$al['id'], $did, $ano, $bim);
        }
        $r['notas']++;
    };

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($linhas as $idx => $l) {
            $n = $idx + 1; // número da linha na planilha
            if (!array_filter($l, fn($x) => trim((string)$x) !== '')) continue;
            $r['linhas']++;
            $nome = preg_replace('/\s+/u', ' ', $v($l, 'nome'));
            $mat = $v($l, 'matricula');
            if ($nome === '' && $mat === '') { $r['ignoradas']++; $prob($n, 'Linha sem nome e sem matrícula: ignorada.'); continue; }
            if (in_array(norm($nome), ['total', 'total geral', 'media', 'media geral', 'soma'], true)) { $r['ignoradas']++; continue; }

            $turma_id = turma_reconhecer($v($l, 'turma'), $v($l, 'serie'), $v($l, 'letra'), $v($l, 'curso'), $ano) ?: ((int)$op['turma_padrao'] ?: null);
            if (!$turma_id && ($v($l, 'turma') . $v($l, 'serie')) !== '') $prob($n, 'Turma "' . trim($v($l, 'turma') . ' ' . $v($l, 'serie') . ' ' . $v($l, 'letra') . ' ' . $v($l, 'curso')) . '" não reconhecida.');

            /* acha o aluno */
            $al = null;
            if ($mat !== '' && isset($por_mat[norm($mat)])) $al = $por_mat[norm($mat)];
            if (!$al && $nome !== '' && !empty($por_nome[norm($nome)])) {
                $lista = $por_nome[norm($nome)];
                if (count($lista) > 1 && $turma_id) $lista = array_values(array_filter($lista, fn($a) => (int)$a['turma_id'] === $turma_id)) ?: $lista;
                if (count($lista) === 1) $al = $lista[0];
                else { $prob($n, 'Há ' . count($lista) . ' alunos chamados "' . $nome . '". Informe a turma ou a matrícula.'); continue; }
            }

            /* dados pessoais */
            $dados = [];
            foreach ($campos_aluno as $k) {
                if (!isset($col[$k])) continue;
                $x = $v($l, $k);
                if ($x === '') continue;
                if ($k === 'data_nascimento') $x = imp_data($x);
                elseif ($k === 'sexo') $x = ['f' => 'F', 'feminino' => 'F', 'm' => 'M', 'masculino' => 'M'][norm($x)] ?? null;
                elseif ($k === 'situacao') {
                    $s = norm($x);
                    $x = str_starts_with($s, 'transf') ? 'transferido' : (str_starts_with($s, 'desist') || str_starts_with($s, 'abandon') || str_starts_with($s, 'evad') ? 'desistente' : (str_starts_with($s, 'conclu') ? 'concluido' : (in_array($s, ['ativo', 'matriculado', 'cursando', 'regular', 'frequentando'], true) ? 'ativo' : null)));
                }
                elseif ($k === 'email') $x = filter_var($x, FILTER_VALIDATE_EMAIL) ?: null;
                else $x = mb_substr($x, 0, $k === 'cpf' ? 14 : ($k === 'nis' ? 20 : 150));
                if ($x !== null && $x !== '') $dados[$k] = $x;
            }

            if (!$al) {
                if (!$op['criar']) { $prob($n, '"' . ($nome ?: $mat) . '" não está cadastrado(a). Marque "cadastrar alunos novos" para incluir.'); continue; }
                if ($nome === '') { $prob($n, 'Matrícula ' . $mat . ' não encontrada e sem nome para cadastrar.'); continue; }
                if (!$turma_id) { $prob($n, '"' . $nome . '" não está cadastrado(a) e a turma não foi reconhecida, então não deu para cadastrar.'); continue; }
                $mat_ok = $mat !== '' && !isset($por_mat[norm($mat)]) ? mb_substr($mat, 0, 30) : null;
                $cols = array_merge(['nome', 'matricula', 'turma_id', 'situacao', 'data_matricula'], array_keys(array_diff_key($dados, ['situacao' => 1])));
                $vals = array_merge([mb_substr($nome, 0, 150), $mat_ok, $turma_id, $dados['situacao'] ?? 'ativo', date('Y-m-d')], array_values(array_diff_key($dados, ['situacao' => 1])));
                q('INSERT INTO alunos (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
                $al = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome, 'matricula' => $mat_ok, 'turma_id' => $turma_id, 'curso_id' => $curso_turma[$turma_id] ?? null];
                $por_nome[norm($nome)][] = $al;
                if ($mat_ok) $por_mat[norm($mat_ok)] = $al;
                $r['novos']++;
            } elseif ($op['atualizar'] !== 'nao') {
                $atual = q_um('SELECT * FROM alunos WHERE id = ?', [$al['id']]);
                $mud = [];
                foreach ($dados as $k => $x) if ($op['atualizar'] === 'substituir' ? (string)$atual[$k] !== (string)$x : ($atual[$k] === null || $atual[$k] === '')) $mud[$k] = $x;
                if ($turma_id && (int)$atual['turma_id'] !== $turma_id && ($op['atualizar'] === 'substituir' || !$atual['turma_id'])) $mud['turma_id'] = $turma_id;
                if ($mat !== '' && ($atual['matricula'] === null || $atual['matricula'] === '') && !isset($por_mat[norm($mat)])) $mud['matricula'] = mb_substr($mat, 0, 30);
                if ($mud) {
                    q('UPDATE alunos SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($mud))) . ' WHERE id = ?', [...array_values($mud), $al['id']]);
                    if (isset($mud['turma_id'])) $al['turma_id'] = $mud['turma_id'];
                    $r['atualizados'][$al['id']] = 1;
                }
            }
            $r['alunos'][$al['id']] = 1;
            if ($al['turma_id']) $r['turmas'][(int)$al['turma_id']] = 1;

            $bim = isset($col['bimestre']) ? (bimestre_de($v($l, 'bimestre')) ?? $bim_padrao) : $bim_padrao;

            /* faltas por dia */
            if (isset($col['data_falta']) && $v($l, 'data_falta') !== '') {
                $d = imp_data($v($l, 'data_falta'));
                if (!$d) $prob($n, 'Data de falta "' . $v($l, 'data_falta') . '" não reconhecida.');
                else {
                    $just = isset($col['justificada']) && imp_sim($v($l, 'justificada')) ? 1 : 0;
                    if (!q_valor('SELECT id FROM faltas WHERE aluno_id = ? AND data_falta = ?', [$al['id'], $d])) {
                        q('INSERT INTO faltas (aluno_id, data_falta, justificada, registrado_por) VALUES (?, ?, ?, ?)', [$al['id'], $d, $just, uid()]);
                        $r['faltas_dias']++;
                    }
                }
            }
            /* total de faltas (por bimestre, e por disciplina quando a linha tiver disciplina) */
            if (isset($col['faltas']) && $v($l, 'faltas') !== '') {
                $qt = imp_numero($v($l, 'faltas'));
                if ($qt === null || $qt < 0) $prob($n, 'Total de faltas "' . $v($l, 'faltas') . '" não é um número.');
                else {
                    $did = 0;
                    if (isset($col['disciplina']) && $v($l, 'disciplina') !== '' && ($dn = disciplina_nome_reconhecer($v($l, 'disciplina')))) $did = disciplina_id_para($dn, $curso_turma[(int)$al['turma_id']] ?? null) ?? 0;
                    $ex = q_valor('SELECT id FROM faltas_bimestre WHERE aluno_id = ? AND ano_letivo = ? AND bimestre = ? AND disciplina_id = ?', [$al['id'], $ano, $bim, $did]);
                    if ($ex) q('UPDATE faltas_bimestre SET faltas = ?, atualizado_em = ? WHERE id = ?', [(int)round($qt), date('Y-m-d H:i:s'), $ex]);
                    else q('INSERT INTO faltas_bimestre (aluno_id, ano_letivo, bimestre, disciplina_id, faltas) VALUES (?, ?, ?, ?, ?)', [$al['id'], $ano, $bim, $did, (int)round($qt)]);
                    $r['faltas_total'] += (int)round($qt);
                }
            }
            /* nota em formato "uma linha por nota" */
            if (isset($col['nota']) && $v($l, 'nota') !== '') {
                $nt = imp_numero($v($l, 'nota'));
                $dn = isset($col['disciplina']) ? disciplina_nome_reconhecer($v($l, 'disciplina')) : null;
                if ($nt === null) $prob($n, 'Nota "' . $v($l, 'nota') . '" não é um número.');
                elseif (!$dn) $r['disc_desconhecidas'][$v($l, 'disciplina') ?: '(sem disciplina)'] = ($r['disc_desconhecidas'][$v($l, 'disciplina') ?: '(sem disciplina)'] ?? 0) + 1;
                else $gravar_nota($al, $dn, $bim, $nt, $n);
            }
            /* notas em formato "uma coluna por disciplina" */
            foreach ($col_notas as $ci => [$dn, $b]) {
                $nt = imp_numero($l[$ci] ?? '');
                if ($nt === null) continue;
                $gravar_nota($al, $dn, $b ?: $bim, $nt, $n);
            }
        }
        $gravar ? $pdo->commit() : $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $r['alunos'] = count($r['alunos']);
    $r['atualizados'] = count($r['atualizados']);
    $r['turmas'] = count($r['turmas']);
    return $r;
}

/** Pasta onde o arquivo enviado fica até a importação terminar */
function importacao_pasta(): string
{
    $p = __DIR__ . '/importacoes';
    if (!is_dir($p)) @mkdir($p, 0750, true);
    if (!is_file("$p/.htaccess")) @file_put_contents("$p/.htaccess", "Require all denied\nDeny from all\n");
    foreach (glob("$p/*") ?: [] as $f) if (preg_match('/\.(csv|txt|xlsx)$/', $f) && filemtime($f) < time() - 86400) @unlink($f);
    return $p;
}

/** Faltas da planilha somadas por turma: [turma_id => total] */
function faltas_planilha_por_turma(int $ano): array
{
    try {
        $r = [];
        foreach (q_todos('SELECT xt.turma_id, SUM(fb.faltas) AS n FROM faltas_bimestre fb JOIN aluno_turmas xt ON xt.aluno_id = fb.aluno_id AND xt.ano_letivo = fb.ano_letivo WHERE fb.ano_letivo = ? GROUP BY xt.turma_id', [$ano]) as $l) $r[(int)$l['turma_id']] = (int)$l['n'];
        return $r;
    } catch (PDOException $e) { return []; }
}

/** Faltas da planilha de um aluno por bimestre: [bimestre => total] */
function faltas_planilha_bimestres(int $aluno_id, int $ano): array
{
    try {
        $r = [];
        foreach (q_todos('SELECT bimestre, SUM(faltas) AS n FROM faltas_bimestre WHERE aluno_id = ? AND ano_letivo = ? GROUP BY bimestre ORDER BY bimestre', [$aluno_id, $ano]) as $l) $r[(int)$l['bimestre']] = (int)$l['n'];
        return $r;
    } catch (PDOException $e) { return []; }
}

/** Faltas informadas pela planilha (total por bimestre) */
function faltas_planilha(int $aluno_id, int $ano): int
{
    try { return (int)q_valor('SELECT COALESCE(SUM(faltas), 0) FROM faltas_bimestre WHERE aluno_id = ? AND ano_letivo = ?', [$aluno_id, $ano]); }
    catch (PDOException $e) { return 0; }
}
