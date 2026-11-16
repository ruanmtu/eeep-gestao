<?php
require __DIR__ . '/../includes/app.php';
exigir_login();
$ver_alunos = pode('alunos.ver');

/* Atualização ao vivo do cartão de ocorrências (chamado pelo sino quando chega um registro novo) */
if (($_GET['parcial'] ?? '') === 'ocorrencias') {
    if (!pode('ocorrencias') || !ocorrencias_ve_tudo()) { http_response_code(403); exit; }
    header('Cache-Control: no-store');
    exit(cartao_ocorrencias_hoje());
}

/* Gestão registra que contatou a família de um aluno em alerta */
/* v67: anotação rápida direto do painel → entra na página de hoje da "Minha agenda" */
if (eh_post() && ($_POST['acao'] ?? '') === 'agenda_rapida') {
    csrf_verificar();
    $tx = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nota'] ?? '')));
    if (mb_strlen($tx) < 1) flash('warning', 'Escreva a anotação.');
    else {
        $hoje_nota = agenda_buscar(uid(), date('Y-m-d'));
        agenda_salvar(uid(), date('Y-m-d'), ($hoje_nota['conteudo'] ?? '') . '<p><b>' . date('H:i') . '</b> ' . e(mb_substr($tx, 0, 500)) . '</p>');
    }
    redirecionar('index.php#minhaAgenda');
}
if (eh_post() && ($_POST['acao'] ?? '') === 'tratar_alerta') {
    csrf_verificar();
    if (pode('alertas.tratar')) {
        $aluno = (int)$_POST['aluno_id'];
        $fim = (string)$_POST['fim'];
        $dias = (int)$_POST['dias'];
        $obs = trim((string)($_POST['observacao'] ?? ''));
        if ($aluno && data_valida($fim)) {
            if (!q_valor('SELECT id FROM alertas_contato WHERE aluno_id = ? AND sequencia_fim = ?', [$aluno, $fim])) {
                q('INSERT INTO alertas_contato (aluno_id, sequencia_fim, dias, observacao, resolvido_por) VALUES (?, ?, ?, ?, ?)', [$aluno, $fim, $dias, $obs ?: null, uid()]);
            }
            try {
                $u0 = usuario();
                q('INSERT INTO busca_ativa (aluno_id, data_acao, hora, acao, motivo, faltas_informadas, resultado, descricao, registrado_por, registrado_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                  [$aluno, date('Y-m-d'), date('H:i:s'), 'ligacao', 'faltas', $dias, 'contato_feito', $obs ?: "Família informada sobre $dias faltas seguidas.", uid(), $u0['nome'], date('Y-m-d H:i:s')]);
            } catch (PDOException $e) {}
            q('INSERT INTO ocorrencias (aluno_id, data_ocorrencia, tipo, gravidade, descricao, providencias, responsavel_notificado, registrado_por) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
              [$aluno, date('Y-m-d'), 'pedagogica', 'media', "Alerta de $dias faltas seguidas (até " . data_br($fim) . ').', $obs ?: 'Contato com o responsável realizado.', uid()]);
            registrar_log('alerta_tratado', "$dias faltas seguidas até " . data_br($fim), $aluno);
            flash('success', 'Contato registrado. O alerta saiu da lista e foi anotado como ocorrência na ficha do aluno.');
        }
    }
    redirecionar('alertas.php');
}

[$seg, $sex] = semana_param();
$atual = semana_de();
$semana_txt = semana_rotulo($seg, $sex);
$eh_atual = $seg === $atual[0];
$sem_ant = date('Y-m-d', strtotime($seg . ' -7 days'));
$sem_prox = date('Y-m-d', strtotime($seg . ' +7 days'));

$ranking = ranking_faltas($seg, $sex);
[$ordem, $base_seg, $base_sex] = ordem_almoco_semana($seg);       // ordem desta semana = faltas da semana anterior
$max_ordem = max(1, ...array_column($ordem, 'faltas') ?: [1]);
$gestao = eh_gestao();
$dt_turmas = turmas_dirigidas();
/* sexta, sábado e domingo: a coleta da semana fechou e já dá para ver a ordem da semana seguinte */
$prox_seg = date('Y-m-d', strtotime($seg . ' +7 days'));
$mostra_prox = $seg === semana_de()[0] && (int)date('N') === 5;
$por_faltas = $ranking;
usort($por_faltas, fn($a, $b) => [$b['faltas'], $b['faltosos']] <=> [$a['faltas'], $a['faltosos']]);
$max_faltas = max(1, ...array_column($ranking, 'faltas') ?: [1]);

$total_alunos = (int)q_valor("SELECT COUNT(*) FROM alunos WHERE situacao = 'ativo'");
$faltas_semana = array_sum(array_column($ranking, 'faltas'));
$faltas_hoje = (int)q_valor('SELECT COUNT(*) FROM faltas WHERE data_falta = ?', [date('Y-m-d')]);
$alertas = alertas_sequencia(true);

/* Saídas por turma na semana */
$saidas_turma = [];
$saidas_semana = 0;
try {
    $saidas_turma = q_todos("SELECT t.id, t.serie, t.letra, c.nome AS curso, c.sigla, COUNT(s.id) AS n
                               FROM turmas t JOIN cursos c ON c.id = t.curso_id
                               LEFT JOIN alunos a ON a.turma_id = t.id
                               LEFT JOIN saidas s ON s.aluno_id = a.id AND s.tipo = 'saida' AND s.status = 'autorizada' AND s.data_hora BETWEEN ? AND ?
                              WHERE t.ano_letivo = ? AND t.ativo = 1
                              GROUP BY t.id, t.serie, t.letra, c.nome, c.sigla ORDER BY n DESC, t.serie, t.letra",
                             ["$seg 00:00:00", "$sex 23:59:59", ANO_LETIVO]);
    $saidas_semana = array_sum(array_column($saidas_turma, 'n'));
} catch (PDOException $e) { /* banco ainda sem a tabela de saídas */ }
$max_saidas = max(1, ...array_map('intval', array_column($saidas_turma, 'n')) ?: [1]);

/* Média de notas por turma no ano */

$u = usuario();
$h = (int)date('G');
$saudacao = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
$icone_hora = $h < 6 ? 'moon-stars' : ($h < 12 ? 'sunrise' : ($h < 18 ? 'sun' : 'moon-stars'));
$hoje = date('Y-m-d');
$data_extenso = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'][(int)date('w')] . ', ' . (int)date('j') . ' de ' . mb_strtolower(MESES[(int)date('n')]) . ' de ' . date('Y');
$per = periodo_atual();
$eventos = proximos_eventos(5);
$ultimos_arquivos = [];
try { $ultimos_arquivos = q_todos('SELECT id, titulo, nome_original, categoria, criado_em FROM arquivos WHERE especial IS NULL ORDER BY id DESC LIMIT 4'); } catch (PDOException $e) {}
$aniversariantes = $ver_alunos ? q_todos("SELECT a.id, a.nome, a.foto, a.data_nascimento, t.serie, t.letra, c.sigla FROM alunos a LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                                           WHERE a.situacao = 'ativo' AND a.data_nascimento IS NOT NULL AND MONTH(a.data_nascimento) = ? AND DAY(a.data_nascimento) = ? ORDER BY a.nome LIMIT 12", [(int)date('n'), (int)date('j')]) : [];
$hoje_nums = [
    'faltas' => $faltas_hoje,
    'saidas' => (function () use ($hoje) { try { return (int)q_valor("SELECT COUNT(*) FROM saidas WHERE tipo = 'saida' AND status = 'autorizada' AND data_hora BETWEEN ? AND ?", ["$hoje 00:00:00", "$hoje 23:59:59"]); } catch (PDOException $e) { return 0; } })(),
    'ocorrencias' => (int)q_valor('SELECT COUNT(*) FROM ocorrencias WHERE data_ocorrencia = ?', [$hoje]),
];
$atalhos = array_values(array_filter([
    ['frequencia', 'frequencia.php', 'calendar2-check', 'Lançar faltas', 'coleta de hoje', 'verde'],
    ['alunos.ver', 'mapa_sala.php', 'grid-3x3', 'Mapa de turma', 'onde cada aluno senta', 'azul'],
    ['saidas', 'saidas.php', 'patch-check', 'Autorizações', 'saída, tablet…', 'azul'],
    ['reservas', 'reservas.php', 'easel2', 'Reservar', 'auditório, projetor…', 'ambar'],
    ['', 'horarios.php', 'calendar3-week', 'Horários', pode('horarios.todos') ? 'de todos os professores' : 'meu horário', 'verde'],
    ['ocorrencias', 'ocorrencias.php#nova', 'shield-exclamation', 'Registrar ocorrência', 'advertência, retirada…', 'vermelho'],
    ['nota_conceitual', 'nota_conceitual.php', 'clipboard2-check', 'Nota conceitual', 'avaliação do bimestre', 'roxo'],
    ['notas', 'notas.php', 'journal-check', 'Notas', 'consultar (Power BI)', 'roxo'],
    ['importar', 'importar.php', 'cloud-arrow-up', 'Enviar notas', 'planilha CSV do Power BI', 'roxo'],
    ['relatorios', 'resultados.php', 'bar-chart-line', 'Resultados', 'gráficos da escola', 'verde'],
    ['', 'agenda.php', 'journal-richtext', 'Minha agenda', 'anotações do dia', 'ambar'],
    ['', 'calendario.php', 'calendar3', 'Calendário SIGE', $per['rotulo'], 'ambar'],
    ['', 'arquivos.php', 'folder2-open', 'Central de Arquivos', 'modelos e materiais', 'azul'],
    ['livros_didaticos', 'livros_didaticos.php', 'journal-bookmark', 'Livros didáticos', 'manual do professor em PDF', 'verde'],
    ['biblioteca', 'biblioteca.php', 'book', 'Multimeios', 'empréstimos', 'verde'],
    ['apresentacao', 'apresentacao.php', 'easel', 'Apresentação', 'slides da reunião', 'escura'],
], fn($a) => pode($a[0])));

$reservas_hoje = reservas_do_dia($hoje);
/* Indicadores rápidos (cada um leva para a tela correspondente) */
$busca_mes = 0;
try { $busca_mes = (int)q_valor('SELECT COUNT(*) FROM busca_ativa WHERE data_acao >= ?', [date('Y-m-01')]); } catch (PDOException $e) {}
$oc_hoje_n = pode('ocorrencias') ? count(ocorrencias_hoje()) : 0;
$turmas_ativas = count($ranking);
$saidas_hoje_n = $hoje_nums['saidas'];
$kpis = array_values(array_filter([
    $gestao ? ['people', 'Alunos ativos', $total_alunos, 'alunos.php', 'verde'] : null,
    $gestao ? ['diagram-3', 'Turmas ativas', $turmas_ativas, 'relatorios.php', 'verde'] : null,
    $gestao ? ['calendar2-x', 'Faltas hoje', $faltas_hoje, 'frequencia.php', 'laranja'] : null,
    $gestao ? ['calendar-week', 'Faltas na semana', $faltas_semana, '#almoco', 'laranja'] : null,
    $gestao ? ['exclamation-triangle', 'Alertas de faltas seguidas', count($alertas), 'alertas.php', count($alertas) ? 'vermelho' : 'verde'] : null,
    pode('ocorrencias') ? ['shield-exclamation', 'Ocorrências hoje', $oc_hoje_n, '#ocorrencias-hoje', $oc_hoje_n ? 'vermelho' : 'verde'] : null,
    $gestao ? ['door-open', 'Saídas hoje', $saidas_hoje_n, 'saidas.php', 'laranja'] : null,
    $gestao ? ['box-arrow-right', 'Saídas na semana', $saidas_semana, 'saidas.php', 'laranja'] : null,
    pode('saidas.autorizar') ? ['patch-question', 'Pedidos de liberação', saidas_pendentes(), 'saidas.php#pendentes', saidas_pendentes() ? 'laranja' : 'verde'] : null,
    $gestao ? ['telephone-outbound', 'Busca ativa no mês', $busca_mes, 'busca_ativa.php', 'verde'] : null,
    $gestao ? ['easel2', 'Agendamentos hoje', count($reservas_hoje), 'reservas.php?aba=agenda', 'verde'] : null,
    ['bell', 'Notificações novas', notificacoes_nao_lidas(), 'notificacoes.php', notificacoes_nao_lidas() ? 'laranja' : 'verde'],
]));;
$kpi_escopo = $gestao ? 'Visão da escola' : '';
if (!$gestao) {
    /* professor diretor de turma: os mesmos contadores, só da(s) turma(s) dele; demais professores: sem contadores */
    $kpis = [];
    if ($dt_turmas) {
        $dt_ids = array_map('intval', array_column($dt_turmas, 'id'));
        $soma = ['alunos' => 0, 'faltas_hoje' => 0, 'faltas_sem' => 0, 'oc_hoje' => 0, 'saidas_hoje' => 0];
        foreach ($dt_ids as $tid) { $r = turma_resumo($tid); foreach (['alunos', 'faltas_hoje', 'faltas_sem', 'oc_hoje'] as $k) $soma[$k] += (int)$r[$k]; }
        try { $soma['saidas_hoje'] = (int)q_valor("SELECT COUNT(*) FROM saidas s JOIN alunos a ON a.id = s.aluno_id WHERE a.turma_id IN (" . implode(',', $dt_ids) . ") AND s.tipo = 'saida' AND s.status = 'autorizada' AND s.data_hora BETWEEN ? AND ?", ["$hoje 00:00:00", "$hoje 23:59:59"]); } catch (PDOException $e) {}
        $ids_turma = array_map('intval', array_column(q_todos('SELECT id FROM alunos WHERE turma_id IN (' . implode(',', $dt_ids) . ") AND situacao = 'ativo'"), 'id'));
        $alertas = array_filter($alertas, fn($a) => in_array((int)$a['aluno_id'], $ids_turma, true));
        $kpis = [
            ['people', 'Alunos ativos', $soma['alunos'], 'minha_turma.php', 'verde'],
            ['calendar2-x', 'Faltas hoje', $soma['faltas_hoje'], 'frequencia.php', 'laranja'],
            ['calendar-week', 'Faltas na semana', $soma['faltas_sem'], 'minha_turma.php', 'laranja'],
            ['exclamation-triangle', 'Alertas de faltas seguidas', count($alertas), 'alertas.php', count($alertas) ? 'vermelho' : 'verde'],
            ['shield-exclamation', 'Ocorrências hoje', $soma['oc_hoje'], 'minha_turma.php', $soma['oc_hoje'] ? 'vermelho' : 'verde'],
            ['door-open', 'Saídas hoje', $soma['saidas_hoje'], 'minha_turma.php', 'laranja'],
        ];
        $kpi_escopo = (count($dt_turmas) > 1 ? 'Minhas turmas · ' : 'Minha turma · ') . implode(', ', array_map(fn($t) => $t['serie'] . 'º ' . $t['letra'] . ' ' . $t['sigla'], $dt_turmas));
    }
}
$minha_grade = grade_professor(uid());
$minhas_aulas_hoje = $minha_grade[(int)date('N')] ?? [];
ksort($minhas_aulas_hoje);
if (!$gestao) $minhas_aulas_hoje = [];
$aula_atual = aula_agora();
/* Chips da saudação: próxima aula, próximo evento, lembretes */
$chips = [];
$hoje_grade = $minha_grade[(int)date('N')] ?? []; ksort($hoje_grade); $hm = date('H:i');
foreach ($hoje_grade as $na => $g) { $hr = aulas()[$na] ?? null; if (!$hr || $hr[1] <= $hm || empty($g['turma_id'])) continue;
    $chips[] = ['easel2', ($hr[0] <= $hm ? 'Agora: ' : 'Próxima: ') . $na . 'ª aula · ' . $g['serie'] . 'º ' . $g['letra'] . (!empty($g['disciplina']) ? ' · ' . $g['disciplina'] : ''), 'horarios.php', 'verde']; break; }
try { $pev = q_um('SELECT titulo, data_inicio FROM eventos WHERE data_inicio >= ? ORDER BY data_inicio LIMIT 1', [date('Y-m-d')]);
      if ($pev) $chips[] = ['calendar-event', ($pev['data_inicio'] === date('Y-m-d') ? 'Hoje: ' : date('d/m', strtotime($pev['data_inicio'])) . ' · ') . mb_strimwidth($pev['titulo'], 0, 40, '…'), 'calendario.php', 'laranja']; } catch (PDOException $e) {}
$n_lemb = count(array_filter(lembretes_lista(uid()), fn($l) => substr($l['quando'], 0, 10) <= date('Y-m-d')));
if ($n_lemb) $chips[] = ['alarm', $n_lemb . ' lembrete' . ($n_lemb > 1 ? 's' : '') . ' para hoje', 'agenda.php#lembretes', 'ambar'];

/* ---- v43: dados do novo topo ---- */
$aulas_hoje = [];
foreach ($hoje_grade as $na => $g) {
    if (empty($g['turma_id'])) continue;
    $aulas_hoje[$na] = $g + ['hora' => aulas()[$na][0] ?? '', 'fim' => aulas()[$na][1] ?? ''];
}
$n_saidas_pend = pode('saidas.autorizar') ? saidas_pendentes() : 0;
$acoes = array_values(array_filter([
    pode('frequencia') ? ['frequencia.php', 'person-x', 'Coleta de faltas', 'faltas do dia', 'laranja'] : null,
    pode('provas') ? ['provas.php', 'ui-checks-grid', 'Corrigir prova', 'pela câmera', ''] : null,
    pode('nota_conceitual') ? ['nota_conceitual.php', 'clipboard2-check', 'Nota conceitual', $per['rotulo'], ''] : null,
    pode('ocorrencias') ? ['ocorrencias.php#nova', 'shield-exclamation', 'Ocorrência', 'registrar', ''] : null,
    pode('saidas') ? ['saidas.php' . ($n_saidas_pend ? '#pendentes' : ''), 'box-arrow-right', 'Autorizações', $n_saidas_pend ? $n_saidas_pend . ' aguardando' : 'saída, tablet…', ''] : null,
    pode('ia') ? ['sofia.php', '', 'Sofia (IA)', 'assistente', 'sofia'] : null,
]));
$acoes = array_slice($acoes, 0, 6);
$notifs_ult = array_slice(notificacoes_lista(5), 0, 5);

$titulo = 'Painel';
$subtitulo = '';
$menu = 'index';
$usa_graficos = true;
require __DIR__ . '/../includes/layout_topo.php';

function li_ranking(array $t, int $pos, string $valor, int $max, bool $detalhe = true, string $extra = ''): string
{
    $h = '<li class="' . ($detalhe && $t['detalhes'] ? 'tem-detalhe' : '') . '" tabindex="' . ($detalhe && $t['detalhes'] ? '0' : '-1') . '">'
       . '<span class="pos">' . $pos . 'º</span>' . $extra
       . '<span class="turma-nome"><i class="turma-dot" style="background:' . cor_curso($t['curso']) . '"></i>' . e($t['serie'] . 'º Ano ' . $t['letra']) . ' <span class="fw-normal text-secondary">· ' . e($t['curso']) . '</span><small>' . $t['faltosos'] . ' faltoso' . ($t['faltosos'] == 1 ? '' : 's') . ' de ' . $t['alunos'] . ' alunos</small></span>'
       . '<span class="barra-prop d-none d-sm-block"><span style="width:' . round($t['faltas'] / $max * 100) . '%"></span></span>'
       . '<span class="valor-rk">' . $valor . '</span>';
    if ($detalhe && $t['detalhes']) {
        $h .= '<div class="rk-detalhe" role="tooltip"><h5>Quem faltou nesta semana</h5><ul>';
        foreach ($t['detalhes'] as $id => $d) {
            $h .= '<li><a class="nome-link" href="aluno.php?id=' . $id . '#frequencia">' . e(nome_proprio($d['nome'])) . '</a><span class="dias-falta">' . e(dias_curtos($d['datas'])) . '</span></li>';
        }
        $h .= '</ul></div>';
    }
    return $h . '</li>';
}
?>

<?php ob_start(); ?>
        <div class="card h-100">
        <div class="card-header"><h2><i class="bi bi-folder2-open me-1"></i>Novidades na Central</h2><a class="ms-auto small" href="arquivos.php">abrir</a></div>
        <?php if ($ultimos_arquivos): ?><ul class="lista-arquivos-mini">
            <?php foreach ($ultimos_arquivos as $a): [$ic, $cor] = arquivo_icone(arquivo_extensao($a['nome_original'])); ?>
                <li><span class="arq-tipo <?= $cor ?>"><i class="bi bi-<?= $ic ?>"></i></span><div><a href="visualizar.php?id=<?= $a['id'] ?>"><?= e($a['titulo']) ?></a><small><?= e($a['categoria'] ?: 'Outros') ?> · <?= data_br(substr($a['criado_em'], 0, 10)) ?></small></div></li>
            <?php endforeach; ?></ul>
        <?php else: ?><div class="vazio py-4"><i class="bi bi-folder2"></i>Nenhum arquivo publicado ainda.</div><?php endif; ?>
    </div>
<?php $cartao_central = ob_get_clean(); ob_start(); ?>
        <div class="card-header p4-alm-cab">
            <div class="min-w-0"><h2><i class="bi bi-box-arrow-right me-1"></i>Liberação para o almoço</h2>
                <small>Semana de <?= e($semana_txt) ?></small></div>
            <a class="p4-alm-imp" href="ordem_almoco.php?semana=<?= $seg ?>" target="_blank" title="Imprimir / PDF" aria-label="Imprimir ordem do almoço"><i class="bi bi-printer"></i></a>
        </div>
        <ol class="p2-rol p4-alm" id="almoco">
            <?php foreach ($ordem as $i => $t): $ult = $i === count($ordem) - 1; ?>
            <li class="<?= $i === 0 ? 'prim' : ($ult ? 'ult' : '') ?>"><span class="p4-pos"><?= $i + 1 ?>º</span>
                <div><b><i class="turma-dot" style="background:<?= cor_curso($t['curso']) ?>"></i><?= e($t['serie'] . 'º ' . $t['letra'] . ' ' . ($t['sigla'] ?? $t['curso'])) ?></b><small><?= $i === 0 ? 'Sai primeiro' : ($ult ? 'Sai por último' : $t['faltosos'] . ' faltoso' . ($t['faltosos'] == 1 ? '' : 's')) ?></small></div>
                <span class="p4-ft"><b><?= (int)$t['faltas'] ?></b>falta<?= $t['faltas'] == 1 ? '' : 's' ?></span></li>
            <?php endforeach; ?>
        </ol>
        <div class="p4-alm-rod"><span><i class="bi bi-calendar2-week"></i>Base: faltas de <b><?= date('d/m', strtotime($base_seg)) ?> a <?= date('d/m', strtotime($base_sex)) ?></b></span><?php if ($mostra_prox): ?> · <a href="index.php?semana=<?= $prox_seg ?>#almoco">próxima semana</a><?php endif; ?></div>
<?php $cartao_almoco = ob_get_clean(); ?>
<!-- ===== v43: boas-vindas + período ===== -->
<section class="p2-topo mb-3">
    <div class="p2-boas">
        <span class="p2-pts" aria-hidden="true"></span>
        <span class="p2-deco-m" aria-hidden="true"><i></i></span>
        <span class="p2-foto"><?= avatar($u, 'usuario', 'avatar-xl') ?></span>
        <div class="min-w-0">
            <span class="p2-quando-m"><i class="bi bi-calendar3"></i><?= e(dia_semana(date('Y-m-d'))) ?>, <?= (int)date('j') ?> de <?= e(mb_strtolower(MESES[(int)date('n')])) ?><i class="p2-sep"></i><i class="bi bi-clock"></i><b class="relogio-m"><?= date('H:i') ?></b></span>
            <h2><?= $saudacao ?>, <span><?= e(nome_exibicao($u)) ?>!</span></h2>
            <span class="p2-cargo-m"><?= e(cargo_usuario($u)) ?></span>
            <?php if ($per['bimestre'] || $per['proximo']): ?><span class="p2-per-m"><i class="bi bi-flag-fill"></i><?= e($per['rotulo']) ?><?= $per['bimestre'] ? ' · ' . $per['dias_restantes'] . ' dias restantes' : '' ?></span><?php endif; ?>
        </div>
    </div>
    <div class="p2-periodo">
        <div class="p2-per-topo">
            <div><b><?= e($per['rotulo']) ?></b><small><?php if ($per['bimestre']): ?><?= date('d/m', strtotime($per['inicio'])) ?> a <?= date('d/m', strtotime($per['fim'])) ?><?php elseif ($per['proximo']): ?><?= $per['proximo'] ?>º período começa em breve<?php endif; ?></small></div>
            <?php if ($per['bimestre'] || $per['proximo']): ?><div class="p2-dias"><b><?= $per['dias_restantes'] ?></b><small>dias<?= $per['bimestre'] ? ' restantes' : '' ?></small></div><?php endif; ?>
        </div>
        <div class="p2-barra"><i style="width:<?= $per['progresso'] ?>%"></i></div>
        <small class="p2-pct"><?= $per['progresso'] ?>% do período concluído</small>
    </div>
</section>

<?php if ($gestao || pode('relatorios')):
    $rs_media = null; $rs_top = null; $rs_n = 0;
    try { $rs_media = q_valor('SELECT ROUND(AVG(nota), 1) FROM notas WHERE ano_letivo = ?', [ANO_LETIVO]); $rs_n = (int)q_valor('SELECT COUNT(DISTINCT a.turma_id) FROM notas n JOIN alunos a ON a.id = n.aluno_id WHERE n.ano_letivo = ?', [ANO_LETIVO]); } catch (PDOException $e) {} ?>
<!-- ===== Resultados da escola: os gráficos ficam na página própria (resultados.php) ===== -->
<a class="rs-chamada mb-3" href="resultados.php">
    <span class="rs-chamada-ic"><i class="bi bi-bar-chart-line-fill"></i></span>
    <span class="rs-chamada-tx"><b>Resultados da escola</b><small>Resultados por turma e período, rendimento por turma e média conceitual</small></span>
    <?php if ($rs_media !== null && $rs_media !== false): ?><span class="rs-chamada-n"><small>Média <?= ANO_LETIVO ?></small><b><?= number_format((float)$rs_media, 1, ',', '') ?></b></span>
    <span class="rs-chamada-n d-none d-sm-flex"><small>Turmas com notas</small><b><?= $rs_n ?></b></span><?php endif; ?>
    <span class="rs-chamada-ir">Abrir <i class="bi bi-arrow-right"></i></span>
</a>
<?php endif; ?>

<?php if ($kpis): ?>
<!-- ===== Contadores ===== -->
<?php if (!$gestao): ?><div class="p4-escopo"><i class="bi bi-person-workspace"></i><?= e($kpi_escopo) ?></div><?php endif; ?>
<section class="kpis kpis-12 mb-3" aria-label="Indicadores">
    <?php foreach ($kpis as [$ic, $rot, $val, $href, $cor]): ?>
        <a class="kpi kpi-<?= $cor ?>" href="<?= $href ?>"><span class="kpi-ic"><i class="bi bi-<?= $ic ?>"></i></span>
            <span><strong data-contar="<?= (int)$val ?>"><?= (int)$val ?></strong><small><?= e($rot) ?></small></span><i class="bi bi-arrow-up-right kpi-seta"></i></a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($acoes): ?>
<nav class="p2-acoes mb-3" aria-label="Ações rápidas">
    <?php foreach ($acoes as [$h, $ic, $rot, $sub, $cl]): ?>
    <a class="p2-ac <?= $cl ?>" href="<?= $h ?>"><span><?php if ($cl === 'sofia'): ?><img src="<?= RAIZ ?>assets/img/sofia.svg" alt=""><?php else: ?><i class="bi bi-<?= $ic ?>"></i><?php endif; ?></span><div><b><?= e($rot) ?></b><small><?= e($sub) ?></small></div></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php $tem_sofia = pode('ia'); ?>
<div class="p2-linha p4-linha<?= $tem_sofia ? '' : ' sem-sofia' ?> mb-4">
    <section class="card p2-cx p4-almoco">
        <?= $cartao_almoco ?>
    </section>

    <?php if ($tem_sofia): ?>
    <section class="card p2-cx p2-sofia">
        <div class="p2-sofia-topo"><span class="p2-sofia-ft"><img src="<?= RAIZ ?>assets/img/sofia.svg" alt=""></span><div><b>Sofia</b><small>assistente pedagógica de IA</small></div></div>
        <p>Peça questões com gabarito, planos de aula, comunicados e atividades de recuperação.</p>
        <div class="p2-sofia-ats">
            <a href="sofia.php"><i class="bi bi-list-check"></i>Questões com gabarito</a>
            <a href="sofia.php"><i class="bi bi-journal-text"></i>Plano de aula</a>
            <a href="sofia.php"><i class="bi bi-envelope-paper"></i>Comunicado aos pais</a>
        </div>
        <a class="p2-sofia-bt" href="sofia.php">Conversar com a Sofia <i class="bi bi-arrow-right"></i></a>
    </section>
    <?php endif; ?>

    <?php $tem_alerta = $gestao || $dt_turmas; if ($tem_alerta): $al_top = $alertas; usort($al_top, fn($x, $y) => count($y['dias']) <=> count($x['dias'])); ?>
    <section class="card p2-cx p4-faltas">
        <div class="card-header p4-af-cab"><h2><i class="bi bi-exclamation-triangle-fill me-1"></i>Faltas seguidas</h2><span class="p4-af-n"><?= count($alertas) ?></span><a class="ms-auto small" href="alertas.php">abrir <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($alertas): ?>
        <ul class="p4-af-lista"><?php foreach (array_slice($al_top, 0, 6) as $a): ?>
            <li><a href="alertas.php"><span class="p4-af-ini"><?= e(mb_strtoupper(mb_substr(nome_proprio($a['nome']), 0, 1) . mb_substr(strrchr(' ' . trim($a['nome']), ' '), 1, 1))) ?></span>
                <span class="p4-af-tx"><b><?= e(primeiro_ultimo(nome_proprio($a['nome']))) ?></b><small><?= e(turma_nome($a)) ?><?= $a['ativa'] ? ' · faltou hoje' : '' ?></small></span>
                <span class="p4-af-d"><b><?= count($a['dias']) ?></b>seguidas</span></a></li>
        <?php endforeach; ?></ul>
        <a class="p4-af-bt" href="alertas.php"><i class="bi bi-telephone-outbound me-1"></i>Contatar famílias<?= count($alertas) > 6 ? ' · +' . (count($alertas) - 6) : '' ?></a>
        <?php else: ?><div class="p2-vazio"><i class="bi bi-check2-circle"></i><b>Nenhum alerta</b><small>Ninguém com <?= FALTAS_SEGUIDAS_ALERTA ?> faltas seguidas.</small></div><?php endif; ?>
    </section>
    <?php else: ?>
    <section class="card p2-cx p2-lista">
        <div class="card-header"><h2>Notificações</h2><a class="ms-auto small" href="notificacoes.php">ver todas <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($notifs_ult): ?><ul class="p2-rol"><?php foreach ($notifs_ult as $nt): ?>
            <li class="<?= empty($nt['lida_em']) ? 'nova' : '' ?>"><span class="p2-ic"><i class="bi bi-<?= e($nt['icone'] ?? 'bell') ?>"></i></span><div><b><?= e(mb_strimwidth((string)($nt['titulo'] ?? ''), 0, 60, '…')) ?></b><small><?= e(mb_strimwidth((string)($nt['mensagem'] ?? $nt['texto'] ?? ''), 0, 70, '…')) ?></small></div></li>
        <?php endforeach; ?></ul>
        <?php else: ?><div class="p2-vazio"><i class="bi bi-bell"></i><b>Tudo em dia</b><small>Nenhuma notificação nova.</small></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card p2-cx p2-aulas">
        <div class="card-header"><h2>Minhas aulas de hoje</h2><a class="ms-auto small" href="horarios.php">horário <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($aulas_hoje): ?>
        <ul class="p2-rol">
            <?php foreach ($aulas_hoje as $na => $a): $passou = $a['fim'] !== '' && $a['fim'] <= date('H:i'); $cl = $passou ? 'ok' : 'futura'; ?>
            <li><a class="p2-aula <?= $cl ?><?= $aula_atual === $na ? ' agora' : '' ?>" href="horarios.php">
                <span class="p2-h"><b><?= e($a['hora']) ?></b><?= $na ?>ª aula</span>
                <span class="p2-tx"><b><?= e($a['serie'] . 'º ' . $a['letra']) ?> <small><?= e($a['sigla']) ?></small></b><small><?= e($a['disciplina'] ?? '') ?></small></span>
                <span class="p2-st"><?= $aula_atual === $na ? '<i class="bi bi-play-circle-fill"></i>Agora' : ($passou ? '<i class="bi bi-check-circle-fill"></i>Dada' : '<i class="bi bi-hourglass"></i>Depois') ?></span>
            </a></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="p2-vazio"><i class="bi bi-calendar3-week"></i><b><?= (int)date('N') > 5 ? 'Fim de semana' : 'Sem aulas suas hoje' ?></b><small>Seu horário do dia aparece aqui.</small><a href="horarios.php">Ver horários <i class="bi bi-arrow-right"></i></a></div>
        <?php endif; ?>
    </section>
</div>

<?php
$tem_oc = pode('ocorrencias') && ocorrencias_ve_tudo();
ob_start(); ?>
<div class="card h-100 cartao-reservas p3-rolar">
    <div class="card-header"><h2><i class="bi bi-easel2 me-1"></i>Agendamentos de hoje</h2><span class="etiqueta"><?= count($reservas_hoje) ?></span>
        <?php if (pode('reservas')): ?><div class="acoes"><a class="btn btn-sm btn-light" href="reservas.php?aba=agenda" title="Agenda de reservas" aria-label="Agenda de reservas"><i class="bi bi-calendar3"></i></a><a class="btn btn-sm btn-primary" href="reservas.php" title="Reservar" aria-label="Reservar"><i class="bi bi-plus-lg"></i></a></div><?php endif; ?></div>
    <?php if ($reservas_hoje): ?><ul class="agenda-lista">
        <?php foreach ($reservas_hoje as $r): $agora_r = $aula_atual === (int)$r['aula']; ?>
            <li class="<?= $agora_r ? 'agora' : '' ?>"><span class="ag-data <?= $r['recurso_tipo'] === 'ambiente' ? 'verde' : 'azul' ?>"><b><?= $r['aula'] ?>ª</b>aula</span>
                <div class="flex-grow-1 min-w-0"><strong><?= e($r['recurso']) ?><?= $agora_r ? ' <span class="etiqueta">agora</span>' : '' ?></strong>
                    <small><?= e(aulas()[(int)$r['aula']][0] ?? '') ?> · <?= e(primeiro_ultimo((string)($r['professor'] ?? '—'))) ?><?= $r['serie'] ? ' · ' . e($r['serie'] . 'º ' . $r['letra'] . ' ' . $r['sigla']) : '' ?><?= $r['metodologia'] ? ' · ' . e($r['metodologia']) : '' ?><?= $r['descricao'] ? ' · ' . e(mb_strimwidth($r['descricao'], 0, 70, '…')) : '' ?></small></div>
                <?= avatar(['id' => $r['usuario_id'], 'nome' => $r['professor'] ?? '?', 'foto' => $r['foto']], 'usuario', 'avatar-sm') ?></li>
        <?php endforeach; ?></ul>
    <?php else: ?><div class="vazio py-4"><i class="bi bi-easel2"></i>Nenhum ambiente ou equipamento reservado para hoje.</div><?php endif; ?>
</div>
<?php $cx_reservas = ob_get_clean(); ?>






<!-- ===== v72: segunda linha: notificações, agendamentos, ocorrências de hoje (ou calendário) e agenda ===== -->
<?php $l2 = $tem_alerta ? 4 : 3; ?>
<div class="p4-l2 c<?= $l2 ?> mb-4">
    <?php if ($tem_alerta): ?><div class="p3-cel"><section class="card p2-cx p2-lista h-100">
        <div class="card-header"><h2>Notificações</h2><a class="ms-auto small" href="notificacoes.php">ver todas <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($notifs_ult): ?><ul class="p2-rol"><?php foreach ($notifs_ult as $nt): ?>
            <li class="<?= empty($nt['lida_em']) ? 'nova' : '' ?>"><span class="p2-ic"><i class="bi bi-<?= e($nt['icone'] ?? 'bell') ?>"></i></span><div><b><?= e(mb_strimwidth((string)($nt['titulo'] ?? ''), 0, 60, '…')) ?></b><small><?= e(mb_strimwidth((string)($nt['mensagem'] ?? $nt['texto'] ?? ''), 0, 70, '…')) ?></small></div></li>
        <?php endforeach; ?></ul>
        <?php else: ?><div class="p2-vazio"><i class="bi bi-bell"></i><b>Tudo em dia</b><small>Nenhuma notificação nova.</small></div><?php endif; ?>
    </section></div><?php endif; ?>
    <div class="p3-cel p3-rolar"><?= $cx_reservas ?></div>
    <?php if ($tem_oc): ?><div class="p3-cel p3-rolar" id="ocHojeBox"><?= cartao_ocorrencias_hoje() ?></div>    <?php else: /* v68: sem ocorrências (professor) → Calendário SIGE completa a linha */ $mc = MESES_CURTO; $dsem = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']; ?>
    <div class="p3-cel"><section class="card p2-cx p4-cal">
        <div class="card-header"><h2><i class="bi bi-calendar3 me-1"></i>Calendário SIGE</h2><a class="ms-auto small" href="calendario.php">ver tudo <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($eventos): $ev0 = $eventos[0]; [$tr0, , $tc0] = TIPOS_EVENTO[$ev0['tipo']] ?? TIPOS_EVENTO['evento']; $t0 = strtotime($ev0['data_inicio']); $falta = (int)round((strtotime(date('Y-m-d', $t0)) - strtotime(date('Y-m-d'))) / 86400); ?>
        <div class="p4-cal-prox <?= $tc0 ?>">
            <div class="p4-cal-dia"><small><?= $dsem[(int)date('w', $t0)] ?></small><b><?= date('d', $t0) ?></b><span><?= $mc[(int)date('n', $t0)] ?></span></div>
            <div class="p4-cal-tx"><em><?= $falta <= 0 ? 'Hoje' : ($falta === 1 ? 'Amanhã' : 'Em ' . $falta . ' dias') ?></em><b><?= e($ev0['titulo']) ?></b><small><?= e($tr0) ?><?= $ev0['data_fim'] ? ' · até ' . date('d/m', strtotime($ev0['data_fim'])) : '' ?></small></div>
        </div>
        <ul class="p4-cal-lista"><?php foreach (array_slice($eventos, 1, 4) as $ev): [$tr, , $tc] = TIPOS_EVENTO[$ev['tipo']] ?? TIPOS_EVENTO['evento']; $te = strtotime($ev['data_inicio']); ?>
            <li class="<?= $tc ?>"><span class="p4-cal-d"><b><?= date('d', $te) ?></b><?= $mc[(int)date('n', $te)] ?></span><div><b><?= e($ev['titulo']) ?></b><small><?= ucfirst($dsem[(int)date('w', $te)]) ?> · <?= e($tr) ?></small></div></li>
        <?php endforeach; ?></ul>
        <?php else: ?><div class="p2-vazio"><i class="bi bi-calendar3"></i><b>Nada marcado</b><small>Os próximos eventos do calendário aparecem aqui.</small></div><?php endif; ?>
    </section></div>
    <?php endif; ?>
    <div class="p3-cel"><section class="card p2-cx p4-agenda" id="minhaAgenda">
        <?php $ag_hoje = agenda_buscar(uid(), date('Y-m-d')); $ag_linhas = $ag_hoje ? array_values(array_filter(array_map('trim', explode("\n", agenda_texto($ag_hoje['conteudo']))))) : []; ?>
        <div class="card-header"><h2><i class="bi bi-journal-text me-1"></i>Minha agenda</h2><a class="ms-auto small" href="agenda.php">abrir <i class="bi bi-arrow-right"></i></a></div>
        <?php if ($ag_linhas): ?>
        <ul class="p2-rol p4-notas" aria-label="Anotações de hoje"><?php foreach (array_slice(array_reverse($ag_linhas), 0, 8) as $ln): $hm = preg_match('/^(\d{2}:\d{2})\s+(.*)$/u', $ln, $mm) ? $mm : null; ?>
            <li><?= $hm ? '<span class="p4-hora">' . e($hm[1]) . '</span><span>' . e($hm[2]) . '</span>' : '<span class="p4-hora"><i class="bi bi-dot"></i></span><span>' . e($ln) . '</span>' ?></li>
        <?php endforeach; ?></ul>
        <?php else: ?><div class="p2-vazio"><i class="bi bi-pencil-square"></i><b>Nada anotado hoje</b><small>Escreva abaixo: vai direto para a sua agenda.</small></div><?php endif; ?>
        <form method="post" class="p4-nota-form" autocomplete="off">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="agenda_rapida">
            <input class="form-control" name="nota" maxlength="500" placeholder="Anotação rápida…" aria-label="Anotação rápida" required>
            <button class="btn btn-primary" type="submit" title="Anotar" aria-label="Anotar"><i class="bi bi-plus-lg"></i></button>
        </form>
    </section></div>
</div>

<?php
$script_pagina = '<script>
/* relógio e números que contam ao abrir */
(function () {
  const r = document.getElementById("relogio");
  setInterval(() => { const d = new Date(), t = String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0"); if (r) r.textContent = t; document.querySelectorAll(".relogio-m").forEach(x => x.textContent = t); }, 15000);
  if (matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  document.querySelectorAll(".kpi [data-contar]").forEach((el, i) => {
    const alvo = +el.dataset.contar, ini = performance.now() + i * 90, dur = 900;
    if (!alvo) return;
    const passo = t => { const p = Math.max(0, Math.min(1, (t - ini) / dur)); el.textContent = Math.round(alvo * (1 - Math.pow(1 - p, 3))).toLocaleString("pt-BR"); if (p < 1) requestAnimationFrame(passo); };
    el.textContent = "0"; requestAnimationFrame(passo);
  });
})();
document.getElementById("modalAlerta") && document.getElementById("modalAlerta").addEventListener("show.bs.modal", e => {
  const b = e.relatedTarget;
  alAluno.value = b.dataset.aluno; alFim.value = b.dataset.fim; alDias.value = b.dataset.dias;
  alNome.textContent = b.dataset.nome; alDiasTxt.textContent = b.dataset.dias;
});
/* No celular, toque na turma mostra quem faltou */
document.querySelectorAll(".ranking > li.tem-detalhe").forEach(li => li.addEventListener("click", ev => {
  if (ev.target.closest("a")) return;
  document.querySelectorAll(".ranking > li.mostrar").forEach(o => o !== li && o.classList.remove("mostrar"));
  li.classList.toggle("mostrar");
}));
configurarGraficos();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
