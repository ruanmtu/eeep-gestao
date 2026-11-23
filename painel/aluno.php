<?php
require __DIR__ . '/../includes/app.php';
exigir_login();

$id = (int)($_GET['id'] ?? $_POST['aluno_id'] ?? 0);
$aluno = aluno_buscar($id);
/* v74: o(a) diretor(a) de turma sempre abre a ficha dos alunos da turma dele(a) (para registrar a busca ativa) */
if (!pode('alunos.ver') && !($aluno && dt_do_aluno($aluno))) exigir('alunos.ver');
if (!$aluno) { flash('warning', 'Aluno não encontrado.'); redirecionar('alunos.php'); }

/* ------------------------- Ações ------------------------- */
if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $volta = 'aluno.php?id=' . $id;

    switch ($acao) {
        case 'falta_add':
            if (!pode('frequencia')) break;
            [$t, $m] = falta_registrar($id, (string)($_POST['data_falta'] ?? ''));
            flash($t, $m); $volta .= '#frequencia';
            break;
        case 'falta_justificar':
            if (!pode('frequencia.justificar')) break;
            $j = trim((string)($_POST['justificativa'] ?? ''));
            $just = $j !== '' ? 1 : 0;
            q('UPDATE faltas SET justificada = ?, justificativa = ? WHERE id = ? AND aluno_id = ?', [$just, $j ?: null, (int)$_POST['falta_id'], $id]);
            registrar_log('falta_justificada', $just ? $j : 'Justificativa removida', $id);
            flash('success', $just ? 'Falta justificada.' : 'Justificativa removida.'); $volta .= '#frequencia';
            break;
        case 'falta_remover':
            if (!pode('frequencia.justificar')) break;
            $f = q_um('SELECT data_falta FROM faltas WHERE id = ? AND aluno_id = ?', [(int)$_POST['falta_id'], $id]);
            if ($f) {
                q('DELETE FROM faltas WHERE id = ?', [(int)$_POST['falta_id']]);
                registrar_log('falta_removida', 'Falta de ' . data_br($f['data_falta']) . ' removida', $id);
                flash('success', 'Falta de ' . data_br($f['data_falta']) . ' removida.');
            }
            $volta .= '#frequencia';
            break;
        case 'ocorrencia_add':
            if (!pode('ocorrencias')) break;
            $_POST['alunos'] = [$id];
            [$t, $m, $ids] = ocorrencia_salvar($_POST);
            flash($t, $m);
            if ($ids && !empty($_POST['imprimir'])) redirecionar('ocorrencia_termo.php?id=' . implode(',', $ids) . '&imprimir=1');
            $volta .= '#ocorrencias';
            break;
        case 'busca_add':
            if (!pode('busca_ativa') && !dt_do_aluno($aluno)) break;
            [$t, $m] = busca_salvar($id, array_merge($_POST, ['acao' => $_POST['acao_busca'] ?? '']), $_FILES['comprovante'] ?? null);
            flash($t, $m); $volta .= '#busca';
            break;
        case 'obs_add':
            [$t, $m] = observacao_salvar($aluno, (string)($_POST['tipo'] ?? ''), (string)($_POST['texto'] ?? ''));
            flash($t, $m); $volta .= '#observacoes';
            break;
        case 'obs_apagar':
            $o = q_um('SELECT * FROM aluno_observacoes WHERE id = ? AND aluno_id = ?', [(int)$_POST['obs_id'], $id]);
            if ($o && (eh_gestao() || ((int)$o['usuario_id'] === uid() && strtotime($o['criado_em']) > time() - 86400))) {
                q('DELETE FROM aluno_observacoes WHERE id = ?', [$o['id']]);
                registrar_log('observacao_apagada', mb_strimwidth($o['texto'], 0, 120, '…'), $id);
                flash('success', 'Observação apagada.');
            } else flash('warning', 'Só a gestão apaga observações (o autor pode apagar nas primeiras 24 horas).');
            $volta .= '#observacoes';
            break;
        case 'foto':
            if (!pode('alunos.editar')) break;
            try {
                if (!empty($_POST['remover'])) { foto_apagar($aluno['foto']); q('UPDATE alunos SET foto = NULL WHERE id = ?', [$id]); flash('success', 'Foto removida.'); }
                else { q('UPDATE alunos SET foto = ? WHERE id = ?', [foto_salvar($_FILES['foto'] ?? [], 'alunos', $id, $aluno['foto']), $id]); flash('success', 'Foto atualizada.'); registrar_log('aluno_foto', 'Foto atualizada', $id); }
            } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
            break;
        default:
            flash('warning', 'Ação não reconhecida.');
    }
    redirecionar($volta);
}

/* ------------------------- Dados ------------------------- */
$ini_ano = ANO_VER . '-01-01';
$fim_ano = ANO_VER . '-12-31';

$faltas = q_todos('SELECT f.*, u.nome AS por FROM faltas f LEFT JOIN usuarios u ON u.id = f.registrado_por
                    WHERE f.aluno_id = ? AND f.data_falta BETWEEN ? AND ? ORDER BY f.data_falta DESC', [$id, $ini_ano, $fim_ano]);
$faltas_ano = array_filter($faltas, fn($f) => $f['data_falta'] >= $ini_ano && $f['data_falta'] <= $fim_ano);
$faltas_pl = faltas_planilha_bimestres($id, ANO_VER);
$n_faltas = count($faltas_ano) + array_sum($faltas_pl);
$n_just = count(array_filter($faltas_ano, fn($f) => $f['justificada']));
$faltas_mes = array_fill(1, 12, 0);
foreach ($faltas_ano as $f) $faltas_mes[(int)substr($f['data_falta'], 5, 2)]++;

$ocorrencias = ocorrencias_lista('o.aluno_id = ? AND o.data_ocorrencia BETWEEN ? AND ?', [$id, $ini_ano, $fim_ano], 500);
$autorizacoes = autorizacoes_lista('s.aluno_id = ? AND s.data_hora BETWEEN ? AND ?', [$id, $ini_ano . ' 00:00:00', $fim_ano . ' 23:59:59'], 500);
$buscas = busca_lista('b.aluno_id = ? AND b.data_acao BETWEEN ? AND ?', [$id, $ini_ano, $fim_ano], 500);
$situacao_busca = busca_situacao($buscas);
$faltas_30 = count(array_filter($faltas, fn($f) => $f['data_falta'] >= date('Y-m-d', strtotime('-30 days')) && !$f['justificada']));
$suspenso = array_filter($ocorrencias, fn($o) => $o['suspensao_inicio'] && $o['suspensao_inicio'] <= date('Y-m-d') && $o['suspensao_fim'] >= date('Y-m-d'));
$boletim = boletim_aluno($id);
$media_geral = $boletim ? round(array_sum(array_column($boletim, 'media')) / count($boletim), 1) : null;
$abaixo = array_filter($boletim, fn($d) => $d['media'] < MEDIA_APROVACAO);
$n_ocorr = count($ocorrencias);
$faltas_aula = faltas_por_aula_aluno($id);
/* Observações: gestão e diretor(a) de turma veem todas; o professor vê as que ele escreveu */
$ve_todas_obs = eh_gestao() || dt_do_aluno($aluno);
$observacoes = array_values(array_filter(observacoes_aluno($id), fn($o) => $ve_todas_obs || (int)$o['usuario_id'] === uid()));

/* Linha do tempo: junta tudo em ordem cronológica */
$tempo = [];
foreach ($faltas as $f) $tempo[] = [$f['data_falta'], 'calendar-x', $f['justificada'] ? '' : 'ambar', 'Falta' . ($f['justificada'] ? ' justificada' : ''), $f['justificativa'] ?? ''];
foreach ($ocorrencias as $o) { [$rot, , $ic] = categoria_ocorrencia($o); $tempo[] = [$o['data_ocorrencia'], $ic, $o['gravidade'] === 'grave' ? 'vermelho' : 'ambar', $rot, $o['descricao']]; }
foreach (q_todos("SELECT l.*, u.nome AS por FROM log_atividades l LEFT JOIN usuarios u ON u.id = l.usuario_id
                  WHERE l.aluno_id = ? AND l.acao IN ('aluno_cadastrado','aluno_editado') ORDER BY l.id DESC LIMIT 20", [$id]) as $l) {
    $tempo[] = [substr($l['criado_em'], 0, 10), 'pencil-square', '', $l['acao'] === 'aluno_cadastrado' ? 'Matrícula cadastrada no sistema' : 'Dados cadastrais atualizados', $l['por'] ? 'Por ' . $l['por'] : ''];
}
foreach ($autorizacoes as $s) $tempo[] = [substr($s['data_hora'], 0, 10), tipo_autorizacao($s)[1], '', autorizacao_nome($s), $s['motivo']];
foreach ($buscas as $b) $tempo[] = [$b['data_acao'], ACOES_BUSCA[$b['acao']][1] ?? 'telephone', 'verde', 'Busca ativa: ' . (ACOES_BUSCA[$b['acao']][0] ?? $b['acao']) . ' · ' . (RESULTADOS_BUSCA[$b['resultado']][0] ?? ''), (string)$b['descricao']];
usort($tempo, fn($a, $b) => strcmp($b[0], $a[0]));

$titulo = 'Ficha do aluno';
$menu = 'alunos';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="d-flex align-items-center gap-2 mb-3 nao-imprimir flex-wrap">
    <a class="btn btn-sm btn-light" href="alunos.php"><i class="bi bi-arrow-left me-1"></i>Alunos</a>
    <div class="ms-auto d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-light" href="boletim.php?id=<?= $id ?>" target="_blank"><i class="bi bi-printer me-1"></i>Boletim</a>
        <button class="btn btn-sm btn-light" type="button" onclick="window.print()"><i class="bi bi-file-earmark-person me-1"></i>Imprimir ficha</button>
        <?php if (pode('alunos.editar')): ?><a class="btn btn-sm btn-primary" href="aluno_form.php?id=<?= $id ?>"><i class="bi bi-pencil me-1"></i>Editar dados</a><?php endif; ?>
    </div>
</div>

<?= cabecalho_impressao('Ficha do aluno', nome_proprio($aluno['nome']) . ' · ' . turma_nome($aluno)) ?>
<section class="ficha-cabecalho mb-3">
    <div class="foto-aluno">
        <?= avatar($aluno, 'aluno', 'avatar-xl') ?>
        <?php if (pode('alunos.editar')): ?>
        <form method="post" enctype="multipart/form-data" class="nao-imprimir">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="foto"><input type="hidden" name="aluno_id" value="<?= $id ?>">
            <label class="conta-camera" title="<?= $aluno['foto'] ? 'Trocar foto' : 'Adicionar foto' ?>"><i class="bi bi-camera-fill"></i>
                <input type="file" name="foto" accept="image/*" hidden data-recorte="1:1" data-saida="800" data-recorte-titulo="Enquadrar a foto do aluno" onchange="this.form.submit()"></label>
        </form>
        <?php endif; ?>
    </div>
    <div>
        <h2><?= e(nome_proprio($aluno['nome'])) ?></h2>
        <div class="ficha-meta">
            <span class="etiqueta contorno"><i class="bi bi-mortarboard"></i><?= e(turma_nome($aluno)) ?></span>
            <span class="etiqueta <?= $aluno['situacao'] === 'ativo' ? '' : 'cinza' ?>"><?= e(SITUACOES[$aluno['situacao']]) ?></span>
            <?php if ($aluno['matricula']): ?><span class="etiqueta cinza">Matrícula <?= e($aluno['matricula']) ?></span><?php endif; ?>
            <?php if ($suspenso): $sp = reset($suspenso); ?><span class="etiqueta vermelho"><i class="bi bi-slash-circle"></i>Suspenso até <?= data_br($sp['suspensao_fim']) ?></span><?php endif; ?>
            <?php if ((int)($aluno['tem_obs_medica'] ?? 0) === 1 || $aluno['necessidades_especiais']): ?><span class="etiqueta vermelho"><i class="bi bi-heart-pulse"></i>Observação médica</span><?php endif; ?>
        </div>
    </div>
    <div class="ficha-numeros">
        <div><strong class="<?= nivel_faltas($n_faltas) === 'critico' ? 'text-danger' : '' ?>"><?= $n_faltas ?></strong><span>faltas em <?= ANO_VER ?></span></div>
        <div><strong><?= $media_geral !== null ? num($media_geral) : '—' ?></strong><span>média geral</span></div>
        <div><strong class="<?= $n_ocorr >= 3 ? 'text-danger' : '' ?>"><?= $n_ocorr ?></strong><span>ocorrências</span></div>
    </div>
</section>

<?php if ($seq = alerta_do_aluno($id)): ?>
<div class="alerta-sequencia mb-3">
    <div class="alerta-topo"><span class="pulso"></span><h2><?= count($seq['dias']) ?> faltas seguidas</h2>
        <span class="small" style="opacity:.9"><?= e(dias_curtos($seq['dias'])) ?></span>
        <?php if ($w = link_whatsapp($aluno['responsavel_telefone'] ?: $aluno['telefone'])): ?><a class="btn btn-sm btn-light ms-auto alerta-acoes" href="<?= e($w) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp text-success me-1"></i>Falar com o responsável</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (nivel_faltas($n_faltas) !== 'ok' || $abaixo): ?>
<div class="alert alert-warning d-flex gap-2 nao-imprimir">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
        <?php if (nivel_faltas($n_faltas) === 'critico'): ?><strong>Busca ativa:</strong> <?= $n_faltas ?> faltas no ano. Recomenda-se contatar o responsável.<br><?php
        elseif (nivel_faltas($n_faltas) === 'atencao'): ?><strong>Atenção à frequência:</strong> <?= $n_faltas ?> faltas no ano.<br><?php endif; ?>
        <?php if ($abaixo): ?><strong>Abaixo da média</strong> em <?= count($abaixo) ?> disciplina(s): <?= e(implode(', ', array_column($abaixo, 'nome'))) ?>.<?php endif; ?>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-abas mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-dados" data-aba="dados" type="button" role="tab">Dados</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-frequencia" data-aba="frequencia" type="button" role="tab">Frequência<span class="contador"><?= $n_faltas ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-notas" data-aba="notas" type="button" role="tab">Notas</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-ocorrencias" data-aba="ocorrencias" type="button" role="tab">Ocorrências<span class="contador"><?= count($ocorrencias) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-autorizacoes" data-aba="autorizacoes" type="button" role="tab">Saídas e autorizações<span class="contador"><?= count($autorizacoes) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-busca" data-aba="busca" type="button" role="tab">Busca ativa<span class="contador"><?= count($buscas) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-observacoes" data-aba="observacoes" type="button" role="tab">Observações<span class="contador"><?= count($observacoes) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-tempo" data-aba="tempo" type="button" role="tab">Linha do tempo</button></li>
</ul>

<div class="tab-content">
    <!-- DADOS -->
    <div class="tab-pane fade show active" id="t-dados" role="tabpanel">
        <?php $tem_med = (int)($aluno['tem_obs_medica'] ?? 0) === 1; ?>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header"><h3>Ficha do aluno</h3><?php if (pode('alunos.editar')): ?><a class="ms-auto small nao-imprimir" href="aluno_form.php?id=<?= $id ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?></div><div class="card-body">
                    <dl class="dados-lista">
                        <div><dt>Nome</dt><dd><?= e(nome_proprio($aluno['nome'])) ?></dd></div>
                        <div><dt>Turma</dt><dd><?= e(turma_nome($aluno)) ?></dd></div>
                        <div><dt>Responsável</dt><dd><?= e($aluno['responsavel_nome'] ?: '—') ?><?= $aluno['responsavel_parentesco'] ? ' (' . e($aluno['responsavel_parentesco']) . ')' : '' ?></dd></div>
                        <div><dt>Contato do responsável</dt><dd>
                            <?php if ($aluno['responsavel_telefone']):
                                $tel = preg_replace('/\D/', '', $aluno['responsavel_telefone']); ?>
                                <a href="tel:<?= e($tel) ?>"><?= e($aluno['responsavel_telefone']) ?></a>
                                <a class="ms-1 nao-imprimir" href="https://wa.me/55<?= e($tel) ?>" target="_blank" rel="noopener" title="Abrir no WhatsApp"><i class="bi bi-whatsapp"></i></a>
                            <?php else: ?>—<?php endif; ?></dd></div>
                    </dl>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100<?= $tem_med ? ' ficha-med' : '' ?>"><div class="card-header"><h3><i class="bi bi-heart-pulse me-1"></i>Observação médica</h3></div><div class="card-body">
                    <?php if ($tem_med): ?>
                        <span class="etiqueta vermelho mb-2"><i class="bi bi-exclamation-circle"></i>Sim</span>
                        <p class="mb-0" style="white-space:pre-line"><?= e($aluno['obs_medica'] ?? '') ?></p>
                    <?php else: ?>
                        <span class="etiqueta">Não</span>
                        <p class="text-secondary small mb-0 mt-2">Nenhuma observação médica informada.</p>
                    <?php endif; ?>
                    <?php if ($aluno['necessidades_especiais']): ?><p class="small text-secondary mt-3 mb-0" style="white-space:pre-line"><b>Registro anterior:</b> <?= e($aluno['necessidades_especiais']) ?></p><?php endif; ?>
                </div></div>
            </div>
        </div>
    </div>

    <!-- FREQUÊNCIA -->
    <div class="tab-pane fade" id="t-frequencia" role="tabpanel">
        <div class="row g-3">
            <div class="col-lg-4">
                <?php if (pode('frequencia')): ?>
                <div class="card mb-3"><div class="card-header"><h3>Registrar falta</h3></div><div class="card-body">
                    <form method="post" class="d-flex gap-2 align-items-end">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="falta_add"><input type="hidden" name="aluno_id" value="<?= $id ?>">
                        <div class="flex-grow-1"><label class="form-label" for="dt_falta">Data</label>
                            <input type="date" class="form-control" id="dt_falta" name="data_falta" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                        <button class="btn btn-primary" type="submit">Registrar</button>
                    </form>
                    <p class="form-text mb-0 mt-2">Para lançar as faltas de várias turmas no dia, use a <a href="frequencia.php">coleta de faltas</a>.</p>
                </div></div>
                <?php endif; ?>
                <?php if ($faltas_pl): ?>
                <div class="card mb-3"><div class="card-header"><h3>Faltas da planilha</h3><span class="ms-auto small text-secondary">importadas</span></div><div class="card-body py-2">
                    <ul class="lista-linhas">
                        <?php $maxp = max(1, ...array_values($faltas_pl)); foreach ($faltas_pl as $b => $n): ?>
                            <li><span style="width:80px"><?= $b ?>º bimestre</span><span class="barra-prop"><span style="width:<?= round($n / $maxp * 100) ?>%"></span></span><strong style="width:28px;text-align:right"><?= $n ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                </div></div>
                <?php endif; ?>
                <div class="card"><div class="card-header"><h3>Faltas por mês</h3><span class="ms-auto small text-secondary"><?= $n_just ?> justificada(s)</span></div><div class="card-body py-2">
                    <ul class="lista-linhas">
                        <?php $max = max(1, ...$faltas_mes); foreach ($faltas_mes as $m => $n): if ($m > (int)date('n') && ANO_VER == date('Y') && !$n) continue; ?>
                            <li><span style="width:80px"><?= MESES[$m] ?></span><span class="barra-prop"><span style="width:<?= round($n / $max * 100) ?>%"></span></span><strong style="width:28px;text-align:right"><?= $n ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                </div></div>
            </div>
            <div class="col-lg-8">
                <div class="card"><div class="card-header"><h3>Histórico de faltas</h3></div>
                <?php if ($faltas): ?>
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>Data</th><th>Dia</th><th>Situação</th><th class="text-end nao-imprimir">Ações</th></tr></thead>
                        <tbody>
                        <?php foreach ($faltas as $f): ?>
                            <tr>
                                <td><strong><?= data_br($f['data_falta']) ?></strong></td>
                                <td><?= dia_semana($f['data_falta']) ?></td>
                                <td><?php if ($f['justificada']): ?><span class="etiqueta">Justificada</span> <small class="text-secondary"><?= e($f['justificativa']) ?></small>
                                    <?php else: ?><span class="etiqueta ambar">Não justificada</span><?php endif; ?></td>
                                <td class="td-acoes nao-imprimir">
                                    <?php if (pode('frequencia.justificar')): ?>
                                        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#just<?= $f['id'] ?>"><?= $f['justificada'] ? 'Editar' : 'Justificar' ?></button>
                                        <form method="post" class="d-inline" data-confirmar="Remover a falta de <?= data_br($f['data_falta']) ?>?" data-botao="Remover falta">
                                            <?= csrf_campo() ?><input type="hidden" name="acao" value="falta_remover"><input type="hidden" name="aluno_id" value="<?= $id ?>"><input type="hidden" name="falta_id" value="<?= $f['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remover falta"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (pode('frequencia.justificar')): ?>
                            <tr class="collapse nao-imprimir" id="just<?= $f['id'] ?>"><td colspan="4">
                                <form method="post" class="d-flex gap-2">
                                    <?= csrf_campo() ?><input type="hidden" name="acao" value="falta_justificar"><input type="hidden" name="aluno_id" value="<?= $id ?>"><input type="hidden" name="falta_id" value="<?= $f['id'] ?>">
                                    <input type="text" class="form-control form-control-sm" name="justificativa" value="<?= e($f['justificativa']) ?>" maxlength="255" placeholder="Ex.: atestado médico (deixe vazio para tirar a justificativa)">
                                    <button class="btn btn-sm btn-primary" type="submit">Salvar</button>
                                </form>
                            </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <div class="vazio"><i class="bi bi-calendar-check"></i><strong>Nenhuma falta registrada</strong>Frequência em dia.</div>
                <?php endif; ?>
                </div>
                <?php if ($faltas_aula): ?>
                <div class="card mt-3"><div class="card-header"><h3>Faltas por aula</h3><span class="ms-auto small text-secondary">histórico da antiga chamada por aula</span></div>
                <?php $por_disc = array_count_values(array_map(fn($f) => $f['disciplina'] ?: 'Sem disciplina', $faltas_aula)); arsort($por_disc); ?>
                    <div class="fa-resumo"><?php foreach ($por_disc as $dn => $q_n): ?><span><?= e($dn) ?> <b><?= $q_n ?></b></span><?php endforeach; ?></div>
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>Data</th><th>Aula</th><th>Disciplina</th><th>Professor(a)</th></tr></thead>
                        <tbody><?php foreach ($faltas_aula as $f): ?>
                            <tr><td><strong><?= data_br($f['data']) ?></strong> <small class="text-secondary"><?= dia_semana($f['data']) ?></small></td><td><?= (int)$f['aula'] ?>ª</td><td><?= e($f['disciplina'] ?? '—') ?></td><td class="small"><?= e(primeiro_ultimo((string)$f['professor'])) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- NOTAS -->
    <div class="tab-pane fade" id="t-notas" role="tabpanel">
        <?php $rd_aluno = resultados_aluno($id); ?>
        <section class="rd rd-aluno mb-3" id="resultadosAluno" data-media="<?= MEDIA_APROVACAO ?>" data-dados="<?= e(json_encode($rd_aluno, JSON_UNESCAPED_UNICODE)) ?>">
            <div class="rd-topo">
                <div class="rd-tit"><span class="rd-ic"><i class="bi bi-person-lines-fill"></i></span><div><h2>Desempenho de <?= e(primeiro_ultimo(nome_proprio($aluno['nome']))) ?></h2><small><?= e(turma_nome($aluno, false)) ?> · <?= ANO_VER ?></small></div></div>
                <div class="rd-periodos" role="tablist" aria-label="Período">
                    <?php foreach ([1, 2, 3, 4] as $b): ?><button type="button" data-bim="<?= $b ?>"><?= $b ?>º per.</button><?php endforeach; ?><button type="button" data-bim="0">Ano</button>
                </div>
            </div>
            <div class="rd-corpo"></div>
        </section>
        <div class="card"><div class="card-header"><h3>Notas de <?= ANO_VER ?></h3><span class="ms-auto small text-secondary">Média para aprovação: <?= num(MEDIA_APROVACAO) ?></span></div>
        <?php if ($boletim): ?>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Disciplina</th><th class="num">1º bim.</th><th class="num">2º bim.</th><th class="num">3º bim.</th><th class="num">4º bim.</th><th class="num">Média</th></tr></thead>
                <tbody>
                <?php $tipo_atual = null; foreach ($boletim as $d):
                    if ($d['tipo'] !== $tipo_atual): $tipo_atual = $d['tipo']; ?>
                        <tr><td colspan="6" class="small fw-bold text-secondary bg-light"><?= $tipo_atual === 'tecnica' ? 'Formação técnica' : 'Base comum' ?></td></tr>
                    <?php endif; ?>
                    <tr><td><?= e($d['nome']) ?></td>
                        <?php for ($b = 1; $b <= 4; $b++): ?><td class="num"><?= nota_fmt($d['b'][$b] ?? null) ?></td><?php endfor; ?>
                        <td class="num"><strong><?= nota_fmt($d['media']) ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <div class="vazio"><i class="bi bi-journal"></i><strong>Ainda não há notas lançadas</strong>As notas aparecem aqui quando os professores lançam pelo menu Notas.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- OCORRÊNCIAS -->
    <div class="tab-pane fade" id="t-ocorrencias" role="tabpanel">
        <div class="row g-3">
            <?php if (pode('ocorrencias')): ?>
            <div class="col-lg-5 nao-imprimir order-lg-2"><div class="card"><div class="card-header"><h3>Registrar ocorrência</h3></div><div class="card-body"><?= form_ocorrencia($aluno) ?></div></div></div>
            <?php endif; ?>
            <div class="col-lg-<?= pode('ocorrencias') ? 7 : 12 ?>">
                <?php if ($ocorrencias): foreach ($ocorrencias as $o) echo ocorrencia_cartao($o, false, 'aluno.php?id=' . $id); else: ?>
                    <div class="card"><div class="vazio"><i class="bi bi-shield-check"></i><strong>Nenhuma ocorrência registrada</strong>Advertências, retiradas de sala e suspensões aparecem aqui.</div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SAÍDAS E AUTORIZAÇÕES -->
    <div class="tab-pane fade" id="t-autorizacoes" role="tabpanel">
        <?php $n_saidas = count(array_filter($autorizacoes, fn($x) => ($x['tipo'] ?? 'saida') === 'saida'));
              $vigentes = array_filter($autorizacoes, fn($x) => ($x['tipo'] ?? 'saida') !== 'saida' && autorizacao_vigente($x)); ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="etiqueta cinza"><?= $n_saidas ?> saída(s) da escola</span>
            <span class="etiqueta cinza"><?= count($autorizacoes) - $n_saidas ?> outra(s) autorização(ões)</span>
            <?php foreach ($vigentes as $v): ?><span class="etiqueta azul"><i class="bi bi-<?= tipo_autorizacao($v)[1] ?>"></i><?= e(autorizacao_nome($v)) ?> · válida hoje</span><?php endforeach; ?>
            <?php if (pode('saidas')): ?>
                <a class="btn btn-sm btn-light ms-auto" href="saidas.php?aluno=<?= $id ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir em Autorizações</a>
                <a class="btn btn-sm btn-primary" href="saidas.php?novo=saida&aluno=<?= $id ?>"><i class="bi bi-plus-lg me-1"></i>Nova autorização</a>
            <?php endif; ?>
        </div>
        <?php if ($autorizacoes): ?>
            <div class="card"><ul class="lista-autorizacoes">
                <?php foreach ($autorizacoes as $s): [$rot, $ic] = tipo_autorizacao($s); ?>
                    <li class="la-<?= e($s['tipo'] ?? 'saida') ?> clicavel">
                        <span class="la-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                        <a class="la-corpo link-esticado" href="<?= pode('saidas') ? 'saidas.php?aluno=' . $id . '&ver=' . $s['id'] . '#aut' . $s['id'] : '#' ?>">
                            <div class="la-topo"><strong><?= e(autorizacao_nome($s)) ?></strong><?php if (($s['tipo'] ?? 'saida') !== 'saida' && autorizacao_vigente($s)): ?><span class="etiqueta">válida hoje</span><?php endif; ?></div>
                            <small class="text-secondary"><?= ($s['tipo'] ?? 'saida') === 'saida' ? data_br($s['data_hora']) . ' às ' . date('H:i', strtotime($s['data_hora'])) . ' · ' . e($s['motivo']) . ($s['responsavel_nome'] ? ' · resp.: ' . e($s['responsavel_nome']) . ($s['responsavel_parentesco'] ? ' (' . e($s['responsavel_parentesco']) . ')' : '') : '') : e(vigencia_texto($s)) . ' · ' . e($s['motivo']) ?></small>
                            <div class="la-ass"><i class="bi bi-pen"></i> <?= e($s['autorizado_nome']) ?> · <?= e($s['autorizado_cargo']) ?></div>
                        </a>
                        <?php if (pode('saidas')): ?><a class="btn btn-sm btn-light position-relative" style="z-index:2" href="saida_cupom.php?id=<?= $s['id'] ?>" target="_blank" title="Cupom"><i class="bi bi-printer"></i></a><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul></div>
        <?php else: ?>
            <div class="card"><div class="vazio"><i class="bi bi-patch-check"></i><strong>Nenhuma saída ou autorização registrada</strong>As saídas da escola, autorizações de uso de tablet e outras aparecem aqui.</div></div>
        <?php endif; ?>
    </div>

    <!-- BUSCA ATIVA -->
    <div class="tab-pane fade" id="t-busca" role="tabpanel">
        <div class="row g-3">
            <div class="col-xl-5">
                <div class="card mb-3 ba-resumo"><div class="card-body">
                    <div class="d-flex gap-3 flex-wrap">
                        <div class="ba-num"><strong><?= $faltas_30 ?></strong><span>faltas sem justificativa<br>nos últimos 30 dias</span></div>
                        <div class="ba-num"><strong><?= count($buscas) ?></strong><span>providência(s)<br>registrada(s)</span></div>
                    </div>
                    <?php if ($situacao_busca): [$sr, $sc, $st] = $situacao_busca; ?>
                        <div class="mt-3"><span class="etiqueta <?= $sc ?>"><?= e($sr) ?></span><div class="small text-secondary mt-1"><?= e($st) ?></div></div>
                    <?php else: ?><p class="small text-secondary mt-3 mb-0">Nenhuma providência de busca ativa registrada para este aluno.</p><?php endif; ?>
                    <div class="small mt-2"><i class="bi bi-telephone me-1"></i>Responsável: <?= e(($aluno['responsavel_nome'] ?? '') ?: ($aluno['nome_mae'] ?? '') ?: '—') ?><?= !empty($aluno['responsavel_telefone']) ? ' · <a href="tel:' . e(preg_replace('/\D/', '', $aluno['responsavel_telefone'])) . '">' . e($aluno['responsavel_telefone']) . '</a>' : '' ?></div>
                </div></div>
                <?php if (pode('busca_ativa') || dt_do_aluno($aluno)): ?>
                <div class="card"><div class="card-header"><h3>Registrar providência</h3></div><div class="card-body"><?= busca_formulario($aluno, $faltas_30) ?></div></div>
                <?php endif; ?>
            </div>
            <div class="col-xl-7">
                <div class="card"><div class="card-header"><h3>Histórico da busca ativa</h3><button class="btn btn-sm btn-light ms-auto" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button></div>
                    <?php if ($buscas): ?><ul class="ba-lista"><?php foreach ($buscas as $b) echo busca_cartao($b); ?></ul>
                    <?php else: ?><div class="vazio"><i class="bi bi-telephone-outbound"></i><strong>Sem registros</strong>Ligações, mensagens, ofícios, visitas e encaminhamentos feitos pelo diretor de turma aparecem aqui.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- OBSERVAÇÕES -->
    <div class="tab-pane fade" id="t-observacoes" role="tabpanel">
        <div class="row g-3">
            <div class="col-lg-5 nao-imprimir order-lg-2">
                <div class="card"><div class="card-header"><h3>Nova observação</h3></div><div class="card-body">
                    <form method="post">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="obs_add"><input type="hidden" name="aluno_id" value="<?= $id ?>">
                        <div class="obs-tipos mb-2" role="radiogroup" aria-label="Tipo">
                            <?php foreach (TIPOS_OBSERVACAO as $k => [$rot, $ic, $cor]): ?>
                                <input type="radio" name="tipo" id="ot_<?= $k ?>" value="<?= $k ?>"<?= $k === 'neutra' ? ' checked' : '' ?>><label for="ot_<?= $k ?>" class="<?= $cor ?>"><i class="bi bi-<?= $ic ?>"></i><?= $rot ?></label>
                            <?php endforeach; ?>
                        </div>
                        <textarea class="form-control mb-2" name="texto" rows="4" maxlength="600" required placeholder="Ex.: participa muito nas aulas práticas; anda mais calado nas últimas semanas; precisa sentar na frente para enxergar o quadro…"></textarea>
                        <div class="d-flex align-items-center gap-2"><small class="text-secondary">Não conta como ocorrência. <?= $aluno['turma_id'] ? 'Ponto de atenção avisa o(a) diretor(a) de turma.' : '' ?></small>
                            <button class="btn btn-primary ms-auto text-nowrap" type="submit"><i class="bi bi-plus-lg me-1"></i>Registrar</button></div>
                    </form>
                </div></div>
                <p class="small text-secondary mt-2 px-1"><i class="bi bi-lock me-1"></i>Visível para a gestão, para o(a) diretor(a) de turma e para quem escreveu.</p>
            </div>
            <div class="col-lg-7">
                <div class="card"><div class="card-header"><h3>Observações dos professores</h3></div>
                <?php if ($observacoes): ?>
                    <ul class="obs-lista">
                    <?php foreach ($observacoes as $o): [$rot, $ic, $cor] = TIPOS_OBSERVACAO[$o['tipo']] ?? TIPOS_OBSERVACAO['neutra']; ?>
                        <li class="obs-<?= $cor ?>">
                            <span class="obs-ic"><i class="bi bi-<?= $ic ?>"></i></span>
                            <div class="obs-corpo">
                                <div class="obs-topo"><strong><?= e($rot) ?></strong><span><?= e(primeiro_ultimo((string)$o['usuario_nome'])) ?> · <?= data_hora_br($o['criado_em']) ?></span></div>
                                <p><?= nl2br(e($o['texto'])) ?></p>
                            </div>
                            <?php if (eh_gestao() || ((int)$o['usuario_id'] === uid() && strtotime($o['criado_em']) > time() - 86400)): ?>
                            <form method="post" class="nao-imprimir" data-confirmar="Apagar esta observação?" data-botao="Apagar">
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="obs_apagar"><input type="hidden" name="aluno_id" value="<?= $id ?>"><input type="hidden" name="obs_id" value="<?= $o['id'] ?>">
                                <button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="vazio"><i class="bi bi-chat-square-heart"></i><strong>Nenhuma observação ainda</strong>Registre comportamentos positivos, pontos de atenção ou combinados com o aluno.</div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- LINHA DO TEMPO -->
    <div class="tab-pane fade" id="t-tempo" role="tabpanel">
        <div class="card"><div class="card-body">
            <?php if ($tempo): ?>
                <ol class="linha-tempo">
                    <?php foreach (array_slice($tempo, 0, 80) as [$data, $icone, $cor, $oque, $det]): ?>
                        <li><span class="marcador <?= $cor ?>"><i class="bi bi-<?= $icone ?>"></i></span>
                            <div class="quando"><?= data_br($data) ?> · <?= dia_semana($data) ?></div>
                            <div class="oque"><?= e($oque) ?></div>
                            <?php if ($det): ?><div class="text-secondary small"><?= e(mb_strimwidth($det, 0, 180, '…')) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <div class="vazio"><i class="bi bi-clock-history"></i><strong>Sem registros ainda</strong>Faltas e ocorrências aparecem aqui em ordem.</div>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_rodape.php';
