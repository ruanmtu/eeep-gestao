/* Salvar várias edições de uma vez.
   Formulários com data-lote viram "rascunho": ao mudar qualquer campo (ou enquadrar uma foto),
   aparece um botão flutuante "Salvar alterações". Ele envia todos os formulários alterados e recarrega a página uma vez.
   - data-lote-acao="salvar": valor de "acao" usado no envio em lote
   - data-lote-exige-arquivo: só entra no lote se tiver um arquivo escolhido (ex.: cartão "adicionar foto") */
(function () {
  'use strict';
  const forms = [...document.querySelectorAll('form[data-lote]')];
  if (!forms.length) return;
  const sujos = new Set();
  let enviando = false;

  const barra = document.createElement('div');
  barra.className = 'lote-barra'; barra.hidden = true; barra.setAttribute('role', 'status');
  barra.innerHTML = '<span class="lote-txt"><i class="bi bi-pencil-square"></i><b>0</b> <span>alteração</span> sem salvar</span>' +
    '<button type="button" class="lote-desfazer">Desfazer</button>' +
    '<button type="button" class="lote-salvar"><i class="bi bi-check2-circle"></i>Salvar alterações</button>';
  document.body.appendChild(barra);
  const btSalvar = barra.querySelector('.lote-salvar');

  function valido(f) { return !f.hasAttribute('data-lote-exige-arquivo') || [...f.querySelectorAll('input[type=file]')].some(i => i.files && i.files.length); }
  function atualizar() {
    const n = [...sujos].filter(valido).length;
    barra.hidden = n === 0;
    barra.querySelector('.lote-txt b').textContent = n;
    barra.querySelector('.lote-txt span').textContent = n === 1 ? 'cartão alterado' : 'cartões alterados';
    forms.forEach(f => f.classList.toggle('lote-sujo', sujos.has(f) && valido(f)));
    document.body.classList.toggle('com-lote', n > 0);
  }
  forms.forEach(f => {
    const marca = e => { if (e.target.closest('[data-lote-ignorar]')) return; sujos.add(f); atualizar(); };
    f.addEventListener('input', marca); f.addEventListener('change', marca); f.addEventListener('recortado', marca);
    f.addEventListener('recorte-cancelado', () => atualizar());
    /* ações imediatas (mover, remover…): salva antes o que estiver pendente */
    f.addEventListener('submit', async e => {
      if (enviando || f._liberado) return;
      const pend = [...sujos].filter(valido).filter(x => x !== f || (e.submitter && e.submitter.value !== f.dataset.loteAcao));
      if (!pend.length) return;
      e.preventDefault();
      const sub = e.submitter;
      if (await salvarTudo(false)) { f._liberado = true; f.requestSubmit(sub || undefined); }
    });
  });

  async function salvarTudo(recarregar = true) {
    const lista = [...sujos].filter(valido);
    if (!lista.length) return true;
    enviando = true; btSalvar.disabled = true; btSalvar.innerHTML = '<span class="spinner-border spinner-border-sm"></span>Salvando…';
    let ok = 0, erro = '';
    for (const f of lista) {
      const fd = new FormData(f);
      fd.set('acao', f.dataset.loteAcao || 'salvar'); fd.set('lote', '1');
      try {
        const r = await fetch(f.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        ok++; sujos.delete(f);
      } catch (err) { erro = 'Não foi possível salvar tudo. Verifique a internet e tente de novo.'; break; }
    }
    enviando = false;
    try { sessionStorage.setItem('lote_msg', erro ? '' : (ok === 1 ? '1 alteração salva. Já aparece no site.' : ok + ' alterações salvas. Já aparecem no site.')); } catch (e) {}
    if (erro) { btSalvar.disabled = false; btSalvar.innerHTML = '<i class="bi bi-check2-circle"></i>Salvar alterações'; alert(erro); atualizar(); return false; }
    if (recarregar) location.reload();
    return true;
  }
  btSalvar.addEventListener('click', () => salvarTudo(true));
  barra.querySelector('.lote-desfazer').addEventListener('click', () => { if (confirm('Desfazer todas as alterações que ainda não foram salvas?')) { sujos.clear(); location.reload(); } });
  addEventListener('beforeunload', e => { if (!enviando && [...sujos].filter(valido).length && !forms.some(f => f._liberado)) { e.preventDefault(); e.returnValue = ''; } });

  /* aviso depois de recarregar */
  try {
    const m = sessionStorage.getItem('lote_msg'); sessionStorage.removeItem('lote_msg');
    if (m) {
      const t = document.createElement('div'); t.className = 'lote-ok'; t.innerHTML = '<i class="bi bi-check-circle-fill"></i>' + m.replace(/</g, '&lt;');
      document.body.appendChild(t); setTimeout(() => t.classList.add('sai'), 3500); setTimeout(() => t.remove(), 4200);
    }
  } catch (e) {}
})();
