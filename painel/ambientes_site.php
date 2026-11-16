<?php
/* Ambientes da escola no site: cada ambiente vira um cartão clicável na página inicial e tem a sua própria página
   (fotos, o que é o ambiente e o que se estuda nele). */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

const AMB_MAX_FOTOS = 8;
$lista = ambientes_lista(false);
$eid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = null; foreach ($lista as $a) if ((int)$a['id'] === $eid) $atual = $a;
$novo = isset($_GET['novo']);

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        switch ($acao) {
            case 'salvar':
                $tit = trim((string)($_POST['titulo'] ?? ''));
                if (mb_strlen($tit) < 3) throw new RuntimeException('Dê um nome ao ambiente (ex.: Laboratório de química).');
                $ic = isset(ICONES_AMBIENTE[$_POST['icone'] ?? '']) ? $_POST['icone'] : 'building';
                $res = mb_substr(trim((string)($_POST['resumo'] ?? '')), 0, 255);
                $txt = mb_substr(trim((string)($_POST['texto'] ?? '')), 0, 20000);
                $est = mb_substr(trim((string)($_POST['estuda'] ?? '')), 0, 4000);
                $fotos = $atual ? ambiente_fotos($atual) : [];
                /* remover as marcadas */
                $tirar = array_map('intval', (array)($_POST['tirar'] ?? []));
                foreach ($tirar as $k) if (isset($fotos[$k])) { site_img_apagar($fotos[$k]); unset($fotos[$k]); }
                $fotos = array_values($fotos);
                /* capa escolhida vai para a frente */
                $capa = (int)($_POST['capa'] ?? -1);
                if ($capa > 0 && isset($fotos[$capa]) && !in_array($capa, $tirar, true)) { $c = $fotos[$capa]; unset($fotos[$capa]); array_unshift($fotos, $c); }
                /* novas fotos */
                $up = $_FILES['fotos'] ?? null; $nao = 0;
                if ($up && is_array($up['name'])) {
                    foreach ($up['name'] as $i => $n) {
                        if ($n === '' || count($fotos) >= AMB_MAX_FOTOS) { if ($n !== '') $nao++; continue; }
                        $f = ['name' => $n, 'type' => $up['type'][$i], 'tmp_name' => $up['tmp_name'][$i], 'error' => $up['error'][$i], 'size' => $up['size'][$i]];
                        [$fotos[]] = site_img_salvar($f, 'ambientes', 'a');
                    }
                }
                $json = json_encode(array_values($fotos), JSON_UNESCAPED_SLASHES);
                if ($atual) q('UPDATE site_ambientes SET titulo = ?, icone = ?, resumo = ?, texto = ?, estuda = ?, fotos = ? WHERE id = ?', [mb_substr($tit, 0, 120), $ic, $res ?: null, $txt ?: null, $est ?: null, $json, $eid]);
                else {
                    $ordem = (int)q_valor('SELECT COALESCE(MAX(ordem), 0) + 10 FROM site_ambientes');
                    q('INSERT INTO site_ambientes (titulo, icone, resumo, texto, estuda, fotos, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)', [mb_substr($tit, 0, 120), $ic, $res ?: null, $txt ?: null, $est ?: null, $json, $ordem, date('Y-m-d H:i:s')]);
                }
                registrar_log('ambiente_site', $tit);
                flash('success', '"' . $tit . '" salvo. Já aparece no site.' . ($nao ? " $nao foto(s) não entraram: o limite é " . AMB_MAX_FOTOS . ' por ambiente.' : ''));
                break;
            case 'ativo':
                if ($atual) q('UPDATE site_ambientes SET ativo = 1 - ativo WHERE id = ?', [$eid]);
                break;
            case 'mover':
                $ids = array_map('intval', array_column($lista, 'id'));
                $k = array_search($eid, $ids, true); $d = (int)($_POST['dir'] ?? 0);
                if ($k !== false && isset($ids[$k + $d])) [$ids[$k], $ids[$k + $d]] = [$ids[$k + $d], $ids[$k]];
                foreach ($ids as $i => $x) q('UPDATE site_ambientes SET ordem = ? WHERE id = ?', [($i + 1) * 10, $x]);
                break;
            case 'apagar':
                if ($atual) { foreach (ambiente_fotos($atual) as $f) site_img_apagar($f); q('DELETE FROM site_ambientes WHERE id = ?', [$eid]); flash('success', '"' . $atual['titulo'] . '" saiu do site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('ambientes_site.php' . ($atual ? '?editar=' . $eid : '?novo=1')); }
    catch (PDOException $e) { flash('warning', 'A tabela de ambientes ainda não existe. Rode a atualização do banco (atualizacao_banco_v59.sql).'); }
    redirecionar('ambientes_site.php');
}

$titulo = 'Ambientes da escola';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="pg-acoes">
    <div><h2>Ambientes da escola</h2><small>Cada ambiente aparece em "Nossa estrutura" na página inicial. Quem toca no cartão abre a página do ambiente, com fotos e o que se estuda nele.</small></div>
    <a class="btn btn-primary" href="?novo=1"><i class="bi bi-plus-lg me-1"></i>Novo ambiente</a>
</div>

<?php if (!$lista): ?>
<div class="card"><div class="vazio"><i class="bi bi-building"></i><strong>Nenhum ambiente ainda</strong>Cadastre os laboratórios, a quadra, a biblioteca e os outros espaços da escola.</div></div>
<?php else: ?>
<div class="am-grade">
    <?php foreach ($lista as $k => $a): $fs = ambiente_fotos($a); $capa = ambiente_foto_url($a, 0, RAIZ, true); ?>
    <article class="am-card<?= $a['ativo'] ? '' : ' fora' ?>">
        <a class="am-capa" href="?editar=<?= $a['id'] ?>" style="<?= $capa ? "background-image:url('" . e($capa) . "')" : '' ?>">
            <?php if (!$capa): ?><i class="bi bi-<?= e($a['icone']) ?>"></i><?php endif; ?>
            <span class="am-nf"><i class="bi bi-images"></i><?= count($fs) ?></span>
            <?php if (!$a['ativo']): ?><span class="am-off">fora do site</span><?php endif; ?>
        </a>
        <div class="am-tx">
            <b><i class="bi bi-<?= e($a['icone']) ?>"></i><?= e($a['titulo']) ?></b>
            <small><?= e($a['resumo'] ?: 'Sem resumo') ?></small>
            <span class="am-ok"><?= $a['texto'] ? '<i class="bi bi-check2"></i>texto' : '<i class="bi bi-dash"></i>sem texto' ?> · <?= count(ambiente_itens($a)) ?> item(ns) estudados</span>
        </div>
        <div class="am-bt">
            <a class="btn btn-sm btn-light" href="<?= RAIZ ?>ambiente.php?id=<?= $a['id'] ?>" target="_blank" rel="noopener" title="Ver a página"><i class="bi bi-box-arrow-up-right"></i></a>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="-1"><button class="btn btn-sm btn-light" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Antes"><i class="bi bi-arrow-left"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="1"><button class="btn btn-sm btn-light" <?= $k === count($lista) - 1 ? 'disabled' : '' ?> aria-label="Depois"><i class="bi bi-arrow-right"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $a['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>"><i class="bi bi-<?= $a['ativo'] ? 'eye-slash' : 'eye-fill' ?>"></i></button></form>
            <a class="btn btn-sm btn-primary ms-auto" href="?editar=<?= $a['id'] ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?= janela_abre('formAmb', $atual ? 'Editar · ' . $atual['titulo'] : 'Novo ambiente', $atual ? 'pencil' : 'building-add', (bool)$atual || $novo, 'modal-xl') ?>
<form method="post" enctype="multipart/form-data" class="am-form" id="amFormSalvar">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($atual['id'] ?? 0) ?>">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="row g-2">
                <div class="col-sm-8"><label class="form-label obrigatorio" for="amTit">Nome do ambiente</label><input class="form-control" id="amTit" name="titulo" maxlength="120" required value="<?= e($atual['titulo'] ?? '') ?>" placeholder="Ex.: Laboratório de química"></div>
                <div class="col-sm-4"><label class="form-label" for="amIc">Ícone</label>
                    <div class="am-ic-sel"><i class="bi bi-<?= e($atual['icone'] ?? 'building') ?>" id="amIcPrev"></i><select class="form-select" id="amIc" name="icone"><?php foreach (ICONES_AMBIENTE as $ic => $r): ?><option value="<?= $ic ?>"<?= ($atual['icone'] ?? 'building') === $ic ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></div></div>
                <div class="col-12"><label class="form-label" for="amRes">Resumo <span class="text-secondary fw-normal">· aparece no cartão da página inicial</span></label><input class="form-control" id="amRes" name="resumo" maxlength="255" value="<?= e($atual['resumo'] ?? '') ?>" placeholder="Uma frase curta"></div>
                <div class="col-12"><label class="form-label" for="amTx">Sobre o ambiente</label><textarea class="form-control" id="amTx" name="texto" rows="6" maxlength="20000" placeholder="O que é o ambiente, como ele é equipado, quem usa…"><?= e($atual['texto'] ?? '') ?></textarea></div>
                <div class="col-12"><label class="form-label" for="amEst">O que se estuda aqui <span class="text-secondary fw-normal">· um item por linha</span></label><textarea class="form-control" id="amEst" name="estuda" rows="5" maxlength="4000" placeholder="Reações químicas&#10;Separação de misturas&#10;Normas de segurança no laboratório"><?= e($atual['estuda'] ?? '') ?></textarea></div>
            </div>
        </div>
        <div class="col-lg-5">
            <label class="form-label">Fotos <span class="text-secondary fw-normal">· até <?= AMB_MAX_FOTOS ?>; a primeira é a capa</span></label>
            <?php $fs = $atual ? ambiente_fotos($atual) : []; if ($fs): ?>
            <div class="am-fotos">
                <?php foreach ($fs as $k => $f): ?>
                <div class="am-ft">
                    <img src="<?= e(ambiente_foto_url($atual, $k, RAIZ, true)) ?>" alt="">
                    <label class="am-capa-r" title="Usar como capa"><input type="radio" name="capa" value="<?= $k ?>"<?= $k === 0 ? ' checked' : '' ?>><span><i class="bi bi-star-fill"></i><?= $k === 0 ? 'Capa' : 'Capa?' ?></span></label>
                    <label class="am-tirar" title="Remover esta foto"><input type="checkbox" name="tirar[]" value="<?= $k ?>"><span><i class="bi bi-trash"></i></span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <label class="sa-solta mt-2" id="amSolta">
                <input type="file" name="fotos[]" id="amArq" accept="image/*" multiple>
                <i class="bi bi-images"></i><b id="amNome">Adicionar fotos</b><small>Pode escolher várias de uma vez · JPG, PNG ou WEBP</small>
            </label>
            <div class="am-novas" id="amNovas"></div>
        </div>
    </div>
</form>
<div class="d-flex gap-2 mt-3 align-items-center">
    <?php if ($atual): ?><form method="post" data-confirmar="Apagar o ambiente &quot;<?= e($atual['titulo']) ?>&quot; e as fotos dele?" data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $atual['id'] ?>"><button class="btn btn-light text-danger" type="submit"><i class="bi bi-trash me-1"></i>Apagar ambiente</button></form><?php endif; ?>
    <button class="btn btn-primary ms-auto" type="submit" form="amFormSalvar"><i class="bi bi-check2 me-1"></i>Salvar ambiente</button>
</div>
<?= janela_fecha() ?>
<?php
$script_pagina = '<script>
(function () {
  const ic = document.getElementById("amIc"), pv = document.getElementById("amIcPrev");
  if (ic) ic.addEventListener("change", () => pv.className = "bi bi-" + ic.value);
  const a = document.getElementById("amArq"), nv = document.getElementById("amNovas");
  if (a) a.addEventListener("change", () => { nv.innerHTML = ""; [...a.files].forEach(x => { const i = document.createElement("img"); i.src = URL.createObjectURL(x); nv.appendChild(i); });
    document.getElementById("amNome").textContent = a.files.length ? a.files.length + " foto(s) escolhida(s)" : "Adicionar fotos"; document.getElementById("amSolta").classList.toggle("tem", a.files.length > 0); });
  document.querySelectorAll(".am-tirar input").forEach(c => c.addEventListener("change", () => c.closest(".am-ft").classList.toggle("sai", c.checked)));
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
