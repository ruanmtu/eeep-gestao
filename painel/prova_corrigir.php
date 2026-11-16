<?php
require __DIR__ . '/../includes/app.php';
exigir('provas');

$prova = prova_buscar((int)($_GET['id'] ?? 0));
if (!$prova || !pode_prova($prova)) { flash('warning', 'Prova não encontrada.'); redirecionar('provas.php'); }
$n = (int)$prova['num_questoes'];
$nalt = (int)$prova['num_alternativas'];
$gab = str_pad((string)$prova['gabarito'], $n, '-');
if (str_contains($gab, '-')) { flash('warning', 'Preencha o gabarito antes de corrigir.'); redirecionar('prova.php?id=' . $prova['id'] . '#gabarito'); }

$L = omr_layout_prova($prova);
$partes_js = array_map(fn($p) => ['nome' => $p['nome'], 'inicio' => $p['inicio'], 'fim' => $p['fim'], 'valor' => $p['valor']], $prova['bloco'] ? $prova['partes'] : []);
$formato = json_decode((string)($prova['formato_externo'] ?? ''), true) ?: null;
$alunos = q_todos("SELECT a.id, a.nome, a.foto, t.letra, c.nome AS curso,
                          (SELECT r.nota FROM prova_resultados r WHERE r.prova_id = ? AND r.aluno_id = a.id) AS nota
                     FROM alunos a JOIN turmas t ON t.id = a.turma_id JOIN cursos c ON c.id = t.curso_id
                    WHERE a.situacao = 'ativo' AND t.serie = ? AND t.ano_letivo = ?" . ($prova['curso_id'] ? ' AND t.curso_id = ' . (int)$prova['curso_id'] : '') . '
                    ORDER BY t.letra, a.nome', [$prova['id'], $prova['serie'], $prova['ano_letivo']]);
$mapa = [];
foreach ($alunos as $a) $mapa[$a['id']] = ['nome' => nome_proprio($a['nome']), 'turma' => $prova['serie'] . 'º ' . $a['letra'] . ' · ' . $a['curso'], 'nota' => $a['nota'] !== null ? (float)$a['nota'] : null, 'foto' => foto_url('aluno', (int)$a['id'], $a['foto'])];
$corrigidas = count(array_filter($alunos, fn($a) => $a['nota'] !== null));

$titulo = 'Corrigir: ' . $prova['titulo'];
$subtitulo = $prova['disciplina'] . ' · ' . $prova['serie'] . 'º ano · ' . $n . ' questões · vale ' . num($prova['valor']);
$menu = 'provas';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <a class="btn btn-light" href="prova.php?id=<?= $prova['id'] ?>#resultados"><i class="bi bi-arrow-left me-1"></i>Resultados</a>
    <span class="scan-so" title="A câmera só corrige o gabarito gerado pelo sistema para esta prova"><i class="bi bi-shield-check"></i>Só o gabarito gerado pelo sistema</span>
    <a class="btn btn-light" href="prova_folhas.php?id=<?= $prova['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Imprimir gabaritos</a>
    <span class="etiqueta escura ms-auto" id="progresso"><?= $corrigidas ?> de <?= count($alunos) ?> corrigidas</span>
</div>

<div class="scan-area">
    <section class="scan-palco" id="palco" aria-label="Câmera">
        <video id="video" playsinline muted autoplay></video>
        <canvas id="congelado" hidden></canvas>
        <div class="scan-guia" id="guia" hidden><span></span><span></span><span></span><span></span></div>
        <div class="scan-status" id="status" hidden>Aponte a câmera para a folha</div>

        <div class="scan-inicio" id="inicio">
            <span class="si-ic"><i class="bi bi-camera"></i></span>
            <strong>Correção pela câmera</strong>
            <p>Use o <b>gabarito gerado pelo sistema</b> para esta prova. Coloque a folha numa mesa bem iluminada e encaixe os <b>4 quadrados pretos</b> da grade de respostas nos 4 quadrados da tela, com o <b>QR code</b> no tracejado laranja. A leitura é automática.</p>
            <button class="btn btn-primary btn-lg" type="button" id="abrirCamera"><i class="bi bi-camera-video me-2"></i>Abrir câmera</button>
            <label class="btn btn-link text-white-50 mt-1" for="arquivoFoto"><i class="bi bi-image me-1"></i>Ou envie uma foto</label>
        </div>

        <div class="scan-resultado" id="resultado" hidden>
            <div class="sr-cab"><span id="srRotulo"></span><button type="button" class="sr-fechar" id="srFechar" aria-label="Fechar e voltar para a câmera"><i class="bi bi-x-lg"></i></button></div>
            <div class="sr-topo">
                <span class="sr-avatar" id="srAvatar"></span>
                <div class="min-w-0 flex-grow-1"><strong id="srNome"></strong><small id="srTurma"></small></div>
                <div class="sr-nota"><strong id="srNota"></strong><small id="srAcertos"></small></div>
            </div>
            <div class="sr-msg" id="srMsg"></div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
                <button class="btn btn-light" type="button" id="srProxima"><i class="bi bi-arrow-left me-1"></i>Voltar</button>
                <button class="btn btn-light" type="button" id="srRevisar"><i class="bi bi-pencil-square me-1"></i>Revisar</button>
                <button class="btn btn-primary flex-grow-1" type="button" id="srSalvar"><i class="bi bi-check2-circle me-1"></i>Salvar nota</button>
            </div>
        </div>

        <div class="scan-ferramentas" id="ferramentas" hidden>
            <button type="button" class="scan-bt" id="btLanterna" hidden aria-label="Lanterna"><i class="bi bi-lightning-charge"></i></button>
            <label class="scan-bt" for="arquivoFoto" aria-label="Enviar foto"><i class="bi bi-image"></i></label>
            <button type="button" class="scan-bt" id="btParar" aria-label="Fechar câmera"><i class="bi bi-x-lg"></i></button>
        </div>
        <input type="file" id="arquivoFoto" accept="image/*" hidden>
    </section>

    <aside>
        <div class="card mb-3 d-none" id="cartaoFormato" hidden>
            <div class="card-body">
                <div class="d-flex gap-2 align-items-start">
                    <i class="bi bi-file-earmark-text fs-4 text-secondary"></i>
                    <div class="flex-grow-1"><strong class="d-block">Folha de outro modelo</strong>
                        <small class="text-secondary" id="formatoTexto"></small></div>
                </div>
                <button class="btn btn-sm btn-light mt-2 w-100" type="button" id="btAjustar"><i class="bi bi-bounding-box me-1"></i>Ajustar posição das bolinhas</button>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h2>Nesta correção</h2><span class="etiqueta" id="contSessao">0</span></div>
            <ul class="scan-sessao" id="sessao"><li class="vazio py-4" id="sessaoVazia"><i class="bi bi-inbox"></i>As folhas lidas aparecem aqui.</li></ul>
        </div>
        <div class="card"><div class="card-body small">
            <strong class="d-block mb-1">Dicas para uma boa leitura</strong>
            <ul class="text-secondary mb-0 ps-3">
                <li>Folha lisa, sobre uma mesa, sem sombra do celular.</li>
                <li>Celular reto, por cima da folha. Na folha da escola, enquadre só a grade de respostas (parte de baixo), com os quadrados das pontas nas marcas.</li>
                <li>Na folha da escola, o <b>QR code</b> identifica o aluno sozinho. Sem QR, você escolhe o aluno.</li>
                <li>Quando a folha é lida, a tela <b>trava</b> com o aluno e a nota. Confira, toque em <b>Salvar nota</b> e depois em <b>Voltar</b> para ler a próxima.</li>
            </ul>
        </div></div>
    </aside>
</div>

<!-- Revisar / escolher aluno -->
<div class="modal fade" id="modalRevisar" tabindex="-1" aria-labelledby="tituloRevisar" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="tituloRevisar">Conferir leitura</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <div class="row g-4">
            <div class="col-lg-5 order-2 order-lg-1"><div class="revisar-foto"><canvas id="telaRevisar"></canvas></div></div>
            <div class="col-lg-7 order-1 order-lg-2">
                <label class="form-label obrigatorio" for="aluno">Aluno desta folha</label>
                <select class="form-select mb-1" id="aluno">
                    <option value="">Escolha o aluno</option>
                    <?php $l = null; foreach ($alunos as $a): if ($a['letra'] !== $l) { if ($l !== null) echo '</optgroup>'; $l = $a['letra']; echo '<optgroup label="' . $prova['serie'] . 'º ' . e($l) . ' · ' . e($a['curso']) . '">'; } ?>
                        <option value="<?= $a['id'] ?>"><?= e(nome_proprio($a['nome'])) ?></option>
                    <?php endforeach; if ($l !== null) echo '</optgroup>'; ?>
                </select>
                <div class="form-text mb-3" id="avisoAluno"></div>
                <div class="d-flex align-items-end justify-content-between mb-3 flex-wrap gap-2">
                    <div class="omr-nota"><strong id="nota">0</strong><span class="text-secondary">de <?= num($prova['valor']) ?></span></div>
                    <div class="text-end"><strong id="acertos">0</strong> acertos de <?= $n ?><br><small class="text-secondary" id="duvidas"></small></div>
                </div>
                <div class="sr-partes mb-3" id="revPartes"></div>
                <div class="omr-respostas" id="respostas"></div>
                <p class="form-text mt-2 mb-0">Verde: certa · vermelho: errada · amarelo: em branco ou duas marcadas. Toque numa questão para corrigir a leitura.</p>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-light" type="button" id="descartar">Descartar folha</button>
        <button class="btn btn-primary btn-lg ms-auto" type="button" id="salvar"><i class="bi bi-check2-circle me-1"></i>Salvar nota</button>
    </div>
</div></div></div>

<!-- Formato da folha externa -->
<div class="modal fade" id="modalFormato" tabindex="-1" aria-labelledby="tituloFormato" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="tituloFormato">Formato da folha de respostas</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <div class="row g-4">
            <div class="col-lg-4">
                <p class="small text-secondary">Faça isso uma vez para esta prova. Informe como as questões estão organizadas e arraste os pontos para o centro das bolinhas indicadas.</p>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label small" for="fColunas">Colunas de questões</label><input class="form-control" type="number" id="fColunas" min="1" max="6" value="1"></div>
                    <div class="col-6"><label class="form-label small" for="fPorColuna">Questões por coluna</label><input class="form-control" type="number" id="fPorColuna" min="1" max="60" value="<?= $n ?>"></div>
                </div>
                <ol class="fmt-legenda">
                    <li><span class="fmt-p p1">1</span>Questão 1, alternativa A</li>
                    <li><span class="fmt-p p2">2</span>Questão 1, última alternativa (<?= letras_alternativas($nalt)[$nalt - 1] ?>)</li>
                    <li><span class="fmt-p p3">3</span>Última questão da 1ª coluna, última alternativa</li>
                    <li id="legP4"><span class="fmt-p p4">4</span>Primeira questão da última coluna, alternativa A</li>
                </ol>
                <p class="small text-secondary mb-0">Os círculos verdes mostram onde o sistema vai ler. Eles devem cair em cima de todas as bolinhas da folha.</p>
            </div>
            <div class="col-lg-8"><div class="fmt-area" id="fmtArea"><canvas id="fmtImagem"></canvas><canvas id="fmtGrade"></canvas>
                <span class="fmt-p p1" data-p="p1">1</span><span class="fmt-p p2" data-p="p2">2</span><span class="fmt-p p3" data-p="p3">3</span><span class="fmt-p p4" data-p="p4">4</span></div></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary ms-auto" type="button" id="salvarFormato"><i class="bi bi-check2 me-1"></i>Salvar formato e corrigir</button></div>
</div></div></div>

<?php
$script_pagina = '<script src="' . RAIZ . 'assets/vendor/jsqr/jsQR.js"></script>
<script src="' . RAIZ . 'assets/js/omr.js?v=15"></script>
<script>
(function () {
  "use strict";
  const L = ' . json_encode($L) . ', GAB = ' . json_encode($gab) . ', N = ' . $n . ', NALT = ' . $nalt . ', VALOR = ' . (float)$prova['valor'] . ';
  const PARTES = ' . json_encode($partes_js, JSON_UNESCAPED_UNICODE) . ';
  const ALUNOS = ' . json_encode((object)$mapa, JSON_UNESCAPED_UNICODE) . ', PROVA = ' . (int)$prova['id'] . ', CSRF = ' . json_encode(csrf_token()) . ';
  const LETRAS = OMR.LETRAS.slice(0, NALT), ASP = (L.marcadores[3][1] - L.marcadores[0][1]) / (L.marcadores[1][0] - L.marcadores[0][0]);
  const $ = id => document.getElementById(id);
  const video = $("video"), palco = $("palco");
  const st = { modo: "escola", estado: "parado", atual: null, tentQR: 0, melhor: null, stream: null, track: null, estaveis: 0, ultimos: null, ausente: 0,
               leitura: null, resp: [], formato: ' . json_encode($formato) . ', sessao: [], total: ' . $corrigidas . ', pendente: null, recalibrar: false, ultimaAss: null };
  st.modo = "escola";   /* v63: só o gabarito gerado pelo sistema (folha da escola com QR code) */
  const TOTAL_ALUNOS = ' . count($alunos) . ';
  const nota = ac => Math.round(ac / N * VALOR * 100) / 100;
  const br = v => Number(v).toLocaleString("pt-BR");
  const acertosDe = (r, a = 0, b = N) => r.slice(a, b).reduce((s, x, i) => s + (GAB[a + i] === "*" || x === GAB[a + i] ? 1 : 0), 0);
  const partesHtml = r => PARTES.map(p => { const ac = acertosDe(r, p.inicio, p.fim), t = p.fim - p.inicio;
    return `<span class="sr-parte"><b>${esc(p.nome)}</b> ${ac}/${t} · ${br(Math.round(ac / t * p.valor * 100) / 100)}</span>`; }).join("");

  /* ---------------- Tipo de folha ---------------- */
  function aplicarModo() {
    document.querySelectorAll("[data-modo]").forEach(a => { a.classList.toggle("ativo", a.dataset.modo === st.modo); a.setAttribute("aria-checked", a.dataset.modo === st.modo); });
    $("cartaoFormato").hidden = true;
    $("formatoTexto").textContent = st.formato ? `${st.formato.colunas} coluna(s), ${st.formato.por_coluna} questões por coluna. Formato salvo.` : "Formato ainda não ajustado: na primeira leitura você indica onde ficam as bolinhas.";
    try { localStorage.setItem("omr_modo_" + PROVA, st.modo); } catch (e) {}
  }
  document.querySelectorAll("[data-modo]").forEach(a => a.addEventListener("click", e => { e.preventDefault(); st.modo = a.dataset.modo; aplicarModo(); }));
  aplicarModo();

  /* ---------------- Guia na tela ---------------- */
  /* Guia: desenhada na MESMA proporção da folha atual (distância real entre os 4 quadrados,
     tamanho dos quadrados, posição do QR), com folga só para o aviso de cima */
  const MM = { x0: L.marcadores[0][0], y0: L.marcadores[0][1], larg: L.marcadores[1][0] - L.marcadores[0][0] };
  function quadro() {
    const sw = palco.clientWidth, sh = palco.clientHeight, topo = 62, base = 74, lado = Math.max(14, sw * 0.04);
    const w = Math.min(sw - 2 * lado, (sh - topo - base) * 0.97 / ASP);
    return { x: (sw - w) / 2, y: topo + (sh - topo - base - w * ASP) / 2, w, h: w * ASP, k: w / MM.larg };
  }
  function posicionarGuia() {
    const f = quadro(), t = Math.max(22, f.k * L.marcador * 1.45);            // quadrado da guia ≈ 1,45× o quadrado impresso
    const px = (x, y) => [f.x + (x - MM.x0) * f.k, f.y + (y - MM.y0) * f.k];
    const g = $("guia"), sp = g.querySelectorAll("span");
    [[f.x, f.y], [f.x + f.w, f.y], [f.x + f.w, f.y + f.h], [f.x, f.y + f.h]].forEach(([x, y], i) => {
      sp[i].style.cssText = `left:${x - t / 2}px;top:${y - t / 2}px;width:${t}px;height:${t}px`;
    });
    /* v73: só os quatro quadrados de canto (sem o desenho da folha por cima da câmera) */
    g.querySelectorAll("i.sg-cartao, i.sg-qr, i.sg-orient").forEach(x => x.remove());
  }
  /* o palco toma a proporção do vídeo (sem cortar a imagem), até 80% da altura da tela */
  function ajustarPalco() {
    if (!video.videoWidth) return;
    palco.style.height = Math.max(380, Math.min(palco.clientWidth * video.videoHeight / video.videoWidth, innerHeight * (innerWidth < 992 ? 0.86 : 0.8))) + "px";
  }
  window.addEventListener("resize", () => { ajustarPalco(); posicionarGuia(); });
  function regioes(img) {
    const sw = palco.clientWidth, sh = palco.clientHeight, vw = video.videoWidth, vh = video.videoHeight;
    const s = Math.max(sw / vw, sh / vh), dx = (sw - vw * s) / 2, dy = (sh - vh * s) / 2, k = img.W / vw, f = quadro();
    const rx = Math.max(f.w * 0.13, f.k * L.marcador * 2.2), ry = Math.max(f.h * 0.07, f.k * L.marcador * 2.2), conv = (X, Y) => [(X - dx) / s * k, (Y - dy) / s * k];
    const lm = f.w * L.marcador / (L.marcadores[1][0] - L.marcadores[0][0]) / s * k;      // lado do quadrado na imagem
    return [[f.x, f.y], [f.x + f.w, f.y], [f.x + f.w, f.y + f.h], [f.x, f.y + f.h]].map(([x, y]) => {
      const [x0, y0] = conv(x - rx, y - ry), [x1, y1] = conv(x + rx, y + ry);
      return { x0, y0, x1, y1, ladoMin: lm * 0.45, ladoMax: lm * 2.4 };
    });
  }
  function marcarGuias(pts) { [...$("guia").children].forEach((s, i) => s.classList.toggle("ok", !!pts[i])); }
  function status(txt, tipo) { const s = $("status"); s.hidden = !txt; s.textContent = txt || ""; s.className = "scan-status" + (tipo ? " " + tipo : ""); }

  /* ---------------- Câmera ---------------- */
  let audio = null;
  function bip(ok) {
    try { navigator.vibrate && navigator.vibrate(ok ? 70 : [40, 60, 40]); } catch (e) {}
    if (!audio) return;
    const o = audio.createOscillator(), g = audio.createGain(); o.connect(g); g.connect(audio.destination);
    o.frequency.value = ok ? 1320 : 440; g.gain.value = 0.08; o.start(); o.stop(audio.currentTime + 0.12);
  }
  async function abrirCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alertaPalco("A câmera só abre em conexão segura (https). Envie uma foto da folha."); return;
    }
    try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
    try {
      st.stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
    } catch (e) { alertaPalco("Não foi possível abrir a câmera. Permita o acesso à câmera no navegador ou envie uma foto."); return; }
    video.srcObject = st.stream; st.track = st.stream.getVideoTracks()[0];
    await video.play().catch(() => {});
    await new Promise(r => video.videoWidth ? r() : video.addEventListener("loadedmetadata", r, { once: true }));
    try { const c = st.track.getCapabilities ? st.track.getCapabilities() : {}; $("btLanterna").hidden = !c.torch; } catch (e) {}
    ajustarPalco();
    $("inicio").hidden = true; $("guia").hidden = false; $("ferramentas").hidden = false; palco.classList.add("ativo");
    posicionarGuia(); procurar();
    document.querySelectorAll("[data-modo]").forEach(a => a.addEventListener("click", () => setTimeout(posicionarGuia, 0)));
    if (palco.getBoundingClientRect().top < 0 || palco.getBoundingClientRect().bottom > innerHeight) palco.scrollIntoView({ behavior: "smooth", block: "end" });
    laco();
  }
  function pararCamera() {
    palco.classList.remove("travado");
    if (st.stream) st.stream.getTracks().forEach(t => t.stop());
    st.stream = null; st.estado = "parado"; palco.classList.remove("ativo");
    $("inicio").hidden = false; $("guia").hidden = true; $("ferramentas").hidden = true; status(""); $("congelado").hidden = true; $("resultado").hidden = true;
  }
  $("abrirCamera").addEventListener("click", abrirCamera);
  $("btParar").addEventListener("click", pararCamera);
  window.addEventListener("pagehide", pararCamera);
  let lanterna = false;
  $("btLanterna").addEventListener("click", () => { lanterna = !lanterna; st.track.applyConstraints({ advanced: [{ torch: lanterna }] }).catch(() => {}); $("btLanterna").classList.toggle("ligado", lanterna); });

  function procurar() {
    st.estado = "procurando"; st.estaveis = 0; st.ultimos = null;
    $("congelado").hidden = true; $("resultado").hidden = true;
    status("Encaixe os 4 quadrados pretos da folha nos 4 quadrados da tela");
  }
  function esperarSaida() { st.estado = "aguardando_saida"; st.ausente = 0; }

  function laco() {
    if (!st.stream) return;
    if ((st.estado === "procurando" || st.estado === "aguardando_saida") && video.videoWidth) {
      const img = OMR.prepararImagem(video, 520);
      const pts = OMR.detectarCantos(img, regioes(img));
      const todos = pts.every(Boolean);
      if (st.estado === "aguardando_saida") {
        st.ausente = todos ? 0 : st.ausente + 1;
        if (st.ausente >= 4) procurar();
      } else {
        marcarGuias(pts);
        if (todos) {
          const mov = st.ultimos ? Math.max(...pts.map((p, i) => Math.hypot(p.x - st.ultimos[i].x, p.y - st.ultimos[i].y))) : 1e9;
          st.estaveis = mov < img.W * 0.015 ? st.estaveis + 1 : 1; st.ultimos = pts;
          status(st.estaveis >= 2 ? "Segure firme…" : "Folha encontrada", "ok");
          if (st.estaveis >= 3) { capturar(pts, img); }
        } else {
          st.estaveis = 0; st.ultimos = null;
          const n = pts.filter(Boolean).length;
          status(n ? `Quase lá: ${n} de 4 quadrados encaixados` : "Encaixe os 4 quadrados pretos da folha nos 4 quadrados da tela");
        }
      }
    }
    setTimeout(laco, 110);
  }

  function capturar(pts, pequena) {
    st.estado = "lendo"; status("Lendo a folha…", "ok"); marcarGuias([1, 1, 1, 1]);
    setTimeout(() => {
      const big = OMR.prepararImagem(video, 1600);
      const cantos = OMR.refinarCantos(big, pts, big.W / pequena.W);
      processar(big, cantos);
    }, 20);
  }

  /* Uma leitura (câmera ou foto) */
  function processar(big, cantos) {
    if (st.modo === "escola") return tratar(cantos ? OMR.lerComCantos(big, L, cantos) : OMR.ler(big.canvas, L));
    if (!cantos) cantos = OMR.cantosNaFoto(big);
    if (!cantos) return tratar({ ok: false, erro: "Não encontrei os 4 quadrados dos cantos da folha.", img: big });
    if (!st.formato || st.recalibrar) { abrirFormato(big, cantos); return; }
    tratar(OMR.lerExterno(big, cantos, st.formato, N, NALT));
  }

  function congelar(res) {
    const c = $("congelado"); c.hidden = false; $("inicio").hidden = true;
    const resp = res.respostas || [];
    if (!res.ok || !res.contorno) { OMR.desenhar(c, res, GAB, resp); return; }
    /* desenha a foto inteira com as marcações e mostra só o cartão de respostas (com folga) */
    const t = document.createElement("canvas"); OMR.desenhar(t, res, GAB, resp);
    const xs = res.contorno.map(p => p[0]), ys = res.contorno.map(p => p[1]);
    const fx = (Math.max(...xs) - Math.min(...xs)) * 0.07, fy = (Math.max(...ys) - Math.min(...ys)) * 0.04;
    const x0 = Math.max(0, Math.min(...xs) - fx), y0 = Math.max(0, Math.min(...ys) - fy);
    const x1 = Math.min(t.width, Math.max(...xs) + fx), y1 = Math.min(t.height, Math.max(...ys) + fy);
    c.width = Math.round(x1 - x0); c.height = Math.round(y1 - y0);
    c.getContext("2d").drawImage(t, x0, y0, c.width, c.height, 0, 0, c.width, c.height);
  }
  function alertaPalco(msg) {
    status("");
    $("resultado").hidden = false; $("resultado").className = "scan-resultado erro";
    $("srAvatar").innerHTML = "<i class=\\"bi bi-exclamation-triangle\\"></i>"; $("srNome").textContent = "Não deu para ler"; $("srTurma").textContent = "";
    $("srNota").textContent = ""; $("srAcertos").textContent = ""; $("srMsg").textContent = msg; $("srRevisar").hidden = true;
  }

  function tratar(res) {
    if (!res.ok) {
      bip(false); if (res.img) { const c = $("congelado"); OMR.desenhar(c, res, GAB); c.hidden = false; $("inicio").hidden = true; }
      alertaPalco(res.erro);
      if (st.stream) { st.estado = "pausa"; setTimeout(() => { if (st.estado === "pausa") procurar(); }, 2600); }
      return;
    }
    /* v63: só folhas geradas pelo sistema (têm o QR code da prova) */
    if (!(res.qr && res.qr.prova)) {
      if (st.stream && st.tentQR < 6) { st.tentQR++; status("Lendo o QR code da folha… segure firme (" + st.tentQR + ")", "ok"); st.estado = "procurando"; st.estaveis = 2; return; }
      st.tentQR = 0; congelar(res); bip(false);
      alertaPalco("Não reconheci esta folha. A câmera só corrige o gabarito gerado pelo sistema para esta prova (com o QR code no canto). Confira se o QR code está limpo e bem iluminado.");
      if (st.stream) esperarSaida(); return;
    }
    if (res.qr && res.qr.prova !== PROVA) { congelar(res); bip(false); alertaPalco("Esta folha é da prova nº " + res.qr.prova + ", não desta. Separe-a e leia a próxima."); if (st.stream) esperarSaida(); return; }
    /* a identificação do aluno é o mais importante: sem QR, tenta mais algumas imagens antes de travar */
    if (st.modo === "escola" && st.stream && !(res.qr && res.qr.aluno) && st.tentQR < 5) {
      st.tentQR++; st.melhor = st.melhor || res;
      status("Lendo o QR code do aluno… segure firme (" + st.tentQR + ")", "ok");
      st.estado = "procurando"; st.estaveis = 2; return;
    }
    st.tentQR = 0; st.melhor = null;
    const alunoQR = res.qr && res.qr.aluno && ALUNOS[res.qr.aluno] ? String(res.qr.aluno) : "";
    const it = { alunoId: alunoQR, origem: alunoQR ? "qr" : "", resp: res.respostas.slice(), leitura: res, duvidas: res.duvidas || [], salvo: false, qrFora: res.qr && res.qr.aluno && !ALUNOS[res.qr.aluno] };
    travar(it);
    bip(true);
  }

  /* ---------------- Tela travada: aluno, nota e ações ---------------- */
  function travar(it) {
    st.atual = it; st.estado = "travado"; status("");
    if (it.leitura) congelar(Object.assign({}, it.leitura, { respostas: it.resp }));
    palco.classList.add("travado"); $("guia").hidden = true;
    mostrarTravado();
    const topoPalco = palco.getBoundingClientRect().top + scrollY - 76;
    if (Math.abs(scrollY - topoPalco) > 40) scrollTo({ top: topoPalco, behavior: "smooth" });
  }
  function mostrarTravado() {
    const it = st.atual; if (!it) return;
    const a = ALUNOS[it.alunoId], ac = acertosDe(it.resp), r = $("resultado");
    r.hidden = false; r.className = "scan-resultado travado " + (it.salvo ? "ok" : a ? "pronto" : "atencao");
    $("srRotulo").innerHTML = a ? (it.origem === "qr" ? "<i class=\\"bi bi-qr-code-scan\\"></i> Aluno identificado pelo QR code" : "<i class=\\"bi bi-person-check\\"></i> Aluno escolhido") : "<i class=\\"bi bi-exclamation-triangle\\"></i> Aluno não identificado";
    $("srAvatar").innerHTML = a ? avatar(a, "avatar-md") : "<i class=\\"bi bi-person-fill-exclamation\\"></i>";
    $("srNome").textContent = a ? a.nome : "Escolha o aluno desta folha";
    $("srTurma").textContent = a ? a.turma : (it.qrFora ? "O QR é de um aluno que não está nesta série." : "Não consegui ler o QR code. Toque em “Escolher aluno”.");
    if (PARTES.length) { $("srNota").textContent = ac + "/" + N; $("srAcertos").textContent = "acertos no bloco"; }
    else { $("srNota").textContent = br(nota(ac)); $("srAcertos").textContent = ac + " de " + N + " acertos"; }
    const msgs = [];
    if (it.salvo) msgs.push("<span class=\\"sr-salvo\\"><i class=\\"bi bi-check-circle-fill\\"></i> Nota salva" + (it.antes !== null && it.antes !== undefined ? " (substituiu " + br(it.antes) + ")" : "") + "</span>");
    else if (a && a.nota !== null) msgs.push("<span class=\\"text-warning-emphasis\\">Já corrigida antes (nota " + br(a.nota) + "): salvar substitui.</span>");
    if (it.erro) msgs.push("<span class=\\"text-danger\\">" + esc(it.erro) + "</span>");
    if (it.duvidas.length) msgs.push("<b>Confira a questão " + it.duvidas.map(i => i + 1).join(", ") + "</b>");
    $("srMsg").innerHTML = (PARTES.length ? `<div class="sr-partes">${partesHtml(it.resp)}</div>` : "") + msgs.join(" · ");
    $("srRevisar").hidden = false;
    $("srRevisar").innerHTML = a ? "<i class=\\"bi bi-pencil-square me-1\\"></i>Revisar" : "<i class=\\"bi bi-person-plus me-1\\"></i>Escolher aluno";
    $("srSalvar").hidden = false; $("srSalvar").disabled = !a || it.salvo || it.salvando;
    $("srSalvar").innerHTML = it.salvo ? "<i class=\\"bi bi-check2-all me-1\\"></i>Nota salva" : it.salvando ? "Salvando…" : "<i class=\\"bi bi-check2-circle me-1\\"></i>Salvar nota";
    $("srProxima").innerHTML = "<i class=\\"bi bi-arrow-left me-1\\"></i>Voltar";
  }
  function voltar() {
    st.atual = null; st.tentQR = 0; st.melhor = null;
    palco.classList.remove("travado"); if (st.stream) $("guia").hidden = false;
    $("resultado").hidden = true; $("congelado").hidden = true;
    if (st.stream) procurar(); else { st.estado = "parado"; $("inicio").hidden = false; }
  }
  $("srSalvar").addEventListener("click", () => { if (st.atual && ALUNOS[st.atual.alunoId] && !st.atual.salvo) salvar(st.atual); });
  $("srFechar").addEventListener("click", voltar);

  /* ---------------- Salvar ---------------- */
  function salvar(it) {
    const fd = new FormData(); fd.append("acao", "omr_salvar"); fd.append("prova_id", PROVA); fd.append("aluno_id", it.alunoId); fd.append("respostas", it.resp.join("")); fd.append("csrf", CSRF);
    it.salvando = true; it.erro = ""; mostrarTravado();
    return fetch("api.php", { method: "POST", body: fd }).then(r => r.json()).then(j => {
      it.salvando = false;
      if (!j.ok) { it.erro = j.msg || "Não foi possível salvar."; mostrarTravado(); return j; }
      const a = ALUNOS[it.alunoId], antes = a.nota;
      if (antes === null) { st.total++; $("progresso").textContent = st.total + " de " + TOTAL_ALUNOS + " corrigidas"; }
      a.nota = j.nota; it.nota = j.nota; it.acertos = j.acertos; it.salvo = true; it.antes = j.substituiu ? antes : null;
      if (!st.sessao.includes(it)) { st.sessao.unshift(it); if (st.sessao.length > 40) st.sessao.pop().leitura = null; }
      listarSessao(); bip(true);
      if (st.atual === it) mostrarTravado();
      return j;
    }).catch(() => { it.salvando = false; it.erro = "Sem conexão: a nota não foi salva. Tente de novo."; mostrarTravado(); });
  }

  function avatar(a, cls) { return a.foto ? `<img class="avatar ${cls}" src="${a.foto}" alt="">` : `<span class="avatar ${cls}">${esc(a.nome.split(" ").filter((_, i, v) => i === 0 || i === v.length - 1).map(p => p[0]).join(""))}</span>`; }
  $("srProxima").addEventListener("click", voltar);
  $("srRevisar").addEventListener("click", () => { if (st.atual) abrirRevisao(st.atual); });

  function listarSessao() {
    $("contSessao").textContent = st.sessao.length;
    $("sessao").innerHTML = st.sessao.length ? st.sessao.map((it, i) => { const a = ALUNOS[it.alunoId]; return `<li><button type="button" data-i="${i}">${avatar(a, "avatar-sm")}
      <span class="min-w-0 flex-grow-1"><strong>${esc(a.nome)}</strong><small>${esc(a.turma)}${it.duvidas.length ? " · <span class=\\"text-warning-emphasis\\">conferir</span>" : ""}</small></span>
      <span class="ss-nota">${br(it.nota)}</span></button></li>`; }).join("") : "<li class=\\"vazio py-4\\"><i class=\\"bi bi-inbox\\"></i>As folhas lidas aparecem aqui.</li>";
  }
  $("sessao").addEventListener("click", e => { const b = e.target.closest("button[data-i]"); if (!b) return; const it = st.sessao[+b.dataset.i]; travar(it); abrirRevisao(it); });

  /* ---------------- Revisar ---------------- */
  const mRev = new bootstrap.Modal($("modalRevisar"));
  let rev = null, revSalvo = false;
  function calcular() {
    const ac = acertosDe(rev.resp);
    $("acertos").textContent = ac; $("nota").textContent = br(nota(ac));
    $("revPartes").innerHTML = partesHtml(rev.resp);
    document.querySelectorAll(".omr-q").forEach((el, i) => {
      el.className = "omr-q " + (rev.resp[i] === "-" || rev.resp[i] === "M" ? "branco" : (GAB[i] === "*" || rev.resp[i] === GAB[i] ? "certa" : "errada"));
    });
    if (rev.leitura) OMR.desenhar($("telaRevisar"), rev.leitura, GAB, rev.resp);
  }
  function avisoAluno() {
    const a = ALUNOS[$("aluno").value];
    $("avisoAluno").innerHTML = a ? (a.nota !== null && String(rev.alunoId) !== $("aluno").value ? `<span class="text-danger">Já corrigida antes (nota ${br(a.nota)}). Salvar substitui.</span>` : esc(a.turma)) : (rev.aviso ? `<span class="text-danger">${esc(rev.aviso)}</span>` : "");
  }
  function abrirRevisao(item, aviso) {
    if (st.stream) st.estado = "travado";
    rev = { item, resp: item.resp.slice(), leitura: item.leitura, alunoId: item.alunoId, aviso };
    revSalvo = false;
    $("tituloRevisar").textContent = item.alunoId ? "Revisar leitura" : "Quem é este aluno?";
    $("aluno").value = item.alunoId || ""; avisoAluno();
    $("descartar").hidden = !!item.salvo;
    $("duvidas").textContent = item.leitura && item.leitura.duvidas.length ? "Confira: questão " + item.leitura.duvidas.map(i => i + 1).join(", ") : "";
    $("respostas").innerHTML = rev.resp.map((r, i) => `<label class="omr-q"><span class="n">${i + 1}</span>
      <select data-i="${i}" aria-label="Resposta da questão ${i + 1}">${["-", ...LETRAS, "M"].map(l => `<option value="${l}"${l === r ? " selected" : ""}>${l === "-" ? "–" : l === "M" ? "2×" : l}</option>`).join("")}</select>
      <span class="gab">${GAB[i] === "*" ? "anul." : GAB[i]}</span></label>`).join("");
    $("telaRevisar").parentElement.hidden = !item.leitura;
    calcular(); mRev.show();
  }
  $("respostas").addEventListener("change", e => { const s = e.target.closest("select"); if (!s) return; rev.resp[+s.dataset.i] = s.value; calcular(); });
  $("aluno").addEventListener("change", avisoAluno);
  $("salvar").addEventListener("click", () => {
    const id = $("aluno").value;
    if (!id) { $("aluno").focus(); $("aluno").classList.add("is-invalid"); setTimeout(() => $("aluno").classList.remove("is-invalid"), 1500); return; }
    revSalvo = true; mRev.hide();
    const it = rev.item;
    if (String(it.alunoId) !== id) { it.alunoId = id; it.origem = "manual"; }
    it.resp = rev.resp.slice(); it.revisado = true; it.salvo = false;
    travar(it); salvar(it);                        // salva e continua travado até o professor tocar em Voltar
  });
  $("descartar").addEventListener("click", () => { revSalvo = true; mRev.hide(); voltar(); });
  $("modalRevisar").addEventListener("hidden.bs.modal", () => { if (!revSalvo && rev && rev.item) travar(rev.item); });

  /* ---------------- Formato da folha externa ---------------- */
  const mFmt = new bootstrap.Modal($("modalFormato"));
  let fmt = null;
  function abrirFormato(big, cantos) {
    if (st.stream) st.estado = "pausa";
    status("");
    const F = st.formato || { colunas: 1, por_coluna: N, p1: [0.18, 0.3], p2: [0.42, 0.3], p3: [0.42, 0.9], p4: [0.6, 0.3] };
    fmt = { big, cantos, F: JSON.parse(JSON.stringify(F)) }; st.ultimaFolha = { big, cantos };
    const tela = OMR.retificar(big, cantos, 900), img = $("fmtImagem");
    img.width = tela.width; img.height = tela.height; img.getContext("2d").drawImage(tela, 0, 0);
    $("fmtGrade").width = tela.width; $("fmtGrade").height = tela.height;
    $("fColunas").value = fmt.F.colunas; $("fPorColuna").value = fmt.F.por_coluna;
    mFmt.show();
  }
  $("modalFormato").addEventListener("shown.bs.modal", desenharFormato);
  function desenharFormato() {
    if (!fmt) return;
    const F = fmt.F; F.colunas = Math.max(1, Math.min(6, +$("fColunas").value || 1)); F.por_coluna = Math.max(1, Math.min(60, +$("fPorColuna").value || N));
    if (F.colunas === 1) F.p4 = F.p1.slice();
    $("legP4").hidden = F.colunas === 1;
    const area = $("fmtArea"), cw = area.clientWidth, ch = area.clientHeight;
    ["p1", "p2", "p3", "p4"].forEach(k => { const h = area.querySelector(`[data-p="${k}"]`); h.hidden = k === "p4" && F.colunas === 1; h.style.left = (F[k][0] * cw) + "px"; h.style.top = (F[k][1] * ch) + "px"; });
    const c = $("fmtGrade"), ctx = c.getContext("2d"), w = c.width, h = c.height; ctx.clearRect(0, 0, w, h);
    const P = OMR.posicoesExterno(F, N, NALT), r = Math.max(3, 0.36 * Math.min(Math.abs(P.passoAlt) * w || 99, Math.abs(P.passoLin) * h || 99));
    ctx.lineWidth = 2; ctx.strokeStyle = "rgba(30,154,43,.95)";
    P.q.forEach(alts => alts.forEach(([u, v]) => { ctx.beginPath(); ctx.arc(u * w, v * h, r, 0, Math.PI * 2); ctx.stroke(); }));
    ctx.fillStyle = "rgba(30,154,43,.9)"; ctx.font = "bold " + Math.round(r * 1.1) + "px sans-serif"; ctx.textAlign = "right";
    P.q.forEach((alts, i) => { if (i % F.por_coluna === 0 || i === N - 1) ctx.fillText(i + 1, alts[0][0] * w - r * 1.6, alts[0][1] * h + r * 0.4); });
  }
  ["fColunas", "fPorColuna"].forEach(id => $(id).addEventListener("input", () => {
    if (id === "fColunas") $("fPorColuna").value = Math.ceil(N / Math.max(1, +$("fColunas").value || 1));
    if (fmt.F.colunas === 1 && +$("fColunas").value > 1) fmt.F.p4 = [Math.min(0.9, fmt.F.p2[0] + 0.15), fmt.F.p1[1]];
    desenharFormato();
  }));
  (function arrastar() {
    const area = $("fmtArea"); let alvo = null;
    area.addEventListener("pointerdown", e => { const h = e.target.closest(".fmt-p"); if (!h) return; alvo = h.dataset.p; h.setPointerCapture(e.pointerId); e.preventDefault(); });
    area.addEventListener("pointermove", e => {
      if (!alvo) return; const r = area.getBoundingClientRect();
      fmt.F[alvo] = [Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), Math.max(0, Math.min(1, (e.clientY - r.top) / r.height))];
      if (alvo === "p1") fmt.F.p2[1] = fmt.F.p1[1]; if (alvo === "p2") fmt.F.p1[1] = fmt.F.p2[1];
      desenharFormato();
    });
    ["pointerup", "pointercancel"].forEach(t => area.addEventListener(t, () => alvo = null));
  })();
  $("salvarFormato").addEventListener("click", () => {
    desenharFormato();
    const fd = new FormData(); fd.append("acao", "omr_formato"); fd.append("prova_id", PROVA); fd.append("formato", JSON.stringify(fmt.F)); fd.append("csrf", CSRF);
    fetch("api.php", { method: "POST", body: fd }).then(r => r.json()).then(j => {
      if (!j.ok) { alert(j.msg); return; }
      st.formato = fmt.F; st.recalibrar = false; aplicarModo(); fmt.salvo = true; mFmt.hide();
      tratar(OMR.lerExterno(fmt.big, fmt.cantos, st.formato, N, NALT));
    });
  });
  $("modalFormato").addEventListener("hidden.bs.modal", () => { if (fmt && !fmt.salvo && st.stream) procurar(); });
  $("btAjustar").addEventListener("click", () => {
    if (st.ultimaFolha) { abrirFormato(st.ultimaFolha.big, st.ultimaFolha.cantos); return; }
    st.recalibrar = true; st.modo = "externa"; aplicarModo();
    if (!st.stream) abrirCamera();
    status("Aponte para uma folha: o ajuste abre na próxima leitura", "ok");
  });

  /* ---------------- Foto enviada ---------------- */
  $("arquivoFoto").addEventListener("change", async e => {
    const f = e.target.files[0]; e.target.value = ""; if (!f) return;
    if (st.stream) st.estado = "pausa";
    status("Lendo a foto…", "ok");
    try {
      const im = await OMR.carregarArquivo(f);
      await new Promise(r => setTimeout(r, 30));
      const big = OMR.prepararImagem(im, 1600);
      processar(big, null);
    } catch (err) { alertaPalco(err.message); }
  });

  /* guarda a última folha externa (para reajustar o formato sem nova leitura) */
  const lerExterno0 = OMR.lerExterno;
  OMR.lerExterno = function (big, cantos) { st.ultimaFolha = { big, cantos }; return lerExterno0.apply(OMR, arguments); };
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
