<?php
/* =====================================================================
 *  Centro de Multimeios (biblioteca): acervo, fichas de leitor e
 *  empréstimos com prazo de devolução.
 * ===================================================================== */

const PRAZO_EMPRESTIMO_DIAS = 7;     // prazo padrão para devolver
const MAX_LIVROS_POR_ALUNO = 3;      // quantos livros um aluno pode ter ao mesmo tempo
const CATEGORIAS_LIVRO = ['Literatura brasileira', 'Literatura estrangeira', 'Infantojuvenil', 'Poesia', 'Didático', 'Paradidático',
                          'Técnico · Informática', 'Técnico · Enfermagem', 'Técnico · Administração', 'Ciências', 'História e Geografia',
                          'Biografia', 'Religião e Filosofia', 'Dicionário e referência', 'Revista / periódico', 'Outro'];

/** Livros com quantidade disponível */
function livro_buscar(int $id): ?array
{
    $l = q_um('SELECT l.*, (SELECT COUNT(*) FROM emprestimos e WHERE e.livro_id = l.id AND e.devolvido_em IS NULL) AS emprestados FROM livros l WHERE l.id = ?', [$id]);
    if (!$l) return null;
    $l['disponiveis'] = max(0, (int)$l['exemplares'] - (int)$l['emprestados']);
    return $l;
}

function ficha_do_aluno(int $aluno_id): ?array
{
    $f = q_um('SELECT * FROM biblioteca_fichas WHERE aluno_id = ?', [$aluno_id]);
    return $f ?: null;
}

function proximo_numero_ficha(): string
{
    $n = 0;
    foreach (q_todos('SELECT numero FROM biblioteca_fichas') as $r) $n = max($n, (int)preg_replace('/\D/', '', $r['numero']));
    return 'F' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** Cria a ficha do aluno se ainda não existir; devolve a ficha */
function garantir_ficha(int $aluno_id): array
{
    if ($f = ficha_do_aluno($aluno_id)) return $f;
    q('INSERT INTO biblioteca_fichas (aluno_id, numero) VALUES (?, ?)', [$aluno_id, proximo_numero_ficha()]);
    registrar_log('biblioteca_ficha', 'Ficha de leitor criada', $aluno_id);
    return ficha_do_aluno($aluno_id);
}

/** Situação de um empréstimo: devolvido, atrasado, vence_hoje ou no_prazo */
function situacao_emprestimo(array $e): string
{
    if ($e['devolvido_em']) return 'devolvido';
    $hoje = date('Y-m-d');
    if ($e['prazo'] < $hoje) return 'atrasado';
    if ($e['prazo'] === $hoje) return 'vence_hoje';
    return 'no_prazo';
}

function etiqueta_emprestimo(array $e): string
{
    $s = situacao_emprestimo($e);
    if ($s === 'atrasado') {
        $d = (int)((strtotime(date('Y-m-d')) - strtotime($e['prazo'])) / 86400);
        return '<span class="etiqueta vermelho"><i class="bi bi-alarm"></i>' . $d . ' dia' . ($d > 1 ? 's' : '') . ' de atraso</span>';
    }
    return [
        'devolvido'  => '<span class="etiqueta cinza"><i class="bi bi-check2"></i>Devolvido</span>',
        'vence_hoje' => '<span class="etiqueta ambar"><i class="bi bi-hourglass-split"></i>Vence hoje</span>',
        'no_prazo'   => '<span class="etiqueta"><i class="bi bi-clock"></i>Até ' . date('d/m', strtotime($e['prazo'])) . '</span>',
    ][$s];
}

/** Empréstimos com dados do livro e do aluno */
function emprestimos_lista(string $where = '1=1', array $p = [], string $ordem = 'e.retirada DESC', int $limite = 300): array
{
    return q_todos("SELECT e.*, l.titulo, l.autor, a.nome, a.responsavel_telefone, a.telefone, t.serie, t.letra, c.nome AS curso, f.numero AS ficha, u.nome AS por
                      FROM emprestimos e JOIN livros l ON l.id = e.livro_id JOIN alunos a ON a.id = e.aluno_id
                      LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                      LEFT JOIN biblioteca_fichas f ON f.aluno_id = a.id LEFT JOIN usuarios u ON u.id = e.registrado_por
                     WHERE $where ORDER BY $ordem LIMIT $limite", $p);
}

function contar_atrasados(): int
{
    try { return (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE devolvido_em IS NULL AND prazo < ?', [date('Y-m-d')]); }
    catch (PDOException $e) { return 0; }
}

/* ---------------------------------------------------------------------
 *  v59 · Importar acervo e fichas de leitor (CSV no mesmo formato do exportar)
 * --------------------------------------------------------------------- */

/** Lê a planilha enviada e devolve as linhas como [cabeçalho normalizado => valor] */
function bib_ler_planilha(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Escolha o arquivo da planilha (CSV).');
    if ($f['size'] > 10 * 1048576) throw new RuntimeException('A planilha passa de 10 MB.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) throw new RuntimeException('Envie a planilha em CSV (ou XLSX).');
    $abas = planilha_ler($f['tmp_name'], $ext === 'xlsx' ? 'xlsx' : 'csv');
    $linhas = array_values(reset($abas) ?: []);
    if (!$linhas) throw new RuntimeException('A planilha está vazia.');
    $cab = array_map('norm', $linhas[0]);
    $out = [];
    foreach (array_slice($linhas, 1) as $l) {
        if (!array_filter($l, fn($v) => trim((string)$v) !== '')) continue;
        $r = [];
        foreach ($cab as $i => $c) if ($c !== '') $r[$c] = trim((string)($l[$i] ?? ''));
        $out[] = $r;
    }
    return $out;
}

function bib_col(array $r, array $nomes): string { foreach ($nomes as $n) if (isset($r[$n]) && $r[$n] !== '') return $r[$n]; return ''; }

/* v66 · a importação agora tem duas etapas:
 *   1) bib_analisar_*: lê a planilha e diz, linha a linha, o que vai acontecer (sem gravar nada) → tela de conferência
 *   2) bib_gravar_*:   só depois que a pessoa confirma, grava de verdade (refaz a análise com o banco atual) */

/** ISBN-10 ou ISBN-13 com dígito verificador certo */
function isbn_valido(string $isbn): bool
{
    $d = strtoupper(preg_replace('/[^0-9Xx]/', '', $isbn));
    if (strlen($d) === 10 && preg_match('/^\d{9}[\dX]$/', $d)) {
        $s = 0; for ($i = 0; $i < 10; $i++) $s += ($d[$i] === 'X' ? 10 : (int)$d[$i]) * (10 - $i);
        return $s % 11 === 0;
    }
    if (strlen($d) === 13 && ctype_digit($d)) {
        $s = 0; for ($i = 0; $i < 12; $i++) $s += (int)$d[$i] * ($i % 2 ? 3 : 1);
        return (10 - $s % 10) % 10 === (int)$d[12];
    }
    return false;
}

/** Acervo: Título; Autor; Editora; Ano; ISBN; Categoria; Localização; Exemplares. Livro já existente (mesmo ISBN ou mesmo título e autor) é atualizado. */
function bib_analisar_acervo(array $linhas): array
{
    if ($linhas && !isset($linhas[0]['titulo']) && !isset($linhas[0]['livro']) && !isset($linhas[0]['autor'])) throw new RuntimeException('Não achei as colunas "Título" e "Autor". Use o mesmo modelo do "Exportar acervo".');
    $existentes = q_todos('SELECT id, titulo, autor, isbn, exemplares, ativo, (SELECT COUNT(*) FROM emprestimos e WHERE e.livro_id = livros.id AND e.devolvido_em IS NULL) AS emp FROM livros');
    $por_isbn = $por_nome = [];
    foreach ($existentes as $l) { if ($l['isbn']) $por_isbn[preg_replace('/\D/', '', $l['isbn'])] = $l; $por_nome[norm($l['titulo']) . '|' . norm($l['autor'])] = $l; }
    $itens = []; $vistos = [];
    foreach ($linhas as $n => $r) {
        $tit = mb_substr(bib_col($r, ['titulo', 'livro']), 0, 200); $aut = mb_substr(bib_col($r, ['autor', 'autora', 'autores']), 0, 150);
        $isbn = mb_substr(bib_col($r, ['isbn']), 0, 20); $ano_bruto = bib_col($r, ['ano']); $ano = (int)$ano_bruto;
        $exb = bib_col($r, ['exemplares', 'quantidade', 'qtd']);
        $d = ['titulo' => $tit, 'autor' => $aut, 'editora' => mb_substr(bib_col($r, ['editora']), 0, 100) ?: null,
              'ano' => $ano >= 1500 && $ano <= (int)date('Y') + 1 ? $ano : null, 'isbn' => $isbn ?: null,
              'categoria' => mb_substr(bib_col($r, ['categoria', 'genero']), 0, 60) ?: null,
              'localizacao' => mb_substr(bib_col($r, ['localizacao', 'local', 'estante']), 0, 40) ?: null,
              'exemplares' => max(1, min(999, (int)$exb))];
        $it = ['linha' => $n + 2, 'dados' => $d, 'avisos' => [], 'status' => 'novo', 'motivo' => '', 'ja' => null, 'chaves' => []];
        if ($tit === '' || $aut === '') { $it['status'] = 'erro'; $it['motivo'] = $tit === '' && $aut === '' ? 'sem título e sem autor' : ($tit === '' ? 'sem título' : 'sem autor'); $itens[] = $it; continue; }
        $dig = preg_replace('/\D/', '', $isbn);
        if ($isbn !== '' && !isbn_valido($isbn)) $it['avisos'][] = 'ISBN parece errado';
        if ($ano_bruto !== '' && $d['ano'] === null) $it['avisos'][] = 'ano "' . $ano_bruto . '" ignorado';
        if ($exb === '' || (int)$exb < 1) $it['avisos'][] = 'sem exemplares: entra 1';
        $it['chaves'] = array_values(array_filter([$dig !== '' ? 'i' . $dig : null, 'n' . norm($tit) . '|' . norm($aut)]));
        $ref = null; foreach ($it['chaves'] as $c) if (isset($vistos[$c])) { $ref = $vistos[$c]; break; }
        if ($ref !== null) { $it['status'] = 'repetido'; $it['motivo'] = 'repetido na planilha (linha ' . $itens[$ref]['linha'] . '): vale esta linha'; $it['ref'] = $ref; }
        else {
            $ja = ($dig !== '' && isset($por_isbn[$dig])) ? $por_isbn[$dig] : ($por_nome[norm($tit) . '|' . norm($aut)] ?? null);
            if ($ja) {
                $it['status'] = 'atualiza'; $it['ja'] = ['id' => (int)$ja['id'], 'titulo' => $ja['titulo'], 'exemplares' => (int)$ja['exemplares'], 'emp' => (int)$ja['emp'], 'ativo' => (int)$ja['ativo']];
                if ($d['exemplares'] < (int)$ja['emp']) $it['avisos'][] = 'tem ' . $ja['emp'] . ' emprestado(s): fica com ' . $ja['emp'];
                if (!(int)$ja['ativo']) $it['avisos'][] = 'volta para o acervo';
            }
        }
        foreach ($it['chaves'] as $c) $vistos[$c] ??= count($itens);
        $itens[] = $it;
    }
    return $itens;
}

function bib_gravar_acervo(array $itens): array
{
    $novos = $atual = 0; $ignor = []; $ids = [];
    db()->beginTransaction();
    try {
        foreach ($itens as $k => $it) {
            $d = $it['dados'];
            if ($it['status'] === 'erro') { $ignor[] = 'linha ' . $it['linha'] . ' ' . $it['motivo']; continue; }
            $id = $it['status'] === 'repetido' ? ($ids[$it['ref']] ?? null) : ($it['ja']['id'] ?? null);
            if ($id) {
                $emp = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE livro_id = ? AND devolvido_em IS NULL', [$id]);
                q('UPDATE livros SET titulo = ?, autor = ?, editora = COALESCE(?, editora), ano = COALESCE(?, ano), isbn = COALESCE(?, isbn), categoria = COALESCE(?, categoria), localizacao = COALESCE(?, localizacao), exemplares = ?, ativo = 1 WHERE id = ?',
                  [$d['titulo'], $d['autor'], $d['editora'], $d['ano'], $d['isbn'], $d['categoria'], $d['localizacao'], max($d['exemplares'], $emp), $id]);
                if ($it['status'] === 'atualiza') $atual++;
            } else {
                q('INSERT INTO livros (titulo, autor, editora, ano, isbn, categoria, localizacao, exemplares) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$d['titulo'], $d['autor'], $d['editora'], $d['ano'], $d['isbn'], $d['categoria'], $d['localizacao'], $d['exemplares']]);
                $id = (int)db()->lastInsertId(); $novos++;
            }
            $ids[$k] = $id;
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    registrar_log('biblioteca_importar', "Acervo: $novos novos, $atual atualizados");
    return [$novos, $atual, $ignor];
}

/** Fichas de leitor: Ficha; Aluno; Turma; Situação; Observação. O aluno é achado pelo nome (e pela turma, se houver homônimo). */
function bib_analisar_leitores(array $linhas): array
{
    if ($linhas && !isset($linhas[0]['aluno']) && !isset($linhas[0]['nome']) && !isset($linhas[0]['leitor'])) throw new RuntimeException('Não achei a coluna "Aluno". Use o mesmo modelo do "Exportar leitores".');
    $alunos = [];
    foreach (q_todos("SELECT a.id, a.nome, t.serie, t.letra, c.sigla FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id WHERE a.situacao = 'ativo'") as $a) $alunos[norm($a['nome'])][] = $a;
    $fichas = [];
    foreach (q_todos('SELECT f.id, f.numero, f.aluno_id, f.bloqueada FROM biblioteca_fichas f') as $f) $fichas[(int)$f['aluno_id']] = $f;
    $itens = []; $vistos = [];
    foreach ($linhas as $n => $r) {
        $nome = bib_col($r, ['aluno', 'nome', 'leitor']);
        $num = strtoupper(mb_substr(bib_col($r, ['ficha', 'numero', 'n ficha', 'no ficha']), 0, 20));
        $sit = norm(bib_col($r, ['situacao', 'status']));
        $it = ['linha' => $n + 2, 'nome' => $nome, 'turma_txt' => bib_col($r, ['turma']), 'num' => $num, 'bloq' => str_contains($sit, 'bloq') ? 1 : 0,
               'obs' => mb_substr(bib_col($r, ['observacao', 'obs']), 0, 255) ?: null, 'status' => 'nova', 'motivo' => '', 'avisos' => [], 'aluno' => null, 'ficha' => null];
        if ($nome === '') { $it['status'] = 'erro'; $it['motivo'] = 'sem nome do aluno'; $itens[] = $it; continue; }
        $pela_ficha = $num !== '' ? q_um('SELECT f.aluno_id, a.nome FROM biblioteca_fichas f JOIN alunos a ON a.id = f.aluno_id WHERE f.numero = ?', [$num]) : null;
        $cands = $pela_ficha && norm($pela_ficha['nome']) === norm($nome) ? array_values(array_filter($alunos[norm($nome)] ?? [], fn($a) => (int)$a['id'] === (int)$pela_ficha['aluno_id'])) : ($alunos[norm($nome)] ?? []);
        $turma = norm($it['turma_txt']);
        if (count($cands) > 1 && $turma !== '') $cands = array_values(array_filter($cands, fn($a) => str_contains($turma, norm($a['serie'] . ' ' . $a['letra'])) || str_contains($turma, norm($a['serie'] . 'º ano ' . $a['letra']))));
        if (count($cands) !== 1) { $it['status'] = 'erro'; $it['motivo'] = count($cands) ? 'mais de um aluno com esse nome (informe a turma)' : 'aluno não encontrado entre os ativos'; $itens[] = $it; continue; }
        $a = $cands[0]; $aid = (int)$a['id'];
        $it['aluno'] = ['id' => $aid, 'nome' => $a['nome'], 'turma' => $a['serie'] ? $a['serie'] . 'º ' . $a['letra'] . ' ' . $a['sigla'] : '—'];
        if (isset($vistos[$aid])) { $it['status'] = 'repetido'; $it['motivo'] = 'aluno repetido na planilha (linha ' . $itens[$vistos[$aid]]['linha'] . '): vale esta linha'; }
        elseif (isset($fichas[$aid])) {
            $it['status'] = 'atualiza'; $it['ficha'] = $fichas[$aid]['numero'];
            if ($num !== '' && $num !== $fichas[$aid]['numero']) $it['avisos'][] = 'já tem a ficha ' . $fichas[$aid]['numero'] . ': o número não muda';
        } else {
            $usada = $num !== '' && q_valor('SELECT id FROM biblioteca_fichas WHERE numero = ?', [$num]);
            $it['ficha'] = $num !== '' && !$usada ? $num : null;
            if ($usada) $it['avisos'][] = 'ficha ' . $num . ' já é de outro aluno: recebe número novo';
        }
        $vistos[$aid] ??= count($itens);
        $itens[] = $it;
    }
    return $itens;
}

function bib_gravar_leitores(array $itens): array
{
    $novas = $atual = 0; $ignor = [];
    db()->beginTransaction();
    try {
        foreach ($itens as $it) {
            if ($it['status'] === 'erro') { $ignor[] = ($it['nome'] ?: 'linha ' . $it['linha']) . ' (' . $it['motivo'] . ')'; continue; }
            $aid = (int)$it['aluno']['id'];
            $ja = ficha_do_aluno($aid);
            if ($ja) {
                q('UPDATE biblioteca_fichas SET bloqueada = ?, observacao = COALESCE(?, observacao) WHERE id = ?', [$it['bloq'], $it['obs'], $ja['id']]);
                if ($it['status'] === 'atualiza') $atual++;
            } else {
                $num = (string)($it['ficha'] ?? '');
                if ($num === '' || q_valor('SELECT id FROM biblioteca_fichas WHERE numero = ?', [$num])) $num = proximo_numero_ficha();
                q('INSERT INTO biblioteca_fichas (aluno_id, numero, bloqueada, observacao) VALUES (?, ?, ?, ?)', [$aid, $num, $it['bloq'], $it['obs']]);
                $novas++;
            }
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    registrar_log('biblioteca_importar', "Leitores: $novas novas fichas, $atual atualizadas");
    return [$novas, $atual, $ignor];
}

/* compatibilidade: importar direto (sem conferência) */
function bib_importar_acervo(array $f): array { return bib_gravar_acervo(bib_analisar_acervo(bib_ler_planilha($f))); }
function bib_importar_leitores(array $f): array { return bib_gravar_leitores(bib_analisar_leitores(bib_ler_planilha($f))); }
