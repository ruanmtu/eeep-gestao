<?php
/* Banners do carrossel da página inicial do site (artes 1600 × 900) */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$bid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = $bid ? q_um('SELECT * FROM site_banners WHERE id = ?', [$bid]) : null;

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    try {
        switch ($acao) {
            case 'destaques':   /* v80: card "Alunos destaque" do carrossel */
                $al = [];
                for ($k = 0; $k < 3; $k++) $al[] = ['nome' => mb_substr(trim((string)($_POST['dq_nome'][$k] ?? '')), 0, 120), 'nota' => mb_substr(trim((string)($_POST['dq_nota'][$k] ?? '')), 0, 8), 'turma' => mb_substr(trim((string)($_POST['dq_turma'][$k] ?? '')), 0, 60)];
                config_gravar('site_destaques', json_encode(['ativo' => !empty($_POST['dq_ativo']) ? 1 : 0, 'titulo' => mb_substr(trim((string)($_POST['dq_titulo'] ?? '')), 0, 60) ?: 'Alunos destaque',
                    'periodo' => mb_substr(trim((string)($_POST['dq_periodo'] ?? '')), 0, 60), 'alunos' => $al], JSON_UNESCAPED_UNICODE));
                for ($k = 0; $k < 3; $k++) {   // uma foto (em pé) de cada aluno
                    if (!empty($_POST['dq_tirar'][$k])) { site_img_apagar(destaques_foto($k)); config_gravar('site_destaques_foto_' . $k, null); }
                    if (!empty($_FILES['dq_foto']['name'][$k])) {
                        $up = ['name' => $_FILES['dq_foto']['name'][$k], 'type' => $_FILES['dq_foto']['type'][$k], 'tmp_name' => $_FILES['dq_foto']['tmp_name'][$k], 'error' => $_FILES['dq_foto']['error'][$k], 'size' => $_FILES['dq_foto']['size'][$k]];
                        [$arq] = site_img_salvar($up, 'banners', 'destaque' . ($k + 1));
                        site_img_apagar(destaques_foto($k));
                        config_gravar('site_destaques_foto_' . $k, $arq);
                    }
                }
                flash('success', 'Card de alunos destaque salvo.');
                redirecionar('banners_site.php#destaques');
            case 'venha':   /* v87: card "Venha ser EEEP!" */
                $it = [];
                for ($k = 0; $k < 3; $k++) $it[] = ['t' => mb_substr(trim((string)($_POST['vs_t'][$k] ?? '')), 0, 40), 'd' => mb_substr(trim((string)($_POST['vs_d'][$k] ?? '')), 0, 60)];
                $lk = trim((string)($_POST['vs_link'] ?? '#cursos'));
                if ($lk !== '' && !preg_match('~^(#[\w-]+|[\w-]+\.php(\?[\w=&-]*)?|https?://\S+)$~', $lk)) $lk = '#cursos';
                config_gravar('site_venha', json_encode(['ativo' => !empty($_POST['vs_ativo']) ? 1 : 0,
                    'linha1' => mb_substr(trim((string)($_POST['vs_linha1'] ?? '')), 0, 30) ?: 'Venha ser', 'linha2' => mb_substr(trim((string)($_POST['vs_linha2'] ?? '')), 0, 20) ?: 'EEEP!',
                    'selo' => mb_substr(trim((string)($_POST['vs_selo'] ?? '')), 0, 40), 'sub' => mb_substr(trim((string)($_POST['vs_sub'] ?? '')), 0, 90),
                    'itens' => $it, 'botao' => mb_substr(trim((string)($_POST['vs_botao'] ?? '')), 0, 30) ?: 'Conheça os cursos', 'link' => $lk ?: '#cursos'], JSON_UNESCAPED_UNICODE));
                if (!empty($_FILES['vs_foto']['name'])) {
                    [$arq] = site_img_salvar($_FILES['vs_foto'], 'banners', 'venha');
                    $ant = venha_foto(); if ($ant) site_img_apagar($ant);
                    config_gravar('site_venha_foto', $arq);
                }
                flash('success', 'Card "Venha ser EEEP!" salvo.');
                redirecionar('banners_site.php#venha');
            case 'vs_tirar_foto':
                $ant = venha_foto(); if ($ant) site_img_apagar($ant);
                config_gravar('site_venha_foto', null); config_gravar('site_venha_semeado', '1');
                flash('success', 'Foto do card "Venha ser EEEP!" removida.');
                redirecionar('banners_site.php#venha');
            case 'dq_tirar_foto':   /* v85: lixeira na foto do aluno destaque */
                $k = (int)($_POST['k'] ?? -1);
                if ($k >= 0 && $k < 3) { site_img_apagar(destaques_foto($k)); config_gravar('site_destaques_foto_' . $k, null); flash('success', 'Foto do ' . ($k + 1) . 'º lugar removida.'); }
                redirecionar('banners_site.php#destaques');
            case 'salvar':
                $t = trim((string)($_POST['titulo'] ?? ''));
                if (mb_strlen($t) < 3) throw new RuntimeException('Escreva o que o banner diz (serve para quem usa leitor de tela e para achar no painel).');
                $tipo = (string)($_POST['link_tipo'] ?? '');
                $link = $tipo === 'outro' ? banner_link_valido((string)($_POST['link_outro'] ?? '')) : banner_link_valido($tipo);
                if ($tipo === 'outro' && trim((string)($_POST['link_outro'] ?? '')) !== '' && $link === '') throw new RuntimeException('O endereço do link precisa começar com https://');
                $ini = data_valida($_POST['inicio'] ?? '') ? $_POST['inicio'] : null;
                $fim = data_valida($_POST['fim'] ?? '') ? $_POST['fim'] : null;
                if ($ini && $fim && $fim < $ini) throw new RuntimeException('A data de saída do ar é antes da data de início.');
                $tem_arquivo = !empty($_FILES['imagem']['name']);
                if (!$atual && !$tem_arquivo) throw new RuntimeException('Escolha a arte do banner (1600 × 900 px).');
                $dados = [mb_substr($t, 0, 140), $link ?: null, !empty($_POST['ativo']) ? 1 : 0, $ini, $fim];
                if ($atual) q('UPDATE site_banners SET titulo = ?, link = ?, ativo = ?, inicio = ?, fim = ? WHERE id = ?', [...$dados, $bid]);
                else {
                    $ordem = (int)q_valor('SELECT COALESCE(MIN(ordem), 1) - 1 FROM site_banners');   // novo entra primeiro
                    q('INSERT INTO site_banners (titulo, link, ativo, inicio, fim, imagem, ordem, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$dados, '', $ordem, uid(), date('Y-m-d H:i:s')]);
                    $bid = (int)db()->lastInsertId();
                }
                if ($tem_arquivo) {
                    [$arq, $w, $h] = site_img_salvar($_FILES['imagem'], 'banners', 'b' . $bid);
                    if ($atual && $atual['imagem']) site_img_apagar($atual['imagem']);
                    q('UPDATE site_banners SET imagem = ? WHERE id = ?', [$arq, $bid]);
                    if ($h > 0 && abs($w / $h - 16 / 9) > 0.08) flash('warning', 'A arte do celular tem ' . $w . ' × ' . $h . ' px. O ideal é 1600 × 900 (16:9): nesse formato ela aparece inteira, sem cortes.');
                }
                if (!empty($_FILES['imagem_larga']['name'])) {
                    [$arq, $w, $h] = site_img_salvar($_FILES['imagem_larga'], 'banners', 'bl' . $bid);
                    if ($atual && !empty($atual['imagem_larga'])) site_img_apagar($atual['imagem_larga']);
                    q('UPDATE site_banners SET imagem_larga = ? WHERE id = ?', [$arq, $bid]);
                    if ($h > 0 && abs($w / $h - 4.8) > 0.4) flash('warning', 'A arte larga tem ' . $w . ' × ' . $h . ' px. O ideal é 1920 × 400: fora disso ela é cortada no computador.');
                } elseif ($atual && !empty($_POST['tirar_larga']) && !empty($atual['imagem_larga'])) { site_img_apagar($atual['imagem_larga']); q('UPDATE site_banners SET imagem_larga = NULL WHERE id = ?', [$bid]); }
                registrar_log('site_banner', $t);
                flash('success', $atual ? 'Banner atualizado.' : 'Banner publicado no site.');
                redirecionar('banners_site.php');
            case 'ativo':
                if ($atual) q('UPDATE site_banners SET ativo = 1 - ativo WHERE id = ?', [$bid]);
                redirecionar('banners_site.php');
            case 'mover':
                $lista = q_todos('SELECT id FROM site_banners ORDER BY ordem, id DESC');
                $ids = array_map('intval', array_column($lista, 'id'));
                $k = array_search($bid, $ids, true); $d = (int)($_POST['dir'] ?? 0);
                if ($k !== false && isset($ids[$k + $d])) { [$ids[$k], $ids[$k + $d]] = [$ids[$k + $d], $ids[$k]]; }
                foreach ($ids as $i => $id) q('UPDATE site_banners SET ordem = ? WHERE id = ?', [$i + 1, $id]);
                redirecionar('banners_site.php');
            case 'apagar':
                if ($atual) { site_img_apagar($atual['imagem']); site_img_apagar($atual['imagem_larga'] ?? null); q('DELETE FROM site_banners WHERE id = ?', [$bid]); registrar_log('site_banner_apagado', $atual['titulo']); flash('success', 'Banner apagado.'); }
                redirecionar('banners_site.php');
            case 'importar_padrao':
                $pasta = pasta_dados('site/banners'); $n = 0;
                foreach ($BANNERS_PADRAO ?? [] as $k => $b) {
                    $orig = dirname(__DIR__) . '/' . $b['imagem'];
                    if (!is_file($orig)) continue;
                    $nome = 'bp' . $k . '_' . bin2hex(random_bytes(4));
                    copy($orig, "$pasta/$nome.jpg"); copy($orig, "$pasta/{$nome}_p.jpg");
                    $larga = null; $ol = dirname(__DIR__) . '/' . ($b['larga'] ?? '');
                    if (!empty($b['larga']) && is_file($ol)) { copy($ol, "$pasta/{$nome}_l.jpg"); copy($ol, "$pasta/{$nome}_l_p.jpg"); $larga = "site/banners/{$nome}_l.jpg"; }
                    q('INSERT INTO site_banners (titulo, imagem, imagem_larga, link, ordem, ativo, inicio, fim, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, 1, NULL, ?, ?, ?)',
                      [$b['titulo'], "site/banners/$nome.jpg", $larga, $b['link'] ?: null, $k + 1, $b['fim'] ?: null, uid(), date('Y-m-d H:i:s')]);
                    $n++;
                }
                flash('success', $n . ' banners prontos para editar.');
                redirecionar('banners_site.php');
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('banners_site.php' . ($bid ? '?editar=' . $bid : '')); }
    redirecionar('banners_site.php');
}

$lista = site_banners(false);
$hoje = date('Y-m-d');
$situacao = function (array $b) use ($hoje): array {
    if (!$b['ativo']) return ['Desligado', 'off'];
    if ($b['inicio'] && $b['inicio'] > $hoje) return ['Entra em ' . data_curta($b['inicio']), 'agenda'];
    if ($b['fim'] && $b['fim'] < $hoje) return ['Saiu do ar em ' . data_curta($b['fim']), 'fim'];
    return ['No ar' . ($b['fim'] ? ' até ' . data_curta($b['fim']) : ''), 'ar'];
};
/* destinos prontos para o link */
$destinos = ['' => 'Sem link (só a imagem)', 'informes.php' => 'Todas as notícias', '#agenda' => 'Página inicial › Próximos eventos', '#cursos' => 'Página inicial › Cursos',
             '#escola' => 'Página inicial › A escola', '#historia' => 'Página inicial › História', '#contato' => 'Página inicial › Contato'];
foreach (site_informes(true, 40) as $i) $destinos['informe.php?id=' . $i['id']] = 'Informe › ' . mb_strimwidth($i['titulo'], 0, 60, '…');
$f = $atual ?: ['id' => 0, 'titulo' => '', 'link' => '', 'ativo' => 1, 'inicio' => null, 'fim' => null, 'imagem' => '', 'imagem_larga' => null];
$link_atual = (string)$f['link'];
$link_outro = $link_atual !== '' && !isset($destinos[$link_atual]);

$titulo = 'Banners do site';
$subtitulo = 'As artes que passam no topo da página inicial: 1920 × 400 px (computador) e 1600 × 900 px (celular).';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
$st_acoes = janela_botao('novoBanner', 'Novo banner', 'plus-lg');
require __DIR__ . '/../includes/site_abas.php';
?>
<?php $dq = destaques_cfg(); $dq_al = array_pad((array)(json_decode((string)config_ler('site_destaques'), true)['alunos'] ?? []), 3, []); ?>
<form method="post" enctype="multipart/form-data" class="card mb-3 dq-adm" id="destaques"><?= csrf_campo() ?><input type="hidden" name="acao" value="destaques">
    <div class="card-header"><h2><i class="bi bi-award me-1"></i>Card de alunos destaque</h2><label class="form-check form-switch ms-auto mb-0"><input class="form-check-input" type="checkbox" name="dq_ativo" value="1"<?= !empty($dq['ativo']) ? ' checked' : '' ?>> <span class="form-check-label small">Mostrar no carrossel (primeiro slide)</span></label></div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small">Título</label><input class="form-control" name="dq_titulo" maxlength="60" value="<?= e($dq['titulo'] ?? 'Alunos destaque') ?>"></div>
            <div class="col-6"><label class="form-label small">Período</label><input class="form-control" name="dq_periodo" maxlength="60" value="<?= e($dq['periodo'] ?? '') ?>" placeholder="3º período · 2026"></div>
        </div>
        <div class="dq-adm-alunos">
            <?php foreach ([0, 1, 2] as $k): $a = $dq_al[$k]; $dqf = destaques_foto($k); ?>
            <div class="dq-adm-aluno">
                <div class="dq-adm-quadro">
                    <label class="dq-adm-foto" for="dqFoto<?= $k ?>" title="Escolher a foto em pé do aluno"><?php if ($dqf): ?><img src="<?= e(site_img_url('dq', $k, $dqf, true, '../')) ?>" alt=""><?php else: ?><img alt="" hidden><span><i class="bi bi-camera"></i>Foto em pé<small>toque para escolher</small></span><?php endif; ?><b class="dq-adm-pos"><?= $k + 1 ?>º</b></label>
                    <?php if ($dqf): ?><button class="dq-adm-lixo" type="submit" form="dqTirarFoto" name="k" value="<?= $k ?>" title="Remover a foto" aria-label="Remover a foto do <?= $k + 1 ?>º lugar"><i class="bi bi-trash3"></i></button><?php endif; ?>
                    <label class="dq-adm-trocar" for="dqFoto<?= $k ?>"><i class="bi bi-crop"></i><?= $dqf ? 'Trocar foto' : 'Escolher foto' ?></label>
                </div>
                <input class="visually-hidden dq-adm-arq" id="dqFoto<?= $k ?>" type="file" name="dq_foto[<?= $k ?>]" accept="image/*" data-recorte="3:4" data-saida="900" data-recorte-titulo="Enquadrar a foto em pé do <?= $k + 1 ?>º lugar">
                <input class="form-control mb-1" name="dq_nome[]" maxlength="120" value="<?= e($a['nome'] ?? '') ?>" placeholder="Nome do aluno" aria-label="Nome do <?= $k + 1 ?>º lugar">
                <div class="d-flex gap-1"><input class="form-control" style="max-width:90px" name="dq_nota[]" maxlength="8" value="<?= e($a['nota'] ?? '') ?>" placeholder="9,94" aria-label="Média"><input class="form-control" name="dq_turma[]" maxlength="60" value="<?= e($a['turma'] ?? '') ?>" placeholder="Turma (opcional)" aria-label="Turma"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Salvar card</button>
    </div>
</form>
<form method="post" id="dqTirarFoto" hidden><?= csrf_campo() ?><input type="hidden" name="acao" value="dq_tirar_foto"></form>
<script>document.querySelectorAll('.dq-adm-arq').forEach(inp => inp.addEventListener('change', e => { const f = e.target.files[0], l = document.querySelector('label[for="' + inp.id + '"]'); if (!f) return; const i = l.querySelector('img'); i.src = URL.createObjectURL(f); i.hidden = false; const c = l.querySelector('span'); if (c) c.hidden = true; }));</script>
<?php $vs = venha_cfg(); $vsf = venha_foto(); $vs_it = array_pad((array)(json_decode((string)config_ler('site_venha'), true)['itens'] ?? []), 3, []);
      $vs_links = ['#cursos' => 'Página inicial › Cursos', 'editais.php' => 'Editais de seleção', '#documentos' => 'Página inicial › Documentos', '#contato' => 'Página inicial › Contato', 'informes.php' => 'Notícias']; ?>
<form method="post" id="vsTirarFoto" hidden><?= csrf_campo() ?><input type="hidden" name="acao" value="vs_tirar_foto"></form>
<form method="post" enctype="multipart/form-data" class="card mb-3 dq-adm vs-adm" id="venha"><?= csrf_campo() ?><input type="hidden" name="acao" value="venha">
    <div class="card-header"><h2><i class="bi bi-stars me-1"></i>Card "Venha ser EEEP!"</h2><label class="form-check form-switch ms-auto mb-0"><input class="form-check-input" type="checkbox" name="vs_ativo" value="1"<?= !empty($vs['ativo']) ? ' checked' : '' ?>> <span class="form-check-label small">Mostrar no carrossel</span></label></div>
    <div class="card-body">
        <div class="vs-adm-grade">
            <div class="dq-adm-quadro">
                <label class="dq-adm-foto" for="vsFoto" title="Escolher a foto dos estudantes"><?php if ($vsf): ?><img src="<?= e(site_img_url('vs', 1, $vsf, true, '../')) ?>" alt=""><?php else: ?><img alt="" hidden><span><i class="bi bi-camera"></i>Foto dos estudantes<small>toque para escolher</small></span><?php endif; ?></label>
                <?php if ($vsf): ?><button class="dq-adm-lixo" type="submit" form="vsTirarFoto" title="Remover a foto" aria-label="Remover a foto"><i class="bi bi-trash3"></i></button><?php endif; ?>
                <label class="dq-adm-trocar" for="vsFoto"><i class="bi bi-crop"></i><?= $vsf ? 'Trocar foto' : 'Escolher foto' ?></label>
                <input class="visually-hidden dq-adm-arq" id="vsFoto" type="file" name="vs_foto" accept="image/*" data-recorte="3:4" data-saida="1100" data-recorte-titulo="Enquadrar a foto dos estudantes">
            </div>
            <div class="vstack gap-2">
                <div class="row g-2">
                    <div class="col-6 col-md-3"><label class="form-label small">Título</label><input class="form-control" name="vs_linha1" maxlength="30" value="<?= e($vs['linha1']) ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label small">Destaque (dourado)</label><input class="form-control" name="vs_linha2" maxlength="20" value="<?= e($vs['linha2']) ?>"></div>
                    <div class="col-12 col-md-6"><label class="form-label small">Selo <span class="text-secondary">(ex.: Seleção 2027, Inscrições abertas)</span></label><input class="form-control" name="vs_selo" maxlength="40" value="<?= e($vs['selo']) ?>"></div>
                    <div class="col-12"><label class="form-label small">Frase</label><input class="form-control" name="vs_sub" maxlength="90" value="<?= e($vs['sub']) ?>"></div>
                </div>
                <label class="form-label small mb-0 mt-1">Vantagens <span class="text-secondary">(título e uma frase curta)</span></label>
                <?php foreach ([0, 1, 2] as $k): ?>
                <div class="d-flex gap-2 align-items-center"><span class="vs-adm-ic"><i class="bi bi-<?= VENHA_ICONES[$k] ?>"></i></span><input class="form-control" name="vs_t[]" maxlength="40" value="<?= e($vs_it[$k]['t'] ?? '') ?>" placeholder="Título" aria-label="Vantagem <?= $k + 1 ?>"><input class="form-control" name="vs_d[]" maxlength="60" value="<?= e($vs_it[$k]['d'] ?? '') ?>" placeholder="Frase curta" aria-label="Frase da vantagem <?= $k + 1 ?>"></div>
                <?php endforeach; ?>
                <div class="row g-2 mt-1">
                    <div class="col-6"><label class="form-label small">Texto do botão</label><input class="form-control" name="vs_botao" maxlength="30" value="<?= e($vs['botao']) ?>"></div>
                    <div class="col-6"><label class="form-label small">O botão leva para</label><select class="form-select" name="vs_link"><?php foreach ($vs_links as $u => $r): ?><option value="<?= e($u) ?>"<?= $vs['link'] === $u ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?><?php if (!isset($vs_links[$vs['link']])): ?><option selected value="<?= e($vs['link']) ?>"><?= e($vs['link']) ?></option><?php endif; ?></select></div>
                </div>
                <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1"></i>Os cursos técnicos aparecem sozinhos no card. Com este card ligado, o banner antigo "Venha ser EEEP" sai do carrossel.</p>
                <div><button class="btn btn-primary mt-1"><i class="bi bi-check2 me-1"></i>Salvar card</button></div>
            </div>
        </div>
    </div>
</form>
<script>vsFoto.addEventListener('change', () => { const f = vsFoto.files[0], l = document.querySelector('label.dq-adm-foto[for="vsFoto"]'); if (!f) return; const i = l.querySelector('img'); i.src = URL.createObjectURL(f); i.hidden = false; const c = l.querySelector('span'); if (c) c.hidden = true; });</script>
<div class="row g-3">
    <div class="col-12">
        <?php if (!$lista): ?>
        <div class="card"><div class="card-body d-flex flex-column flex-sm-row align-items-sm-center gap-3">
            <div class="flex-grow-1"><strong class="d-block">Nenhum banner cadastrado ainda</strong><span class="small text-secondary">Enquanto isso, o site mostra os <?= count($BANNERS_PADRAO ?? []) ?> banners padrão da escola (Matrículas, ENEM, UECE e Venha ser EEEP). Os de data passada saem sozinhos.</span></div>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="importar_padrao"><button class="btn btn-outline-primary text-nowrap" type="submit"><i class="bi bi-download me-1"></i>Trazer os padrão para editar</button></form>
        </div></div>
        <?php else: ?>
        <div class="bn-lista">
            <?php foreach ($lista as $k => $b): [$st, $cl] = $situacao($b); ?>
            <article class="bn-item<?= (int)$b['id'] === $bid ? ' ativo' : '' ?> st-<?= $cl ?>">
                <div class="bn-thumb"><img src="<?= e(site_img_url('b', (int)$b['id'], $b['imagem'], true, RAIZ)) ?>" alt="" loading="lazy"><span class="bn-n"><?= $k + 1 ?></span></div>
                <div class="bn-info">
                    <span class="bn-st"><i class="bi bi-circle-fill"></i><?= e($st) ?></span>
                    <strong><?= e($b['titulo']) ?></strong>
                    <small><?= $b['link'] ? '<i class="bi bi-link-45deg"></i>' . e($destinos[$b['link']] ?? $b['link']) : 'Sem link' ?></small>
                </div>
                <div class="bn-acoes">
                    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="dir" value="-1"><button class="btn btn-sm btn-light" type="submit" aria-label="Subir"<?= $k === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i></button></form>
                    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="dir" value="1"><button class="btn btn-sm btn-light" type="submit" aria-label="Descer"<?= $k === count($lista) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i></button></form>
                    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-sm btn-light" type="submit" aria-label="<?= $b['ativo'] ? 'Desligar' : 'Ligar' ?>" title="<?= $b['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>"><i class="bi bi-<?= $b['ativo'] ? 'eye' : 'eye-slash' ?>"></i></button></form>
                    <a class="btn btn-sm btn-light" href="?editar=<?= $b['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                    <form method="post" data-confirmar="Apagar o banner &quot;<?= e($b['titulo']) ?>&quot;?" data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="small text-secondary mt-2"><i class="bi bi-info-circle me-1"></i>O site mostra só os que estão <strong>no ar</strong>, na ordem acima. Os novos entram em primeiro.</p>
        <?php endif; ?>
    </div>
</div>
<?= janela_abre('novoBanner', $atual ? 'Editar banner' : 'Novo banner', $atual ? 'pencil' : 'image', (bool)$atual) ?>
        <form method="post" enctype="multipart/form-data" class="card bn-form">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="card-header"><h2><i class="bi bi-<?= $atual ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $atual ? 'Editar banner' : 'Novo banner' ?></h2><?php if ($atual): ?><a class="ms-auto small" href="banners_site.php">novo</a><?php endif; ?></div>
            <div class="card-body">
                <label class="form-label"><i class="bi bi-display me-1"></i>Arte larga · computador</label>
                <label class="bn-arte larga" for="bnImgL">
                    <img id="bnPrevL"<?= !empty($f['imagem_larga']) ? ' src="' . e(site_img_url('b', (int)$f['id'], $f['imagem_larga'], true, RAIZ)) . '&l=1"' : ' hidden' ?> alt="">
                    <span id="bnPrevTxtL"<?= !empty($f['imagem_larga']) ? ' hidden' : '' ?>><i class="bi bi-image"></i><b>Escolher a arte larga</b><small>1920 × 400 px</small></span>
                </label>
                <input class="form-control form-control-sm mt-2" type="file" id="bnImgL" name="imagem_larga" data-recorte="24:5" data-saida="1920" data-recorte-titulo="Enquadrar a arte larga (computador)" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($f['imagem_larga'])): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="tirar_larga" value="1"> Tirar a arte larga</label><?php endif; ?>
                <div class="form-text mb-3">Sem a arte larga, o computador mostra a do celular cortada nas bordas.</div>
                <label class="form-label obrigatorio"><i class="bi bi-phone me-1"></i>Arte do celular</label>
                <label class="bn-arte" for="bnImg">
                    <img id="bnPrev"<?= $f['imagem'] ? ' src="' . e(site_img_url('b', (int)$f['id'], $f['imagem'], true, RAIZ)) . '"' : ' hidden' ?> alt="">
                    <span id="bnPrevTxt"<?= $f['imagem'] ? ' hidden' : '' ?>><i class="bi bi-image"></i><b>Escolher a arte</b><small>JPG ou PNG · 1600 × 900 px (16:9)</small></span>
                </label>
                <input class="form-control form-control-sm mt-2" type="file" id="bnImg" name="imagem" data-recorte="16:9" data-saida="1600" data-recorte-titulo="Enquadrar a arte do celular" accept="image/jpeg,image/png,image/webp">
                <div class="small mt-1" id="bnMedida" hidden></div>

                <label class="form-label mt-3 obrigatorio" for="bnT">O que o banner diz</label>
                <input class="form-control" id="bnT" name="titulo" maxlength="140" required value="<?= e($f['titulo']) ?>" placeholder="Ex.: Matrículas 2027 abertas">
                <div class="form-text">Para quem usa leitor de tela e para achar o banner aqui.</div>

                <label class="form-label mt-3" for="bnL">Ao tocar no banner, abrir</label>
                <select class="form-select" id="bnL" name="link_tipo">
                    <?php foreach ($destinos as $v => $rot): ?><option value="<?= e($v) ?>"<?= !$link_outro && $link_atual === $v ? ' selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
                    <option value="outro"<?= $link_outro ? ' selected' : '' ?>>Outro endereço (https://…)</option>
                </select>
                <input class="form-control mt-2" id="bnLo" name="link_outro" value="<?= $link_outro ? e($link_atual) : '' ?>" placeholder="https://…"<?= $link_outro ? '' : ' hidden' ?>>

                <div class="row g-2 mt-2">
                    <div class="col-6"><label class="form-label" for="bnI">Entra no ar em</label><input class="form-control" type="date" id="bnI" name="inicio" value="<?= e((string)$f['inicio']) ?>"></div>
                    <div class="col-6"><label class="form-label" for="bnF">Sai do ar depois de</label><input class="form-control" type="date" id="bnF" name="fim" value="<?= e((string)$f['fim']) ?>"></div>
                </div>
                <div class="form-text">Deixe vazio para publicar já e não tirar sozinho.</div>
                <label class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="ativo" value="1"<?= $f['ativo'] ? ' checked' : '' ?>> Mostrar no site</label>
                <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-send me-1"></i><?= $atual ? 'Salvar alterações' : 'Publicar banner' ?></button>
            </div>
        </form>
        <div class="card mt-3"><div class="card-body small bn-dicas">
            <strong class="d-block mb-1"><i class="bi bi-lightbulb me-1"></i>Para a arte ficar boa no celular</strong>
            Computador: 1920 × 400 px · celular: 1600 × 900 px · margem de 80 px sem texto nas bordas · título com 100 px ou mais · no máximo umas 12 palavras · texto à esquerda e foto à direita. No Canva: Tamanho personalizado 1600 × 900, baixar em JPG.
        </div></div>
<?= janela_fecha() ?>
<?php
$script_pagina = '<script>
(function () {
    const inp = document.getElementById("bnImg"), p = document.getElementById("bnPrev"), t = document.getElementById("bnPrevTxt"), m = document.getElementById("bnMedida");
    const iL = document.getElementById("bnImgL"), pL = document.getElementById("bnPrevL"), tL = document.getElementById("bnPrevTxtL");
    iL.addEventListener("change", () => { const f = iL.files[0]; if (!f) return; pL.src = URL.createObjectURL(f); pL.hidden = false; tL.hidden = true; });
    inp.addEventListener("change", () => { const f = inp.files[0]; if (!f) return; const u = URL.createObjectURL(f); p.src = u; p.hidden = false; t.hidden = true;
        const i = new Image(); i.onload = () => { const r = i.width / i.height, ok = Math.abs(r - 16 / 9) < .08; m.hidden = false; m.className = "small mt-1 " + (ok ? "text-success" : "text-warning");
            m.innerHTML = (ok ? "<i class=\"bi bi-check-circle-fill\"></i> " : "<i class=\"bi bi-exclamation-triangle-fill\"></i> ") + i.width + " × " + i.height + " px" + (ok ? " · formato certo" : " · o ideal é 1600 × 900 (16:9); fora disso a arte pode ser cortada"); }; i.src = u; });
    const s = document.getElementById("bnL"), o = document.getElementById("bnLo");
    s.addEventListener("change", () => { o.hidden = s.value !== "outro"; if (!o.hidden) o.focus(); });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
