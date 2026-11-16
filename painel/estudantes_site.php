<?php
/* Fotos da seção "Nossos estudantes" do site: trocar, enquadrar, remover, legenda e ordem */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

/** Lista atual; na primeira edição, copia a lista padrão (fotos que já estão no site) */
$carregar = function () use ($ESTUDANTES): array {
    $l = estudantes_lista();
    if ($l !== null) return $l;
    $out = [];
    foreach ($ESTUDANTES ?? [] as $g) if (is_file(dirname(__DIR__) . '/' . $g['imagem'])) $out[] = ['a' => $g['imagem'], 'l' => $g['legenda']];
    return $out;
};
$gravar = fn(array $l) => config_gravar('site_estudantes', json_encode(array_values($l), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$apagar_arq = function (?string $a): void { if ($a && str_starts_with($a, 'site/estudantes/')) site_img_apagar($a); };

if (eh_post()) {
    csrf_verificar();
    $lista = $carregar();
    $k = (int)($_POST['k'] ?? -1);
    $acao = (string)($_POST['acao'] ?? '');
    $lote = !empty($_POST['lote']);
    try {
        switch ($acao) {
            case 'salvar':   // trocar foto e/ou legenda de uma posição, ou adicionar nova
                $leg = mb_substr(trim((string)($_POST['legenda'] ?? '')), 0, 80);
                $nova = null;
                if (!empty($_FILES['foto']['name'])) [$nova] = site_img_salvar($_FILES['foto'], 'estudantes', 'e');
                if ($k >= 0 && isset($lista[$k])) {
                    if ($nova) { $apagar_arq($lista[$k]['a']); $lista[$k]['a'] = $nova; }
                    $lista[$k]['l'] = $leg;
                    if (!$lote) flash('success', $nova ? 'Foto trocada. Já aparece no site.' : 'Legenda salva.');
                } else {
                    if (!$nova) throw new RuntimeException('Escolha a foto que vai entrar.');
                    if (count($lista) >= ESTUDANTES_MAX) { $apagar_arq($nova); throw new RuntimeException('A seção mostra no máximo ' . ESTUDANTES_MAX . ' fotos. Remova uma antes.'); }
                    $lista[] = ['a' => $nova, 'l' => $leg];
                    if (!$lote) flash('success', 'Foto adicionada à seção dos estudantes.');
                }
                break;
            case 'remover':
                if (isset($lista[$k])) { $apagar_arq($lista[$k]['a']); array_splice($lista, $k, 1); flash('success', 'Foto removida do site.'); }
                break;
            case 'mover':
                $d = (int)($_POST['dir'] ?? 0);
                if (isset($lista[$k], $lista[$k + $d])) [$lista[$k], $lista[$k + $d]] = [$lista[$k + $d], $lista[$k]];
                break;
        }
        $gravar($lista);
        registrar_log('estudantes_site', $acao);
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    redirecionar('estudantes_site.php');
}

$lista = $carregar();
$src = fn(array $x, int $k) => str_starts_with($x['a'], 'site/') ? RAIZ . 'foto_site.php?e=' . $k . '&v=' . substr(md5($x['a']), 0, 8) : RAIZ . $x['a'];

$titulo = 'Fotos dos estudantes';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="es-cab">
    <div><h2 class="h5 fw-bold mb-1">Quem faz a escola acontecer</h2><p class="small text-secondary mb-0">As fotos da seção "Nossos estudantes" na página inicial. Toque em <b>Alterar foto</b> para escolher e enquadrar. Faça todas as mudanças e salve uma vez só no botão que aparece embaixo. Até <?= ESTUDANTES_MAX ?> fotos.</p></div>
    <a class="btn btn-light btn-sm ms-auto" href="<?= RAIZ ?>index.php#estudantes" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a>
</div>

<div class="es-grade">
    <?php foreach ($lista as $k => $x): ?>
    <form method="post" enctype="multipart/form-data" class="card es-card" data-lote data-lote-acao="salvar">
        <?= csrf_campo() ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="dir" value="0">
        <div class="es-foto" id="esPrev<?= $k ?>">
            <img src="<?= e($src($x, $k)) ?>" alt="">
            <span class="es-num"><?= sprintf('%02d', $k + 1) ?></span>
            <span class="es-ordem">
                <button class="btn btn-sm" name="acao" value="mover" onclick="this.form.dir.value=-1" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Mover para antes" title="Mover para antes"><i class="bi bi-arrow-left"></i></button>
                <button class="btn btn-sm" name="acao" value="mover" onclick="this.form.dir.value=1" <?= $k >= count($lista) - 1 ? 'disabled' : '' ?> aria-label="Mover para depois" title="Mover para depois"><i class="bi bi-arrow-right"></i></button>
            </span>
        </div>
        <div class="es-corpo">
            <input class="form-control form-control-sm" name="legenda" maxlength="80" value="<?= e((string)$x['l']) ?>" placeholder="Frase da foto (ex.: Foco no que importa)" aria-label="Legenda">
            <input type="file" name="foto" accept="image/*" hidden id="esArq<?= $k ?>" data-recorte="20:23" data-saida="1200" data-previa="#esPrev<?= $k ?>" data-recorte-titulo="Enquadrar a foto <?= sprintf('%02d', $k + 1) ?>">
            <div class="es-bts">
                <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('esArq<?= $k ?>').click()"><i class="bi bi-crop me-1"></i>Alterar foto</button>
                <button type="submit" class="btn btn-light btn-sm text-danger" name="acao" value="remover" onclick="return confirm('Remover esta foto da seção dos estudantes?')" aria-label="Remover foto"><i class="bi bi-trash me-1"></i>Remover foto</button>
            </div>
        </div>
    </form>
    <?php endforeach; ?>

    <?php if (count($lista) < ESTUDANTES_MAX): ?>
    <form method="post" enctype="multipart/form-data" class="card es-card es-nova" data-lote data-lote-acao="salvar" data-lote-exige-arquivo>
        <?= csrf_campo() ?><input type="hidden" name="k" value="-1"><input type="hidden" name="acao" value="salvar">
        <label class="es-foto" id="esPrevNova" for="esArqNova"><span class="es-add"><i class="bi bi-plus-lg"></i><b>Adicionar foto</b><small>Você enquadra depois de escolher</small></span></label>
        <div class="es-corpo">
            <input class="form-control form-control-sm" name="legenda" maxlength="80" placeholder="Frase da foto" aria-label="Legenda da nova foto">
            <input type="file" name="foto" accept="image/*" hidden id="esArqNova" data-recorte="20:23" data-saida="1200" data-previa="#esPrevNova" data-recorte-titulo="Enquadrar a nova foto">
        </div>
    </form>
    <?php endif; ?>
</div>
<?php

require __DIR__ . '/../includes/layout_rodape.php'; ?>
