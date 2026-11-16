<?php
/* Arquivos públicos do site: aparecem na página inicial e qualquer visitante pode abrir ou baixar, sem login */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$eid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = null;
try { $atual = $eid ? q_um('SELECT * FROM site_arquivos WHERE id = ?', [$eid]) : null; } catch (PDOException $e) {}

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        switch ($acao) {
            case 'salvar':
                $tit = trim((string)($_POST['titulo'] ?? ''));
                $desc = trim((string)($_POST['descricao'] ?? ''));
                $cat = in_array($_POST['categoria'] ?? '', ARQ_SITE_CATEGORIAS, true) ? $_POST['categoria'] : ARQ_SITE_CATEGORIAS[0];
                $tem_arq = !empty($_FILES['arquivo']['name']);
                if (!$atual && !$tem_arq) throw new RuntimeException('Escolha o arquivo que vai para o site.');
                if ($tit === '' && $tem_arq) $tit = preg_replace('/\.[^.]+$/', '', basename((string)$_FILES['arquivo']['name']));
                if (mb_strlen($tit) < 3) throw new RuntimeException('Dê um nome ao arquivo (ex.: Regimento Escolar 2026).');
                $novo = $tem_arq ? site_arquivo_guardar($_FILES['arquivo']) : null;
                if ($atual) {
                    q('UPDATE site_arquivos SET titulo = ?, descricao = ?, categoria = ? WHERE id = ?', [mb_substr($tit, 0, 160), $desc !== '' ? mb_substr($desc, 0, 255) : null, $cat, $eid]);
                    if ($novo) { site_arquivo_apagar_disco($atual['arquivo']); q('UPDATE site_arquivos SET arquivo = ?, extensao = ?, tamanho = ? WHERE id = ?', [$novo[0], $novo[1], $novo[2], $eid]); }
                    flash('success', '"' . $tit . '" atualizado.');
                } else {
                    $ordem = (int)q_valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM site_arquivos');
                    q('INSERT INTO site_arquivos (titulo, descricao, categoria, arquivo, extensao, tamanho, ordem, ativo, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                      [mb_substr($tit, 0, 160), $desc !== '' ? mb_substr($desc, 0, 255) : null, $cat, $novo[0], $novo[1], $novo[2], $ordem, uid(), date('Y-m-d H:i:s')]);
                    flash('success', '"' . $tit . '" publicado no site.');
                }
                registrar_log('arquivo_site', $tit);
                break;
            case 'ativo':
                if ($atual) q('UPDATE site_arquivos SET ativo = 1 - ativo WHERE id = ?', [$eid]);
                break;
            case 'mover':
                $ids = array_map('intval', array_column(site_arquivos(false), 'id'));
                $k = array_search($eid, $ids, true); $d = (int)($_POST['dir'] ?? 0);
                if ($k !== false && isset($ids[$k + $d])) [$ids[$k], $ids[$k + $d]] = [$ids[$k + $d], $ids[$k]];
                foreach ($ids as $i => $x) q('UPDATE site_arquivos SET ordem = ? WHERE id = ?', [$i + 1, $x]);
                break;
            case 'apagar':
                if ($atual) { site_arquivo_apagar_disco($atual['arquivo']); q('DELETE FROM site_arquivos WHERE id = ?', [$eid]); flash('success', '"' . $atual['titulo'] . '" saiu do site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('arquivos_site.php' . ($atual ? '?editar=' . $eid : '')); }
    catch (PDOException $e) { flash('warning', 'A tabela de arquivos ainda não existe. Rode a atualização do banco (atualizacao_banco_v54.sql).'); }
    redirecionar('arquivos_site.php');
}

$lista = site_arquivos(false);
$titulo = 'Arquivos do site';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="pg-acoes">
    <div><h2>Arquivos públicos</h2><small>Aparecem na página inicial, na seção "Documentos". Qualquer visitante abre ou baixa, sem precisar entrar no sistema.</small></div>
    <?= janela_botao('novoArquivo', 'Enviar arquivo', 'cloud-arrow-up') ?>
</div>

<?php if (!$lista): ?>
<div class="card"><div class="vazio"><i class="bi bi-folder2-open"></i><strong>Nenhum arquivo publicado ainda</strong>Envie o regimento escolar, a matriz curricular dos cursos, editais e outros documentos.</div></div>
<?php else: ?>
<ul class="sa-lista">
    <?php foreach ($lista as $k => $a): [, $ic, $rot, $cor] = ARQ_SITE_TIPOS[$a['extensao']] ?? ['', 'file-earmark', 'Arquivo', 'cinza']; ?>
    <li class="<?= $a['ativo'] ? '' : 'fora' ?>">
        <span class="sa-ic <?= $cor ?>"><i class="bi bi-<?= $ic ?>"></i><small><?= strtoupper(e($a['extensao'])) ?></small></span>
        <div class="sa-txt"><b><?= e($a['titulo']) ?></b><small><span class="sa-cat"><?= e($a['categoria']) ?></span><?= tamanho_legivel((int)$a['tamanho']) ?> · enviado em <?= date('d/m/Y', strtotime($a['criado_em'])) ?><?= $a['ativo'] ? '' : ' · <b>fora do site</b>' ?></small>
            <?php if ($a['descricao']): ?><em><?= e($a['descricao']) ?></em><?php endif; ?></div>
        <div class="sa-bt">
            <a class="btn btn-sm btn-light" href="<?= RAIZ ?>arquivo_site.php?id=<?= $a['id'] ?>" target="_blank" rel="noopener" title="Abrir"><i class="bi bi-eye"></i></a>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="-1"><button class="btn btn-sm btn-light" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Subir"><i class="bi bi-arrow-up"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="1"><button class="btn btn-sm btn-light" <?= $k === count($lista) - 1 ? 'disabled' : '' ?> aria-label="Descer"><i class="bi bi-arrow-down"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $a['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>"><i class="bi bi-<?= $a['ativo'] ? 'eye-slash' : 'eye-fill' ?>"></i></button></form>
            <a class="btn btn-sm btn-light" href="?editar=<?= $a['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></a>
            <form method="post" data-confirmar="Apagar &quot;<?= e($a['titulo']) ?>&quot;? O arquivo some do site." data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-light" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
        </div>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?= janela_abre('novoArquivo', $atual ? 'Editar arquivo' : 'Enviar arquivo para o site', $atual ? 'pencil' : 'cloud-arrow-up', (bool)$atual, '') ?>
<form method="post" enctype="multipart/form-data" id="formArq">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($atual['id'] ?? 0) ?>">
    <label class="sa-solta" id="saSolta">
        <input type="file" name="arquivo" id="saArq" accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.ppt,.pptx,.jpg,.jpeg,.png"<?= $atual ? '' : ' required' ?>>
        <i class="bi bi-cloud-arrow-up"></i><b id="saNome"><?= $atual ? 'Trocar o arquivo (opcional)' : 'Toque para escolher o arquivo' ?></b>
        <small><?= $atual ? 'Atual: ' . e($atual['titulo']) . '.' . e($atual['extensao']) . ' · ' . tamanho_legivel((int)$atual['tamanho']) : 'PDF, Word, Excel, PowerPoint, OpenOffice, JPG ou PNG · até 40 MB' ?></small>
    </label>
    <label class="form-label obrigatorio mt-3" for="saTit">Nome que aparece no site</label>
    <input class="form-control" id="saTit" name="titulo" maxlength="160" value="<?= e((string)($atual['titulo'] ?? '')) ?>" placeholder="Ex.: Regimento Escolar 2026">
    <label class="form-label mt-3" for="saCat">Categoria</label>
    <select class="form-select" id="saCat" name="categoria"><?php foreach (ARQ_SITE_CATEGORIAS as $c): ?><option<?= ($atual['categoria'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <label class="form-label mt-3" for="saDesc">Descrição <span class="text-secondary fw-normal">· opcional</span></label>
    <input class="form-control" id="saDesc" name="descricao" maxlength="255" value="<?= e((string)($atual['descricao'] ?? '')) ?>" placeholder="Ex.: Normas de convivência, direitos e deveres dos estudantes">
    <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-<?= $atual ? 'check2' : 'cloud-arrow-up' ?> me-1"></i><?= $atual ? 'Salvar alterações' : 'Publicar no site' ?></button>
</form>
<?= janela_fecha() ?>
<?php
$script_pagina = '<script>
(function () {
  const i = document.getElementById("saArq"); if (!i) return;
  i.addEventListener("change", () => { const f = i.files[0]; if (!f) return;
    document.getElementById("saNome").textContent = f.name;
    const t = document.getElementById("saTit"); if (!t.value.trim()) t.value = f.name.replace(/\.[^.]+$/, "").replace(/[_-]+/g, " ");
    document.getElementById("saSolta").classList.add("tem"); });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
