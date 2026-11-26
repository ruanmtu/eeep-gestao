/* Página inicial (galeria da escola), álbum e informes: tudo pensado para o toque no celular */
(function () {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduz = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const deslizar = (el, fn) => { let x0 = null, y0 = 0;
    el.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
    el.addEventListener('touchend', e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) fn(dx < 0 ? 1 : -1); x0 = null; }); };

  /* Capa: fotos trocando (pausa ao tocar) */
  const capa = $('#capaFotos');
  if (capa) {
    const fotos = $$('.sg-capa-foto', capa), pontos = $$('.sg-capa-pontos button'); let at = 0, t = null;
    const ir = i => { at = (i + fotos.length) % fotos.length; fotos.forEach((f, k) => { f.classList.toggle('ativo', k === at); f.setAttribute('aria-hidden', k === at ? 'false' : 'true'); }); pontos.forEach((p, k) => p.classList.toggle('ativo', k === at)); };
    const ligar = () => { clearInterval(t); if (!reduz && fotos.length > 1) t = setInterval(() => { if (!document.hidden) ir(at + 1); }, 5500); };
    pontos.forEach((p, k) => p.addEventListener('click', () => { ir(k); ligar(); }));
    deslizar($('.sg-capa'), d => { ir(at + d); ligar(); });
    ligar();
  }

  /* Abas das seções: marca a seção visível e mantém a aba à vista */
  const abas = $('#abas');
  if (abas && 'IntersectionObserver' in window) {
    const links = $$('a', abas), mapa = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
    const obs = new IntersectionObserver(ents => ents.forEach(en => { if (!en.isIntersecting) return; const a = mapa.get(en.target.id); if (!a) return;
      links.forEach(l => l.classList.toggle('ativo', l === a)); const tr = $('.sg-abas-trilha', abas);
      tr.scrollTo({ left: a.offsetLeft - tr.clientWidth / 2 + a.clientWidth / 2, behavior: reduz ? 'auto' : 'smooth' }); }), { rootMargin: '-40% 0px -55% 0px' });
    mapa.forEach((a, id) => { const s = document.getElementById(id); s && obs.observe(s); });
    const fixa = new IntersectionObserver(([en]) => abas.classList.toggle('fixa', !en.isIntersecting && en.boundingClientRect.top < 0));
    const capaSec = $('.sg-capa'); capaSec && fixa.observe(capaSec);
  }

  /* História: ver tudo */
  const tm = $('#tempoMais');
  if (tm) tm.addEventListener('click', () => { const ab = $('#linhaTempo').classList.toggle('aberta'); tm.classList.toggle('aberto', ab); tm.querySelector('span').textContent = ab ? 'Mostrar menos' : 'Ver a história completa'; if (!ab) $('#historia').scrollIntoView({ behavior: 'smooth' }); });

  /* Mapa só carrega quando a pessoa abrir */
  $$('.sg-mapa').forEach(d => d.addEventListener('toggle', () => { const f = $('iframe', d); if (d.open && f && !f.src) f.src = f.dataset.src; }));

  /* Álbum: foto em tela cheia, com deslizar, contador e baixar */
  const mos = $('#mosaico'), luz = $('#luz');
  if (mos && luz) {
    const itens = $$('.sg-mf', mos), img = $('#luzImg'), leg = $('#luzLeg'), cont = $('#luzCont'), baixar = $('#luzBaixar'); let at = 0, volta = null;
    const mostrar = i => { at = (i + itens.length) % itens.length; const b = itens[at]; img.src = b.dataset.src; leg.textContent = b.dataset.leg || ''; leg.hidden = !b.dataset.leg;
      cont.textContent = (at + 1) + ' / ' + itens.length; baixar.href = b.dataset.src; new Image().src = itens[(at + 1) % itens.length].dataset.src; };
    const abrir = i => { volta = document.activeElement; mostrar(i); luz.hidden = false; document.body.style.overflow = 'hidden'; history.pushState({ luz: 1 }, ''); };
    const fechar = (doHist = true) => { if (luz.hidden) return; luz.hidden = true; document.body.style.overflow = ''; volta && volta.focus(); if (doHist && history.state && history.state.luz) history.back(); };
    itens.forEach((b, k) => b.addEventListener('click', () => abrir(k)));
    luz.addEventListener('click', e => { const b = e.target.closest('[data-luz]'); if (b) { b.dataset.luz === 'fechar' ? fechar() : mostrar(at + Number(b.dataset.luz)); } else if (e.target === luz || e.target.tagName === 'FIGURE') fechar(); });
    deslizar(luz, d => mostrar(at + d));
    addEventListener('popstate', () => fechar(false));
    document.addEventListener('keydown', e => { if (luz.hidden) return; if (e.key === 'Escape') fechar(); if (e.key === 'ArrowRight') mostrar(at + 1); if (e.key === 'ArrowLeft') mostrar(at - 1); });
  }

  /* Compartilhar (WhatsApp, etc.) */
  const comp = $('#compartilhar');
  if (comp) comp.addEventListener('click', async () => {
    const dados = { title: document.title, url: location.href };
    if (navigator.share) { try { await navigator.share(dados); } catch (e) {} return; }
    try { await navigator.clipboard.writeText(location.href); comp.innerHTML = '<i class="bi bi-check2"></i>Link copiado'; } catch (e) { location.href = 'https://wa.me/?text=' + encodeURIComponent(document.title + ' ' + location.href); }
  });

  /* Galeria: filtro por ano */
  $$('.sg-filtro-anos [data-ano]').forEach(b => b.addEventListener('click', () => {
    $$('.sg-filtro-anos [data-ano]').forEach(x => x.classList.toggle('ativo', x === b));
    $$('#todosAlbuns .sg-album').forEach(a => a.hidden = !!b.dataset.ano && a.dataset.ano !== b.dataset.ano);
  }));
})();

/* Banners do topo: deslizar, pontos, setas e troca automática */
(function () {
  const tr = document.getElementById('bnTrilha'); if (!tr) return;
  const itens = [...tr.children], pontos = [...document.querySelectorAll('#bnPontos button')];
  if (itens.length < 2) return;
  const reduz = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const passo = () => itens[1].offsetLeft - itens[0].offsetLeft;
  const atual = () => Math.round(tr.scrollLeft / passo());
  const maxIdx = () => Math.max(0, Math.round((tr.scrollWidth - tr.clientWidth) / passo()));
  const ir = i => { const m = maxIdx(); i = i > m ? 0 : (i < 0 ? m : i); tr.scrollTo({ left: i * passo(), behavior: reduz ? 'auto' : 'smooth' }); };
  const marcar = () => { const a = Math.min(atual(), itens.length - 1); pontos.forEach((p, k) => p.classList.toggle('ativo', k === a)); };
  let t = null, parado = false;
  const ligar = () => { clearInterval(t); if (!reduz && !parado) t = setInterval(() => { if (!document.hidden) ir(atual() + 1); }, 6000); };
  tr.addEventListener('scroll', () => { clearTimeout(tr._m); tr._m = setTimeout(marcar, 60); }, { passive: true });
  pontos.forEach((p, k) => p.addEventListener('click', () => { ir(k); ligar(); }));
  document.querySelectorAll('[data-bn]').forEach(b => b.addEventListener('click', () => { ir(atual() + Number(b.dataset.bn)); ligar(); }));
  ['touchstart', 'mouseenter', 'focusin'].forEach(ev => tr.addEventListener(ev, () => { parado = true; clearInterval(t); }, { passive: true }));
  ['mouseleave', 'focusout'].forEach(ev => tr.addEventListener(ev, () => { parado = false; ligar(); }));
  tr.addEventListener('touchend', () => { setTimeout(() => { parado = false; ligar(); }, 4000); }, { passive: true });
  tr.addEventListener('keydown', e => { if (e.key === 'ArrowRight') { ir(atual() + 1); e.preventDefault(); } if (e.key === 'ArrowLeft') { ir(atual() - 1); e.preventDefault(); } });
  const ajustar = () => { const m = maxIdx(); pontos.forEach((p, k) => { p.hidden = k > m; }); marcar(); };
  addEventListener('resize', ajustar); ajustar();
  ligar();
})();

/* Calendário da agenda (página inicial e notícias) */
(function () {
  const dadosEl = document.getElementById('agDados'), grade = document.getElementById('agGrade'); if (!dadosEl || !grade) return;
  const D = JSON.parse(dadosEl.textContent), mesEl = document.getElementById('agMes'), diaEl = document.getElementById('agDia');
  const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const iso = (y, m, d) => y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  const doDia = dia => D.itens.filter(e => e.d <= dia && (e.f || e.d) >= dia);
  let [ano, mes] = D.hoje.split('-').map(Number); mes -= 1; let sel = null;
  const proximoComEvento = () => { const e = D.itens.find(x => (x.f || x.d) >= D.hoje); return e ? e.d.slice(0, 7) : null; };
  const mostrarDia = dia => {
    sel = dia; const evs = doDia(dia);
    diaEl.innerHTML = evs.map(e => { const t = D.tipos[e.k] || D.tipos.evento; const nome = e.l ? '<a href="' + esc(e.l) + '">' + esc(e.t) + '</a>' : esc(e.t);
      return '<div style="--c:' + t.c + '"><i></i><span>' + nome + '<small>' + esc(t.n) + '</small></span></div>'; }).join('');
    desenhar();
  };
  const desenhar = () => {
    mesEl.textContent = MESES[mes] + ' ' + ano;
    const prim = new Date(ano, mes, 1).getDay(), dias = new Date(ano, mes + 1, 0).getDate();
    let h = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map(s => '<span class="ag-sem">' + s + '</span>').join('');
    for (let i = 0; i < prim; i++) h += '<span class="ag-d fora"></span>';
    for (let d = 1; d <= dias; d++) {
      const dia = iso(ano, mes, d), evs = doDia(dia), sem = (prim + d - 1) % 7;
      const cls = ['ag-d', evs.length ? 'tem' : '', dia === D.hoje ? 'hoje' : '', dia === sel ? 'sel' : '', sem === 0 || sem === 6 ? 'fds' : ''].filter(Boolean).join(' ');
      const pts = evs.slice(0, 3).map(e => '<i style="--c:' + (D.tipos[e.k] || D.tipos.evento).c + '"></i>').join('');
      h += evs.length ? '<button type="button" class="' + cls + '" data-dia="' + dia + '" aria-label="' + d + ' de ' + MESES[mes] + ': ' + evs.length + ' evento(s)">' + d + '<span class="ag-pts">' + pts + '</span></button>'
                      : '<span class="' + cls + '">' + d + '<span class="ag-pts"></span></span>';
    }
    grade.innerHTML = h;
  };
  grade.addEventListener('click', e => { const b = e.target.closest('[data-dia]'); if (b) mostrarDia(b.dataset.dia === sel ? null : b.dataset.dia); if (b && sel === null) { diaEl.innerHTML = ''; desenhar(); } });
  document.querySelectorAll('[data-ag]').forEach(b => b.addEventListener('click', () => { mes += Number(b.dataset.ag); if (mes < 0) { mes = 11; ano--; } if (mes > 11) { mes = 0; ano++; } sel = null; diaEl.innerHTML = ''; desenhar(); }));
  /* abre no mês de hoje; se não houver nada neste mês, no próximo mês com evento */
  const temNoMes = D.itens.some(e => e.d.slice(0, 7) === D.hoje.slice(0, 7) || (e.f || e.d).slice(0, 7) === D.hoje.slice(0, 7));
  if (!temNoMes) { const p = proximoComEvento(); if (p) { ano = +p.slice(0, 4); mes = +p.slice(5, 7) - 1; } }
  desenhar();
})();

/* Mais notícias: páginas */
(function () {
  const lista = document.getElementById('nbLista'), nav = document.getElementById('nbPaginas'); if (!lista || !nav) return;
  const itens = [...lista.children]; let pag = 0;
  const porPag = () => innerWidth >= 992 ? 12 : innerWidth >= 768 ? 8 : 6;
  const desenhar = rolar => {
    const pp = porPag(), total = Math.max(1, Math.ceil(itens.length / pp)); pag = Math.min(pag, total - 1);
    itens.forEach((el, k) => { el.hidden = k < pag * pp || k >= (pag + 1) * pp; });
    nav.innerHTML = total < 2 ? '' : '<button type="button" data-p="' + (pag - 1) + '"' + (pag ? '' : ' disabled') + ' aria-label="Anterior"><i class="bi bi-chevron-left"></i></button>'
      + Array.from({ length: total }, (_, k) => '<button type="button" data-p="' + k + '" class="' + (k === pag ? 'ativo' : '') + '">' + (k + 1) + '</button>').join('')
      + '<button type="button" data-p="' + (pag + 1) + '"' + (pag < total - 1 ? '' : ' disabled') + ' aria-label="Próxima"><i class="bi bi-chevron-right"></i></button>';
    if (rolar) lista.closest('.nt-mais').scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  nav.addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (b && !b.disabled) { pag = +b.dataset.p; desenhar(true); } });
  let t; addEventListener('resize', () => { clearTimeout(t); t = setTimeout(() => desenhar(false), 150); });
  desenhar(false);
})();

/* Atalho "Eventos": no computador vai para a grade de próximos eventos */
document.querySelectorAll('a[href="#agenda"]').forEach(a => a.addEventListener('click', e => {
  const g = document.getElementById('agendaGrade');
  if (g && innerWidth >= 992) { e.preventDefault(); g.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}));

/* v84 · Banner "Alunos destaque": anima toda vez que aparece e conta a média de 0 até a nota */
(function () {
  const bns = document.querySelectorAll('.sg-bn-dq2, .sg-bn-ia'); if (!bns.length || !('IntersectionObserver' in window)) return;
  const reduz = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const contar = el => {
    const alvo = parseFloat(String(el.dataset.nota).replace(',', '.')); if (isNaN(alvo)) return;
    const casas = (String(el.dataset.nota).split(/[.,]/)[1] || '').length;
    const fmt = v => v.toFixed(casas).replace('.', ',');
    clearInterval(el._t); const k = Number(getComputedStyle(el.closest('.dq2-al')).getPropertyValue('--k')) || 0;
    const ini = performance.now() + 1100 + k * 280, dur = 1300;
    el.textContent = fmt(0);
    const passo = t => { const p = Math.min(1, Math.max(0, (t - ini) / dur)); el.textContent = fmt(alvo * (1 - Math.pow(1 - p, 3))); if (p < 1) el._r = requestAnimationFrame(passo); else el.textContent = el.dataset.nota; };
    cancelAnimationFrame(el._r); el._r = requestAnimationFrame(passo);
  };
  bns.forEach(b => b.classList.add('dq-pre'));
  const io = new IntersectionObserver(es => es.forEach(e => {
    const b = e.target;
    if (e.isIntersecting && e.intersectionRatio >= .55) { if (!b.classList.contains('dq-on')) { b.classList.add('dq-on'); if (!reduz) b.querySelectorAll('.dq2-nota').forEach(contar); } }
    else if (!e.isIntersecting || e.intersectionRatio < .1) { b.classList.remove('dq-on'); b.querySelectorAll('.dq2-nota').forEach(n => { cancelAnimationFrame(n._r); n.textContent = n.dataset.nota; }); }
  }), { threshold: [0, .1, .55, 1] });
  bns.forEach(b => io.observe(b));
})();
