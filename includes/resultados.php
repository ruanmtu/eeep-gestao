<?php
/* =====================================================================
 *  Resultados (painel da gestão e ficha do aluno)
 *  Usa as notas bimestrais (tabela notas). Período: 1 a 4 = bimestre,
 *  0 = ano todo (média dos bimestres lançados).
 * ===================================================================== */

const CORES_DISCIPLINA = ['#13692a', '#1d5fb0', '#c0261b', '#d4650a', '#6b3fa0', '#0f8a8a', '#a3245c', '#5b6670', '#b08900', '#2f8f4e', '#3f6fd8', '#c2410c', '#7c3aed', '#0e7490', '#be185d', '#4d7c0f'];

/** Cor fixa de cada disciplina (a mesma em todo o sistema) */
function cor_disciplina(string $nome): string
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = []; $i = 0;
        try { foreach (q_todos('SELECT DISTINCT nome FROM disciplinas ORDER BY nome') as $d) { $k = mb_strtolower(trim($d['nome'])); if (!isset($mapa[$k])) $mapa[$k] = CORES_DISCIPLINA[$i++ % count(CORES_DISCIPLINA)]; } } catch (PDOException $e) {}
    }
    return $mapa[mb_strtolower(trim($nome))] ?? CORES_DISCIPLINA[abs(crc32(mb_strtolower(trim($nome)))) % count(CORES_DISCIPLINA)];
}

/** Bimestres que já têm nota lançada na turma (ou na escola, se turma = 0) */
function bimestres_com_nota(int $turma_id = 0, int $ano = ANO_VER): array
{
    $sql = 'SELECT DISTINCT n.bimestre FROM notas n' . ($turma_id ? ' JOIN alunos a ON a.id = n.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND' : ' WHERE') . ' n.ano_letivo = ? ORDER BY n.bimestre';
    return array_map('intval', array_column(q_todos($sql, $turma_id ? [$turma_id, $ano] : [$ano]), 'bimestre'));
}

/** Notas da turma no período: [aluno_id => [disciplina_id => nota]] e os nomes */
function notas_periodo_turma(int $turma_id, int $bim, int $ano = ANO_VER): array
{
    $linhas = q_todos("SELECT n.aluno_id, n.disciplina_id, n.bimestre, n.nota, d.nome AS disciplina
                         FROM notas n JOIN alunos a ON a.id = n.aluno_id JOIN disciplinas d ON d.id = n.disciplina_id
                        WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) AND n.ano_letivo = ?" . ($bim ? ' AND n.bimestre = ' . (int)$bim : ''), [$turma_id, $ano]);
    $acc = []; $disc = [];
    foreach ($linhas as $l) {
        $acc[(int)$l['aluno_id']][(int)$l['disciplina_id']][] = (float)$l['nota'];
        $disc[(int)$l['disciplina_id']] = $l['disciplina'];
    }
    $notas = [];
    foreach ($acc as $a => $ds) foreach ($ds as $d => $v) $notas[$a][$d] = round(array_sum($v) / count($v), 2);
    return [$notas, $disc];
}

/**
 * Painel de resultados de uma turma.
 * Melhores alunos: precisam estar na média em TODAS as disciplinas; a ordem é pela
 * MENOR nota (o ponto mais fraco) e, no empate, pela média. Assim quem tem 10 em
 * quase tudo mas uma nota baixa não passa na frente de quem vai bem em tudo.
 */
function resultados_turma(int $turma_id, int $bim, int $ano = ANO_VER): array
{
    [$notas, $disc] = notas_periodo_turma($turma_id, $bim, $ano);
    $alunos = [];
    foreach (q_todos("SELECT id, nome, foto FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido'))", [$turma_id]) as $a) $alunos[(int)$a['id']] = $a;
    $por_disc = []; $linhas = [];
    foreach ($notas as $aid => $ns) {
        if (!isset($alunos[$aid]) || !$ns) continue;
        foreach ($ns as $d => $v) $por_disc[$d][] = $v;
        $crit = array_filter($ns, fn($v) => $v < MEDIA_APROVACAO);
        asort($crit);
        $linhas[] = ['id' => $aid, 'nome' => nome_proprio($alunos[$aid]['nome']), 'curto' => primeiro_ultimo(nome_proprio($alunos[$aid]['nome'])),
                     'media' => round(array_sum($ns) / count($ns), 2), 'menor' => min($ns), 'n_disc' => count($ns),
                     'criticas' => array_map(fn($d) => ['nome' => $disc[$d], 'nota' => $crit[$d]], array_keys($crit))];
    }
    $disciplinas = [];
    foreach ($por_disc as $d => $vs) $disciplinas[] = ['id' => $d, 'nome' => $disc[$d], 'cor' => cor_disciplina($disc[$d]), 'media' => round(array_sum($vs) / count($vs), 2),
                                                        'abaixo' => count(array_filter($vs, fn($v) => $v < MEDIA_APROVACAO)), 'n' => count($vs)];
    usort($disciplinas, fn($a, $b) => $b['media'] <=> $a['media'] ?: strcmp($a['nome'], $b['nome']));
    $criticos = array_values(array_filter($linhas, fn($l) => $l['criticas']));
    usort($criticos, fn($a, $b) => count($b['criticas']) <=> count($a['criticas']) ?: $a['media'] <=> $b['media']);
    $melhores = array_values(array_filter($linhas, fn($l) => !$l['criticas']));
    usort($melhores, fn($a, $b) => $b['menor'] <=> $a['menor'] ?: $b['media'] <=> $a['media']);
    $qtd = array_values(array_filter($disciplinas, fn($d) => $d['abaixo'] > 0));
    usort($qtd, fn($a, $b) => $b['abaixo'] <=> $a['abaixo'] ?: strcmp($a['nome'], $b['nome']));
    $todas = array_merge(...array_values(array_map(fn($ns) => array_values($ns), array_intersect_key($notas, $alunos)))) ?: [];
    return [
        'media' => $todas ? round(array_sum($todas) / count($todas), 2) : null,
        'alunos_com_nota' => count($linhas), 'alunos_total' => count($alunos),
        'aprovados' => count($melhores), 'criticos_total' => count($criticos),
        'disciplinas' => $disciplinas,
        'criticos' => array_slice($criticos, 0, 12),
        'melhores' => array_slice($melhores, 0, 8),
        'disc_criticas' => $qtd,
    ];
}

/** Painel do aluno: por período, as notas por disciplina e a média da turma em cada uma */
function resultados_aluno(int $aluno_id, int $ano = ANO_VER): array
{
    $a = q_um('SELECT id, turma_id FROM alunos WHERE id = ?', [$aluno_id]);
    if (!$a) return [];
    $periodos = [];
    foreach ([1, 2, 3, 4, 0] as $bim) {
        $linhas = q_todos('SELECT n.disciplina_id, n.nota, d.nome FROM notas n JOIN disciplinas d ON d.id = n.disciplina_id WHERE n.aluno_id = ? AND n.ano_letivo = ?' . ($bim ? ' AND n.bimestre = ' . $bim : ''), [$aluno_id, $ano]);
        if (!$linhas) continue;
        $acc = []; $nomes = [];
        foreach ($linhas as $l) { $acc[(int)$l['disciplina_id']][] = (float)$l['nota']; $nomes[(int)$l['disciplina_id']] = $l['nome']; }
        $turma_media = [];
        if ($a['turma_id']) {
            [$nt] = notas_periodo_turma((int)$a['turma_id'], $bim, $ano);
            $pd = []; foreach ($nt as $ns) foreach ($ns as $d => $v) $pd[$d][] = $v;
            foreach ($pd as $d => $vs) $turma_media[$d] = round(array_sum($vs) / count($vs), 2);
        }
        $disc = [];
        foreach ($acc as $d => $vs) $disc[] = ['nome' => $nomes[$d], 'cor' => cor_disciplina($nomes[$d]), 'nota' => round(array_sum($vs) / count($vs), 2), 'turma' => $turma_media[$d] ?? null];
        usort($disc, fn($x, $y) => $y['nota'] <=> $x['nota'] ?: strcmp($x['nome'], $y['nome']));
        $vals = array_column($disc, 'nota');
        $periodos[$bim] = ['media' => round(array_sum($vals) / count($vals), 2), 'menor' => min($vals), 'disc' => $disc,
                           'criticas' => array_values(array_filter($disc, fn($d) => $d['nota'] < MEDIA_APROVACAO)),
                           'turma_media' => $turma_media ? round(array_sum($turma_media) / count($turma_media), 2) : null];
    }
    /* evolução da média por bimestre */
    $evol = [];
    foreach ([1, 2, 3, 4] as $b) if (isset($periodos[$b])) $evol[$b] = $periodos[$b]['media'];
    return ['periodos' => $periodos, 'evolucao' => $evol];
}
