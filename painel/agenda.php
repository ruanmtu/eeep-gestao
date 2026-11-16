<?php
/* Minha agenda: caderno digital do professor, uma página por dia (só ele vê) */
require __DIR__ . '/../includes/app.php';
exigir_login();

/* Lembretes: avisam no sino na data e hora marcadas */
if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? ''; $lid = (int)($_POST['lembrete_id'] ?? 0);
    $volta = 'agenda.php?data=' . (data_valida($_POST['data'] ?? '') ? $_POST['data'] : date('Y-m-d')) . '#lembretes';
    if ($acao === 'lembrete_add') {
        $dl = (string)($_POST['l_data'] ?? ''); $hl = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['l_hora'] ?? '')) ? $_POST['l_hora'] : '07:00';
        $tx = trim((string)($_POST['l_texto'] ?? ''));
        if (!data_valida($dl) || mb_strlen($tx) < 2) flash('warning', 'Informe a data e o que lembrar.');
        else {
            q('INSERT INTO agenda_lembretes (usuario_id, quando, texto, avisado, feito, criado_em) VALUES (?, ?, ?, 0, 0, ?)', [uid(), "$dl $hl:00", mb_substr($tx, 0, 200), date('Y-m-d H:i:s')]);
            flash('success', 'Lembrete marcado para ' . data_br($dl) . ' às ' . $hl . '. O sino avisa na hora.');
        }
    } elseif ($acao === 'lembrete_feito') {
        q('UPDATE agenda_lembretes SET feito = 1 - feito, avisado = 1 WHERE id = ? AND usuario_id = ?', [$lid, uid()]);
    } elseif ($acao === 'lembrete_apagar') {
        q('DELETE FROM agenda_lembretes WHERE id = ? AND usuario_id = ?', [$lid, uid()]);
        flash('success', 'Lembrete apagado.');
    }
    redirecionar($volta);
}

$data = data_valida($_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
$nota = agenda_buscar(uid(), $data);
$busca = trim((string)($_GET['q'] ?? ''));
$mes = substr($data, 0, 7);
$dias_com = array_flip(agenda_dias_mes(uid(), $mes));
$lista = agenda_lista(uid(), $busca, $busca ? 40 : 10);
$t = strtotime($data);
$sem = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$extenso = ucfirst($sem[(int)date('w', $t)]) . ', ' . (int)date('j', $t) . ' de ' . mb_strtolower(MESES[(int)date('n', $t)]) . ' de ' . date('Y', $t);
$ant = date('Y-m-d', strtotime("$data -1 day")); $prox = date('Y-m-d', strtotime("$data +1 day"));
$eventos_dia = [];
try { $eventos_dia = q_todos('SELECT titulo, tipo FROM eventos WHERE data_inicio <= ? AND COALESCE(data_fim, data_inicio) >= ? ORDER BY data_inicio', [$data, $data]); } catch (PDOException $e) {}
$aulas_dia = (int)date('N', $t) <= 5 ? (grade_professor(uid())[(int)date('N', $t)] ?? []) : [];
ksort($aulas_dia);
$lembretes = lembretes_lista(uid(), true, 30);

$titulo = 'Minha agenda';
$subtitulo = 'Seu caderno de anotações do dia a dia. Só você vê o que escreve aqui.';
$menu = 'agenda';
require __DIR__ . '/../includes/layout_topo.php';
?>

<div class="ag-wrap">
    <section class="ag-caderno">
        <div class="ag-nav nao-imprimir">
            <a class="btn btn-light" href="?data=<?= $ant ?>" title="Dia anterior" aria-label="Dia anterior"><i class="bi bi-chevron-left"></i></a>
            <a class="btn btn-light<?= $data === date('Y-m-d') ? ' active' : '' ?>" href="agenda.php">Hoje</a>
            <a class="btn btn-light" href="?data=<?= $prox ?>" title="Próximo dia" aria-label="Próximo dia"><i class="bi bi-chevron-right"></i></a>
            <input class="form-control" type="date" value="<?= e($data) ?>" onchange="location.href='?data='+this.value" aria-label="Ir para o dia" style="width:auto">
            <span class="ag-status ms-auto" id="agStatus"><?= $nota ? 'Salvo às ' . date('H:i', strtotime($nota['atualizado_em'])) : 'Página em branco' ?></span>
            <button class="btn btn-light" type="button" onclick="window.print()" title="Imprimir"><i class="bi bi-printer"></i></button>
        </div>

        <div class="ag-barra nao-imprimir" role="toolbar" aria-label="Formatação">
            <select class="form-select form-select-sm" id="agFonte" aria-label="Fonte" title="Fonte">
                <option value="">Fonte</option>
                <option value="Plus Jakarta Sans, Arial, sans-serif">Padrão</option>
                <option value="Georgia, 'Times New Roman', serif">Livro</option>
                <option value="'Segoe Print', 'Bradley Hand', 'Comic Sans MS', cursive">Caligrafia</option>
                <option value="'Courier New', monospace">Máquina</option>
            </select>
            <select class="form-select form-select-sm" id="agTamanho" aria-label="Tamanho" title="Tamanho da letra">
                <option value="">Tamanho</option><option value="2">Pequena</option><option value="3">Normal</option><option value="4">Grande</option><option value="5">Título</option>
            </select>
            <span class="ag-sep"></span>
            <button type="button" data-cmd="bold" title="Negrito (Ctrl+B)" aria-label="Negrito"><i class="bi bi-type-bold"></i></button>
            <button type="button" data-cmd="italic" title="Itálico (Ctrl+I)" aria-label="Itálico"><i class="bi bi-type-italic"></i></button>
            <button type="button" data-cmd="underline" title="Sublinhado (Ctrl+U)" aria-label="Sublinhado"><i class="bi bi-type-underline"></i></button>
            <button type="button" data-cmd="strikeThrough" title="Riscado (feito)" aria-label="Riscado"><i class="bi bi-type-strikethrough"></i></button>
            <span class="ag-sep"></span>
            <span class="ag-grupo" title="Marca-texto">
                <i class="bi bi-highlighter"></i>
                <?php foreach (['#fff176' => 'amarelo', '#b9f6ca' => 'verde', '#ffcdd2' => 'rosa', '#b3e5fc' => 'azul'] as $c => $n): ?>
                    <button type="button" class="ag-cor" data-marca="<?= $c ?>" style="background:<?= $c ?>" aria-label="Marca-texto <?= $n ?>"></button>
                <?php endforeach; ?>
                <button type="button" class="ag-cor ag-sem" data-marca="transparent" aria-label="Tirar marca-texto" title="Tirar marca-texto"><i class="bi bi-x"></i></button>
            </span>
            <span class="ag-grupo" title="Cor da letra">
                <i class="bi bi-palette"></i>
                <?php foreach (['#1d2a22' => 'preta', '#1d4fb0' => 'azul', '#c0261b' => 'vermelha', '#13692a' => 'verde'] as $c => $n): ?>
                    <button type="button" class="ag-cor ag-letra" data-letra="<?= $c ?>" style="background:<?= $c ?>" aria-label="Letra <?= $n ?>"></button>
                <?php endforeach; ?>
            </span>
            <span class="ag-sep"></span>
            <button type="button" data-cmd="insertUnorderedList" title="Lista" aria-label="Lista com marcadores"><i class="bi bi-list-ul"></i></button>
            <button type="button" data-cmd="insertOrderedList" title="Lista numerada" aria-label="Lista numerada"><i class="bi bi-list-ol"></i></button>
            <button type="button" id="agTarefa" title="Tarefa (caixinha para marcar)" aria-label="Inserir tarefa"><i class="bi bi-check2-square"></i></button>
            <span class="ag-sep"></span>
            <button type="button" data-cmd="removeFormat" title="Limpar formatação" aria-label="Limpar formatação"><i class="bi bi-eraser"></i></button>
            <button type="button" data-cmd="undo" title="Desfazer (Ctrl+Z)" aria-label="Desfazer"><i class="bi bi-arrow-counterclockwise"></i></button>
            <button type="button" data-cmd="redo" title="Refazer" aria-label="Refazer"><i class="bi bi-arrow-clockwise"></i></button>
        </div>

        <article class="ag-folha">
            <header class="ag-cab">
                <span class="ag-dia"><?= date('d', $t) ?></span>
                <div><strong><?= e($extenso) ?></strong>
                    <small><?php
                        $info = [];
                        if ($aulas_dia) $info[] = count($aulas_dia) . ' aula(s): ' . implode(', ', array_map(fn($a, $c) => $a . 'ª ' . ($c['turma_id'] ? $c['serie'] . 'º ' . $c['letra'] : ($c['sala'] ?: 'planej.')), array_keys($aulas_dia), $aulas_dia));
                        foreach ($eventos_dia as $ev) $info[] = $ev['titulo'];
                        echo e(implode(' · ', $info) ?: 'Escreva suas tarefas, lembretes e anotações do dia.');
                    ?></small></div>
            </header>
            <div class="ag-texto" id="agTexto" contenteditable="true" spellcheck="true" aria-label="Anotações do dia" data-placeholder="Ex.: corrigir as provas do 2º ano…"><?= $nota['conteudo'] ?? '' ?></div>
        </article>
    </section>

    <aside class="ag-lado nao-imprimir">
        <div class="card mb-3 ag-lembretes" id="lembretes">
            <div class="card-header"><h2><i class="bi bi-alarm me-1"></i>Lembretes</h2><span class="etiqueta"><?= count($lembretes) ?></span></div>
            <form method="post" class="card-body border-bottom">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="lembrete_add"><input type="hidden" name="data" value="<?= e($data) ?>">
                <input class="form-control form-control-sm mb-2" name="l_texto" maxlength="200" required placeholder="O que lembrar? Ex.: entregar notas do 2º B">
                <div class="lb-quando"><input class="form-control form-control-sm" type="date" name="l_data" value="<?= e(max($data, date('Y-m-d'))) ?>" min="<?= date('Y-m-d') ?>" required aria-label="Data">
                    <input class="form-control form-control-sm" type="time" name="l_hora" value="<?= date('H:i', strtotime('+1 hour')) ?>" required aria-label="Hora"></div>
                <button class="btn btn-sm btn-primary w-100 mt-2" type="submit"><i class="bi bi-alarm me-1"></i>Marcar lembrete</button>
            </form>
            <?php if ($lembretes): ?><ul class="lb-lista">
                <?php foreach ($lembretes as $l): $venc = strtotime($l['quando']) <= time(); ?>
                <li class="<?= $venc ? 'vencido' : '' ?>">
                    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="lembrete_feito"><input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="lembrete_id" value="<?= $l['id'] ?>">
                        <button class="lb-check" type="submit" title="Marcar como feito" aria-label="Marcar como feito"><i class="bi bi-check2"></i></button></form>
                    <div class="lb-txt"><span><?= e($l['texto']) ?></span><small><i class="bi bi-<?= $venc ? 'bell-fill' : 'clock' ?>"></i> <?= date('Y-m-d', strtotime($l['quando'])) === date('Y-m-d') ? 'hoje' : data_br(substr($l['quando'], 0, 10)) ?> às <?= date('H:i', strtotime($l['quando'])) ?></small></div>
                    <form method="post" data-confirmar="Apagar este lembrete?" data-botao="Apagar"><?= csrf_campo() ?><input type="hidden" name="acao" value="lembrete_apagar"><input type="hidden" name="data" value="<?= e($data) ?>"><input type="hidden" name="lembrete_id" value="<?= $l['id'] ?>">
                        <button class="lb-x" type="submit" aria-label="Apagar"><i class="bi bi-x-lg"></i></button></form>
                </li>
                <?php endforeach; ?></ul>
            <?php else: ?><div class="vazio py-3"><i class="bi bi-alarm"></i>Nenhum lembrete pendente.</div><?php endif; ?>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h2><?= e(MESES[(int)substr($mes, 5, 2)]) ?> de <?= substr($mes, 0, 4) ?></h2>
                <div class="btn-group ms-auto"><a class="btn btn-sm btn-light" href="?data=<?= date('Y-m-01', strtotime("$mes-01 -1 month")) ?>" aria-label="Mês anterior"><i class="bi bi-chevron-left"></i></a>
                    <a class="btn btn-sm btn-light" href="?data=<?= date('Y-m-01', strtotime("$mes-01 +1 month")) ?>" aria-label="Próximo mês"><i class="bi bi-chevron-right"></i></a></div></div>
            <div class="card-body pt-2">
                <div class="ag-mes">
                    <?php foreach (['D', 'S', 'T', 'Q', 'Q', 'S', 'S'] as $d): ?><span class="ag-sem"><?= $d ?></span><?php endforeach; ?>
                    <?php $p1 = strtotime("$mes-01"); for ($i = 0; $i < (int)date('w', $p1); $i++) echo '<span></span>';
                    for ($d = 1; $d <= (int)date('t', $p1); $d++): $dd = sprintf('%s-%02d', $mes, $d); ?>
                        <a href="?data=<?= $dd ?>" class="<?= $dd === $data ? 'atual' : '' ?><?= isset($dias_com[$dd]) ? ' tem' : '' ?><?= $dd === date('Y-m-d') ? ' hoje' : '' ?>"><?= $d ?></a>
                    <?php endfor; ?>
                </div>
                <small class="text-secondary d-block mt-2"><span class="ag-ponto"></span> dias com anotação</small>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2><?= $busca ? 'Resultado da busca' : 'Últimas anotações' ?></h2></div>
            <form class="card-body border-bottom py-2" method="get"><input type="hidden" name="data" value="<?= e($data) ?>">
                <div class="input-group input-group-sm"><input class="form-control" type="search" name="q" value="<?= e($busca) ?>" placeholder="Procurar nas anotações"><button class="btn btn-light" aria-label="Procurar"><i class="bi bi-search"></i></button></div></form>
            <?php if ($lista): ?><ul class="ag-lista">
                <?php foreach ($lista as $n): ?>
                    <li><a href="?data=<?= $n['data'] ?>"><strong><?= data_br($n['data']) ?> · <?= $sem[(int)date('w', strtotime($n['data']))] ?></strong>
                        <small><?= e(mb_strimwidth(str_replace("\n", ' · ', (string)$n['texto']), 0, 110, '…')) ?></small></a></li>
                <?php endforeach; ?></ul>
            <?php else: ?><div class="vazio py-4"><i class="bi bi-journal"></i><?= $busca ? 'Nada encontrado.' : 'Suas anotações aparecem aqui.' ?></div><?php endif; ?>
        </div>
    </aside>
</div>

<?php
$script_pagina = '<script>
(function () {
  const ed = document.getElementById("agTexto"), st = document.getElementById("agStatus");
  const DATA = ' . json_encode($data) . ', CSRF = (document.querySelector("meta[name=csrf]") || {}).content || "";
  let timer = null, sujo = false, salvando = false;
  try { document.execCommand("styleWithCSS", false, true); } catch (e) {}
  function salvar(final) {
    if (!sujo) return Promise.resolve();
    sujo = false; salvando = true; st.textContent = "Salvando…"; st.className = "ag-status ms-auto";
    const fd = new FormData(); fd.append("acao", "agenda_salvar"); fd.append("data", DATA); fd.append("conteudo", ed.innerHTML); fd.append("csrf", CSRF);
    return fetch("api.php", { method: "POST", body: fd, keepalive: !!final }).then(r => r.json()).then(j => {
      salvando = false;
      if (!j.ok) throw new Error(j.msg || "erro");
      st.textContent = j.vazio ? "Página em branco" : "Salvo às " + j.salvo_em; st.className = "ag-status ms-auto ok";
    }).catch(e => { salvando = false; sujo = true; st.textContent = "Não salvou: " + (e.message === "erro" || e.message === "Failed to fetch" ? "sem conexão. Tentando de novo…" : e.message); st.className = "ag-status ms-auto erro"; clearTimeout(timer); timer = setTimeout(salvar, 5000); });
  }
  function mudou() { sujo = true; st.textContent = "Digitando…"; st.className = "ag-status ms-auto"; clearTimeout(timer); timer = setTimeout(salvar, 1200); }
  ed.addEventListener("input", mudou);
  document.addEventListener("visibilitychange", () => { if (document.hidden) salvar(true); });
  window.addEventListener("pagehide", () => salvar(true));
  document.querySelectorAll(".ag-nav a, .ag-lado a").forEach(a => a.addEventListener("click", ev => { if (!sujo) return; ev.preventDefault(); salvar().then(() => location.href = a.href); }));
  window.addEventListener("beforeunload", e => { if (sujo || salvando) { salvar(true); } });

  const cmd = (c, v) => { ed.focus(); document.execCommand(c, false, v ?? null); mudou(); };
  document.querySelectorAll("[data-cmd]").forEach(b => b.addEventListener("mousedown", e => { e.preventDefault(); cmd(b.dataset.cmd); }));
  document.querySelectorAll("[data-marca]").forEach(b => b.addEventListener("mousedown", e => { e.preventDefault(); cmd("hiliteColor", b.dataset.marca); }));
  document.querySelectorAll("[data-letra]").forEach(b => b.addEventListener("mousedown", e => { e.preventDefault(); cmd("foreColor", b.dataset.letra); }));
  document.getElementById("agFonte").addEventListener("change", e => { if (e.target.value) cmd("fontName", e.target.value); e.target.value = ""; });
  document.getElementById("agTamanho").addEventListener("change", e => { if (e.target.value) cmd("fontSize", e.target.value); e.target.value = ""; });
  /* tarefa: caixinha que marca e risca */
  document.getElementById("agTarefa").addEventListener("mousedown", e => { e.preventDefault(); cmd("insertHTML", "<div><span class=\\"tarefa\\">☐</span>&nbsp;</div>"); });
  ed.addEventListener("click", e => {
    const t = e.target.closest(".tarefa"); if (!t) return;
    const feita = t.textContent === "☐"; t.textContent = feita ? "☑" : "☐"; t.classList.toggle("feita", feita);
    const linha = t.parentElement; if (linha && linha !== ed) linha.style.textDecoration = feita ? "line-through" : "";
    mudou();
  });
  /* colar sem trazer a formatação de outros sites */
  ed.addEventListener("paste", e => { e.preventDefault(); const txt = (e.clipboardData || window.clipboardData).getData("text/plain"); document.execCommand("insertText", false, txt); });
  if (!ed.innerHTML.trim()) ed.focus();
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
