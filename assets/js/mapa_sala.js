/* Mapa de turma (v65): visualização + edição em 2 passos
 *  1) Montar a sala: formatos prontos (fileiras, duplas, U, grupos) + ajuste fino carteira por carteira
 *  2) Colocar os alunos: toca no aluno e depois na carteira (segue para o próximo), arrasta, troca de lugar
 * Cadeira = "f-p": f = fila (coluna, da esquerda p/ direita), p = posição na fila (da frente p/ o fundo). */
(function () {
  const M = window.MAPA || {};
  const A = M.A || {}, MAX = M.MAX || 14, EDIT = !!M.EDIT, OC = !!M.OC;
  let cad = new Set(M.cad || []), alu = M.alu || {}, mesa = M.mesa || 'centro';
  if (Array.isArray(alu)) alu = {};
  let modo = 'ver', sel = null, novo = null, mudou = false, formato = null;
  const hist = [];
  const $ = id => document.getElementById(id);
  const grade = $('grade'), sem = $('semLugar'), area = $('formMapa'), pop = $('pop');
  const toast = $('toast');
  const chave = (f, p) => f + '-' + p;
  const fp = k => k.split('-').map(Number);
  const NA = Object.keys(A).length;
  const esc = window.esc || (s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])));
  const guardar = () => { hist.push(JSON.stringify({ c: [...cad], a: alu, m: mesa })); if (hist.length > 60) hist.shift(); mudou = true; };

  /* filas e linhas que têm carteira (as vazias viram corredor) */
  function usados() {
    const F = new Set(), P = new Set();
    cad.forEach(k => { const [f, p] = fp(k); F.add(f); P.add(p); });
    return [F, P];
  }
  function nomeLugar(k) {
    const [f, p] = fp(k), [F, P] = usados();
    const nf = [...F].filter(x => x <= f).length || f + 1, np = [...P].filter(x => x <= p).length || p + 1;
    return 'F' + nf + '·' + np;
  }
  function tamanho() {
    let F = 0, P = 0; cad.forEach(k => { const [f, p] = fp(k); F = Math.max(F, f + 1); P = Math.max(P, p + 1); });
    if (modo === 'montar') { F = Math.min(MAX, Math.max(F + 1, 4)); P = Math.min(MAX, Math.max(P + 1, 4)); }
    return [Math.max(F, 1), Math.max(P, 1)];
  }
  const naFila = f => { let n = 0; cad.forEach(k => { if (fp(k)[0] === f) n++; }); return n; };
  const ficha = (id, curto, k) => `<div class="ms-aluno${sel && +sel.id === +id ? ' sel' : ''}" draggable="${EDIT && modo === 'alunos'}" data-id="${id}"${k ? ` data-de="${k}"` : ''}>${A[id].av}<span>${esc(curto ? A[id].curto : A[id].nome)}</span><b>${String(A[id].n).padStart(2, '0')}</b></div>`;

  /* ---------- desenho ---------- */
  function desenhar() {
    for (const k in alu) if (!cad.has(k) || !A[alu[k]]) delete alu[k];
    const [F, P] = tamanho();
    const cols = [], larg = [];
    for (let f = 0; f < F; f++) { const vazia = !naFila(f), m = modo === 'montar'; cols.push(!vazia ? 'minmax(96px, 1fr)' : (m ? 'minmax(46px, .4fr)' : '22px')); larg.push(!vazia ? 108 : (m ? 56 : 30)); }
    grade.style.setProperty('--cols', F);
    grade.style.gridTemplateColumns = cols.join(' ');
    grade.style.minWidth = larg.reduce((a, b) => a + b, 0) + 'px';
    let h = '', nf = 0;
    for (let f = 0; f < F; f++) {
      const n = naFila(f);
      if (n) nf++;
      if (modo === 'montar') h += `<div class="ms-rot"><span>${n ? 'Fila ' + nf : ''}</span><span class="ms-rot-bt"><button type="button" data-fila="${f}" data-d="-1" aria-label="Tirar a última carteira desta fila">−</button><em>${n}</em><button type="button" data-fila="${f}" data-d="1" aria-label="Pôr carteira no fim desta fila">+</button></span></div>`;
      else h += n ? `<div class="ms-rot"><span>Fila ${nf}</span><em>${n}</em></div>` : '<div class="ms-rot ms-corredor"></div>';
    }
    for (let p = 0; p < P; p++) for (let f = 0; f < F; f++) {
      const k = chave(f, p), tem = cad.has(k), id = alu[k];
      if (tem) h += `<div class="ms-lugar cadeira${id ? ' ocupado' : ''}${novo === k ? ' novo' : ''}${sel && sel.de === k ? ' origem' : ''}" data-k="${k}">${id ? ficha(id, true, k) : `<span class="ms-vazio">${nomeLugar(k)}</span>`}${modo === 'montar' ? `<button type="button" class="ms-tirar" data-k="${k}" aria-label="Tirar carteira">×</button>` : ''}</div>`;
      else h += modo === 'montar' ? `<button type="button" class="ms-lugar ms-mais" data-k="${k}" aria-label="Pôr carteira aqui"><i class="bi bi-plus-lg"></i></button>` : '<div class="ms-lugar ms-nada"></div>';
    }
    grade.innerHTML = h; novo = null;
    grade.classList.toggle('escolhendo', !!sel);
    document.documentElement.dataset.msModo = modo;
    document.documentElement.dataset.msMudou = mudou ? '1' : '0';
    $('frente').className = 'ms-frente mesa-' + mesa;
    $('infoCad').textContent = cad.size;
    const ocup = new Set(Object.values(alu).map(Number));
    const livres = Object.keys(A).filter(id => !ocup.has(+id)).sort((a, b) => A[a].n - A[b].n);
    if (EDIT) {
      const q = ($('buscaSem').value || '').trim().toLowerCase();
      sem.innerHTML = livres.filter(id => !q || A[id].nome.toLowerCase().includes(q)).map(id => ficha(id, false)).join('')
        || `<div class="vazio py-3"><i class="bi bi-${livres.length ? 'search' : 'check2-all'}"></i>${livres.length ? 'Ninguém com esse nome' : 'Todos os alunos têm lugar'}</div>`;
      $('contSem').textContent = livres.length;
      $('rCad').textContent = cad.size; $('rSent').textContent = ocup.size; $('rSem').textContent = livres.length;
      $('pSent').textContent = ocup.size; $('pTot').textContent = NA;
      $('pBarra').style.width = (NA ? Math.round(ocup.size / NA * 100) : 0) + '%';
      area.dataset.modo = modo;
      document.querySelectorAll('.ms2-passos [data-ir]').forEach(b => {
        b.classList.toggle('ativo', b.dataset.ir === modo);
        b.classList.toggle('feito', (b.dataset.ir === 'montar' && cad.size > 0 && modo !== 'montar') || (b.dataset.ir === 'alunos' && NA > 0 && !livres.length && modo !== 'alunos'));
      });
      document.querySelectorAll('[data-mesa]').forEach(b => b.classList.toggle('ativo', b.dataset.mesa === mesa));
      document.querySelectorAll('[data-formato]').forEach(b => { b.classList.toggle('ativo', b.dataset.formato === formato); b.setAttribute('aria-checked', b.dataset.formato === formato); });
      $('btDesfazer').disabled = !hist.length; $('mudou').hidden = !mudou;
      const sp = document.querySelector('.ms-salvar'); if (sp) sp.classList.toggle('pulsar', mudou);
      mostrarToast();
    }
    ligar();
  }

  /* ---------- aviso "escolha a carteira" ---------- */
  function mostrarToast() {
    if (!toast) return;
    if (modo !== 'alunos' || !sel || !A[sel.id]) { toast.hidden = true; return; }
    $('toastTx').innerHTML = sel.de
      ? `<i class="bi bi-arrows-move"></i>Toque na carteira para onde <b>${esc(A[sel.id].curto)}</b> vai`
      : `<i class="bi bi-hand-index-thumb"></i>Toque na carteira de <b>${esc(A[sel.id].curto)}</b>`;
    $('toastTirar').hidden = !sel.de;
    toast.hidden = false;
  }
  function escolher(id, de) { sel = id ? { id: +id, de: de || null } : null; pop.hidden = true; desenhar(); }

  /* coloca o aluno; se a carteira tinha alguém: troca com a de origem ou devolve para a lista */
  function colocar(id, k) {
    if (!cad.has(k) || !A[id]) return;
    guardar();
    let velho = null;
    for (const x in alu) if (+alu[x] === +id) { velho = x; delete alu[x]; }
    const antes = alu[k]; alu[k] = +id;
    if (antes && +antes !== +id && velho) alu[velho] = +antes;
    novo = k;
    const vinhaDaLista = sel && !sel.de;
    sel = null;
    if (vinhaDaLista && $('seguir') && $('seguir').checked) {
      const ocup = new Set(Object.values(alu).map(Number));
      const prox = Object.keys(A).filter(i => !ocup.has(+i)).sort((a, b) => A[a].n - A[b].n)
        .find(i => A[i].n > A[id].n) || Object.keys(A).filter(i => !ocup.has(+i)).sort((a, b) => A[a].n - A[b].n)[0];
      if (prox && [...cad].some(c => !alu[c])) sel = { id: +prox, de: null };
    }
    desenhar();
  }
  function tirar(k) { if (!alu[k]) return; guardar(); delete alu[k]; sel = null; desenhar(); }

  function abrirPop(el, id, k) {
    const r = el.getBoundingClientRect();
    pop.innerHTML = `<div class="ms-pop-cab">${A[id].av}<div><strong>${esc(A[id].nome)}</strong><small>nº ${String(A[id].n).padStart(2, '0')} · carteira ${nomeLugar(k)}</small></div></div>
      <a href="aluno.php?id=${id}"><i class="bi bi-person-vcard"></i>Ficha do aluno</a>` + (OC ? `<a class="oc" href="ocorrencias.php?nova=fora_mapa&aluno=${id}&lugar=${encodeURIComponent(nomeLugar(k))}#nova"><i class="bi bi-exclamation-diamond"></i>Registrar: fora do mapa</a>` : '');
    pop.hidden = false;
    const w = pop.offsetWidth, x = Math.min(window.innerWidth - w - 12, Math.max(12, r.left + r.width / 2 - w / 2));
    pop.style.left = x + 'px'; pop.style.top = (r.bottom + window.scrollY + 8) + 'px';
  }
  document.addEventListener('click', e => { if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('.ms-aluno')) pop.hidden = true; });

  /* ---------- eventos da sala ---------- */
  function ligar() {
    if (modo === 'ver') {
      grade.querySelectorAll('.ms-aluno').forEach(el => el.onclick = e => { e.stopPropagation(); abrirPop(el, el.dataset.id, el.closest('.ms-lugar').dataset.k); });
      return;
    }
    if (modo === 'montar') {
      grade.querySelectorAll('.ms-mais').forEach(b => b.onclick = () => { guardar(); formato = null; cad.add(b.dataset.k); novo = b.dataset.k; desenhar(); });
      grade.querySelectorAll('.ms-tirar').forEach(b => b.onclick = e => { e.stopPropagation(); guardar(); formato = null; cad.delete(b.dataset.k); delete alu[b.dataset.k]; desenhar(); });
      grade.querySelectorAll('[data-fila]').forEach(b => b.onclick = () => {
        const f = +b.dataset.fila; guardar(); formato = null;
        if (+b.dataset.d > 0) { let p = 0; while (cad.has(chave(f, p)) && p < MAX) p++; if (p < MAX) { cad.add(chave(f, p)); novo = chave(f, p); } }
        else { let ult = null; cad.forEach(k => { const [ff, pp] = fp(k); if (ff === f && (!ult || pp > fp(ult)[1])) ult = k; }); if (ult) { cad.delete(ult); delete alu[ult]; } }
        desenhar();
      });
      return;
    }
    /* colocar os alunos */
    document.querySelectorAll('.ms-area .ms-aluno').forEach(el => {
      el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', el.dataset.id + '|' + (el.dataset.de || '')); el.classList.add('arrastando'); grade.classList.add('arrastando'); sem.classList.add('alvo'); });
      el.addEventListener('dragend', () => { grade.classList.remove('arrastando'); sem.classList.remove('alvo'); });
      el.addEventListener('click', e => {
        e.stopPropagation();
        const lugar = el.closest('.ms-lugar');
        if (lugar) {                                            // aluno já sentado
          if (sel && +sel.id !== +el.dataset.id) { colocar(sel.id, lugar.dataset.k); return; }   // troca
          if (sel && +sel.id === +el.dataset.id) { escolher(null); return; }
          escolher(el.dataset.id, lugar.dataset.k); return;
        }
        escolher(sel && +sel.id === +el.dataset.id ? null : el.dataset.id, null);   // aluno da lista
      });
    });
    grade.querySelectorAll('.cadeira').forEach(l => {
      l.addEventListener('dragover', e => { e.preventDefault(); l.classList.add('sobre'); });
      l.addEventListener('dragleave', () => l.classList.remove('sobre'));
      l.addEventListener('drop', e => { e.preventDefault(); const [id, de] = e.dataTransfer.getData('text/plain').split('|'); sel = { id: +id, de: de || null }; colocar(id, l.dataset.k); });
      l.addEventListener('click', () => { if (sel) colocar(sel.id, l.dataset.k); });
    });
  }

  /* ---------- formatos prontos ---------- */
  const PADRAO = { fileiras: 6, duplas: 3, u: 8, grupos: 3 };
  const LIMITE = { fileiras: [1, MAX], duplas: [1, 5], u: [4, MAX], grupos: [1, 5] };
  const ROT = { fileiras: 'Filas', duplas: 'Duplas lado a lado', u: 'Largura do U', grupos: 'Grupos lado a lado' };
  function gerar(fmt, n, c) {
    const out = [], add = (f, p) => { if (f < MAX && p < MAX && out.length < n && !out.includes(chave(f, p))) out.push(chave(f, p)); };
    if (fmt === 'fileiras') { for (let p = 0; p < MAX && out.length < n; p++) for (let f = 0; f < c; f++) add(f, p); }
    else if (fmt === 'duplas') { for (let p = 0; p < MAX && out.length < n; p++) for (let d = 0; d < c; d++) { add(d * 3, p); add(d * 3 + 1, p); } }
    else if (fmt === 'grupos') { for (let b = 0; b * 3 < MAX && out.length < n; b++) for (let g = 0; g < c; g++) { add(g * 3, b * 3); add(g * 3 + 1, b * 3); add(g * 3, b * 3 + 1); add(g * 3 + 1, b * 3 + 1); } }
    else if (fmt === 'u') {
      for (let anel = 0; out.length < n && c - anel * 4 >= 3; anel++) {
        const e = anel * 2, d = c - 1 - anel * 2, resto = n - out.length, base = Math.max(0, d - e - 1);
        let L = Math.max(2, Math.ceil((resto - base) / 2)); L = Math.min(L, MAX - e);
        for (let p = 0; p < L; p++) add(e, p);                         // lado esquerdo, da frente para o fundo
        for (let f = e + 1; f < d; f++) add(f, L - 1);                    // fundo
        for (let p = L - 1; p >= 0; p--) add(d, p);                       // lado direito, do fundo para a frente
        if (L < 4) break;
      }
    }
    return out;
  }
  function capacidade(fmt, c) { return gerar(fmt, 999, c).length; }
  function aplicar() {
    if (!formato) return;
    const c = +$('mCol').value, n = Math.min(+$('mTotal').value, capacidade(formato, c));
    const novas = gerar(formato, n, c);
    const ordem = k => { const [f, p] = fp(k); return p * 100 + f; };
    const sentados = Object.keys(alu).sort((a, b) => ordem(a) - ordem(b)).map(k => alu[k]);
    guardar();
    cad = new Set(novas); alu = {};
    novas.forEach((k, i) => { if (i < sentados.length) alu[k] = +sentados[i]; });
    contar(); desenhar();
  }
  function contar() {
    if (!$('mConta')) return;
    const c = +$('mCol').value, t = +$('mTotal').value, cap = formato ? capacidade(formato, c) : MAX * MAX;
    $('mConta').innerHTML = `A turma tem <b>${NA}</b> alunos` + (t < NA ? ` · <span class="txt-ruim">faltam ${NA - t} carteiras</span>` : '') + (formato && t > cap ? ` · <span class="txt-ruim">cabem só ${cap} neste formato</span>` : '');
    $('rotCol').textContent = ROT[formato || 'fileiras'];
  }

  /* ---------- controles ---------- */
  if (EDIT) {
    const ir = m => { modo = m; sel = null; pop.hidden = true; desenhar(); };
    document.querySelectorAll('[data-ir]').forEach(b => b.addEventListener('click', () => ir(b.dataset.ir)));
    document.querySelectorAll('[data-mesa]').forEach(b => b.onclick = () => { guardar(); mesa = b.dataset.mesa; desenhar(); });
    $('mTotal').value = cad.size || NA || 30; $('mCol').value = PADRAO.fileiras;
    document.querySelectorAll('[data-formato]').forEach(b => b.onclick = () => {
      formato = b.dataset.formato; $('mCol').value = PADRAO[formato];
      if (+$('mTotal').value < NA) $('mTotal').value = Math.max(NA, cad.size) || 30;
      aplicar();
    });
    document.querySelectorAll('[data-step]').forEach(b => b.onclick = () => {
      const o = $(b.dataset.step), fmt = formato || 'fileiras';
      const [mi, ma] = b.dataset.step === 'mCol' ? LIMITE[fmt] : [1, MAX * MAX];
      o.value = Math.max(mi, Math.min(ma, +o.value + +b.dataset.d));
      if (!formato) formato = 'fileiras';
      aplicar();
    });
    contar();
    $('btLimparSala').onclick = () => { if (!cad.size) return; guardar(); cad = new Set(); alu = {}; formato = null; desenhar(); };
    $('btDesfazer').onclick = () => { const s = hist.pop(); if (!s) return; const o = JSON.parse(s); cad = new Set(o.c); alu = o.a; mesa = o.m; sel = null; desenhar(); };
    const ordemCad = () => [...cad].sort((a, b) => { const [fa, pa] = fp(a), [fb, pb] = fp(b); return pa - pb || fa - fb; });
    const encher = ids => { if (!cad.size) { ir('montar'); return; } guardar(); alu = {}; sel = null; ordemCad().forEach((k, i) => { if (i < ids.length) alu[k] = +ids[i]; }); desenhar(); };
    $('btAlfa').onclick = () => encher(Object.keys(A).sort((a, b) => A[a].n - A[b].n));
    $('btSortear').onclick = () => encher(Object.keys(A).sort(() => Math.random() - .5));
    $('btCompletar').onclick = () => {
      const ocup = new Set(Object.values(alu).map(Number));
      const livres = Object.keys(A).filter(i => !ocup.has(+i)).sort((a, b) => A[a].n - A[b].n);
      const vazias = ordemCad().filter(k => !alu[k]);
      if (!livres.length || !vazias.length) return;
      guardar(); sel = null; vazias.forEach((k, i) => { if (i < livres.length) alu[k] = +livres[i]; }); desenhar();
    };
    $('btLimpar').onclick = () => { if (!Object.keys(alu).length) return; guardar(); alu = {}; sel = null; desenhar(); };
    $('buscaSem').addEventListener('input', desenhar);
    $('toastX').onclick = () => escolher(null);
    $('toastTirar').onclick = () => { if (sel && sel.de) tirar(sel.de); };
    sem.addEventListener('dragover', e => e.preventDefault());
    sem.addEventListener('drop', e => { e.preventDefault(); sem.classList.remove('alvo'); const [, de] = e.dataTransfer.getData('text/plain').split('|'); if (de) tirar(de); });
    area.addEventListener('submit', () => { mudou = false; $('mapaJson').value = JSON.stringify({ cadeiras: [...cad], alunos: alu, mesa }); });
    window.addEventListener('beforeunload', e => { if (mudou) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && sel) { escolher(null); return; }
      if ((e.ctrlKey || e.metaKey) && e.key === 'z' && hist.length && !/input|textarea/i.test(e.target.tagName)) { e.preventDefault(); $('btDesfazer').click(); }
    });
    if (!cad.size) modo = 'montar';
  }
  window.addEventListener('beforeprint', () => { if (modo !== 'ver') { modo = 'ver'; sel = null; desenhar(); } });
  desenhar();
})();
