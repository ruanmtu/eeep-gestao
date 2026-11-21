/* Visualizador de PDF dentro do sistema (pdf.js) — funciona também no celular.
 * Uso: <div class="visualizador-pdf" data-url="arquivo.php?id=1&inline=1"></div>  */
import * as pdfjs from '../vendor/pdfjs/pdf.js';

const base = new URL('../vendor/pdfjs/', import.meta.url).href;
pdfjs.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.js';

function montar(caixa) {
  const url = caixa.dataset.url;
  caixa.innerHTML = `
    <div class="vp-barra">
      <button type="button" class="vp-btn" data-z="-1" aria-label="Diminuir"><i class="bi bi-zoom-out"></i></button>
      <span class="vp-zoom">100%</span>
      <button type="button" class="vp-btn" data-z="1" aria-label="Aumentar"><i class="bi bi-zoom-in"></i></button>
      <button type="button" class="vp-btn" data-z="0" aria-label="Ajustar à largura"><i class="bi bi-arrows-angle-expand"></i></button>
      <span class="vp-pag ms-auto">Carregando…</span>
      <input class="vp-ir" type="number" min="1" placeholder="Pág." aria-label="Ir para a página" hidden>
      ${caixa.dataset.anotar ? '<button type="button" class="vp-btn vp-marcar" aria-label="Marcar no livro" title="Riscar e marcar"><i class="bi bi-pencil"></i><span> Marcar</span></button>' : ''}
      <button type="button" class="vp-btn vp-tela" aria-label="Tela cheia" title="Tela cheia"><i class="bi bi-fullscreen"></i></button>
    </div>
    ${caixa.dataset.anotar ? `<div class="vp-paleta" hidden>
      <button type="button" class="vp-fer ativo" data-f="p" title="Caneta"><i class="bi bi-pen"></i></button>
      <button type="button" class="vp-fer" data-f="h" title="Marca-texto"><i class="bi bi-highlighter"></i></button>
      <button type="button" class="vp-fer" data-f="b" title="Borracha (apaga o traço tocado)"><i class="bi bi-eraser"></i></button>
      <span class="vp-sep"></span>
      ${['#d62828','#1d4ed8','#15803d','#111827','#f59e0b','#db2777'].map((c, i) => `<button type="button" class="vp-cor${i ? '' : ' ativo'}" data-c="${c}" style="--c:${c}" aria-label="Cor"></button>`).join('')}
      <span class="vp-sep"></span>
      ${[2, 4, 8].map((w, i) => `<button type="button" class="vp-esp${i ? '' : ' ativo'}" data-w="${w}" title="Espessura"><i style="--w:${w + 2}px"></i></button>`).join('')}
      <span class="vp-sep"></span>
      <button type="button" class="vp-fer2 vp-desf" title="Desfazer"><i class="bi bi-arrow-counterclockwise"></i></button>
      <button type="button" class="vp-fer2 vp-limpar" title="Limpar esta página"><i class="bi bi-trash3"></i></button>
      <label class="vp-dedo" title="No celular/tablet: escolha se o dedo risca ou rola a página"><input type="checkbox" checked> Dedo risca</label>
      <span class="vp-salvo"></span>
    </div>` : ''}
    <div class="vp-paginas" tabindex="0"><div class="vp-carregando"><span class="spinner-border spinner-border-sm"></span> Abrindo o PDF…</div></div>`;
  const area = caixa.querySelector('.vp-paginas'), rotZoom = caixa.querySelector('.vp-zoom'), rotPag = caixa.querySelector('.vp-pag'), ir = caixa.querySelector('.vp-ir');
  const memoria = caixa.dataset.memoria ? 'pdf_pag_' + caixa.dataset.memoria : null;
  const baixar = caixa.dataset.baixar || (url.includes('inline=1') ? url.replace('inline=1', 'baixar=1') : url + (url.includes('?') ? '&' : '?') + 'baixar=1');
  let paginaAtual = 1;
  caixa._vpPagina = () => paginaAtual;
  const irPara = n => { const alvo = area.querySelector(`.vp-pagina[data-n="${n}"]`); if (alvo) area.scrollTop = alvo.offsetTop - 8; };
  let doc = null, escala = 0, base1 = 1, desenhadas = new Map(), obs = null;
  const tinta = caixa.dataset.anotar ? anotacoes(caixa, caixa.dataset.anotar) : null;

  const ajustar = async () => {
    const p = await doc.getPage(1);
    const vp = p.getViewport({ scale: 1 });
    base1 = (area.clientWidth - 24) / vp.width;
    return base1;
  };

  async function desenhar(n, cont) {
    if (desenhadas.get(n) === escala) return;
    desenhadas.set(n, escala);
    const p = await doc.getPage(n);
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const vp = p.getViewport({ scale: escala });
    const c = document.createElement('canvas');
    c.width = Math.floor(vp.width * dpr); c.height = Math.floor(vp.height * dpr);
    c.style.width = Math.floor(vp.width) + 'px'; c.style.height = Math.floor(vp.height) + 'px';
    await p.render({ canvasContext: c.getContext('2d'), viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null }).promise;
    cont.replaceChildren(c);
    if (tinta) tinta.montar(n, cont, vp.width, vp.height, dpr);
  }

  async function paginar() {
    area.innerHTML = '';
    desenhadas = new Map();
    if (obs) obs.disconnect();
    obs = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) desenhar(+e.target.dataset.n, e.target); }), { root: area, rootMargin: '400px' });
    const p1 = await doc.getPage(1); const vp1 = p1.getViewport({ scale: escala });
    for (let n = 1; n <= doc.numPages; n++) {
      const d = document.createElement('div');
      d.className = 'vp-pagina'; d.dataset.n = n;
      d.style.width = Math.floor(vp1.width) + 'px'; d.style.minHeight = Math.floor(vp1.height) + 'px';
      area.appendChild(d); obs.observe(d);
    }
    rotZoom.textContent = Math.round(escala / base1 * 100) + '%';
  }

  area.addEventListener('scroll', () => {
    if (!doc) return;
    const pags = [...area.children]; const meio = area.scrollTop + area.clientHeight / 3;
    let atual = 1; pags.forEach(p => { if (p.offsetTop <= meio) atual = +p.dataset.n; });
    rotPag.textContent = `Página ${atual} de ${doc.numPages}`;
    paginaAtual = atual;
    if (memoria) try { localStorage.setItem(memoria, atual); } catch (e) {}
  }, { passive: true });

  caixa.querySelectorAll('.vp-btn').forEach(b => b.addEventListener('click', async () => {
    const z = +b.dataset.z;
    if (!b.dataset.z) return;
    const voltar = paginaAtual;
    escala = z === 0 ? await ajustar() : Math.max(base1 * 0.4, Math.min(base1 * 4, escala * (z > 0 ? 1.25 : 0.8)));
    await paginar(); irPara(voltar);
  }));

  const leve = !!caixa.dataset.leve;   // livros grandes: busca só as partes do PDF que estão na tela
  pdfjs.getDocument({ url, cMapUrl: base + 'cmaps/', cMapPacked: true, standardFontDataUrl: base + 'standard_fonts/', isEvalSupported: false, disableAutoFetch: leve, disableStream: leve, rangeChunkSize: 1048576 }).promise
    .then(async d => {
      doc = d; escala = await ajustar(); await paginar();
      rotPag.textContent = `Página 1 de ${doc.numPages}`;
      ir.hidden = false; ir.max = doc.numPages;
      let salva = 0; if (memoria) try { salva = +localStorage.getItem(memoria) || 0; } catch (e) {}
      if (salva > 1 && salva <= doc.numPages) { irPara(salva); rotPag.textContent = `Página ${salva} de ${doc.numPages} · continuando de onde parou`; }
    })
    .catch(() => { area.innerHTML = `<div class="vp-carregando">Não foi possível mostrar o PDF aqui. <a href="${baixar}">Baixe o arquivo</a>.</div>`; });
  ir.addEventListener('keydown', e => { if (e.key === 'Enter') { const n = Math.max(1, Math.min(doc ? doc.numPages : 1, +ir.value || 1)); irPara(n); ir.value = ''; ir.blur(); } });
  caixa.querySelector('.vp-tela').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else if (caixa.requestFullscreen) caixa.requestFullscreen().catch(() => caixa.classList.toggle('tela-cheia')); else caixa.classList.toggle('tela-cheia');
  });
  document.addEventListener('fullscreenchange', async () => { if (!doc) return; const v = paginaAtual; const r = escala / base1; await ajustar(); escala = base1 * r; await paginar(); irPara(v); });

  let t = null;
  addEventListener('resize', () => { clearTimeout(t); t = setTimeout(async () => { if (!doc) return; const r = escala / base1; await ajustar(); escala = base1 * r; paginar(); }, 250); });
}

/* ---- Marcações do professor no PDF (caneta, marca-texto, borracha) ----
 * Os traços ficam guardados em coordenadas 0..1 da página, então continuam no
 * lugar certo com qualquer zoom. Cada professor vê só as próprias marcações. */
function anotacoes(caixa, livro) {
  const api = caixa.dataset.api || 'api.php';
  const csrf = document.querySelector('meta[name=csrf]')?.content || '';
  const paleta = caixa.querySelector('.vp-paleta'), botao = caixa.querySelector('.vp-marcar'), aviso = caixa.querySelector('.vp-salvo');
  const paginas = {};      // n -> [traços]
  const telas = {};        // n -> {c, w, h, dpr}
  const historico = [];    // [{n, antes}]
  const pendentes = new Map();
  let ligado = false, fer = 'p', cor = '#d62828', esp = 2, dedo = true;

  fetch(`${api}?acao=livro_anot&livro=${encodeURIComponent(livro)}`, { credentials: 'same-origin' })
    .then(r => r.json()).then(j => { if (j.ok) { Object.assign(paginas, j.paginas || {}); Object.keys(telas).forEach(n => redesenhar(n)); } }).catch(() => {});

  const marcar = v => { aviso.textContent = v; };
  function salvar(n) {
    clearTimeout(pendentes.get(n)); marcar('Salvando…');
    pendentes.set(n, setTimeout(() => {
      pendentes.delete(n);
      const fd = new FormData(); fd.append('csrf', csrf); fd.append('livro', livro); fd.append('pagina', n); fd.append('dados', JSON.stringify(paginas[n] || []));
      fetch(`${api}?acao=livro_anot_salvar`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
        .then(r => r.json()).then(j => marcar(j.ok ? 'Salvo ✓' : (j.msg || 'Não salvou'))).catch(() => marcar('Sem conexão — tente de novo'));
    }, 800));
  }
  addEventListener('beforeunload', () => { pendentes.forEach((t, n) => { clearTimeout(t); const fd = new FormData(); fd.append('csrf', csrf); fd.append('livro', livro); fd.append('pagina', n); fd.append('dados', JSON.stringify(paginas[n] || [])); navigator.sendBeacon?.(`${api}?acao=livro_anot_salvar`, fd); }); });

  function traco(ctx, t, w, h) {
    if (!t.p.length) return;
    ctx.save();
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.strokeStyle = t.c;
    const larg = t.w * (w / 600);
    if (t.t === 'h') { ctx.globalAlpha = 0.33; ctx.lineWidth = larg * 4; ctx.lineCap = 'butt'; ctx.globalCompositeOperation = 'multiply'; }
    else ctx.lineWidth = larg;
    ctx.beginPath(); ctx.moveTo(t.p[0][0] * w, t.p[0][1] * h);
    if (t.p.length === 1) ctx.lineTo(t.p[0][0] * w + 0.1, t.p[0][1] * h);
    for (let i = 1; i < t.p.length; i++) ctx.lineTo(t.p[i][0] * w, t.p[i][1] * h);
    ctx.stroke(); ctx.restore();
  }
  function redesenhar(n, extra) {
    const tl = telas[n]; if (!tl || !tl.c.isConnected) return;
    const ctx = tl.c.getContext('2d');
    ctx.setTransform(tl.dpr, 0, 0, tl.dpr, 0, 0); ctx.clearRect(0, 0, tl.w, tl.h);
    (paginas[n] || []).forEach(t => traco(ctx, t, tl.w, tl.h));
    if (extra) traco(ctx, extra, tl.w, tl.h);
  }
  function apagarEm(n, x, y) {
    const tl = telas[n], lista = paginas[n]; if (!lista || !lista.length) return false;
    const r = 12 / tl.w;
    const fica = lista.filter(t => !t.p.some(([a, b]) => Math.hypot(a - x, (b - y) * tl.h / tl.w) < r + t.w / 1200 * (t.t === 'h' ? 4 : 1)));
    if (fica.length === lista.length) return false;
    paginas[n] = fica; return true;
  }

  function montarPagina(n, cont, w, h, dpr) {
    const c = document.createElement('canvas');
    c.className = 'vp-tinta';
    c.width = Math.floor(w * dpr); c.height = Math.floor(h * dpr);
    c.style.width = Math.floor(w) + 'px'; c.style.height = Math.floor(h) + 'px';
    cont.appendChild(c);
    telas[n] = { c, w: Math.floor(w), h: Math.floor(h), dpr };
    c.classList.toggle('ligada', ligado); c.classList.toggle('dedo-rola', !dedo);
    redesenhar(n);
    let atual = null, id = null, apagou = false, antes = null;
    const pos = e => { const r = c.getBoundingClientRect(); return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))]; };
    c.addEventListener('pointerdown', e => {
      if (!ligado || id !== null) return;
      if (e.pointerType === 'touch' && !dedo) return;
      if (e.button > 0) return;
      e.preventDefault(); id = e.pointerId; c.setPointerCapture(id);
      antes = JSON.stringify(paginas[n] || []);
      if (fer === 'b') { apagou = apagarEm(n, ...pos(e)); if (apagou) redesenhar(n); }
      else atual = { t: fer, c: cor, w: esp, p: [pos(e)] };
      if (atual) redesenhar(n, atual);
    });
    c.addEventListener('pointermove', e => {
      if (e.pointerId !== id) return;
      e.preventDefault();
      const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      if (fer === 'b') { let m = false; evs.forEach(ev => { if (apagarEm(n, ...pos(ev))) m = true; }); if (m) { apagou = true; redesenhar(n); } return; }
      if (!atual) return;
      evs.forEach(ev => { const q = pos(ev), u = atual.p[atual.p.length - 1]; if (Math.hypot(q[0] - u[0], q[1] - u[1]) > 0.0015) atual.p.push(q.map(v => Math.round(v * 10000) / 10000)); });
      redesenhar(n, atual);
    });
    const fim = e => {
      if (e.pointerId !== id) return;
      id = null;
      if (atual) { (paginas[n] = paginas[n] || []).push(atual); atual = null; historico.push({ n, antes }); redesenhar(n); salvar(n); }
      else if (apagou) { historico.push({ n, antes }); salvar(n); }
      apagou = false;
    };
    c.addEventListener('pointerup', fim); c.addEventListener('pointercancel', fim);
  }

  const atualizarTelas = () => Object.values(telas).forEach(t => { t.c.classList.toggle('ligada', ligado); t.c.classList.toggle('dedo-rola', !dedo); });
  botao.addEventListener('click', () => {
    ligado = !ligado; paleta.hidden = !ligado;
    botao.classList.toggle('ativo', ligado); caixa.classList.toggle('vp-anotando', ligado);
    atualizarTelas();
  });
  paleta.querySelectorAll('.vp-fer').forEach(b => b.addEventListener('click', () => { fer = b.dataset.f; paleta.querySelectorAll('.vp-fer').forEach(x => x.classList.toggle('ativo', x === b)); }));
  paleta.querySelectorAll('.vp-cor').forEach(b => b.addEventListener('click', () => { cor = b.dataset.c; paleta.querySelectorAll('.vp-cor').forEach(x => x.classList.toggle('ativo', x === b)); if (fer === 'b') paleta.querySelector('.vp-fer[data-f=p]').click(); }));
  paleta.querySelectorAll('.vp-esp').forEach(b => b.addEventListener('click', () => { esp = +b.dataset.w; paleta.querySelectorAll('.vp-esp').forEach(x => x.classList.toggle('ativo', x === b)); }));
  paleta.querySelector('.vp-dedo input').addEventListener('change', e => { dedo = e.target.checked; atualizarTelas(); });
  paleta.querySelector('.vp-desf').addEventListener('click', () => {
    const h = historico.pop(); if (!h) { marcar('Nada para desfazer'); return; }
    paginas[h.n] = JSON.parse(h.antes); redesenhar(h.n); salvar(h.n);
  });
  paleta.querySelector('.vp-limpar').addEventListener('click', () => {
    const n = caixa._vpPagina ? caixa._vpPagina() : 1;
    if (!(paginas[n] || []).length) { marcar(`A página ${n} não tem marcações`); return; }
    if (!confirm(`Apagar todas as suas marcações da página ${n}?`)) return;
    historico.push({ n, antes: JSON.stringify(paginas[n]) });
    paginas[n] = []; redesenhar(n); salvar(n);
  });
  return { montar: montarPagina };
}

window.vpMontar = montar;
document.querySelectorAll('.visualizador-pdf:not([data-manual])').forEach(montar);
