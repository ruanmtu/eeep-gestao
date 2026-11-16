<?php
/* Próximos eventos do site: a lista que aparece ao lado das notícias */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$tipos = ['evento' => 'Evento', 'feriado' => 'Feriado', 'recesso' => 'Recesso / férias', 'sabado_letivo' => 'Sábado letivo', 'reuniao' => 'Reunião', 'periodo' => 'Período letivo'];
$eid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = $eid ? q_um('SELECT * FROM eventos WHERE id = ?', [$eid]) : null;

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $ini = (string)($_POST['data_inicio'] ?? ''); $fim = (string)($_POST['data_fim'] ?? '');
            $tit = trim((string)($_POST['titulo'] ?? '')); $tipo = isset($tipos[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'evento';
            if (!data_valida($ini) || mb_strlen($tit) < 3) throw new RuntimeException('Informe a data e o nome do evento.');
            $fim = data_valida($fim) && $fim > $ini ? $fim : null;
            $pub = !empty($_POST['publico']) ? 1 : 0;
            if ($atual) q('UPDATE eventos SET data_inicio = ?, data_fim = ?, titulo = ?, tipo = ?, publico = ? WHERE id = ?', [$ini, $fim, mb_substr($tit, 0, 160), $tipo, $pub, $eid]);
            else q('INSERT INTO eventos (data_inicio, data_fim, titulo, tipo, publico, criado_por) VALUES (?, ?, ?, ?, ?, ?)', [$ini, $fim, mb_substr($tit, 0, 160), $tipo, $pub, uid()]);
            registrar_log('agenda_site', $tit);
            flash('success', $atual ? 'Evento atualizado.' : 'Evento adicionado.');
        } elseif ($acao === 'publico' && $atual) {
            q('UPDATE eventos SET publico = 1 - publico WHERE id = ?', [$eid]);
        } elseif ($acao === 'apagar' && $atual) {
            q('DELETE FROM eventos WHERE id = ?', [$eid]);
            flash('success', 'Evento apagado.');
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('agenda_site.php' . ($eid ? '?editar=' . $eid : '')); }
    catch (PDOException $e) { flash('warning', 'Já existe um evento com esse nome nessa data.'); }
    redirecionar('agenda_site.php');
}

$hoje = date('Y-m-d');
$ver_passados = !empty($_GET['passados']);
$lista = q_todos('SELECT * FROM eventos WHERE ' . ($ver_passados ? 'COALESCE(data_fim, data_inicio) < ? ORDER BY data_inicio DESC LIMIT 60' : 'COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio, id'), [$hoje]);
$informes_dia = [];
try { $informes_dia = q_todos('SELECT id, titulo, data_evento, categoria FROM site_informes WHERE publicado = 1 AND (data_evento >= ? OR (data_evento IS NULL AND categoria = ?)) ORDER BY data_evento IS NULL, data_evento', [$hoje, 'evento']); } catch (PDOException $e) {}
$f = $atual ?: ['id' => 0, 'data_inicio' => '', 'data_fim' => null, 'titulo' => '', 'tipo' => 'evento', 'publico' => 1];
$n_site = count(array_filter($lista, fn($e) => !empty($e['publico'])));

$titulo = 'Próximos eventos do site';
$subtitulo = 'A lista que aparece ao lado das notícias na página inicial. Usa as mesmas datas do Calendário SIGE.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="pg-acoes"><?= janela_botao('novoEvento', 'Novo evento', 'plus-lg') ?></div>
<div class="row g-3">
    <?php if ($informes_dia): ?>
    <div class="col-xl-4 order-xl-2">
        <div class="card"><div class="card-header"><h2><i class="bi bi-megaphone me-1"></i>Vindos dos informes</h2></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($informes_dia as $i): ?><li class="list-group-item d-flex gap-2 align-items-center"><span class="text-nowrap fw-bold"><?= $i['data_evento'] ? date('d/m', strtotime($i['data_evento'])) : 'a confirmar' ?></span><span class="flex-grow-1"><?= e($i['titulo']) ?></span><a href="informes_site.php?editar=<?= $i['id'] ?>" aria-label="Editar informe"><i class="bi bi-pencil"></i></a></li><?php endforeach; ?>
            </ul>
            <div class="card-body small text-secondary py-2">Informes com "Dia do evento" entram na lista sozinhos. Edite a data no próprio informe.</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?= $informes_dia ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header flex-wrap"><h2><i class="bi bi-calendar-event me-1"></i><?= $ver_passados ? 'Eventos passados' : 'Próximos eventos' ?></h2>
                <span class="ms-auto small text-secondary"><?= $ver_passados ? '' : $n_site . ' no site · ' ?><a href="?<?= $ver_passados ? '' : 'passados=1' ?>"><?= $ver_passados ? 'ver próximos' : 'ver passados' ?></a></span></div>
            <?php if (!$lista): ?><div class="vazio"><i class="bi bi-calendar-x"></i><strong>Nenhum evento <?= $ver_passados ? 'passado' : 'marcado' ?></strong><?= $ver_passados ? '' : 'Adicione o primeiro ao lado.' ?></div><?php endif; ?>
            <ul class="ags-lista">
                <?php foreach ($lista as $ev): [$cor] = [AGENDA_TIPOS[$ev['tipo']][1] ?? '#1d5fb0']; ?>
                <li class="<?= empty($ev['publico']) ? 'fora' : '' ?><?= (int)$ev['id'] === $eid ? ' ativo' : '' ?>">
                    <span class="ags-data" style="--c:<?= $cor ?>"><b><?= date('d', strtotime($ev['data_inicio'])) ?></b><?= MESES_CURTO[(int)substr($ev['data_inicio'], 5, 2)] ?></span>
                    <div class="ags-txt"><strong><?= e($ev['titulo']) ?></strong><small><?= $tipos[$ev['tipo']] ?? 'Evento' ?><?= $ev['data_fim'] ? ' · até ' . date('d/m', strtotime($ev['data_fim'])) : '' ?><?= empty($ev['publico']) ? ' · <b>fora do site</b>' : '' ?></small></div>
                    <div class="ags-acoes">
                        <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="publico"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button class="btn btn-sm btn-light" type="submit" title="<?= empty($ev['publico']) ? 'Mostrar no site' : 'Tirar do site' ?>" aria-label="Mostrar ou esconder no site"><i class="bi bi-<?= empty($ev['publico']) ? 'eye-slash' : 'eye' ?>"></i></button></form>
                        <a class="btn btn-sm btn-light" href="?editar=<?= $ev['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                        <form method="post" data-confirmar="Apagar &quot;<?= e($ev['titulo']) ?>&quot;? Ele sai também do calendário do painel." data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?= janela_abre('novoEvento', $atual ? 'Editar evento' : 'Novo evento', $atual ? 'pencil' : 'calendar-plus', (bool)$atual) ?>
        <form method="post" class="card">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="card-header"><h2><i class="bi bi-<?= $atual ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $atual ? 'Editar evento' : 'Novo evento' ?></h2><?php if ($atual): ?><a class="ms-auto small" href="agenda_site.php">novo</a><?php endif; ?></div>
            <div class="card-body">
                <label class="form-label obrigatorio" for="agT">Nome do evento</label>
                <input class="form-control mb-3" id="agT" name="titulo" maxlength="160" required value="<?= e($f['titulo']) ?>" placeholder="Ex.: Feira de Ciências e Tecnologia">
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label obrigatorio" for="agI">Data</label><input class="form-control" type="date" id="agI" name="data_inicio" required value="<?= e($f['data_inicio']) ?>"></div>
                    <div class="col-6"><label class="form-label" for="agF">Até <span class="text-secondary fw-normal">· opcional</span></label><input class="form-control" type="date" id="agF" name="data_fim" value="<?= e((string)$f['data_fim']) ?>"></div>
                </div>
                <label class="form-label" for="agTp">Tipo</label>
                <select class="form-select mb-3" id="agTp" name="tipo"><?php foreach ($tipos as $k => $r): ?><option value="<?= $k ?>"<?= $f['tipo'] === $k ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select>
                <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="publico" value="1"<?= !empty($f['publico']) ? ' checked' : '' ?>> Mostrar no site</label>
                <div class="form-text">Desligado, o evento fica só no calendário interno do painel (ex.: reuniões de professores).</div>
                <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-check2 me-1"></i><?= $atual ? 'Salvar alterações' : 'Adicionar evento' ?></button>
            </div>
        </form>
<?= janela_fecha() ?>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
