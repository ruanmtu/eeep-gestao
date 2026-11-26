/* Painel · comportamentos comuns */
(function () {
    'use strict';

    /* Confirmação antes de ações destrutivas: <form data-confirmar="Mensagem"> */
    const html = `
    <div class="modal fade" id="modalConfirmar" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Confirmar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><p class="mb-0" id="modalConfirmarTexto"></p></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-danger" id="modalConfirmarOk">Confirmar</button>
        </div></div></div></div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    const modalEl = document.getElementById('modalConfirmar');
    let formPendente = null;
    document.addEventListener('submit', function (ev) {
        const f = ev.target;
        if (!f.matches('form[data-confirmar]') || f.dataset.confirmado === '1') return;
        ev.preventDefault();
        formPendente = f;
        document.getElementById('modalConfirmarTexto').textContent = f.dataset.confirmar;
        document.getElementById('modalConfirmarOk').textContent = f.dataset.botao || 'Confirmar';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
    document.getElementById('modalConfirmarOk').addEventListener('click', function () {
        if (!formPendente) return;
        formPendente.dataset.confirmado = '1';
        formPendente.submit();
    });

    /* Mantém a aba aberta ao recarregar (usa #aba na URL) */
    const abas = document.querySelectorAll('[data-bs-toggle="tab"][data-aba]');
    if (abas.length) {
        const alvo = (location.hash || '').replace('#', '');
        abas.forEach(a => {
            if (a.dataset.aba === alvo) bootstrap.Tab.getOrCreateInstance(a).show();
            a.addEventListener('shown.bs.tab', () => history.replaceState(null, '', '#' + a.dataset.aba));
        });
    }

    /* Filtro de texto para selects longos: <input data-filtra-select="#idDoSelect"> */
    document.querySelectorAll('[data-filtra-select]').forEach(inp => {
        const sel = document.querySelector(inp.dataset.filtraSelect);
        if (!sel) return;
        const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        inp.addEventListener('input', () => {
            const t = norm(inp.value.trim());
            let primeiro = null;
            sel.querySelectorAll('option').forEach(o => {
                if (!o.value) return;
                const ok = !t || norm(o.textContent).includes(t);
                o.hidden = !ok;
                if (ok && !primeiro) primeiro = o;
            });
            sel.querySelectorAll('optgroup').forEach(g => { g.hidden = ![...g.children].some(o => !o.hidden); });
            if (t && primeiro && (sel.selectedOptions[0]?.hidden ?? true)) sel.value = primeiro.value;
        });
    });

    /* Chamada: destaca linha de quem faltou e atualiza contador */
    const chamada = document.querySelector('.chamada-lista');
    if (chamada) {
        const contar = () => {
            const n = chamada.querySelectorAll('input[value="F"]:checked').length;
            chamada.querySelectorAll('li').forEach(li => {
                li.classList.toggle('faltou', !!li.querySelector('input[value="F"]:checked'));
            });
            const c = document.getElementById('contadorFaltas');
            if (c) c.textContent = n === 1 ? '1 falta marcada' : n + ' faltas marcadas';
        };
        chamada.addEventListener('change', contar);
        contar();
        const todos = document.getElementById('todosPresentes');
        if (todos) todos.addEventListener('click', () => {
            chamada.querySelectorAll('input[value="P"]').forEach(i => { i.checked = true; });
            contar();
        });
    }

    /* Notas: realça notas abaixo da média enquanto digita */
    document.querySelectorAll('.input-nota').forEach(inp => {
        const media = parseFloat(inp.dataset.media || '6');
        const marcar = () => {
            const v = parseFloat(inp.value.replace(',', '.'));
            inp.classList.toggle('abaixo', !isNaN(v) && v < media);
        };
        inp.addEventListener('input', marcar);
        marcar();
    });

    /* Campos que aparecem conforme o tipo (ex.: datas da suspensão) */
    document.querySelectorAll('[data-mostra-quando]').forEach(bloco => {
        const [campo, valor] = bloco.dataset.mostraQuando.split('=');
        const sel = document.querySelector(campo);
        if (!sel) return;
        const atualizar = () => { bloco.hidden = sel.value !== valor; };
        sel.addEventListener('change', atualizar);
        atualizar();
    });
})();

/* Cores padrão dos gráficos (Chart.js) */
window.CORES_ESCOLA = ['#13692a', '#5dbb63', '#0f8a8a', '#c2570c', '#9bbf3a', '#2f6fb0', '#bfe5c2', '#62766a'];
window.configurarGraficos = function () {
    if (!window.Chart) return;
    Chart.defaults.font.family = '"Source Sans 3", system-ui, sans-serif';
    Chart.defaults.font.size = 13;
    Chart.defaults.color = '#3c5240';
    Chart.defaults.borderColor = '#e3eee4';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.tooltip.backgroundColor = '#072e12';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.maintainAspectRatio = false;
};

/* Caixa de busca com lista de sugestões (alunos, livros...) */
window.comboBusca = function ({ input, lista, url, item, escolher, vazio }) {
    let itens = [], foco = 0, timer = null;
    const fechar = () => { lista.hidden = true; input.setAttribute('aria-expanded', 'false'); };
    const desenhar = () => {
        lista.innerHTML = itens.length
            ? itens.map((x, i) => `<button type="button" role="option" data-i="${i}" class="${i === foco ? 'foco' : ''} ${x.desabilitado ? 'ja' : ''}">${item(x)}</button>`).join('')
            : `<div class="vazio-busca">${vazio || 'Nada encontrado.'}</div>`;
        lista.hidden = false; input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = input.value.trim();
            if (q.length < 2) return fechar();
            const j = await (await fetch(url(q))).json();
            if (input.value.trim() !== q) return;
            itens = j.alunos || j.livros || []; foco = Math.max(0, itens.findIndex(x => !x.desabilitado)); desenhar();
        }, 180);
    });
    input.addEventListener('keydown', e => {
        if (lista.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); foco = Math.min(itens.length - 1, foco + 1); desenhar(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); foco = Math.max(0, foco - 1); desenhar(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (itens[foco]) { fechar(); escolher(itens[foco]); } }
        else if (e.key === 'Escape') fechar();
    });
    lista.addEventListener('mousedown', e => { const b = e.target.closest('button'); if (b) { e.preventDefault(); fechar(); escolher(itens[+b.dataset.i]); } });
    input.addEventListener('blur', () => setTimeout(fechar, 150));
};
window.esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/* ---------- Notificações: sino, avisos novos e pedidos de liberação ---------- */
(function () {
    const btn = document.getElementById('sinoBtn'); if (!btn) return;
    const CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
    const cont = document.getElementById('sinoCont'), lista = document.getElementById('sinoLista');
    let vistos = null, pendAnt = null, audio = null, naoLidas = 0;
    try { vistos = new Set(JSON.parse(sessionStorage.getItem('notif_vistas') || 'null') || []); } catch (e) { vistos = new Set(); }
    let primeira = !sessionStorage.getItem('notif_vistas');
    const guardar = () => { try { sessionStorage.setItem('notif_vistas', JSON.stringify([...vistos].slice(-200))); } catch (e) {} };
    document.addEventListener('pointerdown', () => { try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} }, { once: true });
    function bip() {
        if (!audio) return;
        [0, 0.18].forEach(t => { const o = audio.createOscillator(), g = audio.createGain(); o.connect(g); g.connect(audio.destination);
            o.frequency.value = 988; g.gain.value = 0.07; o.start(audio.currentTime + t); o.stop(audio.currentTime + t + 0.12); });
    }
    function contagem(chave, n) {
        document.querySelectorAll(`[data-contagem="${chave}"]`).forEach(el => { el.textContent = n; el.hidden = !n; });
    }
    function aviso(n) {
        const box = document.getElementById('avisosFlutuantes') || Object.assign(document.body.appendChild(document.createElement('div')), { id: 'avisosFlutuantes', className: 'avisos-flutuantes' });
        const d = document.createElement('div');
        d.className = 'aviso-notif' + (n.tipo === 'autorizacao' ? ' urgente' : (n.tipo === 'ocorrencia' ? ' ocorrencia' : ''));
        d.innerHTML = `<i class="bi bi-${n.icone}"></i><div class="flex-grow-1 min-w-0"><strong>${esc(n.titulo)}</strong><small>${esc(n.mensagem || n.autor)}</small></div><button type="button" class="btn-close" aria-label="Fechar"></button>`;
        d.querySelector('.btn-close').onclick = ev => { ev.stopPropagation(); d.remove(); };
        d.onclick = () => abrir(n);
        box.appendChild(d);
        setTimeout(() => d.remove(), n.tipo === 'autorizacao' ? 60000 : 12000);
    }
    function abrir(n) {
        const fd = new FormData(); fd.append('acao', 'notif_lida'); fd.append('id', n.id); fd.append('csrf', CSRF);
        fetch('api.php', { method: 'POST', body: fd }).finally(() => { if (n.link) location.href = n.link; else location.href = 'notificacoes.php#n' + n.id; });
    }
    function desenhar(itens) {
        lista.innerHTML = itens.length ? itens.map(n => `<button type="button" class="sino-item${n.lida ? '' : ' nova'}" data-id="${n.id}">
            <span class="si-ic t-${n.tipo}"><i class="bi bi-${n.icone}"></i></span>
            <span class="min-w-0 flex-grow-1"><strong>${esc(n.titulo)}</strong><small>${esc(n.mensagem || '')}</small><em>${esc(n.autor)} · ${esc(n.quando)}</em></span></button>`).join('')
            : '<div class="text-center text-secondary small py-4"><i class="bi bi-bell-slash d-block fs-4 mb-1"></i>Nenhuma notificação.</div>';
        lista.querySelectorAll('.sino-item').forEach(b => b.onclick = () => abrir(itens.find(n => n.id === +b.dataset.id)));
    }
    async function consultar() {
        try {
            const j = await (await fetch('api.php?acao=notif&limite=8', { cache: 'no-store' })).json();
            if (!j.ok) return;
            cont.textContent = j.nao_lidas; cont.hidden = !j.nao_lidas; contagem('notificacoes', j.nao_lidas); naoLidas = j.nao_lidas;
            const ponto = document.getElementById('menuPonto'); if (ponto) ponto.hidden = !(j.nao_lidas || j.pendentes || (j.pendentes === null && +ponto.dataset.pend));
            if (j.pendentes !== null) { contagem('saidas', j.pendentes); const p = document.getElementById('sinoPend'); if (p) p.textContent = j.pendentes; }
            desenhar(j.itens);
            let toca = false, ocNova = false;
            j.itens.forEach(n => {
                if (vistos.has(n.id)) return;
                vistos.add(n.id);
                if (!primeira && !n.lida) {
                    aviso(n);
                    if (n.tipo === 'autorizacao' || n.tipo === 'ocorrencia' || n.importante) toca = true;
                    if (n.tipo === 'ocorrencia') ocNova = true;
                }
            });
            if (j.pendentes !== null && pendAnt !== null && j.pendentes > pendAnt) toca = true;
            pendAnt = j.pendentes;
            if (toca) { bip(); document.dispatchEvent(new CustomEvent('notif:pedido')); }
            if (ocNova) document.dispatchEvent(new CustomEvent('notif:ocorrencia'));
            primeira = false; guardar();
        } catch (e) {}
    }
    function lerTodas() {
        const fd = new FormData(); fd.append('acao', 'notif_lida'); fd.append('id', 'todas'); fd.append('csrf', CSRF);
        return fetch('api.php', { method: 'POST', body: fd }).then(consultar);
    }
    document.getElementById('sinoLerTodas').addEventListener('click', lerTodas);
    /* abriu o sino e viu as notificações: ao fechar, todas contam como lidas e o número some */
    let abertoCom = 0;
    btn.addEventListener('shown.bs.dropdown', () => { abertoCom = naoLidas; });
    btn.addEventListener('hidden.bs.dropdown', () => {
        if (!abertoCom && !naoLidas) return;
        abertoCom = 0; naoLidas = 0; cont.hidden = true; contagem('notificacoes', 0);
        lerTodas();
    });
    consultar();
    setInterval(() => { if (!document.hidden) consultar(); }, 20000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) consultar(); });
})();

/* Filtro do menu da gestão */
(function () {
    const f = document.getElementById('menuFiltro'); if (!f) return;
    const sem = t => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    f.addEventListener('input', () => {
        const q = sem(f.value.trim());
        document.querySelectorAll('.mg-item').forEach(a => a.hidden = q && !sem(a.textContent).includes(q));
        document.querySelectorAll('.mg-itens').forEach(g => { const vis = [...g.children].some(a => !a.hidden); g.hidden = !vis; g.previousElementSibling.hidden = !vis; });
    });
    document.getElementById('menuLateral').addEventListener('shown.bs.offcanvas', () => { if (matchMedia('(min-width: 768px)').matches) f.focus(); });
})();

/* Painel inicial: cartão "Ocorrências registradas hoje" se atualiza sozinho quando chega um registro */
(function () {
    const box = document.getElementById('ocHojeBox'); if (!box) return;
    async function atualizar() {
        try {
            const r = await fetch('index.php?parcial=ocorrencias', { cache: 'no-store' });
            if (!r.ok) return;
            box.innerHTML = await r.text();
            const c = box.querySelector('.cartao-oc-hoje'); if (c) { c.classList.add('chegou'); setTimeout(() => c.classList.remove('chegou'), 2500); }
            if (!c) return;
            const n = +c.dataset.total, kpi = document.querySelector('a.kpi[href="#ocorrencias-hoje"]');
            if (kpi) { kpi.querySelector('strong').textContent = n; kpi.classList.toggle('kpi-vermelho', n > 0); kpi.classList.toggle('kpi-verde', !n); }
            const bv = document.querySelector('.bv-hoje a[href="ocorrencias.php"] strong'); if (bv && c.dataset.escola === '1') bv.textContent = n;
        } catch (e) {}
    }
    document.addEventListener('notif:ocorrencia', atualizar);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) atualizar(); });
})();

/* ---------------- Assistente de IA (painel inicial) ---------------- */
(function () {
  const form = document.getElementById('iaForm'); if (!form) return;
  const CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
  const conv = document.getElementById('iaConversa'), ta = document.getElementById('iaPergunta'), btn = form.querySelector('.ia-enviar');
  const caixa = document.getElementById('assistente'), FOTO = caixa.dataset.foto || '';
  const boasVindas = conv.innerHTML;
  let hist = []; let ocupado = false;
  document.getElementById('iaLimpar')?.addEventListener('click', () => { if (ocupado) return; hist = []; conv.innerHTML = boasVindas; document.getElementById('iaSugestoes')?.classList.remove('usado'); ta.focus(); });
  const escH = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  /* markdown simples: escapa tudo antes e só depois aplica a formatação */
  function md(t) {
    const blocos = []; t = escH(t).replace(/```[a-z]*\n?([\s\S]*?)```/g, (m, c) => { blocos.push(c); return '\u0000' + (blocos.length - 1) + '\u0000'; });
    const inl = s => s.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    let h = '', lista = null;
    const fecha = () => { if (lista) { h += '</' + lista + '>'; lista = null; } };
    for (const l of t.split('\n')) {
      let m;
      if (/^\u0000\d+\u0000$/.test(l.trim())) { fecha(); h += '<pre>' + blocos[+l.trim().slice(1, -1)] + '</pre>'; }
      else if ((m = l.match(/^\s*#{1,4}\s+(.*)/))) { fecha(); h += '<h4>' + inl(m[1]) + '</h4>'; }
      else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) { if (lista !== 'ul') { fecha(); h += '<ul>'; lista = 'ul'; } h += '<li>' + inl(m[1]) + '</li>'; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (lista !== 'ol') { fecha(); h += '<ol>'; lista = 'ol'; } h += '<li>' + inl(m[1]) + '</li>'; }
      else if (/^\s*(---|\*\*\*)\s*$/.test(l)) { fecha(); h += '<hr>'; }
      else if (l.trim() === '') fecha();
      else { fecha(); h += '<p>' + inl(l) + '</p>'; }
    }
    fecha(); return h;
  }
  function msg(papel, html, extra) {
    const d = document.createElement('div'); d.className = 'ia-msg ' + papel + (extra ? ' ' + extra : '');
    d.innerHTML = papel === 'ia' ? '<img class="ia-av" src="' + FOTO + '" alt=""><div class="ia-balao">' + html + '</div>' : '<div class="ia-balao">' + html + '</div>';
    conv.appendChild(d); conv.scrollTo({ top: conv.scrollHeight, behavior: 'smooth' }); return d;
  }
  function ajustar() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; }
  ta.addEventListener('input', ajustar);
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  document.querySelectorAll('#iaSugestoes [data-sugestao]').forEach(b => b.addEventListener('click', () => { ta.value = b.dataset.sugestao; ajustar(); ta.focus(); }));
  form.addEventListener('submit', async e => {
    e.preventDefault(); const p = ta.value.trim(); if (p.length < 3 || ocupado) return;
    ocupado = true; btn.disabled = true; ta.value = ''; ajustar();
    msg('eu', escH(p).replace(/\n/g, '<br>'));
    document.getElementById('iaSugestoes')?.classList.add('usado');
    const dig = msg('ia', '<span class="ia-digitando"><i></i><i></i><i></i></span>', 'pensando');
    try {
      const fd = new FormData(); fd.append('acao', 'ia'); fd.append('pergunta', p); fd.append('historico', JSON.stringify(hist.slice(-8))); fd.append('csrf', CSRF);
      const r = await fetch('api.php', { method: 'POST', body: fd }); const j = await r.json();
      dig.remove();
      const t = j.texto || j.msg || 'Não consegui responder agora.';
      const cls = j.situacao === 'ok' ? '' : (['recusada', 'sem_chave', 'cota', 'bloqueada'].includes(j.situacao) ? 'recusa' : 'alerta');
      const m = msg('ia', md(t) + (j.aviso ? '<div class="ia-aviso"><i class="bi bi-info-circle"></i> ' + escH(j.aviso) + '</div>' : ''), cls);
      if (j.situacao === 'ok') {
        hist.push({ papel: 'usuario', texto: p }, { papel: 'ia', texto: t });
        const bar = document.createElement('div'); bar.className = 'ia-acoes';
        bar.innerHTML = '<button type="button"><i class="bi bi-clipboard"></i> Copiar</button>';
        bar.firstChild.onclick = () => navigator.clipboard?.writeText(t).then(() => { bar.firstChild.innerHTML = '<i class="bi bi-check2"></i> Copiado'; });
        m.querySelector('.ia-balao').appendChild(bar);
      }
      if (j.uso !== undefined) document.getElementById('iaUso').textContent = j.uso;
    } catch (err) { dig.remove(); msg('ia', 'Falha de conexão. Verifique a internet e tente de novo.', 'alerta'); }
    ocupado = false; btn.disabled = false; ta.focus();
  });
})();
