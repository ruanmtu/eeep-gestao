<?php
/* =====================================================================
 *  Chamada por aula
 *  O professor escolhe a aula e a disciplina; o sistema confere na grade
 *  de horários se ele leciona aquela disciplina naquela aula e abre a
 *  lista da turma para marcar presença/falta. Não substitui a coleta
 *  diária de faltas (que alimenta a ordem do almoço e os alertas).
 * ===================================================================== */

/** Aulas do professor num dia, com a chamada (se já foi feita) */
function aulas_do_dia(int $uid, string $data): array
{
    $dia = (int)date('N', strtotime($data));
    if ($dia > 5) return [];
    $grade = grade_professor($uid)[$dia] ?? [];
    ksort($grade);
    $feitas = [];
    try {
        foreach (q_todos('SELECT * FROM chamadas_aula WHERE data = ? AND turma_id IN (SELECT turma_id FROM horarios WHERE usuario_id = ? AND turma_id IS NOT NULL)', [$data, $uid]) as $c)
            $feitas[(int)$c['aula'] . '-' . (int)$c['turma_id']] = $c;
    } catch (PDOException $e) {}
    $out = [];
    foreach ($grade as $aula => $h) {
        if (empty($h['turma_id'])) continue;
        $h['chamada'] = $feitas[$aula . '-' . (int)$h['turma_id']] ?? null;
        $out[$aula] = $h;
    }
    return $out;
}

/** Confere na grade: o usuário leciona esta disciplina nesta aula, neste dia? Devolve a linha da grade ou null */
function aula_confere(int $uid, string $data, int $aula, int $disciplina_id): ?array
{
    $dia = (int)date('N', strtotime($data));
    $h = grade_professor($uid)[$dia][$aula] ?? null;
    if (!$h || empty($h['turma_id'])) return null;
    if ($disciplina_id && (int)$h['disciplina_id'] !== $disciplina_id) return null;
    return $h;
}

function chamada_aula_buscar(string $data, int $aula, int $turma_id): ?array
{
    try { return q_um('SELECT * FROM chamadas_aula WHERE data = ? AND aula = ? AND turma_id = ?', [$data, $aula, $turma_id]) ?: null; }
    catch (PDOException $e) { return null; }
}

/** Grava a chamada de uma aula. $faltas = ids dos alunos ausentes */
function chamada_aula_salvar(string $data, int $aula, int $turma_id, ?int $disciplina_id, array $faltas): array
{
    $alunos = array_map('intval', array_column(q_todos("SELECT id FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido'))", [$turma_id]), 'id'));
    $faltas = array_values(array_intersect(array_map('intval', $faltas), $alunos));
    $agora = date('Y-m-d H:i:s');
    db()->beginTransaction();
    $c = chamada_aula_buscar($data, $aula, $turma_id);
    if ($c) {
        q('UPDATE chamadas_aula SET disciplina_id = ?, usuario_id = ?, presentes = ?, ausentes = ?, atualizado_em = ? WHERE id = ?',
          [$disciplina_id, uid(), count($alunos) - count($faltas), count($faltas), $agora, $c['id']]);
        $cid = (int)$c['id'];
        q('DELETE FROM chamada_faltas WHERE chamada_id = ?', [$cid]);
    } else {
        q('INSERT INTO chamadas_aula (data, aula, turma_id, disciplina_id, usuario_id, presentes, ausentes, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$data, $aula, $turma_id, $disciplina_id, uid(), count($alunos) - count($faltas), count($faltas), $agora, $agora]);
        $cid = (int)db()->lastInsertId();
    }
    foreach ($faltas as $a) q('INSERT INTO chamada_faltas (chamada_id, aluno_id) VALUES (?, ?)', [$cid, $a]);
    db()->commit();
    return [count($alunos), count($faltas)];
}

/** Faltas por aula de um aluno (para a ficha) */
function faltas_por_aula_aluno(int $aluno_id, int $limite = 60): array
{
    try {
        return q_todos('SELECT c.data, c.aula, d.nome AS disciplina, u.nome AS professor FROM chamada_faltas f JOIN chamadas_aula c ON c.id = f.chamada_id
                          LEFT JOIN disciplinas d ON d.id = c.disciplina_id LEFT JOIN usuarios u ON u.id = c.usuario_id
                         WHERE f.aluno_id = ? ORDER BY c.data DESC, c.aula DESC LIMIT ' . $limite, [$aluno_id]);
    } catch (PDOException $e) { return []; }
}
