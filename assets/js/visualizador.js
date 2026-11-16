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
      <button type="button" class="vp-btn vp-tela" aria-label="Tela cheia" title="Tela cheia"><i class="bi bi-fullscreen"></i></button>
    </div>
    <div class="vp-paginas" tabindex="0"><div class="vp-carregando"><span class="spinner-border spinner-border-sm"></span> Abrindo o PDF…</div></div>`;
  const area = caixa.querySelector('.vp-paginas'), rotZoom = caixa.querySelector('.vp-zoom'), rotPag = caixa.querySelector('.vp-pag'), ir = caixa.querySelector('.vp-ir');
  const memoria = caixa.dataset.memoria ? 'pdf_pag_' + caixa.dataset.memoria : null;
  const baixar = caixa.dataset.baixar || (url.includes('inline=1') ? url.replace('inline=1', 'baixar=1') : url + (url.includes('?') ? '&' : '?') + 'baixar=1');
  let paginaAtual = 1;
  const irPara = n => { const alvo = area.querySelector(`.vp-pagina[data-n="${n}"]`); if (alvo) area.scrollTop = alvo.offsetTop - 8; };
  let doc = null, escala = 0, base1 = 1, desenhadas = new Map(), obs = null;

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

window.vpMontar = montar;
document.querySelectorAll('.visualizador-pdf:not([data-manual])').forEach(montar);
