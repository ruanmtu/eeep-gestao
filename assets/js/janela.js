/* Janelas de formulário: abre sozinha quando pedido (editando, atalho, #âncora) e foca o primeiro campo */
(function () {
  if (!window.bootstrap) return;
  const abrir = m => bootstrap.Modal.getOrCreateInstance(m).show();
  document.querySelectorAll('.modal.janela').forEach(m => {
    m.addEventListener('shown.bs.modal', () => {
      const c = m.querySelector('.modal-body input:not([type=hidden]):not([readonly]):not([type=checkbox]):not([type=radio]), .modal-body textarea, .modal-body select');
      if (c && !m.dataset.semFoco) c.focus({ preventScroll: true });
    });
    if (m.dataset.abrir === '1' || (location.hash && location.hash === '#' + m.id)) abrir(m);
  });
  /* links antigos para #nova, #novo… abrem a janela correspondente */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    const m = document.getElementById(a.getAttribute('href').slice(1));
    if (m && m.classList.contains('janela')) a.addEventListener('click', e => { e.preventDefault(); abrir(m); });
  });
})();
/* ao fechar uma janela de edição (?editar=…), volta para a página sem o item aberto */
(function () {
  const u = new URL(location.href);
  if (!u.searchParams.has('editar')) return;
  document.querySelectorAll('.modal.janela[data-abrir="1"]').forEach(m => m.addEventListener('hidden.bs.modal', () => {
    u.searchParams.delete('editar'); location.replace(u.pathname + (u.searchParams.toString() ? '?' + u.searchParams : ''));
  }));
})();
