<?php
/* =====================================================================
 *  Avaliações e médias.
 *  Cada avaliação pertence a uma turma + disciplina + bimestre.
 *  A média do bimestre do aluno = soma das notas lançadas / quantidade
 *  de notas, e fica gravada na tabela "notas" (usada no boletim,
 *  relatórios e painel).
 * ===================================================================== */

const TIPOS_AVALIACAO = [
    'global'     => 'Avaliação global',
    'parcial'    => 'Avaliação parcial',
    'conceitual' => 'Nota conceitual',
];
const CORES_AVALIACAO = ['global' => 'escura', 'parcial' => '', 'conceitual' => 'ambar'];

/** Turmas que o usuário pode usar (professor: só as atribuídas) */
function turmas_do_usuario(): array
{
    $todas = turmas_lista();
    if (!restrito_a_atribuicoes()) return $todas;
    $ids = array_column(q_todos('SELECT DISTINCT turma_id FROM atribuicoes WHERE usuario_id = ?', [uid()]), 'turma_id');
    return array_values(array_filter($todas, fn($t) => in_array($t['id'], $ids)));
}

/** Disciplinas que o usuário pode usar; com $turma, só as que valem para o curso da turma */
function disciplinas_do_usuario(?array $turma = null): array
{
    if (restrito_a_atribuicoes()) {
        $sql = 'SELECT DISTINCT d.id, d.nome, d.tipo, d.curso_id FROM atribuicoes a JOIN disciplinas d ON d.id = a.disciplina_id WHERE a.usuario_id = ?';
        $p = [uid()];
        if ($turma) { $sql .= ' AND a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?)'; $p[] = $turma['id']; }
        return q_todos($sql . ' ORDER BY d.tipo, d.nome', $p);
    }
    $sql = 'SELECT d.id, d.nome, d.tipo, d.curso_id, c.nome AS curso FROM disciplinas d LEFT JOIN cursos c ON c.id = d.curso_id WHERE d.ativo = 1';
    $p = [];
    if ($turma) { $sql .= ' AND (d.curso_id IS NULL OR d.curso_id = ?)'; $p[] = $turma['curso_id']; }
    return q_todos($sql . ' ORDER BY d.tipo, c.nome, d.nome', $p);
}

/** O professor só mexe em avaliações das suas atribuições (ou que ele criou) */
function pode_avaliacao(array $av): bool
{
    if (!restrito_a_atribuicoes()) return true;
    if ((int)$av['criado_por'] === uid()) return true;
    return (bool)q_valor('SELECT id FROM atribuicoes WHERE usuario_id = ? AND turma_id = ? AND disciplina_id = ?', [uid(), $av['turma_id'], $av['disciplina_id']]);
}

function avaliacao_buscar(int $id): ?array
{
    $a = q_um('SELECT av.*, d.nome AS disciplina, t.serie, t.letra, t.curso_id, c.nome AS curso, u.nome AS autor
                 FROM avaliacoes av JOIN disciplinas d ON d.id = av.disciplina_id
                 JOIN turmas t ON t.id = av.turma_id JOIN cursos c ON c.id = t.curso_id
                 LEFT JOIN usuarios u ON u.id = av.criado_por WHERE av.id = ?', [$id]);
    return $a ?: null;
}

/**
 * Recalcula a média do bimestre de um aluno numa disciplina e grava em "notas".
 * Média = soma das notas lançadas nas avaliações / quantidade de notas lançadas.
 */
function recalcular_media(int $aluno_id, int $disciplina_id, int $ano, int $bimestre): ?float
{
    $r = q_um('SELECT COUNT(n.id) AS qtd, SUM(n.nota) AS soma
                 FROM avaliacao_notas n JOIN avaliacoes av ON av.id = n.avaliacao_id
                WHERE n.aluno_id = ? AND av.disciplina_id = ? AND av.ano_letivo = ? AND av.bimestre = ?',
              [$aluno_id, $disciplina_id, $ano, $bimestre]);
    $existe = q_valor('SELECT id FROM notas WHERE aluno_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ?', [$aluno_id, $disciplina_id, $ano, $bimestre]);
    if (!(int)$r['qtd']) {
        if ($existe) q('DELETE FROM notas WHERE id = ?', [$existe]);
        return null;
    }
    $media = round((float)$r['soma'] / (int)$r['qtd'], 2);
    if ($existe) q('UPDATE notas SET nota = ?, lancado_por = ?, atualizado_em = ? WHERE id = ?', [$media, uid(), date('Y-m-d H:i:s'), $existe]);
    else q('INSERT INTO notas (aluno_id, disciplina_id, ano_letivo, bimestre, nota, lancado_por) VALUES (?, ?, ?, ?, ?, ?)', [$aluno_id, $disciplina_id, $ano, $bimestre, $media, uid()]);
    return $media;
}

/** Grava as notas de uma avaliação (vazio apaga) e atualiza as médias. Devolve [salvas, apagadas, inválidas] */
function salvar_notas_avaliacao(array $av, array $notas): array
{
    $alunos = array_map('intval', array_column(q_todos("SELECT id FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido'))", [$av['turma_id']]), 'id'));
    $salvas = $apagadas = $invalidas = 0;
    db()->beginTransaction();
    foreach ($notas as $aid => $v) {
        $aid = (int)$aid;
        if (!in_array($aid, $alunos, true)) continue;
        $v = trim(str_replace(',', '.', (string)$v));
        $ex = q_valor('SELECT id FROM avaliacao_notas WHERE avaliacao_id = ? AND aluno_id = ?', [$av['id'], $aid]);
        if ($v === '') {
            if ($ex) { q('DELETE FROM avaliacao_notas WHERE id = ?', [$ex]); $apagadas++; recalcular_media($aid, (int)$av['disciplina_id'], (int)$av['ano_letivo'], (int)$av['bimestre']); }
            continue;
        }
        if (!is_numeric($v) || $v < 0 || $v > 10) { $invalidas++; continue; }
        $v = round((float)$v, 2);
        if ($ex) q('UPDATE avaliacao_notas SET nota = ?, lancado_por = ?, atualizado_em = ? WHERE id = ?', [$v, uid(), date('Y-m-d H:i:s'), $ex]);
        else q('INSERT INTO avaliacao_notas (avaliacao_id, aluno_id, nota, lancado_por) VALUES (?, ?, ?, ?)', [$av['id'], $aid, $v, uid()]);
        recalcular_media($aid, (int)$av['disciplina_id'], (int)$av['ano_letivo'], (int)$av['bimestre']);
        $salvas++;
    }
    db()->commit();
    return [$salvas, $apagadas, $invalidas];
}

/** Cria a mesma avaliação em várias turmas. Devolve [criadas(ids), puladas(nomes)] */
function criar_avaliacoes(array $turma_ids, int $disciplina_id, string $tipo, string $titulo, int $bimestre, ?string $data): array
{
    $disc = q_um('SELECT * FROM disciplinas WHERE id = ?', [$disciplina_id]);
    $lote = substr(bin2hex(random_bytes(8)), 0, 16);
    $criadas = []; $puladas = [];
    foreach ($turma_ids as $tid) {
        $t = q_um('SELECT t.*, c.nome AS curso FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?', [(int)$tid]);
        if (!$t) continue;
        if ($disc['curso_id'] && (int)$disc['curso_id'] !== (int)$t['curso_id']) { $puladas[] = turma_nome($t) . ' (disciplina de outro curso)'; continue; }
        if (restrito_a_atribuicoes() && !q_valor('SELECT id FROM atribuicoes WHERE usuario_id = ? AND turma_id = ? AND disciplina_id = ?', [uid(), $t['id'], $disciplina_id])) {
            $puladas[] = turma_nome($t) . ' (não atribuída a você)'; continue;
        }
        if (q_valor('SELECT id FROM avaliacoes WHERE turma_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ? AND tipo = ? AND titulo = ?',
                    [$t['id'], $disciplina_id, ANO_VER, $bimestre, $tipo, $titulo])) { $puladas[] = turma_nome($t) . ' (já existe)'; continue; }
        q('INSERT INTO avaliacoes (turma_id, disciplina_id, ano_letivo, bimestre, tipo, titulo, data_aplicacao, lote, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$t['id'], $disciplina_id, ANO_VER, $bimestre, $tipo, $titulo, $data ?: null, $lote, uid()]);
        $criadas[] = (int)db()->lastInsertId();
    }
    if ($criadas) registrar_log('avaliacao_criada', TIPOS_AVALIACAO[$tipo] . ' · ' . $disc['nome'] . ' · ' . count($criadas) . ' turma(s)');
    return [$criadas, $puladas];
}

/** Apaga uma avaliação e recalcula as médias de quem tinha nota nela */
function excluir_avaliacao(array $av): void
{
    $alunos = array_column(q_todos('SELECT aluno_id FROM avaliacao_notas WHERE avaliacao_id = ?', [$av['id']]), 'aluno_id');
    q('DELETE FROM avaliacoes WHERE id = ?', [$av['id']]);
    foreach ($alunos as $aid) recalcular_media((int)$aid, (int)$av['disciplina_id'], (int)$av['ano_letivo'], (int)$av['bimestre']);
    registrar_log('avaliacao_excluida', $av['titulo'] . ' · ' . turma_nome($av));
}
