<?php
/* =====================================================================
 *  Aulas do dia (horários), reservas de ambientes/equipamentos e
 *  horário dos professores.
 * ===================================================================== */

/* Horário padrão das aulas (tempo integral). Ajustável em Horários › Horário das aulas. */
const AULAS_PADRAO = [
    1 => ['07:20', '08:10'], 2 => ['08:10', '09:00'], 3 => ['09:00', '09:50'], 4 => ['10:10', '11:00'], 5 => ['11:00', '11:50'],
    6 => ['13:20', '14:10'], 7 => ['14:10', '15:00'], 8 => ['15:20', '16:10'], 9 => ['16:10', '17:00'],
];
const DIAS_UTEIS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta'];
const METODOLOGIAS = ['Palestra', 'Aula expositiva', 'Apresentação de trabalhos / seminário', 'Filme ou documentário', 'Aula prática / experimento',
                      'Avaliação', 'Oficina', 'Reunião', 'Evento / culminância', 'Outra'];

/** [n => [inicio, fim]] */
function aulas(): array
{
    $j = json_decode((string)config_ler('horarios_aulas', ''), true);
    if (!is_array($j) || !$j) return AULAS_PADRAO;
    $a = [];
    foreach ($j as $n => $h) if (is_array($h) && count($h) === 2) $a[(int)$n] = [$h[0], $h[1]];
    ksort($a);
    return $a ?: AULAS_PADRAO;
}

function aula_rotulo(int $n, bool $com_hora = true): string
{
    $a = aulas()[$n] ?? null;
    return $n . 'ª aula' . ($com_hora && $a ? ' · ' . $a[0] . '–' . $a[1] : '');
}

/** Aula em andamento agora (ou null) */
function aula_agora(): ?int
{
    if ((int)date('N') > 5) return null;                 // aulas só de segunda a sexta
    $h = date('H:i');
    foreach (aulas() as $n => [$i, $f]) if ($h >= $i && $h < $f) return $n;
    return null;
}

function recursos_lista(bool $so_ativos = true): array
{
    try { return q_todos('SELECT * FROM recursos' . ($so_ativos ? ' WHERE ativo = 1' : '') . ' ORDER BY tipo, ordem, nome'); }
    catch (PDOException $e) { return []; }
}

/** Reservas de um dia (com recurso, professor e turma) */
function reservas_do_dia(string $data, ?int $usuario = null): array
{
    try {
        return q_todos('SELECT r.*, rc.nome AS recurso, rc.tipo AS recurso_tipo, rc.grupo, u.nome AS professor, u.foto, u.nome_exibicao, t.serie, t.letra, c.sigla
                          FROM reservas r JOIN recursos rc ON rc.id = r.recurso_id LEFT JOIN usuarios u ON u.id = r.usuario_id
                          LEFT JOIN turmas t ON t.id = r.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                         WHERE r.data = ?' . ($usuario ? ' AND r.usuario_id = ' . (int)$usuario : '') . ' ORDER BY r.aula, rc.tipo, rc.ordem', [$data]);
    } catch (PDOException $e) { return []; }
}

/** Horário (grade) de um professor: [dia][aula] => linha */
function grade_professor(int $usuario_id): array
{
    $g = [];
    try {
        foreach (q_todos('SELECT h.*, t.serie, t.letra, c.sigla, c.nome AS curso, d.nome AS disciplina FROM horarios h
                            LEFT JOIN turmas t ON t.id = h.turma_id LEFT JOIN cursos c ON c.id = t.curso_id LEFT JOIN disciplinas d ON d.id = h.disciplina_id
                           WHERE h.usuario_id = ?', [$usuario_id]) as $r) $g[(int)$r['dia_semana']][(int)$r['aula']] = $r;
    } catch (PDOException $e) {}
    return $g;
}
