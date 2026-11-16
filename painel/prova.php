<?php
require __DIR__ . '/../includes/app.php';
exigir('provas');

$prova = prova_buscar((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$prova || !pode_prova($prova)) { flash('warning', 'Prova não encontrada.'); redirecionar('provas.php'); }
$id = (int)$prova['id'];
$n = (int)$prova['num_questoes'];
$letras = letras_alternativas((int)$prova['num_alternativas']);

if (eh_post()) {
    csrf_verificar();
    switch ($_POST['acao'] ?? '') {
        case 'gabarito':
            $g = '';
            for ($i = 1; $i <= $n; $i++) {
                $v = (string)($_POST['q'][$i] ?? '-');
                $g .= in_array($v, $letras, true) || $v === '*' ? $v : '-';
            }
            q('UPDATE provas SET gabarito = ? WHERE id = ?', [$g, $id]);
            // recalcula notas já corrigidas
            $prova['gabarito'] = $g;
            foreach (q_todos('SELECT id, respostas FROM prova_resultados WHERE prova_id = ?', [$id]) as $r) {
                [$ac, $nt, $pts] = omr_corrigir($prova, $r['respostas']);
                q('UPDATE prova_resultados SET acertos = ?, nota = ?, partes = ? WHERE id = ?', [$ac, $nt, json_encode($pts, JSON_UNESCAPED_UNICODE), $r['id']]);
            }
            registrar_log('gabarito', $prova['titulo']);
            $falta = substr_count($g, '-');
            flash($falta ? 'warning' : 'success', $falta ? "Gabarito salvo, mas $falta questão(ões) ainda sem resposta certa." : 'Gabarito salvo. Já pode imprimir as folhas e corrigir.');
            redirecionar("prova.php?id=$id#" . ($falta ? 'gabarito' : 'folhas'));
        case 'excluir_resultado':
            if (!pode('provas.excluir')) { flash('warning', 'Só a gestão pode apagar notas corrigidas. Para corrigir, leia a folha de novo: a nota é substituída.'); redirecionar("prova.php?id=$id#resultados"); }
            q('DELETE FROM prova_resultados WHERE id = ? AND prova_id = ?', [(int)$_POST['resultado_id'], $id]);
            flash('success', 'Correção removida. A folha pode ser corrigida de novo.');
            redirecionar("prova.php?id=$id#resultados");
        case 'lancar':
            if (!pode('notas')) { flash('warning', 'As notas são enviadas pela gestão. Exporte o resultado e entregue à coordenação.'); redirecionar("prova.php?id=$id#resultados"); }
            $tipo = isset(TIPOS_AVALIACAO[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'global';
            $res = q_todos('SELECT r.aluno_id, r.respostas, a.turma_id FROM prova_resultados r JOIN alunos a ON a.id = r.aluno_id WHERE r.prova_id = ?', [$id]);
            $total = 0; $discs = [];
            foreach ($prova['partes'] as $pi => $pt) {                         // cada disciplina do bloco vira uma avaliação
                if (!$pt['disciplina_id']) continue;
                $por_turma = [];
                foreach ($res as $r) {
                    $pts = omr_corrigir($prova, $r['respostas'])[2];
                    $por_turma[(int)$r['turma_id']][(int)$r['aluno_id']] = num(round($pts[$pi]['acertos'] / max(1, $pt['n']) * 10, 2), 2);
                }
                foreach ($por_turma as $tid => $notas) {
                    $av_id = q_valor('SELECT id FROM avaliacoes WHERE turma_id = ? AND disciplina_id = ? AND ano_letivo = ? AND bimestre = ? AND tipo = ? AND titulo = ?',
                                     [$tid, $pt['disciplina_id'], $prova['ano_letivo'], $prova['bimestre'], $tipo, $prova['titulo']]);
                    if (!$av_id) {
                        q('INSERT INTO avaliacoes (turma_id, disciplina_id, ano_letivo, bimestre, tipo, titulo, data_aplicacao, lote, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                          [$tid, $pt['disciplina_id'], $prova['ano_letivo'], $prova['bimestre'], $tipo, $prova['titulo'], $prova['data_aplicacao'], 'prova' . $id, uid()]);
                        $av_id = (int)db()->lastInsertId();
                    }
                    [$s1] = salvar_notas_avaliacao(avaliacao_buscar((int)$av_id), $notas);
                    $total += $s1;
                }
                $discs[] = $pt['nome'];
            }
            registrar_log('notas', 'Lançadas da prova ' . $prova['titulo'] . ': ' . $total);
            flash('success', "$total nota(s) lançada(s) como " . TIPOS_AVALIACAO[$tipo] . ' de ' . implode(', ', $discs) . ' (' . $prova['bimestre'] . 'º bimestre), escala 0 a 10. As médias foram recalculadas.');
            redirecionar("prova.php?id=$id#resultados");
        case 'dados_folha':
            $hr = '/^([01]\d|2[0-3]):[0-5]\d$/';
            $hi = preg_match($hr, (string)($_POST['hora_inicio'] ?? '')) ? $_POST['hora_inicio'] : '';
            $hf = preg_match($hr, (string)($_POST['hora_fim'] ?? '')) ? $_POST['hora_fim'] : '';
            if (!$hi || !$hf || $hf <= $hi) { flash('warning', 'Informe a duração da prova (o término precisa ser depois do início).'); redirecionar("prova.php?id=$id#folhas"); }
            $dt = data_valida($_POST['data_aplicacao'] ?? '') ? $_POST['data_aplicacao'] : null;
            $modelo = !empty($_POST['modelo_novo']) ? OMR_MODELO_ATUAL : omr_modelo($prova);
            q('UPDATE provas SET data_aplicacao = ?, hora_inicio = ?, hora_fim = ?, aplicador = ?, modelo_folha = ? WHERE id = ?',
              [$dt, $hi . ':00', $hf . ':00', mb_substr(trim((string)($_POST['aplicador'] ?? '')), 0, 160) ?: null, $modelo, $id]);
            flash('success', 'Dados da folha salvos. Imprima as folhas de novo para sair com as informações atualizadas.');
            redirecionar("prova.php?id=$id#folhas");
        case 'excluir_prova':
            if (!pode('provas.excluir')) { flash('warning', 'Só a gestão pode excluir provas.'); redirecionar("prova.php?id=$id"); }
            q('DELETE FROM provas WHERE id = ?', [$id]);
            registrar_log('prova_excluida', $prova['titulo']);
            flash('success', 'Prova excluída.');
            redirecionar('provas.php');
    }
    redirecionar("prova.php?id=$id");
}

$gab = str_pad((string)$prova['gabarito'], $n, '-');
$pronto = !str_contains($gab, '-');
$turmas = q_todos("SELECT t.id, t.serie, t.letra, c.nome AS curso,
                          (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id AND a.situacao = 'ativo') AS alunos,
                          (SELECT COUNT(*) FROM prova_resultados r JOIN alunos a ON a.id = r.aluno_id WHERE r.prova_id = ? AND a.turma_id = t.id) AS corrigidas
                     FROM turmas t JOIN cursos c ON c.id = t.curso_id
                    WHERE t.serie = ? AND t.ano_letivo = ? AND t.ativo = 1" . ($prova['curso_id'] ? ' AND t.curso_id = ' . (int)$prova['curso_id'] : '') . ' ORDER BY t.letra',
                  [$id, $prova['serie'], $prova['ano_letivo']]);
$resultados = q_todos('SELECT r.*, a.nome, t.serie, t.letra, c.nome AS curso, u.nome AS por FROM prova_resultados r JOIN alunos a ON a.id = r.aluno_id
                        LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id LEFT JOIN usuarios u ON u.id = r.corrigido_por
                       WHERE r.prova_id = ? ORDER BY t.letra, a.nome', [$id]);
$media = $resultados ? array_sum(array_column($resultados, 'nota')) / count($resultados) : 0;

if (($_GET['exportar'] ?? '') === 'csv') {
    $cab = ['Aluno', 'Turma', 'Acertos', 'Questões', 'Nota'];
    if ($prova['bloco']) foreach ($prova['partes'] as $pt) array_push($cab, $pt['nome'] . ' (acertos)', $pt['nome'] . ' (nota)');
    enviar_csv('resultado-prova-' . $id . '.csv', array_merge($cab, ['Respostas', 'Corrigido em']),
        array_map(function ($r) use ($prova, $n) {
            $l = [nome_proprio($r['nome']), turma_nome($r), $r['acertos'], $n, num($r['nota'], 2)];
            if ($prova['bloco']) foreach (omr_corrigir($prova, $r['respostas'])[2] as $pt) array_push($l, $pt['acertos'] . '/' . $pt['total'], num($pt['nota'], 2));
            return array_merge($l, [$r['respostas'], data_hora_br($r['corrigido_em'])]);
        }, $resultados));
}

/* Acertos por questão (para o professor ver as mais difíceis) */
$acertos_q = array_fill(0, $n, 0);
foreach ($resultados as $r) for ($i = 0; $i < $n; $i++) if ($gab[$i] === '*' || ($r['respostas'][$i] ?? '') === $gab[$i]) $acertos_q[$i]++;

/* Análise da prova: marcações por questão, distrator mais marcado, faixas de nota e alunos abaixo da média */
$analise = null;
if ($resultados) {
    $tot = count($resultados);
    $marc = []; for ($i = 0; $i < $n; $i++) $marc[$i] = array_fill_keys(array_merge($letras, ['branco']), 0);
    foreach ($resultados as $r) for ($i = 0; $i < $n; $i++) { $c = $r['respostas'][$i] ?? '-'; isset($marc[$i][$c]) && $c !== 'branco' ? $marc[$i][$c]++ : $marc[$i]['branco']++; }
    $questoes = [];
    for ($i = 0; $i < $n; $i++) {
        $erradas = $marc[$i]; unset($erradas['branco']); if ($gab[$i] !== '*') unset($erradas[$gab[$i]]);
        arsort($erradas); $dist = $gab[$i] === '*' ? null : array_key_first($erradas);
        $parte = null; foreach ($prova['partes'] as $pi => $pt) if ($i >= $pt['inicio'] && $i < $pt['fim']) $parte = $pi;
        $questoes[$i] = ['pct' => round($acertos_q[$i] / $tot * 100), 'dist' => $dist && $erradas[$dist] ? $dist : null, 'dist_n' => $dist ? $erradas[$dist] : 0, 'branco' => $marc[$i]['branco'], 'marc' => $marc[$i], 'parte' => $parte];
    }
    $ordem = array_keys($questoes); usort($ordem, fn($a, $b) => $questoes[$a]['pct'] <=> $questoes[$b]['pct'] ?: $a <=> $b);
    $faixas = array_fill(0, 5, 0); $abaixo = []; $media10 = $media / max(0.01, $prova['valor']) * 10;
    foreach ($resultados as $r) {
        $n10 = $r['nota'] / max(0.01, $prova['valor']) * 10;
        $faixas[min(4, (int)floor($n10 / 2))]++;
        $fracas = [];
        if ($prova['bloco']) foreach (omr_corrigir($prova, $r['respostas'])[2] as $pi => $pt) if ($pt['nota'] / max(0.01, $pt['valor']) * 10 < MEDIA_APROVACAO) $fracas[] = $prova['partes'][$pi]['nome'];
        if ($n10 < $media10 || $fracas) $abaixo[] = $r + ['n10' => $n10, 'fracas' => $fracas];
    }
    usort($abaixo, fn($a, $b) => $a['n10'] <=> $b['n10']);
    $n_abaixo = count(array_filter($abaixo, fn($r) => $r['n10'] < $media10));
    $analise = compact('questoes', 'ordem', 'faixas', 'abaixo', 'media10', 'tot', 'n_abaixo');
}

$titulo = $prova['titulo'];
$subtitulo = $prova['disciplina'] . ' · ' . $prova['serie'] . 'º ano · ' . $prova['bimestre'] . 'º bimestre · ' . $n . ' questões · vale ' . num($prova['valor']);
$menu = 'provas';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="d-flex gap-2 mb-3 flex-wrap nao-imprimir">
    <a class="btn btn-light" href="provas.php"><i class="bi bi-arrow-left me-1"></i>Provas</a>
    <div class="ms-auto d-flex gap-2">
        <?php if ($pronto): ?><a class="btn btn-primary" href="prova_corrigir.php?id=<?= $id ?>"><i class="bi bi-camera me-1"></i>Corrigir com a câmera</a><?php endif; ?>
<?php if (pode('provas.excluir')): ?>
        <form method="post" data-confirmar="Excluir esta prova e todas as correções feitas? As notas já lançadas no diário continuam." data-botao="Excluir prova">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir_prova"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-light" type="submit"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-abas mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link <?= $pronto ? '' : 'active' ?>" data-bs-toggle="tab" data-bs-target="#t-gab" data-aba="gabarito" type="button">1. Gabarito<?= $pronto ? ' <i class="bi bi-check-circle-fill text-success"></i>' : '' ?></button></li>
    <li class="nav-item"><button class="nav-link <?= $pronto && !$resultados ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t-folhas" data-aba="folhas" type="button">2. Folhas de resposta</button></li>
    <li class="nav-item"><button class="nav-link <?= $pronto && $resultados ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t-res" data-aba="resultados" type="button">3. Resultados<span class="contador"><?= count($resultados) ?></span></button></li>
    <?php if ($analise): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-ana" data-aba="analise" type="button"><i class="bi bi-bar-chart-line me-1"></i>4. Análise</button></li><?php endif; ?>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?= $pronto ? '' : 'show active' ?>" id="t-gab">
        <form method="post" class="card">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="gabarito"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="card-header"><h2>Respostas certas</h2><span class="small text-secondary">Clique na letra certa de cada questão. O traço anula a questão (vale ponto para todos).</span></div>
            <div class="card-body">
                <?php foreach ($prova['partes'] as $pt): ?>
                <?php if ($prova['bloco']): ?><div class="d-flex align-items-center gap-2 mb-2 mt-1"><span class="parte-tag" style="background:<?= $pt['cor'] ?>"><?= e($pt['nome']) ?></span><small class="text-secondary">questões <?= $pt['inicio'] + 1 ?> a <?= $pt['fim'] ?> · vale <?= num($pt['valor']) ?></small></div><?php endif; ?>
                <div class="gabarito-grade mb-3">
                    <?php for ($i = $pt['inicio'] + 1; $i <= $pt['fim']; $i++): $v = $gab[$i - 1]; ?>
                        <div class="gabarito-linha"><span class="n"<?= $prova['bloco'] ? ' style="color:' . $pt['cor'] . '"' : '' ?>><?= $i ?></span>
                            <span class="bolhas" role="radiogroup" aria-label="Questão <?= $i ?>">
                                <?php foreach ($letras as $l): ?>
                                    <input type="radio" name="q[<?= $i ?>]" id="q<?= $i . $l ?>" value="<?= $l ?>"<?= $v === $l ? ' checked' : '' ?>><label for="q<?= $i . $l ?>"><?= $l ?></label>
                                <?php endforeach; ?>
                                <input type="radio" class="anular" name="q[<?= $i ?>]" id="q<?= $i ?>x" value="*"<?= $v === '*' ? ' checked' : '' ?>><label for="q<?= $i ?>x" title="Anular questão">–</label>
                            </span>
                        </div>
                    <?php endfor; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="barra-salvar"><span class="small text-secondary">Se mudar o gabarito depois, as notas já corrigidas são recalculadas.</span>
                <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-check2 me-1"></i>Salvar gabarito</button></div>
        </form>
    </div>

    <div class="tab-pane fade <?= $pronto && !$resultados ? 'show active' : '' ?>" id="t-folhas">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h2>Folhas com o nome do aluno</h2></div>
                    <ul class="ranking">
                        <?php foreach ($turmas as $t): ?>
                            <li><span class="pos"><?= $t['serie'] ?>º<?= e($t['letra']) ?></span>
                                <span class="turma-nome"><?= e($t['curso']) ?><small><?= $t['alunos'] ?> alunos · <?= $t['corrigidas'] ?> corrigida(s)</small></span>
                                <a class="btn btn-sm btn-light" href="prova_folhas.php?id=<?= $id ?>&turma=<?= $t['id'] ?>" target="_blank"><i class="bi bi-printer me-1"></i>Imprimir <?= $t['alunos'] ?> folhas</a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-3"><div class="card-body">
                    <h3 class="h6">Dados impressos na folha</h3>
                    <form method="post" class="row g-2">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="dados_folha"><input type="hidden" name="id" value="<?= $id ?>">
                        <div class="col-12"><label class="form-label obrigatorio">Duração da prova</label>
                            <div class="d-flex align-items-center gap-2"><span class="small text-secondary">das</span>
                                <input class="form-control form-control-sm" type="time" name="hora_inicio" required value="<?= e(substr((string)($prova['hora_inicio'] ?? ''), 0, 5)) ?>" aria-label="Início">
                                <span class="small text-secondary">às</span>
                                <input class="form-control form-control-sm" type="time" name="hora_fim" required value="<?= e(substr((string)($prova['hora_fim'] ?? ''), 0, 5)) ?>" aria-label="Término"></div></div>
                        <div class="col-5"><label class="form-label" for="f_data">Data</label><input class="form-control form-control-sm" type="date" id="f_data" name="data_aplicacao" value="<?= e((string)$prova['data_aplicacao']) ?>"></div>
                        <div class="col-7"><label class="form-label" for="f_apl">Aplicador(es) <span class="text-secondary fw-normal">· opcional</span></label><input class="form-control form-control-sm" id="f_apl" name="aplicador" maxlength="160" value="<?= e((string)($prova['aplicador'] ?? '')) ?>" placeholder="à mão na folha"></div>
                        <?php if (omr_modelo($prova) < OMR_MODELO_ATUAL): ?>
                        <div class="col-12"><label class="form-check small"><input class="form-check-input" type="checkbox" name="modelo_novo" value="1">
                            Usar o <strong>modelo novo</strong> de folha <span class="text-secondary">(só marque se ainda não imprimiu folhas desta prova: as folhas do modelo antigo deixam de ser lidas)</span></label></div>
                        <?php endif; ?>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Salvar</button></div>
                    </form>
                </div></div>
                <div class="card mb-3"><div class="card-body">
                    <h3 class="h6">Folhas em branco</h3>
                    <p class="small text-secondary">Para aluno novo ou folha perdida. Na correção, o professor escolhe o aluno na tela.</p>
                    <form class="d-flex gap-2" action="prova_folhas.php" target="_blank">
                        <input type="hidden" name="id" value="<?= $id ?>"><input class="form-control" type="number" name="branco" min="1" max="60" value="5" aria-label="Quantidade">
                        <button class="btn btn-light text-nowrap" type="submit">Imprimir</button>
                    </form>
                </div></div>
                <div class="alert alert-success mb-0"><strong>Na impressão:</strong> papel A4, tamanho real (100%). Os quatro quadrados pretos da área de respostas precisam sair inteiros: são eles que a câmera usa para ler a folha.</div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?= $pronto && $resultados ? 'show active' : '' ?>" id="t-res">
        <?php if ($resultados):
            /* notas por disciplina já calculadas na escala 0 a 10 (é o que vai para o diário) */
            $partes_res = []; $soma = array_fill(0, count($prova['partes']), 0); $aprov = array_fill(0, count($prova['partes']), 0);
            foreach ($resultados as $r) { $pts = omr_corrigir($prova, $r['respostas'])[2]; $partes_res[$r['id']] = $pts;
                foreach ($pts as $pi => $pt) { $n10 = $pt['total'] ? $pt['acertos'] / $pt['total'] * 10 : 0; $soma[$pi] += $n10; if ($n10 >= MEDIA_APROVACAO) $aprov[$pi]++; } }
            $tot_r = count($resultados);
            $turmas_res = array_values(array_unique(array_map(fn($r) => turma_nome($r), $resultados)));
            $sem_disc = array_filter($prova['partes'], fn($pt) => empty($pt['disciplina_id'])); ?>

        <!-- 1. Resumo por matéria -->
        <div class="rs-materias">
            <div class="rs-total"><span class="rs-ic"><i class="bi bi-ui-checks-grid"></i></span><b><?= $tot_r ?></b><small>folha<?= $tot_r == 1 ? '' : 's' ?> corrigida<?= $tot_r == 1 ? '' : 's' ?></small></div>
            <?php foreach ($prova['partes'] as $pi => $pt): $med = $soma[$pi] / $tot_r; ?>
            <div class="rs-mat" style="--mc:<?= e($pt['cor'] ?? '#13692a') ?>">
                <span class="rs-mat-nome"><i class="bi bi-book"></i><?= e($pt['nome']) ?></span>
                <div class="rs-mat-num"><b class="<?= $med < MEDIA_APROVACAO ? 'ruim' : 'bom' ?>"><?= num($med, 1) ?></b><small>média (0 a 10)</small></div>
                <div class="rs-barra"><i style="width:<?= round($aprov[$pi] / $tot_r * 100) ?>%"></i></div>
                <small class="rs-mat-rod"><?= $aprov[$pi] ?> de <?= $tot_r ?> na média · questões <?= $pt['inicio'] + 1 ?> a <?= $pt['fim'] ?></small>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (pode('notas')): ?>
        <!-- 2. Lançar no diário -->
        <form method="post" class="rs-lancar nao-imprimir" data-confirmar="Lançar as <?= $tot_r ?> nota(s) desta prova no diário (<?= $prova['bimestre'] ?>º bimestre)? Cada matéria vira uma avaliação na sua disciplina, na escala de 0 a 10, e as médias são recalculadas." data-botao="Lançar notas">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="lancar"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="rs-lancar-cab"><span class="rs-ic laranja"><i class="bi bi-journal-arrow-down"></i></span>
                <div><h3>Lançar no diário de notas</h3><small>Cada matéria vai para a sua própria disciplina, em <?= $prova['bimestre'] ?>º bimestre, na escala de 0 a 10.</small></div></div>
            <div class="rs-destinos">
                <?php foreach ($prova['partes'] as $pt): ?>
                <span class="rs-dest<?= empty($pt['disciplina_id']) ? ' sem' : '' ?>" style="--mc:<?= e($pt['cor'] ?? '#13692a') ?>"><i class="bi bi-<?= empty($pt['disciplina_id']) ? 'x-circle' : 'arrow-right-circle-fill' ?>"></i><?= e($pt['nome']) ?><?= empty($pt['disciplina_id']) ? ' · sem disciplina, não será lançada' : '' ?></span>
                <?php endforeach; ?>
            </div>
            <div class="rs-lancar-rod">
                <label class="small fw-semibold" for="rsTipo">Lançar como</label>
                <select class="form-select form-select-sm" id="rsTipo" name="tipo" style="width:auto"><?php foreach (TIPOS_AVALIACAO as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                <button class="btn btn-primary ms-auto" type="submit"<?= count($sem_disc) === count($prova['partes']) ? ' disabled' : '' ?>><i class="bi bi-journal-check me-1"></i>Lançar <?= $tot_r ?> nota<?= $tot_r == 1 ? '' : 's' ?></button>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <!-- 3. Notas: matérias em cima, notas embaixo -->
        <div class="card rs-card">
            <div class="card-header"><h2><i class="bi bi-table me-1"></i>Notas por aluno</h2>
                <div class="acoes">
                    <?php if ($resultados): ?>
                        <input class="form-control form-control-sm nao-imprimir" type="search" id="rsBusca" placeholder="Procurar aluno…" style="max-width:190px" aria-label="Procurar aluno">
                        <?php if (count($turmas_res) > 1): ?><select class="form-select form-select-sm nao-imprimir" id="rsTurma" style="width:auto" aria-label="Turma"><option value="">Todas as turmas</option><?php foreach ($turmas_res as $tn): ?><option><?= e($tn) ?></option><?php endforeach; ?></select><?php endif; ?>
                        <a class="btn btn-sm btn-light" href="?id=<?= $id ?>&exportar=csv"><i class="bi bi-filetype-csv me-1"></i>Exportar</a>
                        <button class="btn btn-sm btn-light" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
                    <?php endif; ?>
                </div>
            </div>
            <?= cabecalho_impressao('Resultado · ' . $prova['titulo'], $subtitulo) ?>
            <?php if ($resultados): ?>
            <div class="table-responsive"><table class="table rs-tabela">
                <thead>
                    <tr class="rs-cab-mat"><th rowspan="2">Aluno</th>
                        <?php foreach ($prova['partes'] as $pt): ?><th class="rs-th-mat" style="--mc:<?= e($pt['cor'] ?? '#13692a') ?>"><span><?= e($pt['nome']) ?></span></th><?php endforeach; ?>
                        <th rowspan="2" class="num">Total</th><th rowspan="2" class="nao-imprimir"></th></tr>
                    <tr class="rs-cab-sub"><?php foreach ($prova['partes'] as $pt): ?><th class="text-center">nota 0 a 10 · acertos</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($resultados as $r): $pts = $partes_res[$r['id']]; ?>
                    <tr class="rs-linha" data-nome="<?= e(mb_strtolower($r['nome'])) ?>" data-turma="<?= e(turma_nome($r)) ?>">
                        <td><a class="nome-link" href="aluno.php?id=<?= $r['aluno_id'] ?>#notas"><?= e(nome_proprio($r['nome'])) ?></a><small class="d-block text-secondary"><?= e(turma_nome($r)) ?></small></td>
                        <?php foreach ($pts as $pi => $pt): $n10 = $pt['total'] ? $pt['acertos'] / $pt['total'] * 10 : 0; ?>
                        <td class="rs-nota" style="--mc:<?= e($prova['partes'][$pi]['cor'] ?? '#13692a') ?>"><b class="<?= $n10 < MEDIA_APROVACAO ? 'ruim' : 'bom' ?>"><?= num($n10, 1) ?></b><small><?= $pt['acertos'] ?>/<?= $pt['total'] ?></small></td>
                        <?php endforeach; ?>
                        <td class="num"><b><?= $r['acertos'] ?></b><small class="text-secondary">/<?= $n ?></small></td>
                        <td class="td-acoes nao-imprimir">
                            <button class="btn btn-sm btn-light rs-ver" type="button" data-bs-toggle="collapse" data-bs-target="#rsr<?= $r['id'] ?>" aria-expanded="false"><i class="bi bi-eye me-1"></i>Respostas</button>
                            <?php if (pode('provas.excluir')): ?>
                            <form method="post" class="d-inline" data-confirmar="Apagar a correção de <?= e(nome_proprio($r['nome'])) ?>?" data-botao="Apagar">
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir_resultado"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="resultado_id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="collapse rs-resp nao-imprimir" id="rsr<?= $r['id'] ?>"><td colspan="<?= count($prova['partes']) + 3 ?>">
                        <div class="rs-resp-grade">
                        <?php foreach ($prova['partes'] as $pi => $pt): ?>
                            <div class="rs-resp-mat" style="--mc:<?= e($pt['cor'] ?? '#13692a') ?>"><span class="rs-resp-tit"><?= e($pt['nome']) ?></span>
                                <div class="rs-bolas"><?php for ($i = $pt['inicio']; $i < $pt['fim']; $i++): $c = $r['respostas'][$i] ?? '-'; $ok = $gab[$i] === '*' || $c === $gab[$i]; $vz = !preg_match('/^[A-E]$/', $c); ?>
                                    <span class="<?= $ok ? 'ok' : ($vz ? 'vz' : 'err') ?>" title="Questão <?= $i + 1 ?>: marcou <?= $vz ? 'nada' : $c ?> · certa <?= $gab[$i] === '*' ? 'anulada' : $gab[$i] ?>"><small><?= $i + 1 ?></small><?= $vz ? '–' : e($c) ?></span>
                                <?php endfor; ?></div></div>
                        <?php endforeach; ?>
                        </div>
                        <div class="rs-leg"><span class="ok">certa</span><span class="err">errada</span><span class="vz">em branco / rasurada</span></div>
                    </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <div class="vazio"><i class="bi bi-camera"></i><strong>Nenhuma folha corrigida ainda</strong>Depois da prova, abra "Corrigir com a câmera" e passe as folhas na frente da câmera: a leitura é automática.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($analise): $A = $analise; ?>
    <div class="tab-pane fade" id="t-ana">
        <div class="pa-topo">
            <div class="pa-resumo">
                <div><span class="rotulo">Média da prova</span><strong><?= num($A['media10'], 1) ?></strong><small>na escala de 0 a 10</small></div>
                <div><span class="rotulo">Na média ou acima</span><strong class="ok"><?= $A['tot'] - $A['n_abaixo'] ?></strong><small>aluno(s)</small></div>
                <div><span class="rotulo">Abaixo da média</span><strong class="ruim"><?= $A['n_abaixo'] ?></strong><small>aluno(s)</small></div>
                <div><span class="rotulo">Questões críticas</span><strong class="ambar"><?= count(array_filter($A['questoes'], fn($q) => $q['pct'] < 40)) ?></strong><small>menos de 40% de acerto</small></div>
            </div>
            <div class="pa-faixas card"><div class="card-header"><h2>Distribuição das notas</h2></div>
                <div class="pa-barras"><?php $mx = max(1, max($A['faixas'])); foreach (['0–2', '2–4', '4–6', '6–8', '8–10'] as $fi => $rot): ?>
                    <div class="pa-barra f<?= $fi ?>"><span class="v"><?= $A['faixas'][$fi] ?></span><i style="height:<?= max(4, round($A['faixas'][$fi] / $mx * 100)) ?>%"></i><span class="r"><?= $rot ?></span></div>
                <?php endforeach; ?></div></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card h-100">
                    <div class="card-header"><h2>Da questão mais errada para a mais certa</h2><span class="small text-secondary">barra = % de acerto · em vermelho, a alternativa errada mais marcada</span></div>
                    <div class="pa-questoes">
                    <?php foreach ($A['ordem'] as $i): $qq = $A['questoes'][$i]; $nivel = $qq['pct'] < 40 ? 'ruim' : ($qq['pct'] < 70 ? 'medio' : 'bom'); ?>
                        <div class="pa-q <?= $nivel ?>">
                            <span class="pa-num"<?= $prova['bloco'] && $qq['parte'] !== null ? ' style="--c:' . $prova['partes'][$qq['parte']]['cor'] . '"' : '' ?>><?= $i + 1 ?></span>
                            <div class="pa-meio">
                                <div class="pa-trilho"><i style="width:<?= $qq['pct'] ?>%"></i></div>
                                <div class="pa-letras"><?php foreach ($letras as $l): $q_n = $qq['marc'][$l]; ?><span class="<?= $gab[$i] === $l ? 'certa' : ($qq['dist'] === $l ? 'distrator' : '') ?>" title="<?= $q_n ?> marcaram <?= $l ?>"><?= $l ?> <b><?= $q_n ?></b></span><?php endforeach; ?>
                                    <?php if ($qq['branco']): ?><span class="branco" title="em branco ou rasurada">∅ <b><?= $qq['branco'] ?></b></span><?php endif; ?>
                                    <?php if ($gab[$i] === '*'): ?><span class="certa">anulada</span><?php endif; ?></div>
                            </div>
                            <span class="pa-pct"><?= $qq['pct'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <?php $criticas = array_values(array_filter($A['ordem'], fn($i) => $A['questoes'][$i]['pct'] < 40)); if ($criticas): ?>
                <div class="pa-dica mb-3"><i class="bi bi-lightbulb"></i><div><strong>Sugestão para a próxima aula</strong>
                    Retome o conteúdo das questões <?= implode(', ', array_map(fn($i) => $i + 1, array_slice($criticas, 0, 6))) ?>.
                    <?php $d = $A['questoes'][$criticas[0]]; if ($d['dist']): ?>Na questão <?= $criticas[0] + 1 ?>, <?= $d['dist_n'] ?> aluno(s) marcaram <b><?= $d['dist'] ?></b>: vale discutir por que essa alternativa parece certa.<?php endif; ?></div></div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-header"><h2>Alunos que precisam de atenção</h2><span class="etiqueta"><?= count($A['abaixo']) ?></span></div>
                    <?php if ($A['abaixo']): ?>
                    <ul class="pa-alunos">
                        <?php foreach ($A['abaixo'] as $r): ?>
                        <li><div><a href="aluno.php?id=<?= $r['aluno_id'] ?>#notas"><?= e(nome_proprio($r['nome'])) ?></a>
                            <small><?= e(turma_nome($r)) ?> · <?= $r['acertos'] ?>/<?= $n ?> acertos<?= $r['fracas'] ? ' · abaixo em ' . e(implode(', ', $r['fracas'])) : '' ?></small></div>
                            <span class="pa-nota <?= $r['n10'] < MEDIA_APROVACAO ? 'ruim' : 'medio' ?>"><?= num($r['n10'], 1) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?><div class="vazio"><i class="bi bi-emoji-smile"></i><strong>Todos na média ou acima</strong>Turma foi bem nesta prova.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$script_pagina = ($script_pagina ?? '') . '<script>
(function () {
  const b = document.getElementById("rsBusca"), t = document.getElementById("rsTurma"); if (!b) return;
  const f = () => { const q = b.value.trim().toLowerCase(), tv = t ? t.value : "";
    document.querySelectorAll(".rs-linha").forEach(l => { const ok = (!q || l.dataset.nome.includes(q)) && (!tv || l.dataset.turma === tv); l.hidden = !ok; const r = l.nextElementSibling; if (!ok && r) r.classList.remove("show"); }); };
  b.addEventListener("input", f); if (t) t.addEventListener("change", f);
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
