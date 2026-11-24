<?php
/* v79 · Editais de seleção do site: PDF do edital, período de inscrição, link de inscrição e resultado */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$eid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = null;
try { $atual = $eid ? q_um('SELECT * FROM site_editais WHERE id = ?', [$eid]) : null; } catch (PDOException $e) {}
$data_ok = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        switch ($acao) {
            case 'salvar':
                $tit = trim((string)($_POST['titulo'] ?? ''));
                if (mb_strlen($tit) < 3) throw new RuntimeException('Dê um título ao edital (ex.: Seleção de alunos 2027).');
                $ini = $data_ok($_POST['inscricao_inicio'] ?? ''); $fim = $data_ok($_POST['inscricao_fim'] ?? '');
                if ($ini && $fim && $fim < $ini) throw new RuntimeException('O fim das inscrições está antes do início.');
                $link = trim((string)($_POST['link_inscricao'] ?? ''));
                if ($link !== '' && !preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
                if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) throw new RuntimeException('O link de inscrição não parece válido.');
                $tem_ed = !empty($_FILES['arquivo']['name']); $tem_res = !empty($_FILES['resultado']['name']);
                if (!$atual && !$tem_ed) throw new RuntimeException('Envie o PDF do edital.');
                $ed = $tem_ed ? edital_arquivo_guardar($_FILES['arquivo']) : null;
                $res = $tem_res ? edital_arquivo_guardar($_FILES['resultado']) : null;
                $d = [mb_substr($tit, 0, 180), mb_substr(trim((string)($_POST['numero'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 600) ?: null, $ini, $fim, $link ?: null];
                if ($atual) {
                    q('UPDATE site_editais SET titulo = ?, numero = ?, descricao = ?, inscricao_inicio = ?, inscricao_fim = ?, link_inscricao = ? WHERE id = ?', [...$d, $eid]);
                    if ($ed) { site_arquivo_apagar_disco($atual['arquivo']); q('UPDATE site_editais SET arquivo = ?, arquivo_ext = ?, arquivo_tam = ? WHERE id = ?', [...$ed, $eid]); }
                    if ($res) { site_arquivo_apagar_disco($atual['resultado']); q('UPDATE site_editais SET resultado = ?, resultado_ext = ?, resultado_tam = ? WHERE id = ?', [...$res, $eid]); }
                    if (!$res && !empty($_POST['tirar_resultado'])) { site_arquivo_apagar_disco($atual['resultado']); q('UPDATE site_editais SET resultado = NULL, resultado_ext = NULL, resultado_tam = NULL WHERE id = ?', [$eid]); }
                    flash('success', '"' . $tit . '" atualizado.');
                } else {
                    q('INSERT INTO site_editais (titulo, numero, descricao, inscricao_inicio, inscricao_fim, link_inscricao, arquivo, arquivo_ext, arquivo_tam, resultado, resultado_ext, resultado_tam, ativo, criado_por, criado_em)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', [...$d, ...$ed, ...($res ?: [null, null, null]), uid(), date('Y-m-d H:i:s')]);
                    flash('success', '"' . $tit . '" publicado no site.');
                }
                registrar_log('edital_site', $tit);
                break;
            case 'ativo':
                if ($atual) q('UPDATE site_editais SET ativo = 1 - ativo WHERE id = ?', [$eid]);
                break;
            case 'apagar':
                if ($atual) { site_arquivo_apagar_disco($atual['arquivo']); site_arquivo_apagar_disco($atual['resultado']); q('DELETE FROM site_editais WHERE id = ?', [$eid]); flash('success', '"' . $atual['titulo'] . '" saiu do site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('editais_site.php' . ($atual ? '?editar=' . $eid : '')); }
    redirecionar('editais_site.php');
}

$lista = editais_lista(false);
$titulo = 'Editais';
$subtitulo = 'Editais de seleção que aparecem no site: o PDF, o período de inscrição, o link para se inscrever e o resultado.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
$v = $atual ?? [];
?>
<div class="row g-3">
    <div class="col-lg-5">
        <form method="post" enctype="multipart/form-data" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
            <div class="card-header"><h2><?= $atual ? 'Editar edital' : 'Novo edital' ?></h2><?php if ($atual): ?><a class="small" href="editais_site.php">+ novo</a><?php endif; ?></div>
            <div class="card-body vstack gap-3">
                <div><label class="form-label" for="edT">Título</label><input class="form-control" id="edT" name="titulo" maxlength="180" required value="<?= e($v['titulo'] ?? '') ?>" placeholder="Seleção de novos alunos 2027"></div>
                <div class="row g-2">
                    <div class="col-5"><label class="form-label" for="edN">Número</label><input class="form-control" id="edN" name="numero" maxlength="40" value="<?= e($v['numero'] ?? '') ?>" placeholder="01/2026"></div>
                    <div class="col-7"><label class="form-label" for="edL">Link de inscrição <small class="text-secondary">(opcional)</small></label><input class="form-control" id="edL" name="link_inscricao" type="text" inputmode="url" maxlength="255" value="<?= e($v['link_inscricao'] ?? '') ?>" placeholder="https://…"></div>
                </div>
                <div><label class="form-label" for="edD">Resumo <small class="text-secondary">(opcional)</small></label><textarea class="form-control" id="edD" name="descricao" rows="3" maxlength="600" placeholder="Para quem é, quantas vagas, como funciona a seleção…"><?= e($v['descricao'] ?? '') ?></textarea></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="edI">Inscrições de</label><input class="form-control" id="edI" type="date" name="inscricao_inicio" value="<?= e($v['inscricao_inicio'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label" for="edF">até</label><input class="form-control" id="edF" type="date" name="inscricao_fim" value="<?= e($v['inscricao_fim'] ?? '') ?>"></div>
                </div>
                <div><label class="form-label" for="edA">PDF do edital<?= $atual ? ' <small class="text-secondary">(envie só para trocar)</small>' : '' ?></label><input class="form-control" id="edA" type="file" name="arquivo" accept=".pdf,.doc,.docx,application/pdf"<?= $atual ? '' : ' required' ?>></div>
                <div><label class="form-label" for="edR">Resultado <small class="text-secondary">(quando sair)</small></label><input class="form-control" id="edR" type="file" name="resultado" accept=".pdf,.doc,.docx,.xlsx,application/pdf">
                    <?php if (!empty($v['resultado'])): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="tirar_resultado" value="1"> <span class="form-check-label">tirar o resultado publicado</span></label><?php endif; ?></div>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $atual ? 'Salvar' : 'Publicar edital' ?></button>
            </div>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h2>Editais no site</h2><span class="small text-secondary"><?= count($lista) ?></span></div>
            <?php if (!$lista): ?><div class="vazio py-4"><i class="bi bi-megaphone"></i>Nenhum edital publicado.</div><?php endif; ?>
            <ul class="ed-lista">
                <?php foreach ($lista as $x): [$k, $rot, $det] = edital_status($x); ?>
                <li class="<?= $x['ativo'] ? '' : 'off' ?><?= (int)$x['id'] === $eid ? ' atual' : '' ?>">
                    <span class="ed-st <?= $k ?>"><?= e($rot) ?></span>
                    <div><b><?= e($x['titulo']) ?></b><small><?= $x['numero'] ? 'Edital nº ' . e($x['numero']) . ' · ' : '' ?><?= e($det ?: 'Publicado em ' . date('d/m/Y', strtotime($x['criado_em']))) ?><?= $x['ativo'] ? '' : ' · oculto' ?></small></div>
                    <div class="ed-acoes">
                        <a class="btn btn-sm btn-light" href="../arquivo_site.php?edital=<?= $x['id'] ?>" target="_blank" rel="noopener" title="Ver PDF"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-sm btn-light" href="?editar=<?= $x['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                        <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $x['ativo'] ? 'Ocultar do site' : 'Mostrar no site' ?>"><i class="bi bi-eye<?= $x['ativo'] ? '-slash' : '' ?>"></i></button></form>
                        <form method="post" onsubmit="return confirm('Apagar este edital do site?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-light text-danger" title="Apagar"><i class="bi bi-trash3"></i></button></form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
