<?php
/* Respostas rápidas em JSON para as telas do painel (busca de alunos, coleta de faltas) */
require __DIR__ . '/../includes/app.php';

header('Content-Type: application/json; charset=utf-8');
function responder(array $dados, int $status = 200): void { http_response_code($status); echo json_encode($dados, JSON_UNESCAPED_UNICODE); exit; }

if (!usuario()) responder(['ok' => false, 'msg' => 'Sua sessão expirou. Recarregue a página e entre de novo.'], 401);
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
if ($acao !== 'notif') $_SESSION['ultimo_uso'] = time();     // a consulta automática do sino não conta como uso

if (eh_post()) {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), (string)$t)) responder(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.'], 400);
}

switch ($acao) {
    /* Envio de livro didático em partes */
    case 'livro_parte':
        if (!pode('livros_didaticos.gerenciar')) responder(['ok' => false, 'msg' => 'Sem permissão.'], 403);
        try {
            responder(livro_receber_parte($_FILES['pedaco'] ?? [], (string)($_POST['token'] ?? ''), (int)($_POST['parte'] ?? -1), (int)($_POST['total'] ?? 0),
                (string)($_POST['nome'] ?? ''), (int)($_POST['tamanho'] ?? 0)));
        } catch (RuntimeException $e) { responder(['ok' => false, 'msg' => $e->getMessage()]); }

    /* Sino de notificações (consultado a cada poucos segundos) */
    case 'notif':
        lembretes_disparar(uid());
        $itens = [];
        foreach (notificacoes_lista((int)($_GET['limite'] ?? 8)) as $n) $itens[] = ['id' => (int)$n['id'], 'titulo' => $n['titulo'],
            'mensagem' => mb_strimwidth((string)$n['mensagem'], 0, 140, '…'), 'tipo' => $n['tipo'], 'icone' => TIPOS_NOTIFICACAO[$n['tipo']][1] ?? 'bell',
            'quando' => quando($n['criado_em']), 'lida' => (bool)$n['lida_em'], 'link' => $n['link'], 'importante' => (bool)$n['importante'], 'autor' => primeiro_ultimo((string)$n['autor_nome'])];
        responder(['ok' => true, 'nao_lidas' => notificacoes_nao_lidas(), 'pendentes' => pode('saidas.autorizar') ? saidas_pendentes() : null, 'itens' => $itens]);

    /* Agenda do professor: salva a página do dia (só do próprio usuário) */
    case 'agenda_salvar':
        $d = (string)($_POST['data'] ?? '');
        if (!data_valida($d)) responder(['ok' => false, 'msg' => 'Data inválida.'], 400);
        if (strlen((string)($_POST['conteudo'] ?? '')) > 2000000) responder(['ok' => false, 'msg' => 'Anotação grande demais.'], 400);
        try { responder(agenda_salvar(uid(), $d, (string)($_POST['conteudo'] ?? ''))); }
        catch (PDOException $e) { responder(['ok' => false, 'msg' => 'Não foi possível salvar. Rode a atualização do banco (verificar.php).'], 500); }

    /* Painel de resultados de uma turma (painel inicial da gestão) */
    case 'resultados':
        if (!eh_gestao() && !pode('relatorios')) responder(['ok' => false], 403);
        $tid = (int)($_GET['turma'] ?? 0); $bim = max(0, min(4, (int)($_GET['bim'] ?? 0)));
        if (!q_valor('SELECT id FROM turmas WHERE id = ?', [$tid])) responder(['ok' => false, 'msg' => 'Turma não encontrada.'], 404);
        responder(['ok' => true, 'turma' => $tid, 'bim' => $bim, 'bims' => bimestres_com_nota($tid)] + resultados_turma($tid, $bim));

    /* Assistente de IA */
    case 'ia':
        if (!pode('ia')) responder(['ok' => false, 'texto' => 'Seu perfil não usa o assistente.'], 403);
        $perg = trim(mb_substr((string)($_POST['pergunta'] ?? ''), 0, 1500));
        if (mb_strlen($perg) < 3) responder(['ok' => false, 'texto' => 'Escreva a sua pergunta.']);
        $hist = json_decode((string)($_POST['historico'] ?? '[]'), true); if (!is_array($hist)) $hist = [];
        $agora = date('Y-m-d H:i:s');
        if ($dp = ia_tem_dado_pessoal($perg)) {
            try { q('INSERT INTO ia_uso (usuario_id, pergunta, situacao, criado_em) VALUES (?, ?, ?, ?)', [uid(), '[removido: ' . $dp . ']', 'bloqueada', $agora]); } catch (PDOException $e) {}
            responder(['ok' => true, 'situacao' => 'bloqueada', 'texto' => 'Por segurança, não envio dados pessoais de alunos para a IA (encontrei ' . $dp . ' na pergunta). Troque por "o aluno" ou "a turma" e pergunte de novo.']);
        }
        [$pode, $aviso, $meu, $cota] = ia_pode_perguntar();
        if (!$pode) responder(['ok' => false, 'situacao' => 'cota', 'texto' => $aviso, 'uso' => $meu, 'cota' => $cota]);
        @set_time_limit(90);
        $r = ia_perguntar($perg, $hist);
        try { q('INSERT INTO ia_uso (usuario_id, pergunta, resposta, situacao, criado_em) VALUES (?, ?, ?, ?, ?)', [uid(), $perg, mb_substr($r['texto'], 0, 60000), $r['situacao'], $agora]); } catch (PDOException $e) {}
        responder($r + ['aviso' => $aviso, 'uso' => ia_uso_hoje()[0], 'cota' => $cota]);

    case 'notif_lida':
        if (($_POST['id'] ?? '') === 'todas') notificacoes_marcar_todas(); else notificacao_marcar_lida((int)($_POST['id'] ?? 0));
        responder(['ok' => true, 'nao_lidas' => notificacoes_nao_lidas()]);

    /* Busca de alunos ativos por nome ou matrícula */
    case 'buscar':
        if (!pode('frequencia') && !pode('saidas') && !pode('alunos.ver') && !pode('ocorrencias') && !pode('biblioteca')) responder(['ok' => false], 403);
        $q = trim((string)($_GET['q'] ?? ''));
        $data = data_valida($_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
        if (mb_strlen($q) < 2) responder(['ok' => true, 'alunos' => []]);
        $termos = preg_split('/\s+/', $q);
        $w = []; $p = [];
        foreach ($termos as $t) { $w[] = 'a.nome LIKE ?'; $p[] = '%' . $t . '%'; }
        $linhas = q_todos('SELECT a.id, a.nome, a.matricula, a.foto, t.serie, t.letra, c.nome AS curso,
                                  (SELECT COUNT(*) FROM faltas f WHERE f.aluno_id = a.id AND f.data_falta = ?) AS ja
                             FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                            WHERE a.situacao = \'ativo\' AND ((' . implode(' AND ', $w) . ') OR a.matricula = ?)
                            ORDER BY a.nome LIMIT 12', [$data, ...$p, $q]);
        responder(['ok' => true, 'alunos' => array_map(fn($a) => [
            'id' => (int)$a['id'], 'nome' => nome_proprio($a['nome']), 'turma' => turma_nome($a), 'ja' => (bool)$a['ja'], 'foto' => foto_url('aluno', (int)$a['id'], $a['foto']),
        ], $linhas)]);

    /* Dados do responsável para preencher a liberação de saída */
    case 'aluno_info':
        if (!pode('saidas') && !pode('alunos.ver')) responder(['ok' => false], 403);
        $a = aluno_buscar((int)($_GET['id'] ?? 0));
        if (!$a) responder(['ok' => false, 'msg' => 'Aluno não encontrado.']);
        $saidas_semana = 0;
        try { [$s1, $s2] = semana_de(); $saidas_semana = (int)q_valor("SELECT COUNT(*) FROM saidas WHERE tipo = 'saida' AND status = 'autorizada' AND aluno_id = ? AND data_hora BETWEEN ? AND ?", [$a['id'], "$s1 00:00:00", "$s2 23:59:59"]); } catch (PDOException $e) {}
        responder(['ok' => true, 'aluno' => [
            'id' => (int)$a['id'], 'nome' => nome_proprio($a['nome']), 'turma' => turma_nome($a),
            'responsavel_nome' => $a['responsavel_nome'] ?: ($a['nome_mae'] ?: ''), 'responsavel_parentesco' => $a['responsavel_nome'] ? ($a['responsavel_parentesco'] ?: '') : ($a['nome_mae'] ? 'Mãe' : ''),
            'telefone' => $a['responsavel_telefone'] ?: '', 'saidas_semana' => $saidas_semana, 'foto' => foto_url('aluno', (int)$a['id'], $a['foto'] ?? null),
        ]]);

    /* Lança uma falta pela coleta diária */
    case 'falta_add':
        if (!pode('frequencia')) responder(['ok' => false, 'msg' => 'Sem permissão.'], 403);
        $aluno = aluno_buscar((int)($_POST['aluno_id'] ?? 0));
        $data = (string)($_POST['data'] ?? '');
        if (!$aluno) responder(['ok' => false, 'msg' => 'Aluno não encontrado.']);
        [$tipo, $msg] = falta_registrar((int)$aluno['id'], $data);
        if ($tipo !== 'success') responder(['ok' => false, 'msg' => $msg]);
        $id = (int)q_valor('SELECT id FROM faltas WHERE aluno_id = ? AND data_falta = ?', [$aluno['id'], $data]);
        $seq = alerta_do_aluno((int)$aluno['id']);
        responder(['ok' => true, 'msg' => nome_proprio($aluno['nome']) . ' · falta registrada.', 'falta' => [
            'id' => $id, 'aluno_id' => (int)$aluno['id'], 'nome' => nome_proprio($aluno['nome']),
            'turma_id' => (int)$aluno['turma_id'], 'turma' => turma_nome($aluno),
            'alerta' => $seq ? count($seq['dias']) : 0,
        ]]);

    /* Salva a correção de uma folha lida pela câmera */
    case 'omr_salvar':
        if (!pode('provas')) responder(['ok' => false, 'msg' => 'Sem permissão.'], 403);
        $prova = prova_buscar((int)($_POST['prova_id'] ?? 0));
        if (!$prova || !pode_prova($prova)) responder(['ok' => false, 'msg' => 'Prova não encontrada.']);
        $aluno = aluno_buscar((int)($_POST['aluno_id'] ?? 0));
        if (!$aluno) responder(['ok' => false, 'msg' => 'Escolha o aluno desta folha.']);
        if ((int)$aluno['serie'] !== (int)$prova['serie']) responder(['ok' => false, 'msg' => 'Este aluno não é do ' . $prova['serie'] . 'º ano.']);
        $resp = strtoupper((string)($_POST['respostas'] ?? ''));
        if (strlen($resp) !== (int)$prova['num_questoes'] || !preg_match('/^[A-EM\-]+$/', $resp)) responder(['ok' => false, 'msg' => 'Respostas inválidas.']);
        [$acertos, $nota, $pts] = omr_corrigir($prova, $resp);
        $ex = q_valor('SELECT id FROM prova_resultados WHERE prova_id = ? AND aluno_id = ?', [$prova['id'], $aluno['id']]);
        $pj = json_encode($pts, JSON_UNESCAPED_UNICODE);
        if ($ex) q('UPDATE prova_resultados SET respostas = ?, acertos = ?, nota = ?, partes = ?, corrigido_por = ?, corrigido_em = ? WHERE id = ?', [$resp, $acertos, $nota, $pj, uid(), date('Y-m-d H:i:s'), $ex]);
        else q('INSERT INTO prova_resultados (prova_id, aluno_id, respostas, acertos, nota, partes, corrigido_por, corrigido_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$prova['id'], $aluno['id'], $resp, $acertos, $nota, $pj, uid(), date('Y-m-d H:i:s')]);
        registrar_log('prova_corrigida', $prova['titulo'] . ": $acertos acertos", (int)$aluno['id']);
        responder(['ok' => true, 'msg' => nome_proprio($aluno['nome']) . ': nota ' . num($nota, 2) . " ($acertos de {$prova['num_questoes']}).", 'nota' => $nota, 'acertos' => $acertos, 'partes' => $prova['bloco'] ? $pts : [], 'substituiu' => (bool)$ex]);

    /* Formato de folha de respostas de outro modelo (posição das bolinhas) */
    case 'omr_formato':
        if (!pode('provas')) responder(['ok' => false, 'msg' => 'Sem permissão.'], 403);
        $prova = prova_buscar((int)($_POST['prova_id'] ?? 0));
        if (!$prova || !pode_prova($prova)) responder(['ok' => false, 'msg' => 'Prova não encontrada.']);
        $f = json_decode((string)($_POST['formato'] ?? ''), true);
        $ponto = fn($p) => is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]) && $p[0] >= -0.2 && $p[0] <= 1.2 && $p[1] >= -0.2 && $p[1] <= 1.2
            ? [round((float)$p[0], 5), round((float)$p[1], 5)] : null;
        $F = is_array($f) ? ['colunas' => (int)($f['colunas'] ?? 0), 'por_coluna' => (int)($f['por_coluna'] ?? 0),
            'p1' => $ponto($f['p1'] ?? null), 'p2' => $ponto($f['p2'] ?? null), 'p3' => $ponto($f['p3'] ?? null), 'p4' => $ponto($f['p4'] ?? null)] : null;
        if (!$F || $F['colunas'] < 1 || $F['colunas'] > 6 || $F['por_coluna'] < 1 || $F['por_coluna'] > 60 || in_array(null, $F, true)
            || $F['colunas'] * $F['por_coluna'] < (int)$prova['num_questoes']) responder(['ok' => false, 'msg' => 'Formato inválido: confira colunas e questões por coluna.']);
        q('UPDATE provas SET formato_externo = ? WHERE id = ?', [json_encode($F), $prova['id']]);
        registrar_log('prova_formato', $prova['titulo'] . ': formato de folha externa ajustado');
        responder(['ok' => true]);

    /* Busca de livros do acervo (Centro de Multimeios) */
    /* Reservas: o que está livre numa data e aula(s) */
    case 'disponiveis':
        if (!pode('reservas')) responder(['ok' => false], 403);
        $data = data_valida($_GET['data'] ?? '') ? $_GET['data'] : '';
        $aulas = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['aulas'] ?? ''))), fn($a) => isset(aulas()[$a])));
        if (!$data || !$aulas) responder(['ok' => true, 'recursos' => []]);
        $ocup = [];
        foreach (q_todos('SELECT r.recurso_id, r.aula, u.nome AS professor, u.cargo, r.metodologia, r.descricao, t.serie, t.letra, c.sigla FROM reservas r LEFT JOIN usuarios u ON u.id = r.usuario_id
                           LEFT JOIN turmas t ON t.id = r.turma_id LEFT JOIN cursos c ON c.id = t.curso_id WHERE r.data = ? AND r.aula IN (' . implode(',', $aulas) . ') ORDER BY r.aula', [$data]) as $r) {
            $ocup[(int)$r['recurso_id']][] = ['aula' => (int)$r['aula'], 'hora' => aulas()[(int)$r['aula']][0] ?? '', 'professor' => primeiro_ultimo((string)($r['professor'] ?? '—')), 'iniciais' => iniciais((string)($r['professor'] ?? '?')),
                'cargo' => $r['cargo'], 'metodologia' => $r['metodologia'], 'descricao' => $r['descricao'], 'turma' => $r['serie'] ? $r['serie'] . 'º ' . $r['letra'] . ($r['sigla'] ? ' ' . $r['sigla'] : '') : null];
        }
        responder(['ok' => true, 'recursos' => array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'tipo' => $r['tipo'], 'grupo' => $r['grupo'], 'descricao' => $r['descricao'],
            'livre' => !isset($ocup[(int)$r['id']]), 'ocupado' => $ocup[(int)$r['id']] ?? []], recursos_lista())]);

    case 'livros':
        if (!pode('biblioteca')) responder(['ok' => false], 403);
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) responder(['ok' => true, 'livros' => []]);
        $termos = preg_split('/\s+/', $q); $w = []; $p = [];
        foreach ($termos as $t) { $w[] = '(l.titulo LIKE ? OR l.autor LIKE ? OR l.isbn = ?)'; array_push($p, "%$t%", "%$t%", $t); }
        $ls = q_todos('SELECT l.id, l.titulo, l.autor, l.exemplares, l.localizacao,
                              (SELECT COUNT(*) FROM emprestimos e WHERE e.livro_id = l.id AND e.devolvido_em IS NULL) AS emprestados
                         FROM livros l WHERE l.ativo = 1 AND ' . implode(' AND ', $w) . ' ORDER BY l.titulo LIMIT 12', $p);
        responder(['ok' => true, 'livros' => array_map(fn($l) => ['id' => (int)$l['id'], 'titulo' => $l['titulo'], 'autor' => $l['autor'],
            'local' => $l['localizacao'], 'disponiveis' => max(0, (int)$l['exemplares'] - (int)$l['emprestados']), 'exemplares' => (int)$l['exemplares']], $ls)]);

    /* Situação do aluno na biblioteca */
    case 'leitor':
        if (!pode('biblioteca')) responder(['ok' => false], 403);
        $a = aluno_buscar((int)($_GET['id'] ?? 0));
        if (!$a) responder(['ok' => false]);
        $f = ficha_do_aluno((int)$a['id']);
        $abertos = emprestimos_lista('e.aluno_id = ? AND e.devolvido_em IS NULL', [$a['id']], 'e.prazo');
        responder(['ok' => true, 'leitor' => ['id' => (int)$a['id'], 'nome' => nome_proprio($a['nome']), 'turma' => turma_nome($a),
            'ficha' => $f['numero'] ?? null, 'bloqueada' => (bool)($f['bloqueada'] ?? false),
            'abertos' => array_map(fn($e) => ['titulo' => $e['titulo'], 'prazo' => data_br($e['prazo']), 'atrasado' => situacao_emprestimo($e) === 'atrasado'], $abertos)]]);

    /* Remove uma falta lançada por engano */
    case 'falta_del':
        if (!pode('frequencia')) responder(['ok' => false, 'msg' => 'Sem permissão.'], 403);
        $f = q_um('SELECT * FROM faltas WHERE id = ?', [(int)($_POST['falta_id'] ?? 0)]);
        if (!$f) responder(['ok' => false, 'msg' => 'Falta não encontrada.']);
        if (!pode('frequencia.justificar') && ((int)$f['registrado_por'] !== uid() || $f['data_falta'] !== date('Y-m-d'))) {
            responder(['ok' => false, 'msg' => 'Você só pode desfazer, no mesmo dia, uma falta que lançou por engano. Depois disso, peça à gestão.']);
        }
        q('DELETE FROM faltas WHERE id = ?', [$f['id']]);
        registrar_log('falta_removida', 'Falta de ' . data_br($f['data_falta']) . ' removida na coleta', (int)$f['aluno_id']);
        responder(['ok' => true, 'msg' => 'Falta removida.']);
}
responder(['ok' => false, 'msg' => 'Ação desconhecida.'], 400);
