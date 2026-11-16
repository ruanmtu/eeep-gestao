<?php
/* =====================================================================
 *  Frequência: semanas, rankings por turma, ordem do almoço e o alerta
 *  de 3 faltas seguidas.
 * ===================================================================== */

const FALTAS_SEGUIDAS_ALERTA = 3;

/** Segunda e sexta da semana de uma data (padrão: hoje) */
function semana_de(?string $data = null): array
{
    $t = strtotime($data ?: date('Y-m-d'));
    $dow = (int)date('N', $t);                       // 1 = segunda ... 7 = domingo
    if ($dow >= 6) $t = strtotime('+' . (8 - $dow) . ' days', $t); // fim de semana: próxima semana
    $seg = date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
    return [$seg, date('Y-m-d', strtotime($seg . ' +4 days'))];
}

function semana_rotulo(string $seg, string $sex): string
{
    return date('d/m', strtotime($seg)) . ' a ' . date('d/m/Y', strtotime($sex));
}

/** Seletor de semana: devolve [segunda, sexta, valor do parâmetro] a partir de ?semana=AAAA-MM-DD */
function semana_param(string $nome = 'semana'): array
{
    $v = (string)($_GET[$nome] ?? '');
    [$seg, $sex] = semana_de(data_valida($v) ? $v : null);
    return [$seg, $sex];
}

/**
 * Ranking de faltas por turma num período.
 * Cada item: turma (id, serie, letra, curso, sigla), alunos (ativos), faltas, faltosos, detalhes[aluno => [nome, datas[]]]
 */
function ranking_faltas(string $ini, string $fim): array
{
    $turmas = [];
    foreach (q_todos("SELECT t.id, t.serie, t.letra, c.nome AS curso, c.sigla,
                             (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id AND a.situacao = 'ativo') AS alunos
                        FROM turmas t JOIN cursos c ON c.id = t.curso_id
                       WHERE t.ano_letivo = ? AND t.ativo = 1 ORDER BY t.serie, t.letra", [ANO_LETIVO]) as $t) {
        $turmas[$t['id']] = $t + ['faltas' => 0, 'faltosos' => 0, 'detalhes' => []];
    }
    foreach (q_todos('SELECT a.turma_id, a.id, a.nome, f.data_falta FROM faltas f JOIN alunos a ON a.id = f.aluno_id
                       WHERE f.data_falta BETWEEN ? AND ? ORDER BY a.nome, f.data_falta', [$ini, $fim]) as $r) {
        if (!isset($turmas[$r['turma_id']])) continue;
        $t = &$turmas[$r['turma_id']];
        $t['faltas']++;
        $t['detalhes'][$r['id']]['nome'] = $r['nome'];
        $t['detalhes'][$r['id']]['datas'][] = $r['data_falta'];
        unset($t);
    }
    foreach ($turmas as &$t) $t['faltosos'] = count($t['detalhes']);
    unset($t);
    return array_values($turmas);
}

/** Ordem de liberação para o almoço: menos faltas sai primeiro (empate: menos alunos faltosos) */
function ordem_almoco(array $ranking): array
{
    usort($ranking, fn($a, $b) => [$a['faltas'], $a['faltosos'], $a['serie'], $a['letra']] <=> [$b['faltas'], $b['faltosos'], $b['serie'], $b['letra']]);
    return $ranking;
}

/**
 * Ordem do almoço de uma semana: vale o resultado das faltas da SEMANA ANTERIOR
 * (segunda a sexta). A coleta termina na sexta e o resultado vira a ordem da semana seguinte.
 * Devolve [ordem, segunda da semana-base, sexta da semana-base].
 */
function ordem_almoco_semana(string $seg): array
{
    $bseg = date('Y-m-d', strtotime($seg . ' -7 days'));
    $bsex = date('Y-m-d', strtotime($bseg . ' +4 days'));
    return [ordem_almoco(ranking_faltas($bseg, $bsex)), $bseg, $bsex];
}

/** Datas de falta em texto curto: "seg 20/10, ter 21/10" */
function dias_curtos(array $datas): string
{
    $abrev = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    return implode(', ', array_map(fn($d) => $abrev[(int)date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d)), $datas));
}

/**
 * Alunos com 3 (ou mais) faltas SEGUIDAS em dias letivos.
 * Dias letivos = dias de semana em que houve coleta (alguma falta lançada na escola),
 * assim feriados e fins de semana não quebram nem criam sequências.
 * Se $so_pendentes, esconde as sequências que a gestão já marcou como tratadas.
 */
function alertas_sequencia(bool $so_pendentes = true, int $janela_dias = 45): array
{
    static $cache = [];
    $chave = (int)$so_pendentes . '-' . $janela_dias;
    if (isset($cache[$chave])) return $cache[$chave];

    $ini = date('Y-m-d', strtotime("-$janela_dias days"));
    $hoje = date('Y-m-d');
    try {
        $dias = array_column(q_todos('SELECT DISTINCT data_falta FROM faltas WHERE data_falta BETWEEN ? AND ? ORDER BY data_falta', [$ini, $hoje]), 'data_falta');
        $dias = array_values(array_filter($dias, fn($d) => (int)date('N', strtotime($d)) <= 5));
        if (count($dias) < FALTAS_SEGUIDAS_ALERTA) return $cache[$chave] = [];
        $pos = array_flip($dias);
        $ultimo_dia = end($dias);

        $por_aluno = [];
        foreach (q_todos("SELECT f.aluno_id, f.data_falta FROM faltas f JOIN alunos a ON a.id = f.aluno_id
                           WHERE a.situacao = 'ativo' AND f.data_falta BETWEEN ? AND ? ORDER BY f.aluno_id, f.data_falta", [$ini, $hoje]) as $r) {
            if (isset($pos[$r['data_falta']])) $por_aluno[$r['aluno_id']][] = $r['data_falta'];
        }

        $tratados = [];
        if ($so_pendentes) {
            foreach (q_todos('SELECT aluno_id, sequencia_fim FROM alertas_contato WHERE sequencia_fim >= ?', [$ini]) as $r) {
                $tratados[$r['aluno_id'] . '|' . $r['sequencia_fim']] = true;
            }
        }

        $alertas = [];
        foreach ($por_aluno as $aluno_id => $datas) {
            $seq = [];
            $melhor = null;
            foreach ($datas as $d) {
                if ($seq && $pos[$d] === $pos[end($seq)] + 1) $seq[] = $d;
                else $seq = [$d];
                if (count($seq) >= FALTAS_SEGUIDAS_ALERTA) $melhor = $seq;   // guarda a sequência mais recente
            }
            if (!$melhor) continue;
            $fim = end($melhor);
            if (isset($tratados[$aluno_id . '|' . $fim])) continue;
            $alertas[$aluno_id] = ['aluno_id' => (int)$aluno_id, 'dias' => $melhor, 'fim' => $fim, 'ativa' => $fim === $ultimo_dia];
        }
        if (!$alertas) return $cache[$chave] = [];

        $ids = implode(',', array_map('intval', array_keys($alertas)));
        foreach (q_todos("SELECT a.id, a.nome, a.responsavel_nome, a.responsavel_telefone, a.telefone, t.serie, t.letra, c.nome AS curso
                            FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                           WHERE a.id IN ($ids)") as $a) {
            $alertas[$a['id']] += $a;
        }
        $lista = array_values($alertas);
        usort($lista, fn($x, $y) => [$y['ativa'], count($y['dias']), $y['fim']] <=> [$x['ativa'], count($x['dias']), $x['fim']]);
        return $cache[$chave] = $lista;
    } catch (PDOException $e) {
        return $cache[$chave] = [];   // banco ainda não atualizado: não derruba o sistema
    }
}

/** Sequência atual de um aluno (para a ficha) */
function alerta_do_aluno(int $aluno_id): ?array
{
    foreach (alertas_sequencia(true) as $a) if ($a['aluno_id'] === $aluno_id) return $a;
    return null;
}

function link_whatsapp(?string $tel, string $texto = ''): string
{
    $n = preg_replace('/\D/', '', (string)$tel);
    if (strlen($n) < 10) return '';
    if (strlen($n) <= 11) $n = '55' . $n;
    return 'https://wa.me/' . $n . ($texto ? '?text=' . rawurlencode($texto) : '');
}
