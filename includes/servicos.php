<?php
/* =====================================================================
 *  Operações compartilhadas entre páginas (gravação + formulários)
 * ===================================================================== */

/** Busca um aluno com sua turma e curso */
function aluno_buscar(int $id): ?array
{
    $a = q_um('SELECT a.*, t.serie, t.letra, t.ano_letivo, c.nome AS curso, c.id AS curso_id
                 FROM alunos a
                 LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id)
                 LEFT JOIN cursos c ON c.id = t.curso_id
                WHERE a.id = ?', [$id]);
    return $a ?: null;
}

/** Alunos ativos agrupados por turma, para selects */
function alunos_por_turma(): array
{
    $linhas = q_todos('SELECT a.id, a.nome, t.serie, t.letra, c.nome AS curso
                         FROM alunos a
                         LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id)
                         LEFT JOIN cursos c ON c.id = t.curso_id
                        WHERE a.situacao = \'ativo\'
                        ORDER BY t.serie, t.letra, a.nome');
    $grupos = [];
    foreach ($linhas as $l) $grupos[turma_nome($l)][] = $l;
    return $grupos;
}

function select_aluno(string $nome = 'aluno_id', ?int $selecionado = null): string
{
    $id = 'sel_' . $nome . '_' . mt_rand(100, 999);
    $h = '<input type="search" class="form-control mb-2" placeholder="Digite parte do nome para filtrar" data-filtra-select="#' . $id . '" aria-label="Filtrar alunos">';
    $h .= '<select class="form-select" name="' . e($nome) . '" id="' . $id . '" required size="1"><option value="">Selecione o aluno</option>';
    foreach (alunos_por_turma() as $turma => $alunos) {
        $h .= '<optgroup label="' . e($turma) . '">';
        foreach ($alunos as $a) {
            $h .= '<option value="' . $a['id'] . '"' . ($selecionado === (int)$a['id'] ? ' selected' : '') . '>' . e(nome_proprio($a['nome'])) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
}

/* ------------------------------------------------------------------ */
/*  Faltas                                                             */
/* ------------------------------------------------------------------ */
function validar_data_falta(string $data): ?string
{
    if (!data_valida($data)) return 'Informe uma data válida.';
    if ($data > date('Y-m-d')) return 'Não é possível registrar falta em data futura.';
    $dow = (int)date('w', strtotime($data));
    if ($dow === 0 || $dow === 6) return 'A data escolhida cai num fim de semana.';
    return null;
}

function falta_registrar(int $aluno_id, string $data): array
{
    if ($erro = validar_data_falta($data)) return ['warning', $erro];
    if (q_valor('SELECT COUNT(*) FROM faltas WHERE aluno_id = ? AND data_falta = ?', [$aluno_id, $data])) {
        return ['warning', 'Já existe falta registrada para este aluno em ' . data_br($data) . '.'];
    }
    q('INSERT INTO faltas (aluno_id, data_falta, registrado_por) VALUES (?, ?, ?)', [$aluno_id, $data, uid()]);
    registrar_log('falta', 'Falta em ' . data_br($data), $aluno_id);
    return ['success', 'Falta de ' . data_br($data) . ' registrada.'];
}

/* ------------------------------------------------------------------ */
/*  Notas                                                              */
/* ------------------------------------------------------------------ */
/** Boletim: [disciplina_id => ['nome'=>..., 'tipo'=>..., 'b'=>[1=>nota,...], 'media'=>x]] */
function boletim_aluno(int $aluno_id, int $ano = ANO_VER): array
{
    $linhas = q_todos('SELECT n.disciplina_id, n.bimestre, n.nota, d.nome, d.tipo
                         FROM notas n JOIN disciplinas d ON d.id = n.disciplina_id
                        WHERE n.aluno_id = ? AND n.ano_letivo = ?
                        ORDER BY d.tipo, d.nome', [$aluno_id, $ano]);
    $b = [];
    foreach ($linhas as $l) {
        $b[$l['disciplina_id']]['nome'] = $l['nome'];
        $b[$l['disciplina_id']]['tipo'] = $l['tipo'];
        $b[$l['disciplina_id']]['b'][(int)$l['bimestre']] = (float)$l['nota'];
    }
    foreach ($b as &$d) $d['media'] = round(array_sum($d['b']) / count($d['b']), 1);
    return $b;
}

function nota_fmt($n): string
{
    if ($n === null || $n === '') return '<span class="text-secondary">—</span>';
    $cls = (float)$n < MEDIA_APROVACAO ? 'nota-abaixo' : 'nota-ok';
    return '<span class="' . $cls . '">' . num($n, 1) . '</span>';
}
