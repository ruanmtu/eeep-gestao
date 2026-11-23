<?php
/* ======================================================================
 *  v75 — Ano letivo
 *  aluno_turmas guarda em que turma o aluno estava em cada ano letivo.
 *  Assim, quando os alunos mudam de turma na virada do ano, as turmas
 *  dos anos anteriores continuam mostrando quem estudou nelas (notas,
 *  faltas, ocorrências, resultados...).
 *  - Ano atual e seguintes: acompanham a turma/situação do cadastro.
 *  - Anos que já passaram: ficam congelados como estavam.
 * ====================================================================== */

function v75_migrar(): void
{
    if (config_ler('migracao_v75')) return;
    try {
        $pdo = db();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $int = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS aluno_turmas (aluno_id $int NOT NULL, ano_letivo SMALLINT NOT NULL, turma_id $int NOT NULL,
                    situacao VARCHAR(20) NOT NULL DEFAULT 'ativo', PRIMARY KEY (aluno_id, ano_letivo))$fim");
        try { $pdo->exec('CREATE INDEX idx_aluno_turmas_turma ON aluno_turmas (turma_id)'); } catch (Throwable $e) {}
        aluno_turmas_sincronizar(ANO_INICIAL);
        config_gravar('migracao_v75', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* tenta de novo no próximo acesso */ }
}

/** Copia a turma atual de cada aluno para o histórico (do ano $desde em diante) */
function aluno_turmas_sincronizar(?int $desde = null): void
{
    $desde ??= ano_hoje();
    try {
        $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        q('INSERT INTO aluno_turmas (aluno_id, ano_letivo, turma_id, situacao)
           SELECT x.aid, x.ano, x.tid, x.sit FROM (SELECT a.id AS aid, t.ano_letivo AS ano, a.turma_id AS tid, a.situacao AS sit
                                                     FROM alunos a JOIN turmas t ON t.id = a.turma_id WHERE t.ano_letivo >= ?) x WHERE 1 = 1 '
          . ($mysql ? 'ON DUPLICATE KEY UPDATE aluno_turmas.turma_id = VALUES(turma_id), aluno_turmas.situacao = VALUES(situacao)'
                    : 'ON CONFLICT (aluno_id, ano_letivo) DO UPDATE SET turma_id = excluded.turma_id, situacao = excluded.situacao'), [$desde]);
        // quem saiu da turma (sem turma no cadastro) ou mudou de situação também é atualizado
        q('UPDATE aluno_turmas SET situacao = COALESCE((SELECT a.situacao FROM alunos a WHERE a.id = aluno_turmas.aluno_id), situacao) WHERE ano_letivo >= ?', [$desde]);
        q('DELETE FROM aluno_turmas WHERE ano_letivo >= ? AND NOT EXISTS (SELECT 1 FROM alunos a WHERE a.id = aluno_turmas.aluno_id)', [$desde]);
        $_SESSION['at_sinc'] = time();
    } catch (Throwable $e) {}
}

/** Sincroniza de vez em quando (a cada 5 min por sessão), para pegar mudanças feitas por qualquer caminho */
function aluno_turmas_talvez(): void
{
    if (!config_ler('migracao_v75')) return;
    if (time() - (int)($_SESSION['at_sinc'] ?? 0) > 300) aluno_turmas_sincronizar();
}
