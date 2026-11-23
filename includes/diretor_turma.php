<?php
/* =====================================================================
 *  Professor(a) diretor(a) de turma
 *  O DT é definido em "Cursos e turmas › Turmas". Ele acompanha a turma
 *  dele como a gestão acompanha a escola (frequência, notas, ocorrências,
 *  busca ativa, autorizações), mas não exclui nada: excluir é da gestão.
 * ===================================================================== */

/** Turmas (do ano letivo) em que o usuário é diretor de turma */
function turmas_dirigidas(?int $uid = null, ?int $ano = null): array
{
    static $cache = [];
    $uid ??= uid(); $ano ??= ANO_VER;
    if (isset($cache["$uid.$ano"])) return $cache["$uid.$ano"];
    try {
        return $cache["$uid.$ano"] = q_todos('SELECT t.*, c.nome AS curso, c.sigla FROM turmas t JOIN cursos c ON c.id = t.curso_id
                                        WHERE t.diretor_turma_id = ? AND t.ano_letivo = ? AND t.ativo = 1 ORDER BY t.serie, t.letra', [$uid, $ano]);
    } catch (PDOException $e) { return $cache["$uid.$ano"] = []; }
}

function eh_diretor_turma(): bool { return (bool)turmas_dirigidas_ids(); }
/** Ids das turmas que a pessoa dirige no ano que está vendo e no ano atual (para permissões) */
function turmas_dirigidas_ids(?int $uid = null): array
{
    $ids = array_column(turmas_dirigidas($uid), 'id');
    if (vendo_outro_ano()) $ids = array_merge($ids, array_column(turmas_dirigidas($uid, ano_hoje()), 'id'));
    return array_values(array_unique(array_map('intval', $ids)));
}

/** Gestão vê todas as turmas; o DT, só as dele */
function eh_gestao(): bool { return pode('turmas.todas'); }

function pode_painel_turma(int $turma_id): bool
{
    if (eh_gestao()) return true;
    return in_array($turma_id, turmas_dirigidas_ids(), true);
}

/** O usuário é DT da turma deste aluno? */
function dt_do_aluno(array $aluno): bool
{
    return !empty($aluno['turma_id']) && in_array((int)$aluno['turma_id'], turmas_dirigidas_ids(), true);
}

/** Números da turma para o painel do DT */
function turma_resumo(int $tid): array
{
    $hoje = date('Y-m-d'); [$seg, $sex] = semana_de();
    $v = fn($sql, $p) => (function () use ($sql, $p) { try { return (float)q_valor($sql, $p); } catch (PDOException $e) { return 0; } })();
    $ids = array_map('intval', array_column(q_todos("SELECT id FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido'))", [$tid]), 'id'));
    $alerta = array_values(array_filter(alertas_sequencia(true), fn($a) => in_array((int)$a['aluno_id'], $ids, true)));
    return [
        'alunos'       => count($ids),
        'faltas_hoje'  => (int)$v('SELECT COUNT(*) FROM faltas f JOIN alunos a ON a.id = f.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND f.data_falta = ?', [$tid, $hoje]),
        'faltas_sem'   => (int)$v('SELECT COUNT(*) FROM faltas f JOIN alunos a ON a.id = f.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND f.data_falta BETWEEN ? AND ?', [$tid, $seg, $sex]),
        'faltas_mes'   => (int)$v('SELECT COUNT(*) FROM faltas f JOIN alunos a ON a.id = f.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND f.data_falta >= ?', [$tid, date('Y-m-01')]),
        'alertas'      => $alerta,
        'oc_hoje'      => (int)$v('SELECT COUNT(*) FROM ocorrencias o JOIN alunos a ON a.id = o.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND o.data_ocorrencia = ?', [$tid, $hoje]),
        'oc_mes'       => (int)$v('SELECT COUNT(*) FROM ocorrencias o JOIN alunos a ON a.id = o.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND o.data_ocorrencia >= ?', [$tid, date('Y-m-01')]),
        'media'        => $v('SELECT AVG(n.nota) FROM notas n JOIN alunos a ON a.id = n.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND n.ano_letivo = ?', [$tid, ANO_VER]),
        'busca_mes'    => (int)$v('SELECT COUNT(*) FROM busca_ativa b JOIN alunos a ON a.id = b.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND b.data_acao >= ?', [$tid, date('Y-m-01')]),
        'saidas_mes'   => (int)$v("SELECT COUNT(*) FROM saidas s JOIN alunos a ON a.id = s.aluno_id WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND s.tipo = 'saida' AND s.status = 'autorizada' AND s.data_hora >= ?", [$tid, date('Y-m-01') . ' 00:00:00']),
    ];
}

/** Cartão do painel inicial para o professor diretor de turma */
function cartao_minha_turma_resumo(array $turmas): string
{
    ob_start(); ?>
    <div class="card h-100 cartao-dt">
        <div class="card-header"><h2><i class="bi bi-person-workspace me-1"></i>Minha turma</h2><span class="etiqueta">diretor(a) de turma</span>
            <a class="btn btn-sm btn-primary ms-auto" href="minha_turma.php">Abrir painel</a></div>
        <ul class="dt-resumo">
        <?php foreach ($turmas as $t): $r = turma_resumo((int)$t['id']); ?>
            <li><a href="minha_turma.php?turma=<?= (int)$t['id'] ?>" class="dt-turma"><strong><?= e($t['serie'] . 'º Ano ' . $t['letra']) ?></strong><small><?= e($t['curso']) ?> · <?= $r['alunos'] ?> alunos</small></a>
                <div class="dt-nums">
                    <span><b><?= $r['faltas_hoje'] ?></b>faltas hoje</span>
                    <span class="<?= $r['alertas'] ? 'ruim' : '' ?>"><b><?= count($r['alertas']) ?></b>em alerta</span>
                    <span class="<?= $r['oc_hoje'] ? 'ruim' : '' ?>"><b><?= $r['oc_hoje'] ?></b>ocorrências hoje</span>
                    <span><b><?= $r['media'] ? num($r['media'], 1) : '–' ?></b>média</span>
                </div></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php return ob_get_clean();
}
