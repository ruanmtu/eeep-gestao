<?php
require __DIR__ . '/../includes/app.php';
exigir_login();

const CATEGORIAS_ARQUIVO = ['Formação Cidadã', 'Modelos de slides', 'Planejamento', 'Documentos oficiais', 'Comunicados', 'Projetos', 'Avaliações', 'Formulários', 'Outros'];
$pode_enviar = pode('arquivos.enviar');

if (eh_post()) {
    csrf_verificar();
    if (!$pode_enviar) { flash('danger', 'Seu perfil pode ver e baixar os arquivos, mas não enviar ou alterar.'); redirecionar('arquivos.php'); }
    $acao = $_POST['acao'] ?? '';
    $cat = mb_substr(trim((string)($_POST['categoria'] ?? '')), 0, 60) ?: 'Outros';
    $desc = trim((string)($_POST['descricao'] ?? '')) ?: null;
    if ($acao === 'enviar') {
        $env = 0; $erros = [];
        $arqs = $_FILES['arquivos'] ?? null;
        $n = $arqs ? count((array)$arqs['name']) : 0;
        for ($i = 0; $i < $n; $i++) {
            $f = ['name' => $arqs['name'][$i], 'type' => $arqs['type'][$i], 'tmp_name' => $arqs['tmp_name'][$i], 'error' => $arqs['error'][$i], 'size' => $arqs['size'][$i]];
            if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
            try {
                [$cam, $mime, $tam, $orig] = arquivo_receber($f);
                $tit = $n === 1 && trim((string)($_POST['titulo'] ?? '')) !== '' ? trim($_POST['titulo']) : pathinfo($orig, PATHINFO_FILENAME);
                q('INSERT INTO arquivos (titulo, descricao, categoria, nome_original, caminho, mime, tamanho, enviado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                  [mb_substr(str_replace('_', ' ', $tit), 0, 160), $desc, $cat, $orig, $cam, $mime, $tam, uid()]);
                registrar_log('arquivo_enviado', $orig . ' (' . $cat . ')');
                $env++;
            } catch (RuntimeException $e) { $erros[] = $f['name'] . ': ' . $e->getMessage(); }
        }
        if ($env) flash('success', $env === 1 ? 'Arquivo publicado na Central. Todos já podem ver e baixar.' : "$env arquivos publicados na Central.");
        if ($erros) flash('warning', implode(' ', $erros));
        if (!$env && !$erros) flash('warning', 'Escolha pelo menos um arquivo. Limite por arquivo: ' . tamanho_fmt(limite_upload()) . '.');
    } elseif ($acao === 'editar') {
        $tit = trim((string)($_POST['titulo'] ?? ''));
        if ($tit !== '') q('UPDATE arquivos SET titulo = ?, categoria = ?, descricao = ? WHERE id = ? AND especial IS NULL', [mb_substr($tit, 0, 160), $cat, $desc, (int)$_POST['id']]);
        flash('success', 'Arquivo atualizado.');
    } elseif ($acao === 'excluir') {
        $a = q_um('SELECT * FROM arquivos WHERE id = ? AND especial IS NULL', [(int)$_POST['id']]);
        if ($a) { arquivo_apagar_disco($a['caminho']); q('DELETE FROM arquivos WHERE id = ?', [$a['id']]); registrar_log('arquivo_excluido', $a['titulo']); flash('success', '"' . $a['titulo'] . '" excluído.'); }
    }
    redirecionar('arquivos.php' . (!empty($_POST['cat_volta']) ? '?categoria=' . urlencode($_POST['cat_volta']) : ''));
}

$cat_f = trim((string)($_GET['categoria'] ?? ''));
$busca = trim((string)($_GET['busca'] ?? ''));
$tipo_f = (string)($_GET['tipo'] ?? '');
$w = ['a.especial IS NULL']; $p = [];
if ($cat_f !== '') { $w[] = 'a.categoria = ?'; $p[] = $cat_f; }
if ($busca !== '') { $w[] = '(a.titulo LIKE ? OR a.descricao LIKE ? OR a.nome_original LIKE ?)'; array_push($p, "%$busca%", "%$busca%", "%$busca%"); }
$lista = q_todos('SELECT a.*, u.nome AS por, u.foto AS por_foto FROM arquivos a LEFT JOIN usuarios u ON u.id = a.enviado_por WHERE ' . implode(' AND ', $w) . ' ORDER BY a.criado_em DESC, a.id DESC LIMIT 400', $p);
if ($tipo_f !== '') $lista = array_values(array_filter($lista, fn($a) => arquivo_icone(arquivo_extensao($a['nome_original']))[2] === $tipo_f));
$cats = q_todos("SELECT categoria, COUNT(*) AS n FROM arquivos WHERE especial IS NULL GROUP BY categoria ORDER BY categoria");
$total = array_sum(array_column($cats, 'n'));
$espaco = (int)q_valor('SELECT COALESCE(SUM(tamanho), 0) FROM arquivos');
$categorias_sugeridas = array_values(array_unique(array_merge(CATEGORIAS_ARQUIVO, array_filter(array_column($cats, 'categoria')))));

$titulo = 'Central de Arquivos';
$subtitulo = 'Modelos, slides, documentos e materiais da escola guardados num só lugar. Todos veem e baixam' . ($pode_enviar ? '; você também pode enviar.' : '.');
$menu = 'arquivos';
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="central-topo mb-3">
    <form method="get" class="busca-rapida flex-grow-1"><i class="bi bi-search"></i>
        <input class="form-control" name="busca" value="<?= e($busca) ?>" placeholder="Buscar arquivo" aria-label="Buscar arquivo">
        <?php if ($cat_f !== ''): ?><input type="hidden" name="categoria" value="<?= e($cat_f) ?>"><?php endif; ?></form>
    <?php if ($pode_enviar): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalEnviar"><i class="bi bi-cloud-arrow-up me-1"></i>Enviar arquivos</button><?php endif; ?>
</div>
<div class="chips-filtro mb-3">
    <a class="chip<?= $cat_f === '' ? ' ativo' : '' ?>" href="arquivos.php">Tudo <span><?= $total ?></span></a>
    <?php foreach ($cats as $c): ?><a class="chip<?= $cat_f === $c['categoria'] ? ' ativo' : '' ?>" href="?categoria=<?= e(urlencode((string)$c['categoria'])) ?>"><?= e($c['categoria'] ?: 'Sem pasta') ?> <span><?= $c['n'] ?></span></a><?php endforeach; ?>
</div>

<?php if ($lista): ?>
<div class="grade-arquivos">
    <?php foreach ($lista as $a): $ext = arquivo_extensao($a['nome_original']); [$ic, $cor, $tipo] = arquivo_icone($ext); $novo = strtotime($a['criado_em']) > time() - 7 * 86400; ?>
        <article class="cartao-arquivo">
            <a class="ca-capa <?= $cor ?>" href="visualizar.php?id=<?= $a['id'] ?>" aria-label="Abrir <?= e($a['titulo']) ?>">
                <i class="bi bi-<?= $ic ?>"></i><span class="ca-ext">.<?= e($ext) ?></span>
                <?php if ($novo): ?><span class="ca-novo">novo</span><?php endif; ?>
            </a>
            <div class="ca-corpo">
                <span class="ca-cat"><?= e($a['categoria'] ?: 'Outros') ?></span>
                <a class="ca-titulo" href="visualizar.php?id=<?= $a['id'] ?>"><?= e($a['titulo']) ?></a>
                <?php if ($a['descricao']): ?><p><?= e(mb_strimwidth($a['descricao'], 0, 110, '…')) ?></p><?php endif; ?>
                <div class="ca-meta"><?= avatar(['id' => $a['enviado_por'], 'nome' => $a['por'] ?? '?', 'foto' => $a['por_foto']], 'usuario', 'avatar-xs') ?>
                    <span><?= e(primeiro_ultimo((string)($a['por'] ?? '—'))) ?> · <?= data_br(substr($a['criado_em'], 0, 10)) ?></span></div>
            </div>
            <div class="ca-acoes">
                <span class="small text-secondary"><?= tamanho_fmt((int)$a['tamanho']) ?> · <i class="bi bi-download"></i> <?= (int)$a['downloads'] ?></span>
                <a class="btn btn-sm btn-light ms-auto" href="visualizar.php?id=<?= $a['id'] ?>" title="Ver"><i class="bi bi-eye"></i></a>
                <a class="btn btn-sm btn-primary" href="arquivo.php?id=<?= $a['id'] ?>&baixar=1" title="Baixar"><i class="bi bi-download"></i></a>
                <?php if ($pode_enviar): ?>
                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="modal" data-bs-target="#modalEditar" title="Editar"
                            data-id="<?= $a['id'] ?>" data-titulo="<?= e($a['titulo']) ?>" data-categoria="<?= e($a['categoria'] ?? '') ?>" data-descricao="<?= e($a['descricao'] ?? '') ?>"><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline" data-confirmar="Excluir &quot;<?= e($a['titulo']) ?>&quot; da Central? O arquivo some para todos." data-botao="Excluir">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="cat_volta" value="<?= e($cat_f) ?>">
                        <button class="btn btn-sm btn-light" type="submit" aria-label="Excluir"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div class="card"><div class="vazio py-5"><i class="bi bi-folder2-open"></i><strong><?= $busca || $cat_f ? 'Nada encontrado com esse filtro' : 'A Central ainda está vazia' ?></strong>
        <?= $pode_enviar ? 'Clique em “Enviar arquivos” para publicar modelos, slides e documentos para toda a escola.' : 'Quando a gestão publicar materiais, eles aparecem aqui.' ?></div></div>
<?php endif; ?>
<p class="small text-secondary mt-3"><i class="bi bi-hdd me-1"></i><?= tamanho_fmt($espaco) ?> usados na hospedagem · limite por arquivo: <?= tamanho_fmt(limite_upload()) ?></p>

<?php if ($pode_enviar): ?>
<datalist id="listaCats"><?php foreach ($categorias_sugeridas as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<div class="modal fade" id="modalEnviar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" enctype="multipart/form-data" class="modal-content" id="formEnviar">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="enviar">
    <div class="modal-header"><h5 class="modal-title">Enviar para a Central de Arquivos</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body row g-3">
        <div class="col-12"><label class="zona-envio" id="zonaArq" for="inpArq"><i class="bi bi-cloud-arrow-up"></i>
            <strong>Arraste os arquivos aqui ou clique para escolher</strong>
            <span>PDF, slides, Word, Excel, imagens, vídeo · até <?= tamanho_fmt(limite_upload()) ?> cada</span>
            <span class="arquivo-escolhido" id="arqEscolhidos" hidden></span>
            <input type="file" id="inpArq" name="arquivos[]" multiple required accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, array_keys(EXTENSOES_ARQUIVOS)))) ?>"></label></div>
        <div class="col-md-7" id="boxTitulo"><label class="form-label" for="e_tit">Título</label><input class="form-control" id="e_tit" name="titulo" maxlength="160" placeholder="Usa o nome do arquivo se ficar em branco"></div>
        <div class="col-md-5"><label class="form-label obrigatorio" for="e_cat">Pasta / categoria</label><input class="form-control" id="e_cat" name="categoria" list="listaCats" value="<?= e($cat_f ?: 'Formação Cidadã') ?>" maxlength="60" required></div>
        <div class="col-12"><label class="form-label" for="e_desc">Descrição</label><textarea class="form-control" id="e_desc" name="descricao" rows="2" placeholder="Ex.: modelo de slides para a aula de Formação Cidadã dos diretores de turma (semana 12)."></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="btEnviar"><i class="bi bi-cloud-check me-1"></i>Publicar</button></div>
</form></div></div>
<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" id="ed_id"><input type="hidden" name="cat_volta" value="<?= e($cat_f) ?>">
    <div class="modal-header"><h5 class="modal-title">Editar arquivo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body row g-3">
        <div class="col-12"><label class="form-label" for="ed_tit">Título</label><input class="form-control" id="ed_tit" name="titulo" maxlength="160" required></div>
        <div class="col-12"><label class="form-label" for="ed_cat">Pasta / categoria</label><input class="form-control" id="ed_cat" name="categoria" list="listaCats" maxlength="60"></div>
        <div class="col-12"><label class="form-label" for="ed_desc">Descrição</label><textarea class="form-control" id="ed_desc" name="descricao" rows="3"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div></div>
<?php endif; ?>
<?php
$script_pagina = '<script>
(function () {
  const z = document.getElementById("zonaArq"), inp = document.getElementById("inpArq");
  if (z) {
    const mostrar = () => { const n = inp.files.length; arqEscolhidos.hidden = !n;
      arqEscolhidos.textContent = n === 1 ? inp.files[0].name : n + " arquivos";
      boxTitulo.hidden = n > 1; z.classList.toggle("com-arquivo", n > 0);
      if (n === 1 && !e_tit.value) e_tit.value = inp.files[0].name.replace(/\.[^.]+$/, "").replace(/[_-]+/g, " "); };
    inp.addEventListener("change", mostrar);
    ["dragenter", "dragover"].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add("arrastando"); }));
    ["dragleave", "drop"].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove("arrastando"); }));
    z.addEventListener("drop", e => { if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; mostrar(); } });
    formEnviar.addEventListener("submit", () => { btEnviar.disabled = true; btEnviar.innerHTML = "<span class=\"spinner-border spinner-border-sm me-1\"></span>Enviando…"; });
  }
  const me = document.getElementById("modalEditar");
  me && me.addEventListener("show.bs.modal", e => { const b = e.relatedTarget; ed_id.value = b.dataset.id; ed_tit.value = b.dataset.titulo; ed_cat.value = b.dataset.categoria; ed_desc.value = b.dataset.descricao; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
