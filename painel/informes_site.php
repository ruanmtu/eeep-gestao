<?php
/* Informes do site: avisos, eventos, conquistas e matrículas que aparecem na página da escola */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$eid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = $eid ? q_um('SELECT * FROM site_informes WHERE id = ?', [$eid]) : null;

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $t = trim((string)($_POST['titulo'] ?? '')); $tx = trim((string)($_POST['texto'] ?? ''));
            $cat = isset(CATEGORIAS_INFORME[$_POST['categoria'] ?? '']) ? $_POST['categoria'] : 'aviso';
            $dp = data_valida($_POST['data_publicacao'] ?? '') ? $_POST['data_publicacao'] : date('Y-m-d');
            if (materia_eh_html($tx)) $tx = materia_limpar($tx);
            if (mb_strlen($t) < 3 || mb_strlen(materia_texto($tx)) < 3) throw new RuntimeException('Preencha o título e o texto da matéria.');
            if (strlen($tx) > 200000) throw new RuntimeException('A matéria ficou grande demais. Divida em duas notícias.');
            $dev = data_valida($_POST['data_evento'] ?? '') ? $_POST['data_evento'] : null;
            $foco = preg_match('/^\d{1,3}% \d{1,3}%$/', (string)($_POST['imagem_foco'] ?? '')) ? $_POST['imagem_foco'] : null;
            $dados = [mb_substr($t, 0, 160), $tx, $cat, !empty($_POST['fixado']) ? 1 : 0, !empty($_POST['publicado']) ? 1 : 0, $dp, $dev, $foco];
            if ($atual) q('UPDATE site_informes SET titulo = ?, texto = ?, categoria = ?, fixado = ?, publicado = ?, data_publicacao = ?, data_evento = ?, imagem_foco = ? WHERE id = ?', [...$dados, $eid]);
            else { q('INSERT INTO site_informes (titulo, texto, categoria, fixado, publicado, data_publicacao, data_evento, imagem_foco, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$dados, uid(), date('Y-m-d H:i:s')]); $eid = (int)db()->lastInsertId(); }
            $sub_inf = trim(preg_replace('/\s+/', ' ', (string)($_POST['subtitulo'] ?? '')));
            try { q('UPDATE site_informes SET subtitulo = ? WHERE id = ?', [$sub_inf !== '' ? mb_substr($sub_inf, 0, 255) : null, $eid]); } catch (PDOException $e) {}
            if (!empty($_FILES['imagem']['name'])) {
                [$arq] = site_img_salvar($_FILES['imagem'], 'informes', 'i' . $eid);
                if ($atual && $atual['imagem']) site_img_apagar($atual['imagem']);
                q('UPDATE site_informes SET imagem = ? WHERE id = ?', [$arq, $eid]);
            } elseif ($atual && !empty($_POST['tirar_imagem'])) { site_img_apagar($atual['imagem']); q('UPDATE site_informes SET imagem = NULL WHERE id = ?', [$eid]); }
            /* PDFs anexados (resultado assinado, trabalho acadêmico...) */
            $pdfs = $_FILES['anexos'] ?? null; $nomes = (array)($_POST['anexo_titulo'] ?? []);
            if ($pdfs && is_array($pdfs['name'])) foreach ($pdfs['name'] as $k => $nm) {
                if ($nm === '' || ($pdfs['error'][$k] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
                informe_anexo_salvar($eid, ['name' => $nm, 'tmp_name' => $pdfs['tmp_name'][$k], 'error' => $pdfs['error'][$k], 'size' => $pdfs['size'][$k]], (string)($nomes[$k] ?? ''));
            }
            foreach ((array)($_POST['anexo_apagar'] ?? []) as $aid) { $a = q_um('SELECT * FROM site_informe_anexos WHERE id = ? AND informe_id = ?', [(int)$aid, $eid]); if ($a) informe_anexo_apagar($a); }
            foreach ((array)($_POST['anexo_nome'] ?? []) as $aid => $nv) { $nv = trim((string)$nv); if ($nv !== '') q('UPDATE site_informe_anexos SET titulo = ? WHERE id = ? AND informe_id = ?', [mb_substr($nv, 0, 160), (int)$aid, $eid]); }
            registrar_log('site_informe', $t);
            flash('success', $atual ? 'Informe atualizado.' : 'Informe publicado.');
        } elseif ($acao === 'apagar' && $atual) {
            foreach (informe_anexos($eid) as $a) informe_anexo_apagar($a);
            site_img_apagar($atual['imagem']); q('DELETE FROM site_informes WHERE id = ?', [$eid]);
            flash('success', 'Informe apagado.');
        } elseif ($acao === 'publicar' && $atual) {
            q('UPDATE site_informes SET publicado = 1 - publicado WHERE id = ?', [$eid]);
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('informes_site.php' . ($eid ? '?editar=' . $eid : '')); }
    redirecionar('informes_site.php');
}

$lista = site_informes(false, 200);
$titulo = 'Notícias do site';
$subtitulo = 'Avisos, eventos, conquistas e matrículas que aparecem na página da escola.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
$f = $atual ?: ['titulo' => '', 'texto' => '', 'categoria' => 'aviso', 'fixado' => 0, 'publicado' => 1, 'data_publicacao' => date('Y-m-d'), 'data_evento' => null, 'imagem' => null, 'imagem_foco' => null, 'id' => 0];
?>
<div class="pg-acoes"><?= janela_botao('novoInforme', 'Nova notícia', 'plus-lg') ?></div>
<div class="row g-3">
    <div class="col-12">
        <?php if ($lista): ?>
        <div class="if-lista">
            <?php foreach ($lista as $i): [$crot, $cic] = CATEGORIAS_INFORME[$i['categoria']] ?? CATEGORIAS_INFORME['aviso']; ?>
            <article class="if-item<?= $i['publicado'] ? '' : ' rascunho' ?><?= (int)$i['id'] === $eid ? ' ativo' : '' ?>">
                <?php if ($i['imagem']): ?><img src="<?= e(site_img_url('i', (int)$i['id'], $i['imagem'], true, RAIZ)) ?>" alt="" loading="lazy"><?php else: ?><span class="if-ic c-<?= e($i['categoria']) ?>"><i class="bi bi-<?= $cic ?>"></i></span><?php endif; ?>
                <div class="min-w-0">
                    <div class="if-meta"><span class="if-cat c-<?= e($i['categoria']) ?>"><?= $crot ?></span><?php if ($i['fixado']): ?><span class="if-fix"><i class="bi bi-pin-angle-fill"></i>fixado</span><?php endif; ?><?php if (!$i['publicado']): ?><span class="if-fix">rascunho</span><?php endif; ?><small><?= data_curta($i['data_publicacao']) ?></small></div>
                    <strong><?= e($i['titulo']) ?></strong>
                    <p><?= e(mb_strimwidth(materia_texto((string)$i['texto']), 0, 150, '…')) ?></p>
                </div>
                <div class="if-acoes">
                    <a class="btn btn-sm btn-light" href="?editar=<?= $i['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                    <form method="post" data-confirmar="Apagar o informe &quot;<?= e($i['titulo']) ?>&quot;?" data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $i['id'] ?>"><button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button></form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="card"><div class="vazio"><i class="bi bi-megaphone"></i><strong>Nenhum informe ainda</strong>Publique o primeiro ao lado: ele aparece no início da página da escola.</div></div><?php endif; ?>
    </div>
</div>
<?= janela_abre('novoInforme', $atual ? 'Editar notícia' : 'Nova notícia', $atual ? 'pencil' : 'newspaper', (bool)$atual, 'modal-xl') ?>
        <form method="post" enctype="multipart/form-data" class="card if-form" id="formInf">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="card-header"><h2><i class="bi bi-<?= $atual ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $atual ? 'Editar informe' : 'Novo informe' ?></h2><?php if ($atual): ?><a class="ms-auto small" href="informes_site.php">novo</a><?php endif; ?></div>
            <div class="card-body">
                <label class="form-label">Tipo</label>
                <div class="if-cats mb-3"><?php foreach (CATEGORIAS_INFORME as $k => [$rot, $ic]): ?><label><input type="radio" name="categoria" value="<?= $k ?>"<?= $f['categoria'] === $k ? ' checked' : '' ?>><span><i class="bi bi-<?= $ic ?>"></i><?= $rot ?></span></label><?php endforeach; ?></div>
                <label class="form-label obrigatorio" for="ifT">Título</label>
                <input class="form-control mb-3" id="ifT" name="titulo" maxlength="160" required value="<?= e($f['titulo']) ?>" placeholder="Ex.: Matrículas abertas para 2027">
                <label class="form-label" for="ifSub">Subtítulo <span class="text-secondary fw-normal">· opcional, uma frase que resume a notícia</span></label>
                <input class="form-control mb-3" id="ifSub" name="subtitulo" maxlength="255" value="<?= e((string)($f['subtitulo'] ?? '')) ?>" placeholder="Ex.: Inscrições vão até 15 de dezembro na secretaria da escola">
                <label class="form-label obrigatorio" id="edRot">Matéria completa</label>
                <div class="ed" id="edMateria">
                    <div class="ed-barra" role="toolbar" aria-label="Formatação do texto">
                        <select class="ed-sel" data-bloco title="Tipo de parágrafo" aria-label="Tipo de parágrafo"><option value="p">Parágrafo</option><option value="h2">Intertítulo</option><option value="h3">Subtítulo</option><option value="blockquote">Citação</option></select>
                        <select class="ed-sel" data-fonte title="Fonte" aria-label="Fonte"><?php foreach (MATERIA_FONTES as $k => [$r, $css]): ?><option value="<?= e($css) ?>" style="font-family:<?= e($css) ?>"><?= e($r) ?></option><?php endforeach; ?></select>
                        <select class="ed-sel" data-tam title="Tamanho da letra" aria-label="Tamanho da letra"><option value="">Tamanho</option><option value="small">Pequena</option><option value="medium">Normal</option><option value="large">Grande</option><option value="x-large">Muito grande</option></select>
                        <span class="ed-sep"></span>
                        <button type="button" data-cmd="bold" title="Negrito (Ctrl+B)" aria-label="Negrito"><i class="bi bi-type-bold"></i></button>
                        <button type="button" data-cmd="italic" title="Itálico (Ctrl+I)" aria-label="Itálico"><i class="bi bi-type-italic"></i></button>
                        <button type="button" data-cmd="underline" title="Sublinhado (Ctrl+U)" aria-label="Sublinhado"><i class="bi bi-type-underline"></i></button>
                        <button type="button" data-cmd="strikeThrough" title="Riscado" aria-label="Riscado"><i class="bi bi-type-strikethrough"></i></button>
                        <span class="ed-sep"></span>
                        <span class="ed-cores" title="Cor da letra"><i class="bi bi-fonts"></i><?php foreach (['#0f1f14' => 'Preto', '#13692a' => 'Verde', '#e8650c' => 'Laranja', '#c0261b' => 'Vermelho', '#1d5fb0' => 'Azul'] as $c => $n): ?><button type="button" data-cor="<?= $c ?>" style="--c:<?= $c ?>" title="<?= $n ?>" aria-label="Letra <?= mb_strtolower($n) ?>"></button><?php endforeach; ?></span>
                        <span class="ed-cores" title="Marca-texto"><i class="bi bi-highlighter"></i><?php foreach (['#fff1a8' => 'Amarelo', '#cdeed3' => 'Verde', '#ffe0c2' => 'Laranja', 'transparent' => 'Sem destaque'] as $c => $n): ?><button type="button" data-fundo="<?= $c ?>" style="--c:<?= $c ?>" class="<?= $c === 'transparent' ? 'nenhum' : '' ?>" title="<?= $n ?>" aria-label="Marca-texto <?= mb_strtolower($n) ?>"></button><?php endforeach; ?></span>
                        <span class="ed-sep"></span>
                        <button type="button" data-cmd="insertUnorderedList" title="Lista" aria-label="Lista"><i class="bi bi-list-ul"></i></button>
                        <button type="button" data-cmd="insertOrderedList" title="Lista numerada" aria-label="Lista numerada"><i class="bi bi-list-ol"></i></button>
                        <button type="button" data-cmd="justifyLeft" title="Alinhar à esquerda" aria-label="Alinhar à esquerda"><i class="bi bi-text-left"></i></button>
                        <button type="button" data-cmd="justifyCenter" title="Centralizar" aria-label="Centralizar"><i class="bi bi-text-center"></i></button>
                        <button type="button" data-cmd="justifyFull" title="Justificar" aria-label="Justificar"><i class="bi bi-justify"></i></button>
                        <button type="button" data-link title="Inserir link" aria-label="Inserir link"><i class="bi bi-link-45deg"></i></button>
                        <button type="button" data-cmd="insertHorizontalRule" title="Linha divisória" aria-label="Linha divisória"><i class="bi bi-hr"></i></button>
                        <span class="ed-sep"></span>
                        <button type="button" data-cmd="removeFormat" title="Limpar formatação" aria-label="Limpar formatação"><i class="bi bi-eraser"></i></button>
                        <button type="button" data-cmd="undo" title="Desfazer (Ctrl+Z)" aria-label="Desfazer"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button type="button" data-cmd="redo" title="Refazer" aria-label="Refazer"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                    <div class="ed-area nm-texto" id="edArea" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="edRot" data-placeholder="Conte como foi: o que aconteceu, quem participou, os resultados…"><?= informe_materia((string)$f['texto']) ?></div>
                    <div class="ed-pe"><span>Selecione um trecho e use a barra para destacar. Ao colar, o texto vem sem a formatação de origem.</span><span id="edConta">0 palavras</span></div>
                </div>
                <textarea id="ifTx" name="texto" hidden><?= e($f['texto']) ?></textarea>
                <label class="form-label">Foto de capa <span class="text-secondary fw-normal">· opcional</span></label>
                <div class="if-capa" id="ifCapa"<?= $f['imagem'] ? '' : ' hidden' ?>>
                    <img id="ifPrev"<?= $f['imagem'] ? ' src="' . e(site_img_url('i', (int)$f['id'], $f['imagem'], false, RAIZ)) . '"' : '' ?> alt="" style="<?= e(informe_foco($f)) ?>" draggable="false">
                    <span class="if-alvo" id="ifAlvo"></span>
                    <small class="if-dica"><i class="bi bi-arrows-move"></i>Arraste ou toque na foto para escolher o que aparece</small>
                </div>
                <input type="hidden" name="imagem_foco" id="ifFoco" value="<?= e((string)($f['imagem_foco'] ?? '50% 40%')) ?>">
                <input class="form-control form-control-sm mt-2" type="file" id="ifImg" name="imagem" data-recorte="16:9" data-saida="1600" data-recorte-titulo="Enquadrar a foto da notícia" accept="image/*">
                <?php if ($f['imagem']): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="tirar_imagem" value="1"> Tirar a foto</label><?php endif; ?>
                <div class="row g-2 mt-2 align-items-end">
                    <div class="col-6"><label class="form-label" for="ifD">Publicar em</label><input class="form-control" type="date" id="ifD" name="data_publicacao" value="<?= e($f['data_publicacao']) ?>"></div>
                    <div class="col-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="fixado" value="1"<?= $f['fixado'] ? ' checked' : '' ?>> Fixar no topo</label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="publicado" value="1"<?= $f['publicado'] ? ' checked' : '' ?>> Publicado</label></div>
                </div>
                <label class="form-label mt-3"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>PDFs anexados <span class="text-secondary fw-normal">· opcional</span></label>
                <div class="if-anexos" id="ifAnexos">
                    <?php foreach ($atual ? informe_anexos((int)$atual['id']) : [] as $a): ?>
                    <div class="if-anexo"><i class="bi bi-file-earmark-pdf-fill"></i><input class="form-control form-control-sm" name="anexo_nome[<?= (int)$a['id'] ?>]" value="<?= e($a['titulo']) ?>" maxlength="160" aria-label="Nome do PDF"><a class="btn btn-sm btn-light" href="<?= RAIZ ?>anexo_site.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" aria-label="Abrir"><i class="bi bi-box-arrow-up-right"></i></a><label class="btn btn-sm btn-light" title="Remover ao salvar"><input type="checkbox" name="anexo_apagar[]" value="<?= (int)$a['id'] ?>" hidden onchange="this.closest('.if-anexo').classList.toggle('remover', this.checked)"><i class="bi bi-trash"></i></label></div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-light mt-1" id="ifAddPdf"><i class="bi bi-paperclip me-1"></i>Anexar PDF</button>
                <div class="form-text">Ex.: resultado preliminar assinado, edital, trabalho acadêmico. Quem lê a notícia pode abrir ou baixar. Até 30 MB cada.</div>
                <label class="form-label mt-3" for="ifDe"><i class="bi bi-calendar-event me-1"></i>Dia do evento <span class="text-secondary fw-normal">· opcional</span></label>
                <input class="form-control" type="date" id="ifDe" name="data_evento" value="<?= e((string)($f['data_evento'] ?? '')) ?>">
                <div class="form-text">Com data, o informe aparece no calendário do site. Evento sem data aparece como "data a confirmar".</div>
                <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-send me-1"></i><?= $atual ? 'Salvar alterações' : 'Publicar no site' ?></button>
            </div>
        </form>
<?= janela_fecha() ?>
<?php
$script_pagina = '<script src="' . RAIZ . 'assets/js/editor.js?v=1" defer></script><script>
(function () {
    const caixa = document.getElementById("ifCapa"), img = document.getElementById("ifPrev"), foco = document.getElementById("ifFoco"), alvo = document.getElementById("ifAlvo");
    const aplicar = (x, y) => { x = Math.round(Math.min(100, Math.max(0, x))); y = Math.round(Math.min(100, Math.max(0, y))); foco.value = x + "% " + y + "%"; img.style.objectPosition = foco.value; alvo.style.left = x + "%"; alvo.style.top = y + "%"; };
    const [x0, y0] = (foco.value || "50% 40%").split(" ").map(parseFloat); aplicar(x0, y0);
    document.getElementById("ifImg").addEventListener("change", e => { const f = e.target.files[0]; if (!f) return; img.src = URL.createObjectURL(f); caixa.hidden = false; aplicar(50, 40); });
    document.getElementById("ifAddPdf").addEventListener("click", () => {
        const d = document.createElement("div"); d.className = "if-anexo novo";
        d.innerHTML = \'<i class="bi bi-file-earmark-pdf-fill"></i><input class="form-control form-control-sm" name="anexo_titulo[]" maxlength="160" placeholder="Nome (ex.: Resultado preliminar)"><input type="file" name="anexos[]" accept="application/pdf,.pdf" hidden><button type="button" class="btn btn-sm btn-light" aria-label="Tirar"><i class="bi bi-x-lg"></i></button><small class="if-anexo-arq"></small>\';
        const arq = d.querySelector("input[type=file]"), nome = d.querySelector("input[name^=anexo_titulo]");
        d.querySelector("button").addEventListener("click", () => d.remove());
        arq.addEventListener("change", () => { const f = arq.files[0]; if (!f) { d.remove(); return; } d.querySelector(".if-anexo-arq").textContent = f.name + " · " + (f.size / 1048576).toFixed(1) + " MB"; if (!nome.value) nome.value = f.name.replace(/\.pdf$/i, ""); });
        document.getElementById("ifAnexos").appendChild(d); arq.click();
    });
    let arrastando = false;
    const mover = ev => { const r = caixa.getBoundingClientRect(), p = ev.touches ? ev.touches[0] : ev; aplicar((p.clientX - r.left) / r.width * 100, (p.clientY - r.top) / r.height * 100); };
    caixa.addEventListener("pointerdown", ev => { arrastando = true; caixa.setPointerCapture(ev.pointerId); mover(ev); });
    caixa.addEventListener("pointermove", ev => { if (arrastando) mover(ev); });
    caixa.addEventListener("pointerup", () => { arrastando = false; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
