<?php
require __DIR__ . '/../includes/app.php';
exigir('livros_didaticos');
$gerencia = pode('livros_didaticos.gerenciar');

if (eh_post() && $gerencia) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $atual = $id ? livro_didatico_buscar($id) : null;
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $tipo = isset(TIPOS_LIVRO[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'professor';
        $disc = (int)($_POST['disciplina_id'] ?? 0) ?: null;
        $area = in_array($_POST['area'] ?? '', AREAS_LIVRO, true) ? $_POST['area'] : null;
        $serie = in_array((int)($_POST['serie'] ?? 0), [1, 2, 3], true) ? (int)$_POST['serie'] : null;
        $txt = fn($k, $n) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' ? mb_substr($v, 0, $n) : null;
        $token = (string)($_POST['token'] ?? '');
        try {
            if (mb_strlen($titulo) < 3) throw new RuntimeException('Dê um título ao livro.');
            if (!$disc && !$area) throw new RuntimeException('Escolha a disciplina ou a área do livro.');
            if ($disc && !q_valor('SELECT id FROM disciplinas WHERE id = ?', [$disc])) throw new RuntimeException('Disciplina não encontrada.');
            if (!$atual && $token === '') throw new RuntimeException('Envie o arquivo PDF do livro.');
            $arq = $token !== '' ? livro_finalizar_envio($token) : null;
            $dados = [$titulo, $tipo, $disc, $disc ? null : $area, $serie, $txt('colecao', 160), $txt('autores', 255), $txt('edicao', 40), $txt('descricao', 4000)];
            if ($atual) {
                q('UPDATE livros_didaticos SET titulo = ?, tipo = ?, disciplina_id = ?, area = ?, serie = ?, colecao = ?, autores = ?, edicao = ?, descricao = ? WHERE id = ?', array_merge($dados, [$id]));
                if ($arq) {
                    $antigo = PASTA_DADOS . '/' . $atual['caminho'];
                    q('UPDATE livros_didaticos SET caminho = ?, tamanho = ?, nome_original = ? WHERE id = ?', [$arq[0], $arq[1], $arq[2], $id]);
                    if (preg_match('#^livros/\d{4}/[\w.-]+\.pdf$#', $atual['caminho']) && is_file($antigo)) @unlink($antigo);
                }
                registrar_log('livro_didatico', 'Livro atualizado: ' . $titulo);
                flash('success', 'Livro atualizado.');
            } else {
                q('INSERT INTO livros_didaticos (titulo, tipo, disciplina_id, area, serie, colecao, autores, edicao, descricao, caminho, tamanho, nome_original, enviado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                  array_merge($dados, [$arq[0], $arq[1], $arq[2], uid(), date('Y-m-d H:i:s')]));
                $novo = (int)db()->lastInsertId();
                registrar_log('livro_didatico', 'Livro enviado: ' . $titulo);
                if (!empty($_POST['avisar'])) {
                    $nd = $disc ? q_valor('SELECT nome FROM disciplinas WHERE id = ?', [$disc]) : $area;
                    notificar(['perfis' => ['professor', 'coordenacao', 'diretor']], 'Livro didático disponível: ' . $titulo,
                        TIPOS_LIVRO[$tipo][0] . ' de ' . $nd . ($serie ? ', ' . $serie . 'º ano' : '') . '. Leia no sistema ou baixe em Livros didáticos.', 'aviso', 'livro_didatico.php?id=' . $novo);
                }
                flash('success', 'Livro enviado. Os professores já podem ler e baixar.');
            }
        } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
        livros_limpar_tmp();
        redirecionar('livros_didaticos.php?ver=todos');
    }
    if ($acao === 'excluir') {
        $l = livro_didatico_buscar((int)($_POST['id'] ?? 0));
        if ($l) {
            q('DELETE FROM livros_didaticos WHERE id = ?', [$l['id']]);
            if (preg_match('#^livros/\d{4}/[\w.-]+\.pdf$#', $l['caminho'])) @unlink(PASTA_DADOS . '/' . $l['caminho']);
            registrar_log('livro_didatico', 'Livro apagado: ' . $l['titulo']);
            flash('success', 'Livro apagado.');
        }
        redirecionar('livros_didaticos.php?ver=todos');
    }
}

/* "Meus livros": disciplinas e séries que o professor leciona */
$minhas = livros_do_professor((int)uid());
$todos = livros_lista();
$meus = array_values(array_filter($todos, function ($l) use ($minhas) {
    foreach ($minhas as $m) if ((int)$m['disciplina_id'] === (int)$l['disciplina_id'] && (!$l['serie'] || (int)$l['serie'] === (int)$m['serie'])) return true;
    return false;
}));
$ver = ($_GET['ver'] ?? '') === 'todos' || !$meus ? 'todos' : 'meus';
$lista = $ver === 'meus' ? $meus : $todos;
$disciplinas = q_todos('SELECT d.id, d.nome, c.sigla FROM disciplinas d LEFT JOIN cursos c ON c.id = d.curso_id WHERE d.ativo = 1 ORDER BY d.curso_id IS NOT NULL, d.nome');
$total_mb = array_sum(array_column($todos, 'tamanho'));

$titulo = 'Livros didáticos';
$subtitulo = 'Manuais do professor e livros em PDF: leia no sistema, no computador ou no celular, ou baixe para usar sem internet.';
$menu = 'livros_didaticos';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="ld-barra mb-4">
    <div class="segmentado">
        <?php if ($meus): ?><a class="<?= $ver === 'meus' ? 'ativo' : '' ?>" href="?ver=meus"><i class="bi bi-person-workspace"></i>Meus livros <span><?= count($meus) ?></span></a><?php endif; ?>
        <a class="<?= $ver === 'todos' ? 'ativo' : '' ?>" href="?ver=todos"><i class="bi bi-collection"></i>Todos <span><?= count($todos) ?></span></a>
    </div>
    <div class="segmentado" id="filtroSerie">
        <a href="#" class="ativo" data-serie="">Todas as séries</a><a href="#" data-serie="1">1º ano</a><a href="#" data-serie="2">2º ano</a><a href="#" data-serie="3">3º ano</a>
    </div>
    <div class="busca-rapida ms-auto"><i class="bi bi-search"></i><input class="form-control form-control-sm" id="filtroTexto" placeholder="Procurar livro, disciplina, coleção…" aria-label="Procurar livro"></div>
    <?php if ($gerencia): ?><button class="btn btn-laranja" type="button" data-bs-toggle="modal" data-bs-target="#modalLivro" data-novo="1"><i class="bi bi-cloud-arrow-up me-1"></i>Enviar livro</button><?php endif; ?>
</div>

<?php if ($lista): ?>
<div class="ld-grade" id="gradeLivros">
    <?php foreach ($lista as $l): [$tr, $ti] = TIPOS_LIVRO[$l['tipo']] ?? TIPOS_LIVRO['professor']; $nd = $l['disciplina'] ?? $l['area'] ?? ''; ?>
    <article class="ld-livro" data-serie="<?= (int)$l['serie'] ?>" data-busca="<?= e(mb_strtolower($l['titulo'] . ' ' . $nd . ' ' . $l['colecao'] . ' ' . $l['autores'] . ' ' . $l['edicao'])) ?>">
        <a class="ld-capa c<?= livro_cor($l) ?>" href="livro_didatico.php?id=<?= $l['id'] ?>">
            <span class="ld-tipo"><i class="bi bi-<?= $ti ?>"></i><?= e($tr) ?></span>
            <span class="ld-serie"><?= $l['serie'] ? $l['serie'] . 'º ano' : 'Volume único' ?></span>
            <strong><?= e($nd) ?></strong>
            <span class="ld-colecao"><?= e($l['colecao'] ?: $l['titulo']) ?></span>
            <i class="bi bi-book-half ld-icone"></i>
        </a>
        <div class="ld-info">
            <h3><?= e($l['titulo']) ?></h3>
            <small><?= e(trim(($l['edicao'] ? $l['edicao'] . ' · ' : '') . tamanho_fmt((int)$l['tamanho']))) ?></small>
            <div class="ld-acoes">
                <a class="btn btn-sm btn-primary flex-grow-1" href="livro_didatico.php?id=<?= $l['id'] ?>"><i class="bi bi-book me-1"></i>Ler</a>
                <a class="btn btn-sm btn-light" href="livro_arquivo.php?id=<?= $l['id'] ?>&baixar=1" title="Baixar PDF" aria-label="Baixar PDF"><i class="bi bi-download"></i></a>
                <?php if ($gerencia): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Mais ações"><i class="bi bi-three-dots"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#modalLivro" data-livro="<?= e(json_encode(['id' => (int)$l['id'], 'titulo' => $l['titulo'], 'tipo' => $l['tipo'], 'disciplina_id' => $l['disciplina_id'], 'area' => $l['area'], 'serie' => $l['serie'], 'colecao' => $l['colecao'], 'autores' => $l['autores'], 'edicao' => $l['edicao'], 'descricao' => $l['descricao'], 'arquivo' => $l['nome_original']], JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-pencil me-2"></i>Editar ou trocar o PDF</button></li>
                        <li><span class="dropdown-item-text small text-secondary"><i class="bi bi-eye me-2"></i><?= (int)$l['acessos'] ?> acesso(s)</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" data-confirmar="Apagar o livro &quot;<?= e($l['titulo']) ?>&quot;? O PDF sai do sistema." data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Apagar</button></form></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<div class="vazio py-5" id="semResultado" hidden><i class="bi bi-search"></i>Nenhum livro com esse filtro.</div>
<?php else: ?>
<div class="card"><div class="vazio py-5"><i class="bi bi-journal-bookmark"></i><strong>Nenhum livro didático ainda</strong>
    <?= $gerencia ? 'Toque em "Enviar livro" para subir o primeiro PDF (manual do professor).' : 'Quando a gestão enviar os livros, eles aparecem aqui.' ?></div></div>
<?php endif; ?>
<?php if ($gerencia && $todos): ?><p class="small text-secondary mt-3"><i class="bi bi-hdd me-1"></i><?= count($todos) ?> livro(s) · <?= tamanho_fmt($total_mb) ?> ocupados no servidor</p><?php endif; ?>

<?php if ($gerencia): ?>
<div class="modal fade" id="modalLivro" tabindex="-1" aria-labelledby="tLivro" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><form class="modal-content" method="post" id="formLivro">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" id="lId"><input type="hidden" name="token" id="lToken">
    <div class="modal-header"><h5 class="modal-title" id="tLivro">Enviar livro didático</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <label class="ld-envio" id="zonaEnvio" for="lArquivo">
            <input type="file" id="lArquivo" accept="application/pdf,.pdf" hidden>
            <i class="bi bi-file-earmark-pdf"></i>
            <strong id="envioTitulo">Escolha ou arraste o PDF do livro</strong>
            <span id="envioSub">Até 1 GB. O envio é feito em partes e pode continuar mesmo com internet lenta.</span>
            <div class="progress mt-2" id="envioBarra" hidden><div class="progress-bar bg-success" style="width:0%"></div></div>
        </label>
        <div class="row g-3 mt-1">
            <div class="col-12"><label class="form-label obrigatorio" for="lTitulo">Título</label><input class="form-control" id="lTitulo" name="titulo" maxlength="160" required placeholder="Ex.: Matemática em Contextos – Manual do Professor"></div>
            <div class="col-sm-6"><label class="form-label" for="lTipo">Tipo</label><select class="form-select" id="lTipo" name="tipo"><?php foreach (TIPOS_LIVRO as $k => [$r]): ?><option value="<?= $k ?>"><?= e($r) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6"><label class="form-label" for="lSerie">Série</label><select class="form-select" id="lSerie" name="serie"><option value="">Volume único / todas</option><option value="1">1º ano</option><option value="2">2º ano</option><option value="3">3º ano</option></select></div>
            <div class="col-sm-6"><label class="form-label" for="lDisc">Disciplina</label><select class="form-select" id="lDisc" name="disciplina_id"><option value="">— livro de área (escolha ao lado) —</option>
                <?php foreach ($disciplinas as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['nome']) ?><?= $d['sigla'] ? ' (' . e($d['sigla']) . ')' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6"><label class="form-label" for="lArea">Ou área do conhecimento</label><select class="form-select" id="lArea" name="area"><option value=""></option><?php foreach (AREAS_LIVRO as $a): ?><option><?= e($a) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-7"><label class="form-label" for="lColecao">Coleção / editora</label><input class="form-control" id="lColecao" name="colecao" maxlength="160"></div>
            <div class="col-sm-5"><label class="form-label" for="lEdicao">Edição</label><input class="form-control" id="lEdicao" name="edicao" maxlength="40" placeholder="Ex.: PNLD 2026"></div>
            <div class="col-12"><label class="form-label" for="lAutores">Autores</label><input class="form-control" id="lAutores" name="autores" maxlength="255"></div>
            <div class="col-12"><label class="form-label" for="lDesc">Observações</label><textarea class="form-control" id="lDesc" name="descricao" rows="2" maxlength="4000"></textarea></div>
            <div class="col-12" id="lAvisarBox"><div class="form-check"><input class="form-check-input" type="checkbox" name="avisar" value="1" id="lAvisar" checked><label class="form-check-label" for="lAvisar">Avisar os professores no sino de notificações</label></div></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary ms-auto" type="submit" id="lSalvar"><i class="bi bi-check2 me-1"></i>Salvar livro</button></div>
</form></div></div>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  /* filtros da estante */
  let serie = "";
  const texto = document.getElementById("filtroTexto"), livros = [...document.querySelectorAll(".ld-livro")];
  const sem = t => t.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  function filtrar() {
    const q = sem(texto.value.trim()); let n = 0;
    livros.forEach(l => { const ok = (!serie || l.dataset.serie === serie || l.dataset.serie === "0") && (!q || sem(l.dataset.busca).includes(q)); l.hidden = !ok; if (ok) n++; });
    const s = document.getElementById("semResultado"); if (s) s.hidden = n > 0;
  }
  texto.addEventListener("input", filtrar);
  document.querySelectorAll("#filtroSerie a").forEach(a => a.addEventListener("click", e => { e.preventDefault(); serie = a.dataset.serie;
    document.querySelectorAll("#filtroSerie a").forEach(b => b.classList.toggle("ativo", b === a)); filtrar(); }));

  /* envio do PDF em partes */
  const form = document.getElementById("formLivro"); if (!form) return;
  const CSRF = ' . json_encode(csrf_token()) . ', PARTE = ' . livro_parte_max() . ';
  const arq = document.getElementById("lArquivo"), zona = document.getElementById("zonaEnvio"), barra = document.getElementById("envioBarra"), bar = barra.firstElementChild;
  const tit = document.getElementById("envioTitulo"), sub = document.getElementById("envioSub"), salvar = document.getElementById("lSalvar");
  let enviando = false;
  const fmt = b => b > 1048576 ? (b / 1048576).toFixed(1).replace(".", ",") + " MB" : Math.round(b / 1024) + " KB";
  async function enviar(f) {
    if (!f) return;
    if (!/\\.pdf$/i.test(f.name)) { sub.textContent = "Escolha um arquivo PDF."; return; }
    const token = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, "0")).join("");
    const total = Math.max(1, Math.ceil(f.size / PARTE));
    enviando = true; salvar.disabled = true; barra.hidden = false; zona.classList.add("enviando"); document.getElementById("lToken").value = "";
    tit.textContent = f.name;
    if (!document.getElementById("lTitulo").value) document.getElementById("lTitulo").value = f.name.replace(/\\.pdf$/i, "").replace(/[_-]+/g, " ");
    for (let i = 0; i < total; i++) {
      const fd = new FormData();
      fd.append("acao", "livro_parte"); fd.append("csrf", CSRF); fd.append("token", token); fd.append("parte", i); fd.append("total", total);
      fd.append("nome", f.name); fd.append("tamanho", f.size); fd.append("pedaco", f.slice(i * PARTE, (i + 1) * PARTE), "parte.bin");
      let j = null;
      for (let tentativa = 0; tentativa < 4 && !j; tentativa++) {
        try { const r = await fetch("api.php", { method: "POST", body: fd }); j = await r.json(); }
        catch (e) { await new Promise(r => setTimeout(r, 1500 * (tentativa + 1))); }
      }
      if (!j || !j.ok) { sub.textContent = (j && j.msg) || "A conexão caiu. Escolha o arquivo de novo."; zona.classList.remove("enviando"); enviando = false; salvar.disabled = false; return; }
      const p = Math.round((i + 1) / total * 100); bar.style.width = p + "%";
      sub.textContent = `Enviando… ${p}% (${fmt(Math.min(f.size, (i + 1) * PARTE))} de ${fmt(f.size)})`;
    }
    document.getElementById("lToken").value = token;
    sub.textContent = "Arquivo recebido (" + fmt(f.size) + "). Preencha os dados e salve.";
    zona.classList.remove("enviando"); zona.classList.add("pronto"); enviando = false; salvar.disabled = false;
  }
  arq.addEventListener("change", () => enviar(arq.files[0]));
  ["dragover", "dragenter"].forEach(t => zona.addEventListener(t, e => { e.preventDefault(); zona.classList.add("arrastando"); }));
  ["dragleave", "drop"].forEach(t => zona.addEventListener(t, e => { e.preventDefault(); zona.classList.remove("arrastando"); }));
  zona.addEventListener("drop", e => { if (!enviando && e.dataTransfer.files[0]) enviar(e.dataTransfer.files[0]); });
  form.addEventListener("submit", e => {
    if (enviando) { e.preventDefault(); return; }
    if (!document.getElementById("lId").value && !document.getElementById("lToken").value) { e.preventDefault(); sub.textContent = "Envie o PDF do livro primeiro."; zona.classList.add("erro"); }
    if (!document.getElementById("lDisc").value && !document.getElementById("lArea").value) { e.preventDefault(); document.getElementById("lDisc").focus(); }
  });
  /* abrir para novo ou editar */
  document.getElementById("modalLivro").addEventListener("show.bs.modal", e => {
    const d = e.relatedTarget && e.relatedTarget.dataset.livro ? JSON.parse(e.relatedTarget.dataset.livro) : null;
    form.reset(); document.getElementById("lToken").value = ""; bar.style.width = "0%"; barra.hidden = true; zona.classList.remove("pronto", "erro");
    document.getElementById("lId").value = d ? d.id : "";
    document.getElementById("tLivro").textContent = d ? "Editar livro" : "Enviar livro didático";
    document.getElementById("lAvisarBox").hidden = !!d;
    tit.textContent = d ? "PDF atual: " + d.arquivo : "Escolha ou arraste o PDF do livro";
    sub.textContent = d ? "Para trocar o arquivo, escolha outro PDF. Se não escolher, o atual continua." : "Até 1 GB. O envio é feito em partes e pode continuar mesmo com internet lenta.";
    if (d) { ["titulo", "tipo", "colecao", "autores", "edicao", "descricao"].forEach(k => form.elements[k].value = d[k] || "");
      form.elements.disciplina_id.value = d.disciplina_id || ""; form.elements.area.value = d.area || ""; form.elements.serie.value = d.serie || ""; }
  });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
