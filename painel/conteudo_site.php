<?php
/* Site › A escola e contato: carrossel de fotos da seção "A escola", textos da escola, os 4 destaques e o contato */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

const ESCOLA_FOTOS_MAX = 10;
$carregar = function (): array {
    $l = escola_fotos_lista();
    if ($l !== null) return $l;
    return is_file(dirname(__DIR__) . '/assets/img/escola-aerea.jpg') ? [['a' => 'assets/img/escola-aerea.jpg', 'l' => 'Nossa escola vista de cima · Alto da Queiroz, Boa Viagem']] : [];
};
$gravar_fotos = fn(array $l) => config_gravar('site_escola_fotos', json_encode(array_values($l), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$apagar_arq = function (?string $a): void { if ($a && str_starts_with($a, 'site/escola/')) site_img_apagar($a); };

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    $lote = !empty($_POST['lote']);
    try {
        if (in_array($acao, ['foto_salvar', 'foto_remover', 'foto_mover'], true)) {
            $lista = $carregar(); $k = (int)($_POST['k'] ?? -1);
            if ($acao === 'foto_salvar') {
                $leg = mb_substr(trim((string)($_POST['legenda'] ?? '')), 0, 120);
                $nova = !empty($_FILES['foto']['name']) ? site_img_salvar($_FILES['foto'], 'escola', 'c')[0] : null;
                if ($k >= 0 && isset($lista[$k])) { if ($nova) { $apagar_arq($lista[$k]['a']); $lista[$k]['a'] = $nova; } $lista[$k]['l'] = $leg; }
                else {
                    if (!$nova) throw new RuntimeException('Escolha a foto que vai entrar no carrossel.');
                    if (count($lista) >= ESCOLA_FOTOS_MAX) { $apagar_arq($nova); throw new RuntimeException('O carrossel aceita até ' . ESCOLA_FOTOS_MAX . ' fotos. Remova uma antes.'); }
                    $lista[] = ['a' => $nova, 'l' => $leg];
                }
                if (!$lote) flash('success', 'Carrossel atualizado. Já aparece no site.');
            } elseif ($acao === 'foto_remover' && isset($lista[$k])) {
                if (count($lista) <= 1) throw new RuntimeException('O carrossel precisa de pelo menos uma foto. Adicione outra antes de remover esta.');
                $apagar_arq($lista[$k]['a']); array_splice($lista, $k, 1); flash('success', 'Foto removida do carrossel.');
            } elseif ($acao === 'foto_mover') {
                $d = (int)($_POST['dir'] ?? 0);
                if (isset($lista[$k], $lista[$k + $d])) [$lista[$k], $lista[$k + $d]] = [$lista[$k + $d], $lista[$k]];
            }
            $gravar_fotos($lista);
            registrar_log('site_escola_fotos', $acao);
            redirecionar('conteudo_site.php#carrossel');
        }
        if ($acao === 'textos') {
            $t = site_textos();
            $lim = fn($k, $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
            $t['escola'] = ['titulo' => $lim('e_titulo', 120), 'texto' => $lim('e_texto', 2000)];
            $t['pilares'] = [];
            for ($i = 0; $i < 4; $i++) $t['pilares'][$i] = ['titulo' => mb_substr(trim((string)($_POST['p_tit'][$i] ?? '')), 0, 40), 'texto' => mb_substr(trim((string)($_POST['p_tx'][$i] ?? '')), 0, 80)];
            $em = $lim('c_email', 160);
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('O e-mail de contato não parece válido.');
            $t['contato'] = ['endereco' => $lim('c_endereco', 200), 'cidade' => $lim('c_cidade', 80), 'telefone' => $lim('c_telefone', 40), 'email' => $em,
                             'instagram' => ltrim($lim('c_instagram', 60), '@'), 'atendimento' => $lim('c_atendimento', 80), 'mapa' => $lim('c_mapa', 200)];
            config_gravar('site_textos', json_encode($t, JSON_UNESCAPED_UNICODE));
            registrar_log('site_textos', 'textos e contato');
            flash('success', 'Textos e contato salvos. Já aparecem no site.');
            redirecionar('conteudo_site.php#' . ($_POST['volta'] ?? 'textos'));
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    redirecionar('conteudo_site.php');
}

$fotos = $carregar();
$src = fn(array $x, int $k) => str_starts_with($x['a'], 'site/') ? RAIZ . 'foto_site.php?c=' . $k . '&v=' . substr(md5($x['a']), 0, 8) : RAIZ . $x['a'];
$t = site_textos();
$ct = $t['contato'] ?? [];
/* valores atuais (o que está no ar) para mostrar como sugestão */
$padrao_escola = $GLOBALS['ESCOLA_PADRAO'] ?? $ESCOLA;
$pil = site_pilares();

$titulo = 'A escola e contato';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<section class="cs-sec" id="carrossel">
    <div class="cs-cab"><span class="cs-n">1</span><div><h2>Carrossel da seção "A escola"</h2><p>As fotos passam sozinhas no lugar da foto aérea. Toque em <b>Trocar foto</b> para enquadrar (formato largo). Até <?= ESCOLA_FOTOS_MAX ?> fotos.</p></div>
        <a class="btn btn-light btn-sm ms-auto" href="<?= RAIZ ?>index.php#escola" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a></div>
    <div class="cs-fotos">
        <?php foreach ($fotos as $k => $x): ?>
        <form method="post" enctype="multipart/form-data" class="card cs-foto" data-lote data-lote-acao="foto_salvar">
            <?= csrf_campo() ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="dir" value="0">
            <div class="cs-img" id="csPrev<?= $k ?>"><img src="<?= e($src($x, $k)) ?>" alt=""><span class="es-num"><?= sprintf('%02d', $k + 1) ?></span>
                <span class="es-ordem">
                    <button class="btn btn-sm" name="acao" value="foto_mover" onclick="this.form.dir.value=-1" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Mover para antes"><i class="bi bi-arrow-left"></i></button>
                    <button class="btn btn-sm" name="acao" value="foto_mover" onclick="this.form.dir.value=1" <?= $k >= count($fotos) - 1 ? 'disabled' : '' ?> aria-label="Mover para depois"><i class="bi bi-arrow-right"></i></button>
                </span></div>
            <div class="es-corpo">
                <input class="form-control form-control-sm" name="legenda" maxlength="120" value="<?= e((string)$x['l']) ?>" placeholder="Legenda (opcional)" aria-label="Legenda">
                <input type="file" name="foto" accept="image/*" hidden id="csArq<?= $k ?>" data-recorte="16:9" data-saida="1920" data-previa="#csPrev<?= $k ?>" data-recorte-titulo="Enquadrar a foto <?= sprintf('%02d', $k + 1) ?>">
                <div class="es-bts">
                    <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('csArq<?= $k ?>').click()"><i class="bi bi-crop me-1"></i>Trocar foto</button>
                    <button type="submit" class="btn btn-light btn-sm text-danger" name="acao" value="foto_remover" onclick="return confirm('Remover esta foto do carrossel?')"><i class="bi bi-trash me-1"></i>Remover</button>
                </div>
            </div>
        </form>
        <?php endforeach; ?>
        <?php if (count($fotos) < ESCOLA_FOTOS_MAX): ?>
        <form method="post" enctype="multipart/form-data" class="card cs-foto es-nova" data-lote data-lote-acao="foto_salvar" data-lote-exige-arquivo>
            <?= csrf_campo() ?><input type="hidden" name="k" value="-1"><input type="hidden" name="acao" value="foto_salvar">
            <label class="cs-img" id="csPrevNova" for="csArqNova"><span class="es-add"><i class="bi bi-plus-lg"></i><b>Adicionar foto</b><small>Você enquadra depois de escolher</small></span></label>
            <div class="es-corpo">
                <input class="form-control form-control-sm" name="legenda" maxlength="120" placeholder="Legenda (opcional)" aria-label="Legenda da nova foto">
                <input type="file" name="foto" accept="image/*" hidden id="csArqNova" data-recorte="16:9" data-saida="1920" data-previa="#csPrevNova" data-recorte-titulo="Enquadrar a nova foto">
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>

<form method="post" class="cs-sec" id="textos">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="textos"><input type="hidden" name="volta" value="textos">
    <div class="cs-cab"><span class="cs-n">2</span><div><h2>Texto da escola e destaques</h2><p>O título e o texto ao lado da foto, e os 4 destaques logo abaixo. Deixe em branco para usar o texto padrão.</p></div></div>
    <div class="card"><div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="eTit">Título</label><input class="form-control" id="eTit" name="e_titulo" maxlength="120" value="<?= e($t['escola']['titulo'] ?? '') ?>" placeholder="Formando para a vida e para o trabalho"></div>
        <div class="col-12"><label class="form-label" for="eTx">Texto sobre a escola</label><textarea class="form-control" id="eTx" name="e_texto" rows="4" maxlength="2000" placeholder="<?= e(mb_strimwidth($HISTORIA_INTRO, 0, 160, '…')) ?>"><?= e($t['escola']['texto'] ?? '') ?></textarea></div>
        <?php foreach ($pil as $i => $p): ?>
        <div class="col-sm-6 col-xl-3"><div class="cs-pilar"><i class="bi bi-<?= e($p['icone']) ?>"></i>
            <input class="form-control form-control-sm mb-1" name="p_tit[<?= $i ?>]" maxlength="40" value="<?= e($t['pilares'][$i]['titulo'] ?? '') ?>" placeholder="<?= e(PILARES_PADRAO[$i][1]) ?>" aria-label="Destaque <?= $i + 1 ?>: título">
            <input class="form-control form-control-sm" name="p_tx[<?= $i ?>]" maxlength="80" value="<?= e($t['pilares'][$i]['texto'] ?? '') ?>" placeholder="<?= e(PILARES_PADRAO[$i][2]) ?>" aria-label="Destaque <?= $i + 1 ?>: texto"></div></div>
        <?php endforeach; ?>
    </div></div>

    <div class="cs-cab mt-4" id="contato"><span class="cs-n">3</span><div><h2>Contato</h2><p>Aparece na seção "Contato" do site, no rodapé e nos documentos impressos. Em branco = usa o valor atual.</p></div></div>
    <div class="card"><div class="card-body row g-3">
        <?php foreach ([['c_endereco', 'endereco', 'Endereço', 'geo-alt', 200, 'text'], ['c_cidade', 'cidade', 'Cidade', 'buildings', 80, 'text'], ['c_telefone', 'telefone', 'Telefone', 'telephone', 40, 'tel'],
                        ['c_email', 'email', 'E-mail', 'envelope', 160, 'email'], ['c_instagram', 'instagram', 'Instagram (sem @)', 'instagram', 60, 'text'], ['c_atendimento', 'atendimento', 'Atendimento ao público', 'clock', 80, 'text'],
                        ['c_mapa', 'mapa', 'Local no mapa (o que se busca no Google Maps)', 'map', 200, 'text']] as [$n, $k, $r, $ic, $max, $tp]): ?>
        <div class="<?= in_array($k, ['endereco', 'mapa'], true) ? 'col-12' : 'col-md-6' ?>"><label class="form-label" for="<?= $n ?>"><i class="bi bi-<?= $ic ?> me-1 text-success"></i><?= e($r) ?></label>
            <input class="form-control" type="<?= $tp ?>" id="<?= $n ?>" name="<?= $n ?>" maxlength="<?= $max ?>" value="<?= e($ct[$k] ?? '') ?>" placeholder="<?= e($padrao_escola[$k] ?? '') ?>"></div>
        <?php endforeach; ?>
        <div class="col-12"><div class="cs-mapa"><iframe title="Prévia do mapa" loading="lazy" src="https://maps.google.com/maps?q=<?= rawurlencode($ESCOLA['mapa']) ?>&z=15&output=embed"></iframe></div></div>
    </div></div>
    <div class="cs-salvar"><span><i class="bi bi-info-circle me-1"></i>As mudanças aparecem no site assim que você salvar.</span><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvar textos e contato</button></div>
</form>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
