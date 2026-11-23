<?php
require __DIR__ . '/../includes/app.php';
exigir_login();

$pode_editar = pode('calendario.editar');
if (eh_post()) {
    csrf_verificar();
    if (!$pode_editar) { flash('danger', 'Seu perfil não pode alterar o calendário.'); redirecionar('calendario.php'); }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'pdf') {
        try {
            [$cam, $mime, $tam, $orig] = arquivo_receber($_FILES['pdf'] ?? [], ['pdf']);
            q("UPDATE arquivos SET especial = 'calendario_sige_antigo' WHERE especial = 'calendario_sige'");
            $tit = trim((string)($_POST['titulo'] ?? '')) ?: 'Calendário letivo ' . ANO_VER . ' · SIGE';
            q('INSERT INTO arquivos (titulo, descricao, categoria, especial, nome_original, caminho, mime, tamanho, enviado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [mb_substr($tit, 0, 160), 'Calendário anual da escola (SIGE Escola).', 'Calendário', 'calendario_sige', $orig, $cam, $mime, $tam, uid()]);
            registrar_log('calendario', 'Novo PDF do calendário: ' . $orig);
            flash('success', 'Calendário atualizado. Todos já veem a nova versão.');
        } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    } elseif ($acao === 'evento') {
        $ini = (string)($_POST['data_inicio'] ?? ''); $fim = (string)($_POST['data_fim'] ?? '');
        $tit = trim((string)($_POST['titulo'] ?? '')); $tipo = isset(TIPOS_EVENTO[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'evento';
        if (!data_valida($ini) || $tit === '') flash('warning', 'Informe a data e o nome do evento.');
        else {
            q('INSERT INTO eventos (data_inicio, data_fim, titulo, tipo, publico, criado_por) VALUES (?, ?, ?, ?, ?, ?)', [$ini, data_valida($fim) && $fim > $ini ? $fim : null, mb_substr($tit, 0, 160), $tipo, !empty($_POST['publico']) ? 1 : 0, uid()]);
            flash('success', 'Evento adicionado à agenda.');
        }
    } elseif ($acao === 'evento_publico') {
        q('UPDATE eventos SET publico = 1 - publico WHERE id = ?', [(int)$_POST['id']]);
    } elseif ($acao === 'evento_excluir') {
        q('DELETE FROM eventos WHERE id = ?', [(int)$_POST['id']]);
        flash('success', 'Evento removido.');
    } elseif ($acao === 'periodos' && pode('config')) {
        foreach ([1, 2, 3, 4] as $n) foreach (['inicio', 'fim'] as $k) {
            $v = (string)($_POST["b{$n}_$k"] ?? '');
            if (data_valida($v)) config_gravar('bimestre_' . ANO_VER . "_{$n}_$k", $v);
        }
        flash('success', 'Datas dos períodos salvas.');
    }
    redirecionar('calendario.php' . (in_array($acao, ['evento', 'evento_excluir', 'evento_publico'], true) ? '#agenda' : ''));
}

$cal = q_um("SELECT a.*, u.nome AS por FROM arquivos a LEFT JOIN usuarios u ON u.id = a.enviado_por WHERE a.especial = 'calendario_sige' ORDER BY a.id DESC LIMIT 1");
if ($cal && !is_file(PASTA_DADOS . '/' . $cal['caminho'])) $cal['ausente'] = true;
$per = periodo_atual();
$bims = bimestres();
$eventos = eventos_entre(ANO_VER . '-01-01', (ANO_VER + 1) . '-01-31');
$por_mes = [];
foreach ($eventos as $ev) $por_mes[substr($ev['data_inicio'], 0, 7)][] = $ev;
$hoje = date('Y-m-d');

$titulo = 'Calendário SIGE';
$subtitulo = 'Calendário letivo ' . ANO_VER . ' da escola: períodos, feriados, recessos, sábados letivos e reuniões.';
$menu = 'calendario';
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';
?>
<section class="periodos-faixa mb-3">
    <?php foreach ($bims as $n => [$i, $f]): $atual = $per['bimestre'] === $n; $passou = $f < $hoje; ?>
        <div class="periodo-item<?= $atual ? ' atual' : ($passou ? ' passou' : '') ?>">
            <span class="pn"><?= $n ?>º período</span>
            <strong><?= date('d/m', strtotime($i)) ?> a <?= date('d/m', strtotime($f)) ?></strong>
            <?php if ($atual): ?><div class="progresso"><span style="width:<?= $per['progresso'] ?>%"></span></div><small><?= $per['progresso'] ?>% · faltam <?= $per['dias_restantes'] ?> dias</small>
            <?php else: ?><small><?= $passou ? 'concluído' : 'começa em ' . (int)round((strtotime($i) - strtotime($hoje)) / 86400) . ' dias' ?></small><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header flex-wrap"><h2><i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= e($cal['titulo'] ?? 'Calendário letivo') ?></h2>
                <?php if ($cal): ?><div class="acoes d-flex gap-2">
                    <a class="btn btn-sm btn-primary" href="arquivo.php?id=<?= $cal['id'] ?>&baixar=1"><i class="bi bi-download me-1"></i>Baixar PDF</a>
                    <a class="btn btn-sm btn-light" href="arquivo.php?id=<?= $cal['id'] ?>&inline=1" target="_blank" rel="noopener"><i class="bi bi-arrows-fullscreen me-1"></i>Tela cheia</a>
                </div><?php endif; ?></div>
            <?php if ($cal && empty($cal['ausente'])): ?>
                <div class="visualizador-pdf" data-url="arquivo.php?id=<?= $cal['id'] ?>&inline=1"></div>
                <div class="card-body py-2 small text-secondary">Atualizado em <?= data_br(substr($cal['criado_em'], 0, 10)) ?><?= $cal['por'] ? ' por ' . e($cal['por']) : '' ?></div>
            <?php else: ?>
                <div class="vazio py-5"><i class="bi bi-calendar3"></i><strong>O PDF do calendário ainda não foi enviado</strong><?= $pode_editar ? 'Use o quadro “Atualizar o PDF”.' : 'Peça à gestão para enviar.' ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card mb-3"><div class="card-header"><h2><i class="bi bi-bell me-1"></i>Próximos</h2></div>
            <ul class="agenda-lista">
                <?php foreach (proximos_eventos(7) as $ev): [$tr, $ti, $tc] = TIPOS_EVENTO[$ev['tipo']] ?? TIPOS_EVENTO['evento']; $agora = $ev['data_inicio'] <= $hoje; ?>
                    <li><span class="ag-data <?= $tc ?>"><b><?= date('d', strtotime($ev['data_inicio'])) ?></b><?= MESES_CURTO[(int)substr($ev['data_inicio'], 5, 2)] ?></span>
                        <div><strong><?= e($ev['titulo']) ?></strong><small><?= $ev['data_fim'] ? 'até ' . data_br($ev['data_fim']) . ' · ' : '' ?><?= $tr ?><?= $agora ? ' · <b class="text-success">acontecendo</b>' : '' ?></small></div></li>
                <?php endforeach; ?>
            </ul></div>
        <?php if ($pode_editar): ?>
        <form method="post" enctype="multipart/form-data" class="card mb-3">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="pdf">
            <div class="card-header"><h2>Atualizar o PDF</h2></div>
            <div class="card-body">
                <input class="form-control mb-2" type="file" name="pdf" accept="application/pdf" required>
                <input class="form-control" name="titulo" placeholder="Título (opcional)" maxlength="160">
                <div class="form-text">O PDF anterior fica guardado. Limite: <?= tamanho_fmt(limite_upload()) ?>.</div>
            </div>
            <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-upload me-1"></i>Enviar novo calendário</button></div>
        </form>
        <?php endif; ?>
        <?php if (pode('config')): ?>
        <form method="post" class="card">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="periodos">
            <div class="card-header"><h2>Datas dos períodos</h2></div>
            <div class="card-body">
                <?php foreach ($bims as $n => [$i, $f]): ?>
                    <div class="row g-2 mb-2 align-items-center"><div class="col-3 small fw-semibold"><?= $n ?>º</div>
                        <div class="col"><input class="form-control form-control-sm" type="date" name="b<?= $n ?>_inicio" value="<?= $i ?>" aria-label="Início do <?= $n ?>º período"></div>
                        <div class="col"><input class="form-control form-control-sm" type="date" name="b<?= $n ?>_fim" value="<?= $f ?>" aria-label="Fim do <?= $n ?>º período"></div></div>
                <?php endforeach; ?>
                <div class="form-text">Usadas no painel (período atual), na apresentação e nos relatórios.</div>
            </div>
            <div class="barra-salvar"><button class="btn btn-light ms-auto" type="submit">Salvar datas</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3" id="agenda">
    <div class="card-header"><h2><i class="bi bi-calendar-week me-1"></i>Agenda do ano</h2>
        <div class="legenda-eventos ms-auto d-none d-md-flex"><?php foreach (TIPOS_EVENTO as [$r, $ic, $c]): ?><span class="etiqueta <?= $c ?>"><i class="bi bi-<?= $ic ?>"></i><?= $r ?></span><?php endforeach; ?></div></div>
    <?php if ($pode_editar): ?>
    <form method="post" class="card-body border-bottom row g-2 align-items-end">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="evento">
        <div class="col-6 col-md-2"><label class="form-label" for="ev_i">Data</label><input class="form-control" type="date" id="ev_i" name="data_inicio" required></div>
        <div class="col-6 col-md-2"><label class="form-label" for="ev_f">Até (opcional)</label><input class="form-control" type="date" id="ev_f" name="data_fim"></div>
        <div class="col-md-3"><label class="form-label" for="ev_t">Evento</label><input class="form-control" id="ev_t" name="titulo" maxlength="160" required placeholder="Ex.: Feira de ciências"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="ev_tp">Tipo</label><select class="form-select" id="ev_tp" name="tipo" onchange="document.getElementById('ev_pub').checked = this.value !== 'reuniao'"><?php foreach (TIPOS_EVENTO as $k => [$r]): ?><option value="<?= $k ?>"<?= $k === 'evento' ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-1"><label class="form-check small mb-2" title="Aparece no calendário do site da escola"><input class="form-check-input" type="checkbox" id="ev_pub" name="publico" value="1" checked> No site</label></div>
        <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit">Adicionar</button></div>
    </form>
    <?php endif; ?>
    <div class="card-body">
        <div class="agenda-meses">
        <?php foreach ($por_mes as $ym => $evs): [$y, $m] = explode('-', $ym); ?>
            <div class="agenda-mes<?= $ym === date('Y-m') ? ' atual' : '' ?>">
                <h3><?= MESES[(int)$m] ?><?= (int)$y !== ANO_VER ? ' ' . $y : '' ?></h3>
                <ul><?php foreach ($evs as $ev): [$tr, $ti, $tc] = TIPOS_EVENTO[$ev['tipo']] ?? TIPOS_EVENTO['evento']; ?>
                    <li class="<?= ($ev['data_fim'] ?: $ev['data_inicio']) < $hoje ? 'passou' : '' ?>"><span class="ponto <?= $tc ?>" title="<?= $tr ?>"></span>
                        <b><?= date('d', strtotime($ev['data_inicio'])) ?><?= $ev['data_fim'] ? '–' . date('d/m', strtotime($ev['data_fim'])) : '' ?></b> <?= e($ev['titulo']) ?>
                        <?php if ($pode_editar): ?><form method="post" class="d-inline"><?= csrf_campo() ?><input type="hidden" name="acao" value="evento_publico"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button class="btn-x ev-site<?= !empty($ev['publico']) ? ' on' : '' ?>" type="submit" title="<?= !empty($ev['publico']) ? 'Aparece no site (clique para esconder)' : 'Não aparece no site (clique para mostrar)' ?>" aria-label="Mostrar ou esconder no site"><i class="bi bi-globe2"></i></button></form><?php endif; ?>
                        <?php if ($pode_editar): ?><form method="post" class="d-inline" data-confirmar="Remover &quot;<?= e($ev['titulo']) ?>&quot; da agenda?" data-botao="Remover"><?= csrf_campo() ?><input type="hidden" name="acao" value="evento_excluir"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button class="btn-x" type="submit" aria-label="Remover">×</button></form><?php endif; ?></li>
                <?php endforeach; ?></ul>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php
$script_pagina = '<script type="module" src="../assets/js/visualizador.js?v=10"></script>';
require __DIR__ . '/../includes/layout_rodape.php';
