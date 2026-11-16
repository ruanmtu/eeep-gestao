/* Recorte de imagem antes de enviar.
   Uso: <input type="file" data-recorte="4:5" data-saida="1200" data-previa="#idDaPrevia">
   - data-recorte: proporção do quadro (largura:altura)
   - data-saida: largura final em pixels (opcional, padrão 1600)
   - data-previa: seletor de um <img> (ou de um elemento que recebe um <img>); "label" = o label do próprio campo
   A foto aparece inteira e um quadro marca o que vai ser usado:
   - arrastar dentro do quadro (mouse ou dedo) move;
   - puxar os cantos aumenta ou diminui;
   - dois dedos (pinça) ou a rodinha do mouse também mudam o tamanho.
   Ao confirmar, o arquivo do campo é trocado pela imagem já enquadrada (JPG). */
(function () {
  'use strict';
  let modal, palco, foto, quadro, titulo, info, tam;
  let E = null;   // estado: { input, arquivo, img, url, prop, saidaW, W, H, q:{x,y,w,h} }

  function criar() {
    modal = document.createElement('div');
    modal.className = 'rc-modal'; modal.hidden = true;
    modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', 'Enquadrar a foto');
    modal.innerHTML =
      '<div class="rc-caixa">' +
        '<div class="rc-topo"><b class="rc-titulo">Enquadrar a foto</b><button type="button" class="rc-x" data-rc="cancelar" aria-label="Cancelar"><i class="bi bi-x-lg"></i></button></div>' +
        '<p class="rc-dica"><i class="bi bi-hand-index-thumb"></i><span>Arraste o quadro para posicionar e puxe os <b>cantos</b> para aumentar ou diminuir. No celular, use os dedos.</span></p>' +
        '<div class="rc-palco"><img class="rc-foto" alt="" draggable="false">' +
          '<div class="rc-quadro" tabindex="0" aria-label="Área da foto que será usada. Use as setas para mover.">' +
            '<span class="rc-linhas"></span>' +
            '<i class="rc-canto" data-c="nw"></i><i class="rc-canto" data-c="ne"></i><i class="rc-canto" data-c="sw"></i><i class="rc-canto" data-c="se"></i>' +
          '</div></div>' +
        '<div class="rc-zoom"><button type="button" data-rc="menor" aria-label="Quadro menor"><i class="bi bi-dash-lg"></i></button>' +
          '<input type="range" min="15" max="100" value="100" aria-label="Tamanho do quadro"><button type="button" data-rc="maior" aria-label="Quadro maior"><i class="bi bi-plus-lg"></i></button>' +
          '<button type="button" data-rc="centro" title="Centralizar" aria-label="Centralizar"><i class="bi bi-bullseye"></i></button></div>' +
        '<small class="rc-info"></small>' +
        '<div class="rc-pe"><button type="button" class="btn btn-light" data-rc="trocar"><i class="bi bi-images me-1"></i>Escolher outra</button>' +
          '<button type="button" class="btn btn-light ms-auto" data-rc="cancelar">Cancelar</button>' +
          '<button type="button" class="btn btn-primary" data-rc="ok"><i class="bi bi-check2 me-1"></i>Usar esta foto</button></div>' +
      '</div>';
    palco = modal.querySelector('.rc-palco'); foto = modal.querySelector('.rc-foto'); quadro = modal.querySelector('.rc-quadro');
    titulo = modal.querySelector('.rc-titulo'); info = modal.querySelector('.rc-info'); tam = modal.querySelector('input[type=range]');

    modal.addEventListener('click', e => {
      const b = e.target.closest('[data-rc]'); if (!b || !E) return;
      const a = b.dataset.rc;
      if (a === 'cancelar') fechar(false);
      else if (a === 'ok') confirmar();
      else if (a === 'trocar') { const inp = E.input; fechar(false); inp.click(); }
      else if (a === 'maior' || a === 'menor') escalar(a === 'maior' ? 1.1 : 1 / 1.1);
      else if (a === 'centro') { E.q.x = (E.W - E.q.w) / 2; E.q.y = (E.H - E.q.h) / 2; desenhar(); }
    });
    tam.addEventListener('input', () => { if (E) escalar(maxQ().w * (+tam.value / 100) / E.q.w); });
    document.addEventListener('keydown', e => {
      if (!modal || modal.hidden || !E) return;
      if (e.key === 'Escape') { e.preventDefault(); fechar(false); return; }
      if (document.activeElement !== quadro) return;
      const p = e.shiftKey ? 20 : 4, d = { ArrowLeft: [-p, 0], ArrowRight: [p, 0], ArrowUp: [0, -p], ArrowDown: [0, p] }[e.key];
      if (d) { e.preventDefault(); E.q.x += d[0]; E.q.y += d[1]; prender(); desenhar(); }
      else if (e.key === '+' || e.key === '=') escalar(1.05); else if (e.key === '-') escalar(1 / 1.05);
    });

    /* ---- mouse e dedos ---- */
    const pts = new Map(); let g = null;   // gesto atual
    palco.addEventListener('pointerdown', e => {
      if (!E) return; e.preventDefault();
      palco.setPointerCapture(e.pointerId);
      pts.set(e.pointerId, ponto(e));
      const canto = e.target.closest('.rc-canto');
      let modo = canto ? canto.dataset.c : null;
      if (!canto && pts.size === 1 && !e.target.closest('.rc-quadro')) {   // tocou fora do quadro: leva o quadro até ali
        const p = ponto(e); E.q.x = p.x - E.q.w / 2; E.q.y = p.y - E.q.h / 2; prender(); desenhar();
      }
      iniciar(modo);
    });
    palco.addEventListener('pointermove', e => {
      if (!E || !pts.has(e.pointerId) || !g) return;
      pts.set(e.pointerId, ponto(e));
      const q0 = g.q;
      if (pts.size >= 2) {                     // pinça: muda o tamanho em volta do centro
        if (!g.d) return;
        tamanho(q0.w * dist() / g.d, q0.x + q0.w / 2, q0.y + q0.h / 2);
      } else if (g.canto) {                    // canto: o canto oposto fica parado
        const p = ponto(e), c = g.canto;
        const ax = c.includes('w') ? q0.x + q0.w : q0.x, ay = c.includes('n') ? q0.y + q0.h : q0.y;
        let w = Math.max(Math.abs(p.x - ax), Math.abs(p.y - ay) * E.prop);
        w = Math.max(40, Math.min(w, c.includes('w') ? ax : E.W - ax, (c.includes('n') ? ay : E.H - ay) * E.prop));
        const h = w / E.prop;
        E.q = { w, h, x: c.includes('w') ? ax - w : ax, y: c.includes('n') ? ay - h : ay };
      } else {                                 // arrastar: move o quadro
        const p = ponto(e);
        E.q.x = q0.x + (p.x - g.p.x); E.q.y = q0.y + (p.y - g.p.y);
      }
      prender(); desenhar();
    });
    const soltar = e => { pts.delete(e.pointerId); if (E) iniciar(null); };
    palco.addEventListener('pointerup', soltar); palco.addEventListener('pointercancel', soltar);
    palco.addEventListener('wheel', e => { if (!E) return; e.preventDefault(); escalar(e.deltaY < 0 ? 1.06 : 1 / 1.06); }, { passive: false });
    function iniciar(canto) { const v = [...pts.values()]; g = { q: { ...E.q }, p: v[0] || { x: 0, y: 0 }, d: dist(), canto: pts.size === 1 ? canto : null }; }
    function ponto(e) { const r = palco.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function dist() { const v = [...pts.values()]; return v.length < 2 ? 0 : Math.hypot(v[0].x - v[1].x, v[0].y - v[1].y); }
    addEventListener('resize', () => { if (E && !modal.hidden) medir(true); });
  }

  /* maior quadro possível dentro da foto */
  function maxQ() { let w = E.W, h = w / E.prop; if (h > E.H) { h = E.H; w = h * E.prop; } return { w, h }; }
  function tamanho(w, cx, cy) {
    w = Math.max(40, Math.min(maxQ().w, w));
    const h = w / E.prop; E.q = { w, h, x: cx - w / 2, y: cy - h / 2 };
  }
  function escalar(f) { tamanho(E.q.w * f, E.q.x + E.q.w / 2, E.q.y + E.q.h / 2); prender(); desenhar(); }
  function prender() { E.q.x = Math.max(0, Math.min(E.W - E.q.w, E.q.x)); E.q.y = Math.max(0, Math.min(E.H - E.q.h, E.q.y)); }

  /* foto inteira na tela; o quadro acompanha se a tela mudar de tamanho */
  function medir(manter) {
    const nw = E.img.naturalWidth, nh = E.img.naturalHeight;
    const maxW = Math.min(640, innerWidth - 56), maxH = Math.max(220, Math.min(460, innerHeight - 330));
    const s = Math.min(maxW / nw, maxH / nh);
    const rel = manter && E.W ? { x: E.q.x / E.W, y: E.q.y / E.H, w: E.q.w / E.W } : null;
    E.W = nw * s; E.H = nh * s;
    palco.style.width = E.W + 'px'; palco.style.height = E.H + 'px';
    if (rel) { const w = rel.w * E.W; E.q = { w, h: w / E.prop, x: rel.x * E.W, y: rel.y * E.H }; }
    else { const w = maxQ().w * 0.9; E.q = { w, h: w / E.prop, x: (E.W - w) / 2, y: (E.H - w / E.prop) / 2 }; }
    prender(); desenhar();
  }
  function desenhar() {
    const q = E.q;
    quadro.style.transform = 'translate(' + q.x + 'px,' + q.y + 'px)';
    quadro.style.width = q.w + 'px'; quadro.style.height = q.h + 'px';
    tam.value = Math.round(q.w / maxQ().w * 100);
    const realW = q.w / E.W * E.img.naturalWidth;
    info.textContent = realW < E.saidaW * 0.45 ? 'Atenção: um quadro muito pequeno pode deixar a foto sem nitidez.' : '';
  }

  function abrir(input, arquivo) {
    if (!modal) criar();
    (input.closest('.modal') || document.body).appendChild(modal);   // dentro do modal do Bootstrap, para o foco funcionar
    const [a, b] = (input.dataset.recorte || '1:1').split(':').map(Number);
    const saidaW = +(input.dataset.saida || 1600);
    const url = URL.createObjectURL(arquivo), img = new Image();
    img.onload = () => {
      E = { input, arquivo, img, url, prop: a / b, saidaW, W: 0, H: 0, q: null };
      foto.src = url;
      titulo.textContent = input.dataset.recorteTitulo || 'Enquadrar a foto';
      modal.hidden = false; document.body.classList.add('rc-aberto');
      medir(false);
      quadro.focus({ preventScroll: true });
    };
    img.onerror = () => { URL.revokeObjectURL(url); alert('Não foi possível abrir essa imagem. Use JPG, PNG ou WEBP.'); input.value = ''; };
    img.src = url;
  }
  function fechar(ok) {
    if (!E) { modal.hidden = true; return; }
    if (!ok) { E.input.value = ''; E.input.dispatchEvent(new CustomEvent('recorte-cancelado', { bubbles: true })); }
    URL.revokeObjectURL(E.url);
    modal.hidden = true; document.body.classList.remove('rc-aberto'); E = null;
  }
  function confirmar() {
    const k = E.img.naturalWidth / E.W;   // tela → foto original
    const sx = E.q.x * k, sy = E.q.y * k, sw = E.q.w * k, sh = E.q.h * k;
    const outW = Math.round(Math.min(E.saidaW, Math.max(sw, E.saidaW * 0.5))), outH = Math.round(outW / E.prop);
    const c = document.createElement('canvas'); c.width = outW; c.height = outH;
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, outW, outH);
    g.imageSmoothingQuality = 'high';
    g.drawImage(E.img, sx, sy, sw, sh, 0, 0, outW, outH);
    const bt = modal.querySelector('[data-rc=ok]'); bt.disabled = true;
    const e = E;
    c.toBlob(blob => {
      bt.disabled = false;
      if (!blob) { alert('Não foi possível preparar a foto.'); return; }
      const nome = (e.arquivo.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
      const f = new File([blob], nome, { type: 'image/jpeg', lastModified: Date.now() });
      const dt = new DataTransfer(); dt.items.add(f);
      const input = e.input; input._rcPronto = true; input.files = dt.files;
      mostrar(input, URL.createObjectURL(blob));
      fechar(true);
      input.dispatchEvent(new Event('change', { bubbles: true }));   // avisa a página (prévia, ponto de foco…)
      input.dispatchEvent(new CustomEvent('recortado', { bubbles: true, detail: { arquivo: f } }));
    }, 'image/jpeg', 0.88);
  }
  function mostrar(input, url) {
    const sel = input.dataset.previa; if (!sel) return;
    const alvo = sel === 'label' ? input.closest('label') : document.querySelector(sel); if (!alvo) return;
    let img = alvo.tagName === 'IMG' ? alvo : alvo.querySelector('img');
    if (!img) { alvo.querySelectorAll(':scope > span').forEach(s => s.remove()); img = document.createElement('img'); img.alt = ''; alvo.prepend(img); }
    img.src = url; img.hidden = false; alvo.classList.add('rc-tem');
  }

  document.addEventListener('change', e => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.hasAttribute('data-recorte')) return;
    if (input._rcPronto) { input._rcPronto = false; return; }
    const f = input.files && input.files[0];
    if (!f || !/^image\//.test(f.type) || f.type === 'image/gif') return;
    e.stopImmediatePropagation();
    abrir(input, f);
  }, true);
})();
