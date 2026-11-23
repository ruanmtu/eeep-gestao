<?php
/* =====================================================================
 *  Nota conceitual (v59)
 *  Cada professor avalia, em cada turma e disciplina atribuída, os alunos em 3 aspectos.
 *  Os valores máximos somam 10 (padrão: pedagógicos 2, disciplinares 3, atitudinais 5).
 *  Nota da disciplina = soma dos 3 aspectos.
 *  Média conceitual do aluno (vai para o boletim) = média das notas de todas as disciplinas da turma.
 *  A média só é mostrada quando TODAS as disciplinas atribuídas à turma tiverem a nota de TODOS os alunos.
 * ===================================================================== */

const NC_CRITERIOS_PADRAO = [
    ['nome' => 'Aspectos pedagógicos', 'max' => 2, 'cor' => 'azul', 'icone' => 'lightbulb',
     'itens' => [['Desenvolvimento cognitivo', 'Avaliação do nível de compreensão, raciocínio e habilidades cognitivas do aluno em relação aos conteúdos abordados em sala de aula.'],
                 ['Participação e interesse', 'Observação do envolvimento e da participação do aluno nas atividades de sala de aula, sua disposição para aprender, fazer perguntas e contribuir para discussões.']]],
    ['nome' => 'Aspectos disciplinares', 'max' => 3, 'cor' => 'laranja', 'icone' => 'shield-check',
     'itens' => [['Conduta disciplinar', 'Comportamento do aluno em relação às regras, normas e regulamentos estabelecidos pela escola. Respeito aos professores, aos colegas, cumprimento de todos os horários, comportamento em sala de aula.'],
                 ['Relacionamento interpessoal', 'Observação da capacidade do aluno de se relacionar de forma adequada com seus colegas, professores e gestores.']]],
    ['nome' => 'Aspectos atitudinais', 'max' => 5, 'cor' => 'verde', 'icone' => 'rocket-takeoff',
     'itens' => [['Comprometimento', 'Avaliação do grau de dedicação e engajamento do aluno em suas atividades e demais responsabilidades, incluindo sua pontualidade, assiduidade e disposição para colaborar.'],
                 ['Proatividade', 'Análise da iniciativa e da capacidade do aluno de tomar iniciativas e resolver atividades de forma independente.']]],
];

/** Os 3 critérios em uso (o diretor pode renomear e ajustar os valores, sempre somando 10) */
function nc_criterios(): array
{
    $c = NC_CRITERIOS_PADRAO;
    $j = config_ler('nota_conceitual_criterios');
    $v = $j ? json_decode($j, true) : null;
    if (is_array($v) && count($v) === 3) {
        foreach ($v as $i => $x) {
            if (!empty($x['nome'])) $c[$i]['nome'] = (string)$x['nome'];
            if (isset($x['max']) && is_numeric($x['max'])) $c[$i]['max'] = (float)$x['max'];
            if (isset($x['itens']) && is_array($x['itens'])) $c[$i]['itens'] = $x['itens'];
        }
    }
    return $c;
}

function nc_num(?float $v, int $casas = 1): string { return $v === null ? '—' : number_format($v, $casas, ',', ''); }

/** Turmas em que a pessoa pode lançar (professor: as atribuídas; gestão: todas) */
function nc_turmas(): array { return turmas_do_usuario(); }

/** Disciplinas da turma que contam para a média: as atribuídas a algum professor, com os nomes dos professores */
function nc_disciplinas_turma(int $turma_id): array
{
    $rs = q_todos('SELECT d.id, d.nome, u.id AS uid, u.nome AS professor, u.nome_exibicao
                     FROM atribuicoes a JOIN disciplinas d ON d.id = a.disciplina_id JOIN usuarios u ON u.id = a.usuario_id
                    WHERE a.turma_id = ? AND u.ativo = 1 ORDER BY d.nome, u.nome', [$turma_id]);
    $out = [];
    foreach ($rs as $r) {
        $out[(int)$r['id']] ??= ['id' => (int)$r['id'], 'nome' => $r['nome'], 'professores' => []];
        $out[(int)$r['id']]['professores'][(int)$r['uid']] = trim((string)$r['nome_exibicao']) !== '' ? $r['nome_exibicao'] : primeiro_ultimo(nome_proprio($r['professor']));
    }
    return array_values($out);
}

function nc_alunos(int $turma_id): array
{
    $al = q_todos("SELECT id, nome, foto FROM alunos WHERE id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) ORDER BY nome", [$turma_id]);
    if (class_exists('Collator')) { $col = new Collator('pt_BR'); usort($al, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); }
    return $al;
}

/** Notas de uma turma no bimestre: [aluno_id][disciplina_id] => linha */
function nc_notas_turma(int $turma_id, int $bimestre, ?int $disciplina_id = null): array
{
    $sql = 'SELECT n.* FROM nota_conceitual n JOIN alunos a ON a.id = n.aluno_id
             WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?) AND n.ano_letivo = ? AND n.bimestre = ?';
    $p = [$turma_id, ANO_VER, $bimestre];
    if ($disciplina_id) { $sql .= ' AND n.disciplina_id = ?'; $p[] = $disciplina_id; }
    $out = [];
    foreach (q_todos($sql, $p) as $r) $out[(int)$r['aluno_id']][(int)$r['disciplina_id']] = $r;
    return $out;
}

/**
 * Situação da turma no bimestre.
 * fechada = todas as disciplinas atribuídas têm a nota de todos os alunos ativos.
 * pendentes = disciplinas que faltam, com os professores e quantos alunos faltam.
 */
function nc_situacao(int $turma_id, int $bimestre): array
{
    $disc = nc_disciplinas_turma($turma_id);
    $alunos = nc_alunos($turma_id);
    $notas = nc_notas_turma($turma_id, $bimestre);
    $pend = [];
    foreach ($disc as $d) {
        $faltam = 0;
        foreach ($alunos as $a) if (!isset($notas[(int)$a['id']][$d['id']])) $faltam++;
        if ($faltam) $pend[] = $d + ['faltam' => $faltam];
    }
    $medias = [];
    foreach ($alunos as $a) {
        $v = [];
        foreach ($disc as $d) if (isset($notas[(int)$a['id']][$d['id']])) $v[] = (float)$notas[(int)$a['id']][$d['id']]['total'];
        $medias[(int)$a['id']] = $v ? array_sum($v) / count($v) : null;
    }
    return [
        'disciplinas' => $disc, 'alunos' => $alunos, 'notas' => $notas, 'pendentes' => $pend, 'medias' => $medias,
        'fechada' => $disc && $alunos && !$pend,
        'feitas' => count($disc) - count($pend),
    ];
}

/** Grava as notas do professor numa turma/bimestre. A mesma nota vale para TODAS as disciplinas dele na turma.
 *  $disciplinas = ids; $linhas = [aluno_id => [c1, c2, c3]] (strings). Retorna [salvas, apagadas, erros[]] */
function nc_salvar(int $turma_id, array $disciplinas, int $bimestre, array $linhas): array
{
    $disciplinas = array_values(array_unique(array_map('intval', $disciplinas)));
    $crit = nc_criterios();
    $ids = array_map('intval', array_column(nc_alunos($turma_id), 'id'));
    $nomes = array_column(nc_alunos($turma_id), 'nome', 'id');
    $salvas = $apagadas = 0; $erros = []; $incompletos = [];
    $agora = date('Y-m-d H:i:s');
    db()->beginTransaction();
    try {
        foreach ($linhas as $aid => $v) {
            $aid = (int)$aid;
            if (!in_array($aid, $ids, true) || !is_array($v)) continue;
            $vals = [];
            foreach ([0, 1, 2] as $i) {
                $t = trim(str_replace(',', '.', (string)($v[$i] ?? '')));
                $vals[$i] = $t === '' ? null : (is_numeric($t) ? round((float)$t, 2) : false);
            }
            $vazias = count(array_filter($vals, fn($x) => $x === null));
            if ($vazias === 3) {
                $ap = 0;
                foreach ($disciplinas as $disciplina_id) $ap += q('DELETE FROM nota_conceitual WHERE aluno_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ?', [$aid, $disciplina_id, ANO_VER, $bimestre])->rowCount();
                if ($ap) $apagadas++;
                continue;
            }
            $nome = primeiro_ultimo(nome_proprio((string)($nomes[$aid] ?? '')));
            if ($vazias) { $incompletos[] = $nome; continue; }
            $ok = true;
            foreach ($vals as $i => $x) if ($x === false || $x < 0 || $x > (float)$crit[$i]['max']) { $ok = false; $erros[] = $nome . ': ' . $crit[$i]['nome'] . ' vai de 0 a ' . nc_num((float)$crit[$i]['max']); break; }
            if (!$ok) continue;
            $total = round(array_sum($vals), 2);
            foreach ($disciplinas as $disciplina_id) {
            $ja = q_valor('SELECT id FROM nota_conceitual WHERE aluno_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ?', [$aid, $disciplina_id, ANO_VER, $bimestre]);
            if ($ja) q('UPDATE nota_conceitual SET c1 = ?, c2 = ?, c3 = ?, total = ?, turma_id = ?, lancado_por = ?, atualizado_em = ? WHERE id = ?', [$vals[0], $vals[1], $vals[2], $total, $turma_id, uid(), $agora, $ja]);
            else q('INSERT INTO nota_conceitual (aluno_id, turma_id, disciplina_id, ano_letivo, bimestre, c1, c2, c3, total, lancado_por, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                   [$aid, $turma_id, $disciplina_id, ANO_VER, $bimestre, $vals[0], $vals[1], $vals[2], $total, uid(), $agora]);
            }
            $salvas++;
        }
        db()->commit();
        if ($incompletos) array_unshift($erros, count($incompletos) . ' aluno' . (count($incompletos) > 1 ? 's' : '') . ' sem os 3 aspectos preenchidos (' . implode(', ', array_slice($incompletos, 0, 3)) . (count($incompletos) > 3 ? '…' : '') . ')');
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return [$salvas, $apagadas, $erros];
}

/** Média conceitual de um aluno no bimestre (para a ficha/boletim) — só quando a turma está fechada */
function nc_media_aluno(int $aluno_id, int $turma_id, int $bimestre): ?float
{
    try { $s = nc_situacao($turma_id, $bimestre); } catch (PDOException $e) { return null; }
    return $s['fechada'] ? ($s['medias'][$aluno_id] ?? null) : null;
}

/**
 * Atualização automática da v59 (roda uma vez só, sozinha, mesmo se o .sql não tiver sido importado):
 * cria as tabelas novas, as colunas novas e ajusta as permissões dos perfis personalizados
 * (nota conceitual para professores e coordenação; notas/planilha do Power BI só com o diretor).
 */
function nc_migrar_auto(): void
{
    nc_migrar_v61();
    nc_migrar_v63();
    try {
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        if (config_ler('migracao_v59')) return;
        $pdo = db();
        $sql = (string)@file_get_contents(__DIR__ . '/banco_escola.sql');
        foreach (['nota_conceitual', 'site_ambientes'] as $tab)
            if (preg_match('/CREATE TABLE IF NOT EXISTS ' . $tab . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) $pdo->exec($m[0]);
        $tem = fn(string $t, string $c) => (bool)q_valor('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
        if (!$tem('alunos', 'tem_obs_medica')) $pdo->exec('ALTER TABLE alunos ADD COLUMN tem_obs_medica TINYINT(1) NOT NULL DEFAULT 0');
        if (!$tem('alunos', 'obs_medica')) $pdo->exec('ALTER TABLE alunos ADD COLUMN obs_medica TEXT NULL');
        if (!$tem('equipe_site', 'formacao')) $pdo->exec('ALTER TABLE equipe_site ADD COLUMN formacao VARCHAR(255) NULL');
        if (!$tem('equipe_site', 'biografia')) $pdo->exec('ALTER TABLE equipe_site ADD COLUMN biografia TEXT NULL');
        $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
        foreach (['diretor', 'coordenacao', 'professor'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'nota_conceitual']);
        foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'nota_conceitual.gerir']);
        $pdo->exec("DELETE FROM perfil_permissoes WHERE permissao IN ('notas', 'notas.excluir', 'importar') AND perfil <> 'diretor'");
        config_gravar('migracao_v59', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* sem acesso para alterar o banco: fica para o verificar.php / .sql */ }
}

/**
 * v61 · Perfis criados ou personalizados no painel (ex.: "Desenvolvedor Web") não recebem permissões novas sozinhos.
 * Quem trabalha com turmas (faltas, provas, alunos ou todas as turmas) passa a ter a Nota conceitual;
 * quem vê todas as turmas também pode gerenciar (ver o andamento de todas e definir os critérios). Roda uma vez só.
 */
function nc_migrar_v61(): void
{
    try {
        if (config_ler('migracao_v61')) return;
        $pers = array_column(q_todos('SELECT chave FROM perfis WHERE personalizado = 1'), 'chave');
        foreach ($pers as $pf) {
            $tem = array_flip(array_column(q_todos('SELECT permissao FROM perfil_permissoes WHERE perfil = ?', [$pf]), 'permissao'));
            $add = [];
            if (array_intersect_key($tem, array_flip(['frequencia', 'provas', 'turmas.todas', 'notas', 'alunos.ver']))) $add[] = 'nota_conceitual';
            if (isset($tem['turmas.todas'])) $add[] = 'nota_conceitual.gerir';
            foreach ($add as $perm) if (!isset($tem[$perm])) q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$pf, $perm]);
        }
        config_gravar('migracao_v61', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* tabela de perfis ainda não existe: tenta de novo no próximo acesso */ }
}

/** Professores atribuídos à turma, cada um com as disciplinas que leciona nela: [uid => ['nome' =>, 'disciplinas' => [id => nome]]] */
function nc_professores_turma(int $turma_id): array
{
    $out = [];
    foreach (q_todos('SELECT u.id, u.nome, u.nome_exibicao, d.id AS did, d.nome AS disc FROM atribuicoes a JOIN usuarios u ON u.id = a.usuario_id JOIN disciplinas d ON d.id = a.disciplina_id
                       WHERE a.turma_id = ? AND u.ativo = 1 ORDER BY u.nome, d.nome', [$turma_id]) as $r) {
        $out[(int)$r['id']] ??= ['nome' => trim((string)$r['nome_exibicao']) !== '' ? $r['nome_exibicao'] : primeiro_ultimo(nome_proprio($r['nome'])), 'disciplinas' => []];
        $out[(int)$r['id']]['disciplinas'][(int)$r['did']] = $r['disc'];
    }
    return $out;
}

/** O bimestre ainda pode ser editado? Professor: só o período em andamento. Gestão: qualquer um. */
function nc_pode_editar(int $bimestre): bool
{
    if (pode('nota_conceitual.gerir')) return true;
    return (int)(periodo_atual()['bimestre'] ?? 0) === $bimestre;
}

/** v63 · "Notas" voltou como consulta (só leitura): quem tem a Nota conceitual também passa a ver as notas. Roda uma vez só. */
function nc_migrar_v63(): void
{
    try {
        if (config_ler('migracao_v63')) return;
        foreach (array_column(q_todos('SELECT chave FROM perfis WHERE personalizado = 1'), 'chave') as $pf) {
            $tem = array_flip(array_column(q_todos('SELECT permissao FROM perfil_permissoes WHERE perfil = ?', [$pf]), 'permissao'));
            if ((isset($tem['nota_conceitual']) || isset($tem['frequencia']) || isset($tem['turmas.todas'])) && !isset($tem['notas'])) q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$pf, 'notas']);
        }
        config_gravar('migracao_v63', date('Y-m-d H:i:s'));
    } catch (Throwable $e) {}
}
