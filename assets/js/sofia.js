/* Sofia: página da assistente de IA (histórico, chat, copiar/baixar/refazer) */
(function () {
  const raiz = document.getElementById('sofia'); if (!raiz) return;
  const CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
  const FOTO = raiz.dataset.foto, NOME = (raiz.dataset.nome || '').split(' ')[0];
  const $ = id => document.getElementById(id);
  const conv = $('sfConversa'), ta = $('sfPergunta'), form = $('sfForm'), btn = form.querySelector('.sf-enviar');
  const lista = $('sfLista'), inicio = $('sfInicio');
  let atual = 0, titulo = '', msgs = [], ocupado = false;

  const escH = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  /* markdown simples: escapa tudo antes e só depois aplica a formatação */
  function md(t) {
    const blocos = []; t = escH(t).replace(/```[a-z]*\n?([\s\S]*?)```/g, (m, c) => { blocos.push(c); return '\u0000' + (blocos.length - 1) + '\u0000'; });
    const inl = s => s.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    let h = '', lst = null, tab = null;
    const fecha = () => { if (lst) { h += '</' + lst + '>'; lst = null; } if (tab) { h += '</table></div>'; tab = null; } };
    for (const l of t.split('\n')) {
      let m;
      if (/^\u0000\d+\u0000$/.test(l.trim())) { fecha(); h += '<pre>' + blocos[+l.trim().slice(1, -1)] + '</pre>'; }
      else if (/^\s*\|.*\|\s*$/.test(l)) {
        if (/^\s*\|[\s:|-]+\|\s*$/.test(l)) continue;
        const cel = l.trim().slice(1, -1).split('|').map(x => inl(x.trim()));
        if (!tab) { fecha(); h += '<div class="sf-tab"><table><tr>' + cel.map(x => '<th>' + x + '</th>').join('') + '</tr>'; tab = 1; }
        else h += '<tr>' + cel.map(x => '<td>' + x + '</td>').join('') + '</tr>';
      }
      else if ((m = l.match(/^\s*#{1,4}\s+(.*)/))) { fecha(); h += '<h4>' + inl(m[1]) + '</h4>'; }
      else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) { if (lst !== 'ul') { fecha(); h += '<ul>'; lst = 'ul'; } h += '<li>' + inl(m[1]) + '</li>'; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (lst !== 'ol') { fecha(); h += '<ol>'; lst = 'ol'; } h += '<li>' + inl(m[1]) + '</li>'; }
      else if (/^\s*(---|\*\*\*)\s*$/.test(l)) { fecha(); h += '<hr>'; }
      else if (l.trim() === '') fecha();
      else { fecha(); h += '<p>' + inl(l) + '</p>'; }
    }
    fecha(); return h;
  }

  async function post(dados) {
    const fd = new FormData(); fd.append('csrf', CSRF);
    for (const k in dados) fd.append(k, dados[k]);
    const r = await fetch('sofia.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    try { return await r.json(); } catch (e) { return { ok: false, texto: 'Resposta inválida do servidor.' }; }
  }

  function rolar() { conv.scrollTo({ top: conv.scrollHeight, behavior: 'smooth' }); }
  function bolha(papel, html, extra) {
    const d = document.createElement('div'); d.className = 'sf-msg ' + papel + (extra ? ' ' + extra : '');
    d.innerHTML = papel === 'ia' ? '<img class="sf-av" src="' + FOTO + '" alt=""><div class="sf-balao">' + html + '</div>' : '<div class="sf-balao">' + html + '</div>';
    conv.appendChild(d); rolar(); return d;
  }
  function boasVindas() {
    conv.innerHTML = '';
    const sem = $('sfSemChave');
    bolha('ia', '<p>Oi, ' + escH(NOME) + '! Eu sou a <b>Sofia</b>, a corujinha pedagógica da escola. 🦉</p><p>Posso montar <b>planos de aula</b>, criar <b>questões com gabarito</b>, explicar conteúdos e escrever <b>comunicados</b>. Escolha um atalho abaixo ou escreva o que precisa.</p>' + (sem ? sem.innerHTML : ''), 'oi');
  }
  function estado() {
    const tem = msgs.length > 0;
    inicio.hidden = tem;
    $('sfCopiarTudo').disabled = !tem; $('sfImprimir').disabled = !tem;
  }

  /* barra de ações de uma resposta */
  function acoes(m, texto, ultima) {
    const bar = document.createElement('div'); bar.className = 'sf-acoes';
    bar.innerHTML = '<button type="button" data-a="copiar"><i class="bi bi-clipboard"></i>Copiar</button>'
      + '<button type="button" data-a="baixar"><i class="bi bi-file-earmark-word"></i>Baixar .doc</button>'
      + (ultima ? '<button type="button" data-a="refazer"><i class="bi bi-arrow-repeat"></i>Refazer</button>' : '');
    bar.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.a === 'copiar') copiar(texto, b);
      else if (b.dataset.a === 'baixar') baixar(m.querySelector('.sf-balao').cloneNode(true));
      else if (b.dataset.a === 'refazer') refazer();
    });
    m.querySelector('.sf-balao').appendChild(bar);
  }
  function copiar(t, b) {
    const ok = () => { if (b) { const h = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i>Copiado'; setTimeout(() => b.innerHTML = h, 1600); } };
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(ok, () => {}); else ok();
  }
  function nomeArquivo() { return 'sofia-' + (titulo || 'resposta').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 50); }
  function baixar(no) {
    no.querySelectorAll('.sf-acoes').forEach(x => x.remove());
    const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><title>' + escH(titulo) + '</title>'
      + '<style>body{font-family:Calibri,Arial,sans-serif;font-size:12pt;line-height:1.4}h4{font-size:13pt;margin:12pt 0 4pt}table{border-collapse:collapse}td,th{border:1px solid #999;padding:4pt 6pt}pre{font-family:Consolas,monospace}</style></head><body>'
      + no.innerHTML + '</body></html>';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + html], { type: 'application/msword' }));
    a.download = nomeArquivo() + '.doc'; document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  function desenhar() {
    if (msgs.length) conv.innerHTML = ''; else boasVindas();
    msgs.forEach((x, i) => {
      if (x.papel === 'usuario') bolha('eu', escH(x.texto).replace(/\n/g, '<br>'));
      else { const m = bolha('ia', md(x.texto)); acoes(m, x.texto, i === msgs.length - 1); }
    });
    estado();
  }

  /* ---------- histórico ---------- */
  function grupo(em) {
    const d = new Date(em.replace(' ', 'T')), h = new Date(); h.setHours(0, 0, 0, 0);
    const dias = Math.floor((h - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 864e5);
    return dias <= 0 ? 'Hoje' : dias === 1 ? 'Ontem' : dias < 7 ? 'Esta semana' : dias < 31 ? 'Este mês' : 'Anteriores';
  }
  async function carregarLista() {
    const j = await post({ acao: 'listar' });
    if (!j.ok) { lista.innerHTML = '<p class="sf-vazio">' + escH(j.texto || 'Não consegui carregar.') + '</p>'; return; }
    if (!j.conversas.length) { lista.innerHTML = '<p class="sf-vazio"><i class="bi bi-chat-dots"></i>Suas conversas aparecem aqui.</p>'; return; }
    let h = '', g = '';
    j.conversas.forEach(c => {
      const gg = grupo(c.em); if (gg !== g) { h += '<h6>' + gg + '</h6>'; g = gg; }
      h += '<div class="sf-item' + (c.id === atual ? ' ativo' : '') + '" data-id="' + c.id + '"><button type="button" class="sf-abrir" title="' + escH(c.titulo) + '">' + escH(c.titulo) + '</button>'
        + '<button type="button" class="sf-apagar" aria-label="Apagar conversa" title="Apagar"><i class="bi bi-trash3"></i></button></div>';
    });
    lista.innerHTML = h;
  }
  lista.addEventListener('click', async e => {
    const it = e.target.closest('.sf-item'); if (!it || ocupado) return;
    const id = +it.dataset.id;
    if (e.target.closest('.sf-apagar')) {
      if (!confirm('Apagar esta conversa? Ela some do seu histórico.')) return;
      await post({ acao: 'apagar', conversa: id });
      if (id === atual) nova(); else carregarLista();
      return;
    }
    abrir(id); fecharHist();
  });
  async function abrir(id) {
    const j = await post({ acao: 'abrir', conversa: id });
    if (!j.ok) { nova(); return; }
    atual = j.id; titulo = j.titulo; msgs = j.mensagens || [];
    history.replaceState(null, '', '?c=' + atual);
    desenhar(); carregarLista();
  }
  function nova() {
    atual = 0; titulo = ''; msgs = [];
    history.replaceState(null, '', location.pathname);
    desenhar(); carregarLista(); ta.focus();
  }
  $('sfNova').addEventListener('click', () => { if (!ocupado) { nova(); fecharHist(); } });

  /* gaveta de conversas no celular */
  const hist = $('sfHist'), fundo = $('sfFundo');
  function fecharHist() { hist.classList.remove('aberto'); fundo.hidden = true; }
  $('sfAbrirHist').addEventListener('click', () => { hist.classList.add('aberto'); fundo.hidden = false; });
  $('sfFecharHist').addEventListener('click', fecharHist); fundo.addEventListener('click', fecharHist);

  /* ---------- enviar ---------- */
  function ajustar() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 180) + 'px'; }
  ta.addEventListener('input', ajustar);
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  inicio.querySelectorAll('[data-modelo]').forEach(b => b.addEventListener('click', () => {
    ta.value = b.dataset.modelo; ajustar(); ta.focus();
    const i = ta.value.indexOf('['); if (i >= 0) ta.setSelectionRange(i, ta.value.indexOf(']', i) + 1);
  }));

  async function enviar(dados, pergunta) {
    ocupado = true; btn.disabled = true; inicio.hidden = true;
    if (pergunta) bolha('eu', escH(pergunta).replace(/\n/g, '<br>'));
    const dig = bolha('ia', '<span class="sf-digitando"><i></i><i></i><i></i></span>', 'pensando');
    let j;
    try { j = await post(Object.assign({ acao: 'perguntar', conversa: atual }, dados)); }
    catch (err) { j = { ok: false, situacao: 'erro', texto: 'Falha de conexão. Verifique a internet e tente de novo.' }; }
    dig.remove();
    if (j.uso !== undefined) {
      $('sfUso').textContent = j.uso;
      if (j.cota) $('sfUsoBarra').style.width = Math.min(100, Math.round(j.uso * 100 / j.cota)) + '%';
    }
    if (j.situacao === 'ok' && j.conversa) {
      const novaConv = atual !== j.conversa;
      atual = j.conversa; titulo = j.titulo;
      const r = await post({ acao: 'abrir', conversa: atual });
      msgs = r.ok ? r.mensagens : msgs; desenhar();
      if (j.aviso) bolha('ia', '<div class="ia-aviso"><i class="bi bi-info-circle"></i> ' + escH(j.aviso) + '</div>', 'nota');
      if (novaConv) history.replaceState(null, '', '?c=' + atual);
      carregarLista();
    } else {
      const cls = ['recusada', 'sem_chave', 'cota', 'bloqueada'].includes(j.situacao) ? 'recusa' : 'alerta';
      bolha('ia', md(j.texto || 'Não consegui responder agora.'), cls);
      if (!msgs.length) inicio.hidden = false;
    }
    ocupado = false; btn.disabled = false; ta.focus();
  }
  form.addEventListener('submit', e => {
    e.preventDefault(); const p = ta.value.trim(); if (p.length < 3 || ocupado) return;
    ta.value = ''; ajustar(); enviar({ pergunta: p }, p);
  });
  function refazer() {
    if (ocupado || msgs.length < 2) return;
    conv.querySelectorAll('.sf-msg.ia:not(.oi)').forEach((m, i, a) => { if (i === a.length - 1) m.remove(); });
    enviar({ refazer: 1 }, '');
  }

  /* ---------- copiar tudo / imprimir ---------- */
  $('sfCopiarTudo').addEventListener('click', e => copiar(msgs.map(x => (x.papel === 'ia' ? 'Sofia:\n' : 'Eu:\n') + x.texto).join('\n\n'), e.currentTarget));
  $('sfImprimir').addEventListener('click', () => window.print());

  /* início */
  desenhar();
  const ab = +raiz.dataset.abrir; if (ab) abrir(ab); else carregarLista();
})();
