/* Painéis de resultados: turma (painel inicial da gestão) e aluno (ficha) */
(function () {
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const n1 = v => v == null ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const n2 = v => v == null ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  /* barras horizontais das disciplinas (maior para menor) */
  function barrasDisc(lista, MEDIA, campo = 'media', comTurma = false) {
    const max = 10;
    return '<ul class="rd-barras">' + lista.map((d, i) => {
      const v = d[campo], baixa = v < MEDIA;
      return `<li style="--dc:${d.cor};--i:${i}" title="${esc(d.nome)}: ${n1(v)}${comTurma && d.turma != null ? ' · turma ' + n1(d.turma) : ''}">
        <span class="rd-bn">${esc(d.nome)}</span>
        <span class="rd-bt"><i style="width:${Math.max(3, v / max * 100)}%"></i>${comTurma && d.turma != null ? `<em style="left:${d.turma / max * 100}%" title="média da turma"></em>` : ''}<b class="${baixa ? 'ruim' : ''}">${n1(v)}</b></span></li>`;
    }).join('') + `<li class="rd-linha-media" aria-hidden="true"><span></span><span class="rd-bt"><em style="left:${MEDIA / max * 100}%"></em></span></li></ul>`;
  }

  /* gráfico de área (SVG) dos melhores alunos */
  function area(itens) {
    if (!itens.length) return '<div class="vazio py-4"><i class="bi bi-trophy"></i>Ninguém na média em todas as disciplinas neste período.</div>';
    const W = 640, H = 170, n = itens.length, xs = i => (i + .5) / n * W;
    const vals = itens.map(x => x.media), lo = Math.max(0, Math.min(...vals) - 1), hi = Math.min(10, Math.max(...vals) + .3), ys = v => 20 + (hi - v) / Math.max(.1, hi - lo) * (H - 50);
    const pts = itens.map((x, i) => [xs(i), ys(x.media)]);
    const linha = pts.map((q, i) => (i ? 'L' : 'M') + q[0].toFixed(1) + ' ' + q[1].toFixed(1)).join(' ');
    return `<svg class="rd-area" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Média dos melhores alunos">
      <defs><linearGradient id="rdG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1f9a3a" stop-opacity=".35"/><stop offset="1" stop-color="#1f9a3a" stop-opacity=".03"/></linearGradient></defs>
      <path d="${linha} L ${pts[n - 1][0]} ${H - 20} L ${pts[0][0]} ${H - 20} Z" fill="url(#rdG)"/>
      <path d="${linha}" fill="none" stroke="#13692a" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
      ${pts.map((q, i) => `<circle cx="${q[0]}" cy="${q[1]}" r="5" fill="#fff" stroke="${i === 0 ? '#f07c12' : '#13692a'}" stroke-width="3" vector-effect="non-scaling-stroke"/>`).join('')}
    </svg>
    <ol class="rd-melhores" style="--n:${n}">${itens.map((x, i) => `<li><a href="aluno.php?id=${x.id}#notas"><b>${n2(x.media)}</b>${i === 0 ? '<i class="bi bi-trophy-fill"></i>' : ''}<span>${esc(x.curto)}</span><small>menor nota ${n1(x.menor)}</small></a></li>`).join('')}</ol>`;
  }

  /* barras verticais: quantos alunos abaixo da média por disciplina */
  function colunas(lista) {
    if (!lista.length) return '<div class="vazio py-4"><i class="bi bi-emoji-smile"></i>Nenhuma disciplina com aluno abaixo da média.</div>';
    const mx = Math.max(...lista.map(d => d.abaixo));
    return '<div class="rd-colunas">' + lista.map((d, i) => `<div class="rd-col" style="--dc:${d.cor};--i:${i}" title="${esc(d.nome)}: ${d.abaixo} aluno(s) abaixo da média"><b>${d.abaixo}</b><i style="height:${Math.max(6, d.abaixo / mx * 100)}%"></i><span>${esc(d.nome)}</span></div>`).join('') + '</div>';
  }

  /* ------------------------------ Turma ------------------------------ */
  const sec = document.getElementById('resultados');
  if (sec && document.getElementById('rdCorpo')) {
    const corpo = document.getElementById('rdCorpo'), MEDIA = +sec.dataset.media || 6;
    let turma = +sec.dataset.turma, bim = +sec.dataset.bim, req = 0;
    try { const s = JSON.parse(localStorage.getItem('rdFiltro') || '{}'); if (s.turma && sec.querySelector(`[data-turma="${s.turma}"]`)) turma = s.turma; if (s.bim !== undefined && !sec.querySelector(`[data-bim="${s.bim}"]`)?.disabled) bim = s.bim; } catch (e) {}
    const marcar = () => { sec.querySelectorAll('.rd-turmas [data-turma]').forEach(b => b.classList.toggle('ativo', +b.dataset.turma === turma));
      sec.querySelectorAll('.rd-periodos [data-bim]').forEach(b => b.classList.toggle('ativo', +b.dataset.bim === bim));
      try { localStorage.setItem('rdFiltro', JSON.stringify({ turma, bim })); } catch (e) {} };
    async function carregar() {
      marcar(); const meu = ++req; corpo.classList.add('carregando');
      let j; try { j = await (await fetch(`api.php?acao=resultados&turma=${turma}&bim=${bim}`, { cache: 'no-store' })).json(); } catch (e) { j = { ok: false }; }
      if (meu !== req) return; corpo.classList.remove('carregando');
      if (!j.ok) { corpo.innerHTML = '<div class="vazio py-5"><i class="bi bi-wifi-off"></i>Não foi possível carregar os resultados.</div>'; return; }
      if (!j.disciplinas.length) { corpo.innerHTML = `<div class="vazio py-5"><i class="bi bi-journal-x"></i><strong>Sem notas lançadas</strong>Esta turma ainda não tem notas ${bim ? 'no ' + bim + 'º período' : 'neste ano'}.</div>`; return; }
      const nome = sec.querySelector(`[data-turma="${turma}"]`)?.textContent || '';
      const mx = Math.max(1, ...j.criticos.map(c => c.criticas.length));
      corpo.innerHTML = `
        <div class="rd-grade">
          <div class="rd-bloco rd-disc"><h3><i class="bi bi-sort-down"></i>Média por disciplina</h3>${barrasDisc(j.disciplinas, MEDIA)}</div>
          <div class="rd-meio">
            <div class="rd-media ${j.media < MEDIA ? 'ruim' : ''}"><small>Média da turma</small><b>${n2(j.media)}</b><span>${esc(nome)} · ${bim ? bim + 'º período' : 'ano todo'}</span></div>
            <div class="rd-mini"><span class="ok"><b>${j.aprovados}</b>na média em todas</span><span class="ruim"><b>${j.criticos_total}</b>com disciplina crítica</span></div>
          </div>
          <div class="rd-bloco rd-crit"><h3><i class="bi bi-exclamation-triangle"></i>Alunos com disciplinas críticas <small>abaixo de ${n1(MEDIA)}</small></h3>
            ${j.criticos.length ? `<ul class="rd-funil">${j.criticos.map((c, i) => `<li><button type="button" data-i="${i}" title="${esc(c.nome)}"><span class="rd-fn">${esc(c.nome)}</span><span class="rd-fb"><i style="width:${c.criticas.length / mx * 100}%;--h:${Math.round(50 - c.criticas.length / mx * 50)}"></i><b>${c.criticas.length}</b></span></button></li>`).join('')}</ul>
              <div class="rd-det" id="rdDet"></div>` : '<div class="vazio py-4"><i class="bi bi-emoji-smile"></i>Nenhum aluno abaixo da média neste período.</div>'}
          </div>
          <div class="rd-bloco rd-qtd"><h3><i class="bi bi-bar-chart"></i>Disciplinas críticas <small>alunos abaixo da média</small></h3>${colunas(j.disc_criticas)}</div>
          <div class="rd-bloco rd-top"><h3><i class="bi bi-trophy"></i>Alunos com melhor desempenho <small>na média em todas as disciplinas · ordem pela menor nota</small></h3>${area(j.melhores)}</div>
        </div>`;
      const det = document.getElementById('rdDet');
      const mostrar = i => { const c = j.criticos[i]; if (!c || !det) return;
        corpo.querySelectorAll('.rd-funil button').forEach(b => b.classList.toggle('ativo', +b.dataset.i === i));
        det.innerHTML = `<table class="table rd-tab"><thead><tr><th>Aluno</th><th>Situação</th><th class="num">Críticas</th><th class="num">Média</th></tr></thead>
          <tbody><tr><td><a href="aluno.php?id=${c.id}#notas">${esc(c.nome)}</a></td><td>${c.media >= MEDIA ? 'média acima de ' + n1(MEDIA) : '<span class="txt-ruim">média abaixo de ' + n1(MEDIA) + '</span>'}</td><td class="num">${c.criticas.length}</td><td class="num"><b>${n2(c.media)}</b></td></tr></tbody></table>
          <div class="rd-tags">${c.criticas.map(d => `<span>${esc(d.nome)} <b>${n1(d.nota)}</b></span>`).join('')}</div>`; };
      corpo.querySelectorAll('.rd-funil button').forEach(b => b.addEventListener('click', () => mostrar(+b.dataset.i)));
      mostrar(0);
    }
    sec.querySelectorAll('.rd-turmas [data-turma]').forEach(b => b.addEventListener('click', () => { turma = +b.dataset.turma; carregar(); }));
    sec.querySelectorAll('.rd-periodos [data-bim]').forEach(b => b.addEventListener('click', () => { if (!b.disabled) { bim = +b.dataset.bim; carregar(); } }));
    carregar();
  }

  /* ------------------------------ Aluno ------------------------------ */
  const al = document.getElementById('resultadosAluno');
  if (al) {
    const D = JSON.parse(al.dataset.dados || '{}'), MEDIA = +al.dataset.media || 6, corpo = al.querySelector('.rd-corpo');
    const per = D.periodos || {}; const bims = Object.keys(per).map(Number);
    let bim = bims.filter(b => b > 0).pop() ?? 0;
    const evol = D.evolucao || {};
    function desenhar() {
      al.querySelectorAll('[data-bim]').forEach(b => { b.classList.toggle('ativo', +b.dataset.bim === bim); b.disabled = !per[b.dataset.bim]; });
      const p = per[bim];
      if (!p) { corpo.innerHTML = '<div class="vazio py-5"><i class="bi bi-journal-x"></i><strong>Sem notas lançadas</strong>As notas aparecem aqui quando forem lançadas.</div>'; return; }
      const melhor = p.disc[0], pior = p.disc[p.disc.length - 1];
      const ev = [1, 2, 3, 4].map(b => evol[b] != null ? `<div class="rd-ev${b === bim ? ' ativo' : ''}"><b class="${evol[b] < MEDIA ? 'ruim' : ''}">${n1(evol[b])}</b><i style="height:${evol[b] * 10}%"></i><span>${b}º</span></div>` : `<div class="rd-ev vazio-ev"><b>—</b><i></i><span>${b}º</span></div>`).join('');
      corpo.innerHTML = `
        <div class="rd-grade aluno">
          <div class="rd-bloco rd-disc"><h3><i class="bi bi-sort-down"></i>Notas por disciplina <small>o tracinho é a média da turma</small></h3>${barrasDisc(p.disc, MEDIA, 'nota', true)}</div>
          <div class="rd-meio">
            <div class="rd-media ${p.media < MEDIA ? 'ruim' : ''}"><small>Média do aluno</small><b>${n2(p.media)}</b><span>${bim ? bim + 'º período' : 'ano todo'}${p.turma_media != null ? ' · turma ' + n2(p.turma_media) : ''}</span></div>
            <div class="rd-mini"><span class="ok"><b>${esc(melhor.nome)}</b>melhor · ${n1(melhor.nota)}</span><span class="${pior.nota < MEDIA ? 'ruim' : ''}"><b>${esc(pior.nome)}</b>mais difícil · ${n1(pior.nota)}</span></div>
          </div>
          <div class="rd-bloco rd-crit"><h3><i class="bi bi-exclamation-triangle"></i>Disciplinas críticas <small>abaixo de ${n1(MEDIA)}</small></h3>
            ${p.criticas.length ? '<ul class="rd-lista-crit">' + p.criticas.sort((a, b) => a.nota - b.nota).map(d => `<li style="--dc:${d.cor}"><span>${esc(d.nome)}</span><b>${n1(d.nota)}</b><small>turma ${n1(d.turma)}</small><em>faltam ${n1(MEDIA - d.nota)} para a média</em></li>`).join('') + '</ul>'
              : '<div class="vazio py-4"><i class="bi bi-emoji-smile"></i>Na média em todas as disciplinas neste período.</div>'}
          </div>
          <div class="rd-bloco rd-qtd"><h3><i class="bi bi-graph-up"></i>Evolução da média <small>por período</small></h3><div class="rd-evol">${ev}</div></div>
          <div class="rd-bloco rd-top"><h3><i class="bi bi-arrow-left-right"></i>Aluno × turma <small>diferença para a média da turma em cada disciplina</small></h3>
            <ul class="rd-comp">${p.disc.filter(d => d.turma != null).map(d => { const df = d.nota - d.turma; return `<li><span>${esc(d.nome)}</span><span class="rd-cb"><i class="${df < 0 ? 'neg' : 'pos'}" style="width:${Math.min(50, Math.abs(df) * 12)}%"></i></span><b class="${df < 0 ? 'ruim' : 'bom'}">${df > 0 ? '+' : ''}${n1(df)}</b></li>`; }).join('')}</ul></div>
        </div>`;
    }
    al.querySelectorAll('[data-bim]').forEach(b => b.addEventListener('click', () => { if (!b.disabled) { bim = +b.dataset.bim; desenhar(); } }));
    desenhar();
  }
})();
