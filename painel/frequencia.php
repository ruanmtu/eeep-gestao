<?php
require __DIR__ . '/../includes/app.php';
exigir('frequencia');

$data = (string)($_GET['data'] ?? date('Y-m-d'));
if (!data_valida($data) || $data > date('Y-m-d')) $data = date('Y-m-d');
$aviso = validar_data_falta($data);

/* Faltas já lançadas no dia, agrupadas por turma */
$faltas = q_todos('SELECT f.id, f.aluno_id, f.registrado_por, a.nome, a.turma_id, t.serie, t.letra, c.nome AS curso, u.nome AS por
                     FROM faltas f JOIN alunos a ON a.id = f.aluno_id
                     LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                     LEFT JOIN usuarios u ON u.id = f.registrado_por
                    WHERE f.data_falta = ? ORDER BY t.serie, t.letra, a.nome', [$data]);
$grupos = [];
foreach ($faltas as $f) $grupos[(int)$f['turma_id']][] = $f;
$em_alerta = array_column(alertas_sequencia(true), 'dias', 'aluno_id');
$turmas = turmas_lista();
$pode_remover_tudo = pode('frequencia.justificar');

$titulo = 'Coleta de faltas';
$subtitulo = 'Passe nas salas, digite o nome de quem faltou e toque para lançar. Tudo é salvo na hora.';
$menu = 'frequencia';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="filtros d-flex flex-wrap align-items-end gap-3">
    <form method="get" class="d-flex align-items-end gap-2">
        <div><label class="form-label" for="f_data">Dia da coleta</label>
            <input type="date" class="form-control" id="f_data" name="data" value="<?= e($data) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"></div>
    </form>
    <div class="pb-1"><strong><?= dia_semana($data) ?>, <?= data_br($data) ?></strong><?= $data === date('Y-m-d') ? ' <span class="etiqueta">hoje</span>' : '' ?></div>
    <div class="ms-auto d-flex gap-2">
        <a class="btn btn-primary" href="faltas_imprimir.php?data=<?= e($data) ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Imprimir relatório do dia</a>
    </div>
</div>

<?php if ($aviso): ?>
    <div class="card"><div class="vazio"><i class="bi bi-calendar-x"></i><strong><?= e($aviso) ?></strong>Escolha um dia letivo.</div></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label" for="busca">Quem faltou?</label>
                <div class="coleta-busca">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" class="form-control" id="busca" placeholder="Digite parte do nome do aluno" autocomplete="off" autofocus
                           role="combobox" aria-expanded="false" aria-controls="resultados" aria-autocomplete="list">
                    <div class="busca-resultados" id="resultados" role="listbox" hidden></div>
                </div>
                <div class="form-text mt-2">Use as setas e Enter para escolher sem tirar a mão do teclado. Quem já tem falta no dia aparece marcado.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Faltas lançadas em <?= data_br($data) ?></h2><span class="etiqueta escura" id="totalFaltas"><?= count($faltas) ?></span></div>
            <div id="listaFaltas">
                <?php foreach ($turmas as $t): $lista = $grupos[(int)$t['id']] ?? []; ?>
                    <section class="coleta-turma" data-turma="<?= $t['id'] ?>"<?= $lista ? '' : ' hidden' ?>>
                        <h4><?= e(turma_nome($t)) ?> <span class="etiqueta cinza qtd"><?= count($lista) ?></span></h4>
                        <ul>
                            <?php foreach ($lista as $f): ?>
                                <li data-falta="<?= $f['id'] ?>">
                                    <a class="nome nome-link" href="aluno.php?id=<?= $f['aluno_id'] ?>#frequencia"><?= e(nome_proprio($f['nome'])) ?></a>
                                    <?php if (isset($em_alerta[$f['aluno_id']])): ?><span class="selo-sequencia"><i class="bi bi-exclamation-triangle-fill"></i><?= count($em_alerta[$f['aluno_id']]) ?> seguidas</span><?php endif; ?>
                                    <small class="text-secondary d-none d-md-inline"><?= e($f['por'] ? explode(' ', $f['por'])[0] : '') ?></small>
                                    <?php if ($pode_remover_tudo || ((int)$f['registrado_por'] === uid() && $f['data_falta'] === date('Y-m-d'))): ?>
                                        <button class="btn btn-sm btn-light remover" type="button" aria-label="Remover falta"><i class="bi bi-x-lg"></i></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
                <div class="vazio" id="semFaltas"<?= $faltas ? ' hidden' : '' ?>><i class="bi bi-clipboard-check"></i><strong>Nenhuma falta lançada neste dia</strong>Comece digitando o nome de um aluno acima.</div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h2>Resumo do dia por turma</h2></div>
            <ul class="ranking" id="resumoTurmas">
                <?php foreach ($turmas as $t): $n = count($grupos[(int)$t['id']] ?? []); ?>
                    <li data-turma="<?= $t['id'] ?>"><span class="pos"><?= $t['serie'] ?>º<?= e($t['letra']) ?></span>
                        <span class="turma-nome"><?= e($t['curso']) ?></span>
                        <span class="valor-rk"><?= $n ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Os números entram na hora no ranking semanal e na ordem do almoço do painel. Com <?= FALTAS_SEGUIDAS_ALERTA ?> faltas seguidas o aluno entra no alerta vermelho.</p>
    </div>
</div>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  const DATA = ' . json_encode($data) . ', CSRF = ' . json_encode(csrf_token()) . ', PODE_TUDO = ' . json_encode($pode_remover_tudo) . ';
  const busca = document.getElementById("busca"), res = document.getElementById("resultados");
  if (!busca) return;
  let itens = [], foco = -1, timer = null, ultimo = "";
  const esc = s => s.replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]));

  function fechar() { res.hidden = true; busca.setAttribute("aria-expanded", "false"); foco = -1; }
  function desenhar() {
    if (!itens.length) { res.innerHTML = "<div class=\\"vazio-busca\\">Nenhum aluno ativo encontrado.</div>"; }
    else res.innerHTML = itens.map((a, i) => `<button type="button" role="option" data-i="${i}" class="${a.ja ? "ja" : ""} ${i === foco ? "foco" : ""}">
        <i class="bi ${a.ja ? "bi-check-circle-fill text-danger" : "bi-person"}"></i><span>${esc(a.nome)}${a.ja ? " · já tem falta" : ""}</span><span class="turma">${esc(a.turma)}</span></button>`).join("");
    res.hidden = false; busca.setAttribute("aria-expanded", "true");
  }
  async function pesquisar() {
    const q = busca.value.trim();
    if (q.length < 2) { fechar(); return; }
    if (q === ultimo) return; ultimo = q;
    const r = await fetch("api.php?acao=buscar&data=" + DATA + "&q=" + encodeURIComponent(q));
    const j = await r.json(); if (busca.value.trim() !== q) return;
    itens = j.alunos || []; foco = itens.findIndex(a => !a.ja); desenhar();
  }
  busca.addEventListener("input", () => { clearTimeout(timer); timer = setTimeout(pesquisar, 180); });
  busca.addEventListener("keydown", e => {
    if (res.hidden) return;
    if (e.key === "ArrowDown") { e.preventDefault(); foco = Math.min(itens.length - 1, foco + 1); desenhar(); }
    else if (e.key === "ArrowUp") { e.preventDefault(); foco = Math.max(0, foco - 1); desenhar(); }
    else if (e.key === "Enter") { e.preventDefault(); if (itens[foco]) lancar(itens[foco]); }
    else if (e.key === "Escape") fechar();
  });
  res.addEventListener("mousedown", e => { const b = e.target.closest("button"); if (b) { e.preventDefault(); lancar(itens[+b.dataset.i]); } });
  busca.addEventListener("blur", () => setTimeout(fechar, 150));

  function aviso(msg, tipo) {
    const d = document.createElement("div");
    d.className = "alert alert-" + tipo + " position-fixed shadow-sm"; d.style.cssText = "right:20px;bottom:20px;z-index:2000;max-width:360px";
    d.textContent = msg; document.body.appendChild(d); setTimeout(() => d.remove(), 2600);
  }
  function atualizarContagens() {
    let total = 0;
    document.querySelectorAll(".coleta-turma").forEach(s => {
      const n = s.querySelectorAll("li").length; total += n;
      s.hidden = !n; s.querySelector(".qtd").textContent = n;
      const r = document.querySelector(`#resumoTurmas li[data-turma="${s.dataset.turma}"] .valor-rk`); if (r) r.textContent = n;
    });
    document.getElementById("totalFaltas").textContent = total;
    document.getElementById("semFaltas").hidden = total > 0;
  }
  async function lancar(a) {
    if (!a) return;
    if (a.ja) { aviso(a.nome + " já tem falta neste dia.", "warning"); return; }
    const fd = new FormData(); fd.append("acao", "falta_add"); fd.append("aluno_id", a.id); fd.append("data", DATA); fd.append("csrf", CSRF);
    const j = await (await fetch("api.php", { method: "POST", body: fd })).json();
    if (!j.ok) { aviso(j.msg, "warning"); return; }
    const f = j.falta, sec = document.querySelector(`.coleta-turma[data-turma="${f.turma_id}"]`);
    if (sec) {
      const li = document.createElement("li"); li.dataset.falta = f.id; li.className = "novo";
      li.innerHTML = `<a class="nome nome-link" href="aluno.php?id=${f.aluno_id}#frequencia">${esc(f.nome)}</a>`
        + (f.alerta ? `<span class="selo-sequencia"><i class="bi bi-exclamation-triangle-fill"></i>${f.alerta} seguidas</span>` : "")
        + `<small class="text-secondary d-none d-md-inline">você</small><button class="btn btn-sm btn-light remover" type="button" aria-label="Remover falta"><i class="bi bi-x-lg"></i></button>`;
      sec.querySelector("ul").prepend(li);
    }
    atualizarContagens();
    aviso(j.msg + (f.alerta ? " Atenção: " + f.alerta + " faltas seguidas!" : ""), f.alerta ? "danger" : "success");
    busca.value = ""; ultimo = ""; fechar(); busca.focus();
  }
  document.getElementById("listaFaltas").addEventListener("click", async e => {
    const b = e.target.closest(".remover"); if (!b) return;
    const li = b.closest("li"); if (!confirm("Remover a falta de " + li.querySelector(".nome").textContent + "?")) return;
    const fd = new FormData(); fd.append("acao", "falta_del"); fd.append("falta_id", li.dataset.falta); fd.append("csrf", CSRF);
    const j = await (await fetch("api.php", { method: "POST", body: fd })).json();
    if (j.ok) { li.remove(); atualizarContagens(); } aviso(j.msg, j.ok ? "success" : "warning");
  });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
