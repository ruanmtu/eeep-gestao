/* Editor de texto das notícias: negrito, cores, marca-texto, fontes, listas, links… O HTML é limpo de novo no servidor. */
(function () {
  const ed = document.getElementById('edMateria'); if (!ed) return;
  const area = document.getElementById('edArea'), saida = document.getElementById('ifTx'), form = saida.form;
  const conta = document.getElementById('edConta');
  try { document.execCommand('styleWithCSS', false, true); document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
  let faixa = null;   // seleção guardada (os <select> tiram o foco do texto)
  const guardar = () => { const s = getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) faixa = s.getRangeAt(0).cloneRange(); };
  const voltar = () => { area.focus(); if (faixa) { const s = getSelection(); s.removeAllRanges(); s.addRange(faixa); } };
  const cmd = (c, v = null) => { voltar(); document.execCommand(c, false, v); guardar(); estado(); sincronizar(); };
  ['keyup', 'mouseup', 'input'].forEach(ev => area.addEventListener(ev, () => { guardar(); estado(); }));
  document.addEventListener('selectionchange', () => { if (document.activeElement === area) { guardar(); estado(); } });

  ed.querySelectorAll('[data-cmd]').forEach(b => b.addEventListener('mousedown', e => { e.preventDefault(); cmd(b.dataset.cmd); }));
  ed.querySelectorAll('[data-cor]').forEach(b => b.addEventListener('mousedown', e => { e.preventDefault(); cmd('foreColor', b.dataset.cor); }));
  ed.querySelectorAll('[data-fundo]').forEach(b => b.addEventListener('mousedown', e => { e.preventDefault(); cmd('hiliteColor', b.dataset.fundo); }));
  const blo = ed.querySelector('[data-bloco]'), fon = ed.querySelector('[data-fonte]'), tam = ed.querySelector('[data-tam]');
  blo.addEventListener('change', () => cmd('formatBlock', '<' + blo.value + '>'));
  fon.addEventListener('change', () => cmd('fontName', fon.value));
  tam.addEventListener('change', () => {
    const v = tam.value; if (!v) return;
    cmd('fontSize', '7');   // marca o trecho e troca pelo tamanho escolhido
    area.querySelectorAll('font[size="7"], span[style*="xxx-large"], span[style*="-webkit-xxx-large"]').forEach(n => {
      const s = document.createElement('span'); s.style.fontSize = v; while (n.firstChild) s.appendChild(n.firstChild); n.replaceWith(s); });
    tam.value = ''; sincronizar();
  });
  ed.querySelector('[data-link]').addEventListener('mousedown', e => {
    e.preventDefault(); const sel = String(getSelection());
    let u = prompt('Endereço do link (comece com https://)', 'https://'); if (!u || u === 'https://') return;
    if (!/^(https?:\/\/|mailto:)/i.test(u)) u = 'https://' + u;
    if (sel) cmd('createLink', u); else cmd('insertHTML', '<a href="' + u.replace(/"/g, '%22') + '">' + u.replace(/</g, '&lt;') + '</a>');
  });

  /* colar sempre como texto simples, mantendo os parágrafos */
  area.addEventListener('paste', e => {
    e.preventDefault();
    const t = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const html = t.replace(/\r/g, '').split(/\n{2,}/).map(p => '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>').join('');
    document.execCommand('insertHTML', false, html.includes('</p><p>') ? html : esc(t).replace(/\n/g, '<br>'));
    sincronizar();
  });
  area.addEventListener('keydown', e => {   // Tab não sai do campo dentro de listas
    if (e.key === 'Tab' && getSelection().anchorNode && getSelection().anchorNode.parentElement?.closest('li')) { e.preventDefault(); document.execCommand(e.shiftKey ? 'outdent' : 'indent'); }
  });

  function estado() {
    ed.querySelectorAll('[data-cmd]').forEach(b => { try { b.classList.toggle('ativo', ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList', 'justifyCenter', 'justifyFull'].includes(b.dataset.cmd) && document.queryCommandState(b.dataset.cmd)); } catch (x) {} });
    try { const v = String(document.queryCommandValue('formatBlock') || 'p').toLowerCase().replace(/[<>]/g, ''); blo.value = ['h2', 'h3', 'blockquote'].includes(v) ? v : 'p'; } catch (x) {}
  }
  function sincronizar() {
    area.querySelectorAll('font').forEach(n => {   // <font> antigo vira <span style>
      const s = document.createElement('span');
      if (n.color) s.style.color = n.color; if (n.face) s.style.fontFamily = n.face;
      while (n.firstChild) s.appendChild(n.firstChild); n.replaceWith(s); });
    const vazio = !area.textContent.trim();
    area.classList.toggle('vazio', vazio);
    saida.value = vazio ? '' : area.innerHTML;
    const n = vazio ? 0 : area.textContent.trim().split(/\s+/).length;
    conta.textContent = n + (n === 1 ? ' palavra' : ' palavras');
  }
  area.addEventListener('input', sincronizar);
  if (!area.innerHTML.trim()) area.innerHTML = '<p><br></p>';
  sincronizar();
  form.addEventListener('submit', e => {
    sincronizar();
    if (!saida.value.trim()) { e.preventDefault(); area.focus(); ed.classList.add('falta'); setTimeout(() => ed.classList.remove('falta'), 1800); }
  });
})();
