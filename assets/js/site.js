/* Site público · comportamentos */
(function () {
    'use strict';
    const reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => [...el.querySelectorAll(s)];

    /* Cabeçalho: fica sólido ao rolar; botão de voltar ao topo */
    const cab = $('#cabecalho'), topo = $('.topo');
    const aoRolar = () => {
        cab && cab.classList.toggle('rolado', scrollY > 40);
        topo && topo.classList.toggle('visivel', scrollY > 800);
    };
    aoRolar();
    addEventListener('scroll', aoRolar, { passive: true });

    /* Menu lateral (gaveta) */
    const menu = $('#menu'), hamb = $('.tp-menu-bt');
    if (menu && hamb) {
        const fechar = () => {
            if (menu.hidden) return;
            menu.classList.remove('aberta'); hamb.setAttribute('aria-expanded', 'false'); document.body.style.overflow = '';
            setTimeout(() => { menu.hidden = true; }, 260); hamb.focus();
        };
        hamb.addEventListener('click', () => {
            menu.hidden = false; hamb.setAttribute('aria-expanded', 'true'); document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => requestAnimationFrame(() => menu.classList.add('aberta')));
            setTimeout(() => { const f = $('.tp-fechar', menu); f && f.focus(); }, 60);
        });
        $$('[data-fechar]', menu).forEach(el => el.addEventListener('click', fechar));
        $$('a', menu).forEach(a => a.addEventListener('click', fechar));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') fechar(); });
        let x0 = null;   // arrastar para a esquerda fecha
        menu.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
        menu.addEventListener('touchend', e => { if (x0 !== null && x0 - e.changedTouches[0].clientX > 60) fechar(); x0 = null; }, { passive: true });
    }

    /* Busca: a lupa abre a caixa logo abaixo do cabeçalho; busca por voz */
    const busca = $('#tpBusca'), painelBusca = $('#tpBuscaPainel'), lupa = $('.tp-lupa');
    if (cab && busca && painelBusca && lupa) {
        const campo = $('input', busca), mic = $('.tp-mic', busca);
        const abrirBusca = abrir => {
            painelBusca.hidden = !abrir; lupa.setAttribute('aria-expanded', String(abrir));
            lupa.innerHTML = abrir ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-search"></i>';
            lupa.setAttribute('aria-label', abrir ? 'Fechar a busca' : 'Buscar no site');
            if (abrir) setTimeout(() => campo.focus(), 30);
        };
        lupa.addEventListener('click', () => abrirBusca(painelBusca.hidden));
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !painelBusca.hidden) { abrirBusca(false); lupa.focus(); } });
        document.addEventListener('click', e => { if (!painelBusca.hidden && !e.composedPath().includes(cab)) abrirBusca(false); });
        busca.addEventListener('submit', e => { if (!campo.value.trim()) { e.preventDefault(); campo.focus(); } });
        const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (Rec && mic) {
            mic.hidden = false;
            let rec = null;
            mic.addEventListener('click', () => {
                if (rec) { rec.stop(); return; }
                rec = new Rec(); rec.lang = 'pt-BR'; rec.interimResults = true; rec.maxAlternatives = 1;
                const antes = campo.placeholder; campo.placeholder = 'Pode falar…'; mic.classList.add('ouvindo');
                let final = '';
                rec.onresult = ev => { const r = ev.results[ev.results.length - 1]; campo.value = r[0].transcript; if (r.isFinal) final = r[0].transcript; };
                rec.onend = () => { mic.classList.remove('ouvindo'); campo.placeholder = antes; rec = null; if (final.trim()) busca.submit(); };
                rec.onerror = () => { campo.placeholder = antes; };
                try { rec.start(); } catch (e) { mic.classList.remove('ouvindo'); rec = null; }
            });
        }
    }

    /* Trilhas roláveis com setas (história e galeria) */
    $$('.setas').forEach(grupo => {
        const bts = $$('[data-rolar]', grupo);
        const trilha = bts[0] && document.getElementById(bts[0].getAttribute('aria-controls'));
        if (!trilha) return;
        const atualizar = () => {
            bts[0].disabled = trilha.scrollLeft < 6;
            bts[1].disabled = trilha.scrollLeft + trilha.clientWidth >= trilha.scrollWidth - 6;
        };
        bts.forEach(b => b.addEventListener('click', () => {
            const passo = trilha.firstElementChild ? trilha.firstElementChild.getBoundingClientRect().width + 20 : trilha.clientWidth;
            trilha.scrollBy({ left: passo * Number(b.dataset.rolar), behavior: reduz ? 'auto' : 'smooth' });
        }));
        trilha.addEventListener('scroll', atualizar, { passive: true });
        addEventListener('resize', atualizar);
        atualizar();
    });

    /* Leve movimento da foto de abertura ao rolar */
    const fundo = $('.hero-fundo');
    if (fundo && !reduz) addEventListener('scroll', () => { if (scrollY < innerHeight) fundo.style.translate = `0 ${scrollY * 0.25}px`; }, { passive: true });

    if ('IntersectionObserver' in window) {
        /* Destaca no menu a seção visível */
        const links = $$('.tp-links a[href*="#"]');
        const obsSecao = new IntersectionObserver(ents => ents.forEach(en => {
            if (en.isIntersecting) links.forEach(a => a.classList.toggle('atual', a.getAttribute('href').endsWith('#' + en.target.id)));
        }), { rootMargin: '-45% 0px -50% 0px' });
        links.forEach(a => { const el = document.getElementById(a.getAttribute('href').split('#')[1]); el && obsSecao.observe(el); });

        /* Revela blocos ao rolar, em cascata */
        const obs = new IntersectionObserver(ents => ents.forEach(en => {
            if (en.isIntersecting) { en.target.classList.add('visivel'); obs.unobserve(en.target); }
        }), { threshold: .12, rootMargin: '0px 0px -40px 0px' });
        $$('.revelar').forEach(el => {
            const irmaos = $$(':scope > .revelar', el.parentElement);
            if (irmaos.length > 1) el.style.transitionDelay = (irmaos.indexOf(el) % 4) * 90 + 'ms';
            obs.observe(el);
        });

        /* Números contam ao aparecer */
        if (!reduz) {
            const obsNum = new IntersectionObserver(ents => ents.forEach(en => {
                if (!en.isIntersecting) return;
                obsNum.unobserve(en.target);
                const alvo = Number(en.target.dataset.contar), ini = performance.now(), dur = 1400;
                const passo = t => {
                    const p = Math.min(1, (t - ini) / dur);
                    en.target.textContent = Math.round(alvo * (1 - Math.pow(1 - p, 3))).toLocaleString('pt-BR');
                    if (p < 1) requestAnimationFrame(passo);
                };
                requestAnimationFrame(passo);
            }), { threshold: .6 });
            $$('[data-contar]').forEach(n => obsNum.observe(n));
        }
    } else {
        $$('.revelar').forEach(el => el.classList.add('visivel'));
    }
})();

/* Carrosséis: estudantes (3D) e gestão (cartas) */
(function () {
    'use strict';
    const reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function arrastar(el, cb) {
        let x0 = null;
        el.addEventListener('pointerdown', e => { x0 = e.clientX; });
        el.addEventListener('pointerup', e => { if (x0 === null) return; const d = e.clientX - x0; x0 = null; if (Math.abs(d) > 40) cb(d < 0 ? 1 : -1); });
        el.addEventListener('pointercancel', () => { x0 = null; });
    }
    function automatico(raiz, avancar, tempo) {
        let t = null, parado = false, visivel = !('IntersectionObserver' in window);
        const ligar = () => { clearInterval(t); if (!reduz && !parado && visivel && !document.hidden) t = setInterval(avancar, tempo); };
        if (!visivel) new IntersectionObserver(es => { visivel = es[0].isIntersecting; ligar(); }, { threshold: .35 }).observe(raiz);
        raiz.addEventListener('mouseenter', () => { parado = true; raiz.classList.add('pausado'); clearInterval(t); });
        raiz.addEventListener('mouseleave', () => { parado = false; raiz.classList.remove('pausado'); ligar(); });
        raiz.addEventListener('focusin', () => { parado = true; clearInterval(t); });
        raiz.addEventListener('focusout', () => { parado = false; ligar(); });
        document.addEventListener('visibilitychange', ligar);
        return ligar;
    }

    /* Estudantes */
    document.querySelectorAll('[data-carrossel]').forEach(raiz => {
        const slides = [...raiz.querySelectorAll('.ce-slide')], pontos = [...raiz.querySelectorAll('.ce-pontos button')], n = slides.length, TEMPO = 4500;
        if (!n) return;
        let atual = 0;
        raiz.style.setProperty('--ce-tempo', TEMPO + 'ms');
        const mostrar = i => {
            atual = (i + n) % n;
            slides.forEach((s, k) => {
                let d = k - atual; if (d > n / 2) d -= n; if (d < -n / 2) d += n;
                s.className = 'ce-slide' + (d === 0 ? ' p0' : d === 1 ? ' p1' : d === -1 ? ' m1' : d === 2 ? ' p2' : d === -2 ? ' m2' : '');
                s.setAttribute('aria-hidden', d === 0 ? 'false' : 'true');
            });
            pontos.forEach((p, k) => { p.classList.remove('ativo'); void p.offsetWidth; p.classList.toggle('ativo', k === atual); p.setAttribute('aria-selected', k === atual); });
        };
        const ligar = automatico(raiz, () => mostrar(atual + 1), TEMPO);
        const ir = i => { mostrar(i); ligar(); };
        raiz.querySelectorAll('[data-dir]').forEach(b => b.addEventListener('click', () => ir(atual + Number(b.dataset.dir))));
        pontos.forEach((p, k) => p.addEventListener('click', () => ir(k)));
        slides.forEach((s, k) => s.addEventListener('click', () => { if (k !== atual) ir(k); }));
        arrastar(raiz.querySelector('.ce-palco'), d => ir(atual + d));
        raiz.addEventListener('keydown', e => { if (e.key === 'ArrowRight') ir(atual + 1); if (e.key === 'ArrowLeft') ir(atual - 1); });
        mostrar(0); ligar();
    });

    /* Gestão */
    document.querySelectorAll('[data-gestores]').forEach(raiz => {
        const cartas = [...raiz.querySelectorAll('.gc-cartao')], minis = [...raiz.querySelectorAll('.gc-miniaturas button')], n = cartas.length;
        const papel = document.querySelector('.gc-papel'), nome = document.getElementById('gcNome'), texto = document.getElementById('gcTexto');
        if (!n) return;
        let atual = 0;
        const mostrar = (i, dir = 1) => {
            const antes = atual; atual = (i + n) % n;
            cartas.forEach((c, k) => {
                const d = (k - atual + n) % n;
                c.className = 'gc-cartao' + (d === 0 ? ' ativo' : d === 1 ? ' atras1' : d === 2 ? ' atras2' : '') + (k === antes && k !== atual && dir > 0 ? ' saindo' : '');
            });
            setTimeout(() => cartas[antes] && cartas[antes].classList.remove('saindo'), 700);
            minis.forEach((m, k) => m.classList.toggle('ativo', k === atual));
            if (papel) {
                papel.classList.add('troca');
                setTimeout(() => { const c = cartas[atual]; nome.textContent = c.querySelector('strong').textContent + ' · ' + c.querySelector('figcaption span').textContent; texto.textContent = c.dataset.texto; papel.classList.remove('troca'); }, 300);
            }
        };
        const ligar = automatico(raiz, () => mostrar(atual + 1), 5500);
        const ir = (i, d) => { mostrar(i, d); ligar(); };
        raiz.querySelectorAll('[data-dir]').forEach(b => b.addEventListener('click', () => ir(atual + Number(b.dataset.dir), Number(b.dataset.dir))));
        minis.forEach((m, k) => m.addEventListener('click', () => ir(k, k > atual ? 1 : -1)));
        arrastar(raiz.querySelector('.gc-palco'), d => ir(atual + d, d));
        mostrar(0); ligar();
    });

    /* Nossa equipe: filtros, busca e páginas */
    const eqg = document.getElementById('eqsGrade');
    if (eqg) {
        const itens = [...eqg.children], pags = document.getElementById('eqsPaginas'), busca = document.getElementById('eqsBusca'), nada = document.getElementById('eqsNada');
        let grupo = '', aberto = false;
        const porPag = () => innerWidth < 576 ? 8 : innerWidth < 768 ? 9 : innerWidth < 1200 ? 12 : 15;
        const desenhar = (rolar) => {
            const q = (busca.value || '').trim().toLowerCase();
            const vis = itens.filter(li => (!grupo || li.dataset.grupo === grupo) && (!q || li.dataset.busca.includes(q)));
            const pp = porPag(), resto = Math.max(0, vis.length - pp), mostra = new Set(aberto ? vis : vis.slice(0, pp));
            let k = 0;
            itens.forEach(li => {
                const ver = mostra.has(li);
                if (ver && li.hidden) { li.hidden = false; li.classList.remove('entra'); li.style.setProperty('--k', k++ % 10); requestAnimationFrame(() => li.classList.add('entra')); }
                else if (!ver && !li.hidden) li.hidden = true;
            });
            nada.hidden = vis.length > 0;
            pags.innerHTML = resto > 0 ? (aberto
                ? '<button type="button" class="eqs-mais" data-mais="0"><span>Ver menos</span><i class="bi bi-chevron-up"></i></button>'
                : '<button type="button" class="eqs-mais" data-mais="1"><span>Ver mais</span><i class="bi bi-chevron-down"></i></button>') : '';
            if (rolar) { const t = document.getElementById('equipe'); if (t.getBoundingClientRect().top < 0) t.scrollIntoView({ block: 'start' }); }
        };
        pags.addEventListener('click', e => { const b = e.target.closest('[data-mais]'); if (!b) return; e.preventDefault(); aberto = b.dataset.mais === '1'; desenhar(!aberto); });
        document.querySelectorAll('.eqs-filtros [data-grupo]').forEach(b => b.addEventListener('click', () => {
            document.querySelectorAll('.eqs-filtros [data-grupo]').forEach(x => x.classList.toggle('ativo', x === b)); grupo = b.dataset.grupo; aberto = false; desenhar(); }));
        busca.addEventListener('input', () => { aberto = !!busca.value.trim(); desenhar(); });
        let rt; addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => desenhar(), 200); });
        desenhar();
    }

    /* Gestão: carrossel (sem passar sozinho) */
    const gx = document.getElementById('gx');
    if (gx) {
        const sl = [...gx.querySelectorAll('.gx-slide')], mi = [...gx.querySelectorAll('[data-gx-ir]')], n = sl.length; let at = 0;
        const ir = i => { at = (i + n) % n; sl.forEach((s, k) => { s.classList.toggle('ativo', k === at); s.setAttribute('aria-hidden', k === at ? 'false' : 'true'); }); mi.forEach((m, k) => m.classList.toggle('ativo', k === at)); };
        gx.querySelectorAll('[data-gx]').forEach(b => b.addEventListener('click', () => ir(at + Number(b.dataset.gx))));
        mi.forEach(m => m.addEventListener('click', () => ir(Number(m.dataset.gxIr))));
        gx.addEventListener('keydown', e => { if (e.key === 'ArrowRight') ir(at + 1); if (e.key === 'ArrowLeft') ir(at - 1); });
        let x0 = null; const palco = gx.querySelector('.gx-palco');
        palco.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
        palco.addEventListener('touchend', e => { if (x0 === null) return; const d = e.changedTouches[0].clientX - x0; if (Math.abs(d) > 40) ir(at + (d < 0 ? 1 : -1)); x0 = null; });
    }

    /* Estudantes: mosaico com foto ampliada */
    const bento = document.getElementById('estBento'), luz = document.getElementById('estLuz');
    if (bento && luz) {
        const itens = [...bento.querySelectorAll('.est-item')], img = document.getElementById('estLuzImg'), leg = document.getElementById('estLuzLeg'); let at = 0, volta = null;
        const mostrar = i => { at = (i + itens.length) % itens.length; const f = itens[at].querySelector('img'); img.src = f.src; img.alt = f.alt; leg.textContent = f.alt; };
        const abrir = i => { volta = document.activeElement; mostrar(i); luz.hidden = false; document.body.style.overflow = 'hidden'; luz.querySelector('.est-fechar').focus(); };
        const fechar = () => { luz.hidden = true; document.body.style.overflow = ''; volta && volta.focus(); };
        itens.forEach((b, k) => b.addEventListener('click', () => abrir(k)));
        luz.addEventListener('click', e => { const b = e.target.closest('[data-luz]'); if (b) { b.dataset.luz === 'fechar' ? fechar() : mostrar(at + Number(b.dataset.luz)); } else if (e.target === luz) fechar(); });
        document.addEventListener('keydown', e => { if (luz.hidden) return; if (e.key === 'Escape') fechar(); if (e.key === 'ArrowRight') mostrar(at + 1); if (e.key === 'ArrowLeft') mostrar(at - 1); });
    }
})();

/* v83 · cursos: cada "O que se aprende" abre e fecha sozinho (vários podem ficar abertos) */

/* v54 · Documentos: filtro por categoria */
(function () {
    const bs = document.querySelectorAll('.dc-filtros [data-dc]'); if (!bs.length) return;
    const itens = document.querySelectorAll('.dc-item');
    bs.forEach(b => b.addEventListener('click', () => {
        bs.forEach(x => x.classList.toggle('ativo', x === b));
        itens.forEach(i => { i.hidden = !!b.dataset.dc && i.dataset.cat !== b.dataset.dc; });
    }));
})();

/* v58 · Documentos: ver o arquivo dentro do site, sem baixar (PDF com pdf.js, imagem, Office pelo visualizador on-line) */
(function () {
    const visor = document.getElementById('dcVisor'); if (!visor) return;
    const corpo = document.getElementById('dcvCorpo'), doc = document.getElementById('dcvDoc');
    const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], ODF = ['odt', 'ods'];
    let aberto = false, voltarFoco = null;
    const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function abrir(item, doHistorico) {
        const id = item.dataset.id, ext = item.dataset.ext, url = item.dataset.url || 'arquivo_site.php?id=' + id, baixar = url + '&baixar=1';   // v79: editais usam data-url
        const abs = new URL(baixar, location.href).href;
        document.getElementById('dcvTit').textContent = item.dataset.titulo;
        document.getElementById('dcvMeta').textContent = item.dataset.meta;
        document.getElementById('dcvBaixar').href = baixar;
        doc.className = 'dc-doc ' + item.dataset.cor;
        doc.querySelector('i').className = 'bi bi-' + item.dataset.ic + '-fill';
        doc.querySelector('b').textContent = ext.toUpperCase().replace('JPEG', 'JPG');
        const aviso = '<div class="dcv-aviso">Se o documento não aparecer, <a href="' + esc(baixar) + '">baixe o arquivo</a>.</div>';
        if (ext === 'pdf') {
            corpo.innerHTML = '<div class="visualizador-pdf" data-manual data-url="' + esc(url) + '" data-baixar="' + esc(baixar) + '"></div>';
            const caixa = corpo.firstChild;
            (window.vpMontar ? Promise.resolve() : import('./visualizador.js?v=10')).then(() => window.vpMontar(caixa))
                .catch(() => { corpo.innerHTML = '<div class="dcv-img"><p>Não foi possível mostrar o PDF aqui. <a href="' + esc(baixar) + '">Baixe o arquivo</a>.</p></div>'; });
        } else if (['jpg', 'jpeg', 'png'].includes(ext)) {
            corpo.innerHTML = '<div class="dcv-img"><img src="' + esc(url) + '" alt="' + esc(item.dataset.titulo) + '"></div>';
        } else if (OFFICE.includes(ext)) {
            corpo.innerHTML = '<iframe src="https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(abs) + '" title="' + esc(item.dataset.titulo) + '"></iframe>' + aviso;
        } else if (ODF.includes(ext)) {
            corpo.innerHTML = '<iframe src="https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(abs) + '" title="' + esc(item.dataset.titulo) + '"></iframe>' + aviso;
        } else { location.href = baixar; return; }
        voltarFoco = document.activeElement;
        visor.hidden = false; aberto = true; document.body.classList.add('dcv-aberto');
        visor.querySelector('.dcv-x2').focus({ preventScroll: true });
        if (doHistorico && !item.dataset.url) try { history.pushState({ dcv: id }, '', '#ver-' + id); } catch (e) {}
    }
    function fechar(doHistorico) {
        if (!aberto) return;
        aberto = false; visor.hidden = true; corpo.innerHTML = ''; document.body.classList.remove('dcv-aberto');
        if (doHistorico && history.state && history.state.dcv) history.back();
        else if (/^#ver-\d+$/.test(location.hash)) try { history.replaceState(null, '', '#documentos'); } catch (e) {}
        if (voltarFoco) voltarFoco.focus({ preventScroll: true });
    }
    document.querySelectorAll('.dc-item [data-ver]').forEach(a => a.addEventListener('click', e => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;   // Ctrl+clique: abre em outra aba, como antes
        e.preventDefault(); abrir(a.closest('.dc-item'), true);
    }));
    visor.querySelectorAll('[data-fechar]').forEach(b => b.addEventListener('click', () => fechar(true)));
    visor.addEventListener('click', e => { if (e.target === visor) fechar(true); });
    addEventListener('keydown', e => { if (aberto && e.key === 'Escape') fechar(true); });
    addEventListener('popstate', () => { if (aberto) fechar(false); });
    /* link direto: index.php#ver-12 abre o documento */
    const m = location.hash.match(/^#ver-(\d+)$/);
    if (m) { const it = document.querySelector('.dc-item[data-id="' + m[1] + '"]'); if (it) { it.scrollIntoView({ block: 'center' }); abrir(it, false); } }
})();

/* v59 · Carrosséis de fotos (seção "A escola" e página do ambiente) */
(function () {
    document.querySelectorAll('.sg-carro').forEach(c => {
        const tr = c.querySelector('.sg-carro-trilho'), itens = [...tr.children], pts = [...c.querySelectorAll('.sg-carro-pontos button, .pa-miniaturas button')];
        if (itens.length < 2) return;
        let atual = 0, timer = null;
        const ir = i => { atual = (i + itens.length) % itens.length; tr.scrollTo({ left: itens[atual].offsetLeft, behavior: 'smooth' }); };
        const marca = () => { const i = Math.round(tr.scrollLeft / Math.max(1, tr.clientWidth)); if (i !== atual) atual = i; pts.forEach((p, k) => p.classList.toggle('ativo', k === atual)); };
        tr.addEventListener('scroll', () => { clearTimeout(tr._t); tr._t = setTimeout(marca, 60); }, { passive: true });
        c.querySelector('.ant')?.addEventListener('click', () => { ir(atual - 1); para(); });
        c.querySelector('.prox')?.addEventListener('click', () => { ir(atual + 1); para(); });
        pts.forEach((p, k) => p.addEventListener('click', () => { ir(k); para(); }));
        const anda = () => { if (!document.hidden) ir(atual + 1); };
        const liga = () => { if (!matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(anda, 5500); };
        const para = () => { clearInterval(timer); timer = null; };
        c.addEventListener('pointerenter', para); c.addEventListener('pointerleave', () => { if (!timer) liga(); });
        tr.addEventListener('touchstart', para, { passive: true });
        liga();
    });
})();
