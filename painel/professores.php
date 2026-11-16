<?php
/* Professores e componentes curriculares: quem leciona o quê e as disciplinas da escola */
require __DIR__ . '/../includes/app.php';
exigir('config');

$aba = in_array($_GET['aba'] ?? '', ['atribuicoes', 'disciplinas'], true) ? $_GET['aba'] : 'atribuicoes';
$prof_sel = (int)($_GET['prof'] ?? $_POST['usuario_id'] ?? 0);

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $volta = 'professores.php?aba=' . $aba . ($prof_sel ? '&prof=' . $prof_sel : '');
    try {
        switch ($acao) {
            case 'disc_add':
                $nome = trim((string)$_POST['nome']); $tipo = ($_POST['tipo'] ?? '') === 'tecnica' ? 'tecnica' : 'base_comum';
                $curso = $tipo === 'tecnica' ? ((int)($_POST['curso_id'] ?? 0) ?: null) : null;
                if (mb_strlen($nome) < 2) { flash('warning', 'Informe o nome do componente.'); break; }
                if ($tipo === 'tecnica' && !$curso) { flash('warning', 'Componente da formação técnica precisa de um curso.'); break; }
                q('INSERT INTO disciplinas (nome, tipo, curso_id, carga_horaria) VALUES (?, ?, ?, ?)', [$nome, $tipo, $curso, (int)($_POST['carga_horaria'] ?? 0) ?: null]);
                flash('success', "Componente \"$nome\" cadastrado.");
                break;
            case 'disc_editar':
                $nome = trim((string)$_POST['nome']);
                if (mb_strlen($nome) < 2) { flash('warning', 'Informe o nome do componente.'); break; }
                q('UPDATE disciplinas SET nome = ?, carga_horaria = ? WHERE id = ?', [$nome, (int)($_POST['carga_horaria'] ?? 0) ?: null, (int)$_POST['id']]);
                flash('success', 'Componente atualizado.');
                break;
            case 'disc_status':
                q('UPDATE disciplinas SET ativo = 1 - ativo WHERE id = ?', [(int)$_POST['id']]);
                flash('success', 'Situação do componente alterada.');
                break;
            case 'atr_add':
                $u = (int)$_POST['usuario_id'];
                $turmas = array_filter(array_map('intval', (array)($_POST['turmas'] ?? [])));
                $discs = array_filter(array_map('intval', (array)($_POST['disciplinas'] ?? [])));
                if (!$u || !$turmas || !$discs) { flash('warning', 'Marque pelo menos uma turma e um componente.'); break; }
                $n = 0;
                foreach ($turmas as $t) foreach ($discs as $d) {
                    if (!q_valor('SELECT id FROM atribuicoes WHERE usuario_id = ? AND turma_id = ? AND disciplina_id = ?', [$u, $t, $d])) {
                        q('INSERT INTO atribuicoes (usuario_id, turma_id, disciplina_id) VALUES (?, ?, ?)', [$u, $t, $d]); $n++;
                    }
                }
                registrar_log('atribuicao', "$n atribuição(ões) para o usuário $u");
                flash('success', $n ? "$n atribuição(ões) adicionada(s)." : 'Essas atribuições já existiam.');
                break;
            case 'atr_remover':
                q('DELETE FROM atribuicoes WHERE id = ?', [(int)$_POST['id']]);
                flash('success', 'Atribuição removida.');
                break;
            case 'atr_remover_turma':
                q('DELETE FROM atribuicoes WHERE usuario_id = ? AND turma_id = ?', [(int)$_POST['usuario_id'], (int)$_POST['turma_id']]);
                flash('success', 'O(a) professor(a) saiu dessa turma.');
                break;
        }
    } catch (PDOException $e) {
        flash('danger', 'Não foi possível salvar: já existe um registro com esses dados.');
    }
    redirecionar($volta);
}

$cursos = cursos_lista(false);
$discs = q_todos('SELECT d.*, c.nome AS curso, (SELECT COUNT(DISTINCT a.usuario_id) FROM atribuicoes a WHERE a.disciplina_id = d.id) AS profs
                    FROM disciplinas d LEFT JOIN cursos c ON c.id = d.curso_id ORDER BY d.tipo, c.nome, d.nome');

if ($aba === 'atribuicoes') {
    $profs = q_todos("SELECT u.id, u.nome, u.foto, u.perfil, u.cargo,
                             (SELECT COUNT(DISTINCT a.turma_id) FROM atribuicoes a JOIN turmas t ON t.id = a.turma_id WHERE a.usuario_id = u.id AND t.ano_letivo = ?) AS n_turmas,
                             (SELECT COUNT(*) FROM atribuicoes a JOIN turmas t ON t.id = a.turma_id WHERE a.usuario_id = u.id AND t.ano_letivo = ?) AS n_atr
                        FROM usuarios u WHERE u.perfil <> 'admin' AND u.ativo = 1 ORDER BY u.perfil = 'professor' DESC, u.nome", [ANO_LETIVO, ANO_LETIVO]);
    if (!$prof_sel || !in_array($prof_sel, array_map('intval', array_column($profs, 'id')), true)) $prof_sel = (int)($profs[0]['id'] ?? 0);
    $prof = null; foreach ($profs as $p) if ((int)$p['id'] === $prof_sel) $prof = $p;
    $atrs = $prof ? q_todos('SELECT at.id, at.turma_id, d.nome AS disciplina, d.tipo, t.serie, t.letra, c.nome AS curso, c.sigla
                               FROM atribuicoes at JOIN disciplinas d ON d.id = at.disciplina_id JOIN turmas t ON t.id = at.turma_id JOIN cursos c ON c.id = t.curso_id
                              WHERE at.usuario_id = ? AND t.ano_letivo = ? ORDER BY t.serie, t.letra, d.nome', [$prof_sel, ANO_LETIVO]) : [];
    $por_turma = []; foreach ($atrs as $a) $por_turma[$a['turma_id']][] = $a;
    $todas_turmas = turmas_lista();
}

$titulo = 'Professores e componentes curriculares';
$subtitulo = 'Quem leciona cada componente em cada turma, e os componentes da base comum e da formação técnica.';
$menu = 'professores';
require __DIR__ . '/../includes/layout_topo.php';
?>

<ul class="nav nav-abas mb-3">
    <?php foreach (['atribuicoes' => ['person-video3', 'Atribuições dos professores'], 'disciplinas' => ['book', 'Componentes curriculares']] as $k => [$ic_aba, $v]): ?>
        <li class="nav-item"><a class="nav-link<?= $aba === $k ? ' active' : '' ?>" href="?aba=<?= $k ?>"><i class="bi bi-<?= $ic_aba ?> me-1"></i><?= $v ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($aba === 'atribuicoes'): ?>
<div class="pf-wrap">
    <!-- lista de professores -->
    <aside class="pf-lista card">
        <div class="pf-busca"><i class="bi bi-search"></i><input type="search" id="filtroProf" placeholder="Procurar professor(a)…" aria-label="Procurar professor"></div>
        <div class="pf-itens">
            <?php foreach ($profs as $p): ?>
            <a class="pf-item<?= (int)$p['id'] === $prof_sel ? ' ativo' : '' ?>" href="?aba=atribuicoes&prof=<?= $p['id'] ?>" data-nome="<?= e(mb_strtolower($p['nome'])) ?>">
                <?= avatar($p, 'usuario', 'avatar-sm') ?>
                <span class="min-w-0"><strong><?= e(primeiro_ultimo($p['nome'])) ?></strong><small><?= e(cargo_usuario($p)) ?></small></span>
                <?php if ($p['n_atr']): ?><em><?= (int)$p['n_turmas'] ?> turma<?= $p['n_turmas'] == 1 ? '' : 's' ?></em><?php else: ?><em class="vazio-pf">sem turma</em><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- professor escolhido -->
    <section class="pf-det">
        <?php if (!$prof): ?>
            <div class="card"><div class="vazio"><i class="bi bi-person-video3"></i><strong>Nenhum professor cadastrado</strong>Cadastre os professores em <a href="usuarios.php">Usuários e permissões</a>.</div></div>
        <?php else: ?>
        <div class="pf-cab card">
            <?= avatar($prof, 'usuario', 'avatar-lg') ?>
            <div class="min-w-0"><h2><?= e(nome_proprio($prof['nome'])) ?></h2><small><?= e(cargo_usuario($prof)) ?></small></div>
            <div class="pf-nums"><span><b><?= count($por_turma) ?></b>turmas</span><span><b><?= count($atrs) ?></b>componentes</span></div>
        </div>

        <h3 class="pf-sub"><i class="bi bi-diagram-3"></i>Turmas e componentes em <?= ANO_LETIVO ?></h3>
        <?php if ($por_turma): ?>
        <div class="pf-turmas">
            <?php foreach ($por_turma as $tid => $itens): $t0 = $itens[0]; ?>
            <article class="pf-turma" style="--tc:<?= cor_curso($t0['curso']) ?>">
                <header><span class="pf-selo"><?= $t0['serie'] ?>º<b><?= e($t0['letra']) ?></b></span>
                    <div class="min-w-0"><strong><?= e($t0['curso']) ?></strong><small><?= count($itens) ?> componente<?= count($itens) == 1 ? '' : 's' ?></small></div>
                    <form method="post" class="ms-auto" data-confirmar="Tirar <?= e(primeiro_ultimo($prof['nome'])) ?> do <?= $t0['serie'] ?>º <?= e($t0['letra']) ?> (todos os componentes)?" data-botao="Tirar da turma">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="atr_remover_turma"><input type="hidden" name="usuario_id" value="<?= $prof_sel ?>"><input type="hidden" name="turma_id" value="<?= $tid ?>">
                        <button class="pf-sair" type="submit" title="Tirar desta turma"><i class="bi bi-box-arrow-right"></i></button></form>
                </header>
                <ul>
                    <?php foreach ($itens as $a): ?>
                    <li><i class="bi bi-<?= $a['tipo'] === 'tecnica' ? 'tools' : 'book' ?>"></i><span><?= e($a['disciplina']) ?></span>
                        <form method="post" data-confirmar="Tirar &quot;<?= e($a['disciplina']) ?>&quot; do <?= $a['serie'] ?>º <?= e($a['letra']) ?>?" data-botao="Tirar">
                            <?= csrf_campo() ?><input type="hidden" name="acao" value="atr_remover"><input type="hidden" name="usuario_id" value="<?= $prof_sel ?>"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="pf-tirar" title="Tirar este componente"><i class="bi bi-trash3"></i></button></form></li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="pf-vazio"><i class="bi bi-arrow-down-circle"></i><div><strong><?= e(primeiro_ultimo($prof['nome'])) ?> ainda não tem turma</strong>Use o quadro abaixo: marque as turmas, os componentes e clique em Atribuir.</div></div>
        <?php endif; ?>

        <form method="post" class="pf-add card" id="formAtr">
            <?= csrf_campo() ?><input type="hidden" name="acao" value="atr_add"><input type="hidden" name="usuario_id" value="<?= $prof_sel ?>">
            <div class="pf-add-cab"><span class="pf-add-ic"><i class="bi bi-plus-lg"></i></span><div><h3>Atribuir turmas e componentes</h3><small>Marque uma ou mais turmas e os componentes que <?= e(primeiro_ultimo($prof['nome'])) ?> leciona nelas.</small></div></div>
            <div class="pf-passo"><span class="pf-n">1</span><b>Turmas</b><button type="button" class="pf-link" id="todasTurmas">marcar todas</button></div>
            <div class="pf-chips">
                <?php foreach ($todas_turmas as $t): ?>
                    <label class="pf-tchip" style="--tc:<?= cor_curso($t['curso']) ?>"><input type="checkbox" name="turmas[]" value="<?= $t['id'] ?>"><span><b><?= $t['serie'] ?>º<?= e($t['letra']) ?></b><small><?= e($t['sigla'] ?: $t['curso']) ?></small></span></label>
                <?php endforeach; ?>
            </div>
            <div class="pf-passo"><span class="pf-n">2</span><b>Componentes</b><input class="form-control form-control-sm ms-auto" type="search" id="filtroDisc" placeholder="Procurar…" style="max-width:200px" aria-label="Procurar componente"></div>
            <div class="pf-discs" id="listaDisc">
                <?php $grupo = null; foreach ($discs as $d): if (!$d['ativo']) continue; $g = $d['tipo'] === 'tecnica' ? 'Formação técnica · ' . $d['curso'] : 'Base comum';
                    if ($g !== $grupo): $grupo = $g; ?><span class="pf-grupo"<?= $d['tipo'] === 'tecnica' ? ' style="--tc:' . cor_curso($d['curso']) . '"' : '' ?>><?= e($g) ?></span><?php endif; ?>
                    <label class="pf-dchip" data-nome="<?= e(mb_strtolower($d['nome'] . ' ' . $d['curso'])) ?>"><input type="checkbox" name="disciplinas[]" value="<?= $d['id'] ?>"><span><i class="bi bi-check-lg"></i><?= e($d['nome']) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="pf-rodape"><span class="pf-resumo" id="resumoAtr">Marque as turmas e os componentes.</span>
                <button class="btn btn-primary" type="submit" id="btAtr" disabled><i class="bi bi-check2-circle me-1"></i>Atribuir</button></div>
        </form>
        <?php endif; ?>
    </section>
</div>
<?php $script_pagina = '<script>
(function () {
  const filtro = (inp, sel) => { const i = document.getElementById(inp); if (i) i.addEventListener("input", () => { const v = i.value.trim().toLowerCase(); document.querySelectorAll(sel).forEach(el => el.style.display = !v || el.dataset.nome.includes(v) ? "" : "none"); }); };
  filtro("filtroProf", ".pf-item"); filtro("filtroDisc", ".pf-dchip");
  const f = document.getElementById("formAtr"); if (!f) return;
  const res = document.getElementById("resumoAtr"), bt = document.getElementById("btAtr");
  const conta = () => { const t = f.querySelectorAll("[name=\'turmas[]\']:checked").length, d = f.querySelectorAll("[name=\'disciplinas[]\']:checked").length;
    bt.disabled = !t || !d;
    res.innerHTML = t || d ? `<b>${t}</b> turma${t === 1 ? "" : "s"} × <b>${d}</b> componente${d === 1 ? "" : "s"} = <b>${t * d}</b> atribuiç${t * d === 1 ? "ão" : "ões"}` : "Marque as turmas e os componentes."; };
  f.addEventListener("change", conta);
  document.getElementById("todasTurmas").onclick = e => { const cs = f.querySelectorAll("[name=\'turmas[]\']"), todas = [...cs].every(c => c.checked); cs.forEach(c => c.checked = !todas); e.target.textContent = todas ? "marcar todas" : "desmarcar"; conta(); };
})();
</script>'; ?>

<?php else: /* ---------------- Componentes curriculares ---------------- */
    $grupos = [];
    foreach ($discs as $d) { $k = $d['tipo'] === 'tecnica' ? 'c' . (int)$d['curso_id'] : 'base'; $grupos[$k] ??= ['nome' => $d['tipo'] === 'tecnica' ? $d['curso'] : 'Base comum', 'tipo' => $d['tipo'], 'cor' => $d['tipo'] === 'tecnica' ? cor_curso($d['curso']) : '#13692a', 'itens' => []]; $grupos[$k]['itens'][] = $d; }
    $n_ativos = count(array_filter($discs, fn($d) => $d['ativo'])); ?>
<div class="cf-topo">
    <div class="cf-resumo"><span><b><?= $n_ativos ?></b> componentes ativos</span><span><b><?= count(array_filter($discs, fn($d) => $d['tipo'] !== 'tecnica' && $d['ativo'])) ?></b> base comum</span><span><b><?= count(array_filter($discs, fn($d) => $d['tipo'] === 'tecnica' && $d['ativo'])) ?></b> formação técnica</span></div>
    <div class="pf-busca ms-auto" style="max-width:260px"><i class="bi bi-search"></i><input type="search" id="filtroComp" placeholder="Procurar componente…" aria-label="Procurar componente"></div>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#novoComp"><i class="bi bi-plus-lg me-1"></i>Novo componente</button>
</div>

<div class="collapse mb-3" id="novoComp"><form method="post" class="card pc-novo"><div class="card-body">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="disc_add">
    <div class="row g-3 align-items-end">
        <div class="col-lg-4"><label class="form-label obrigatorio" for="pcNome">Nome do componente</label><input class="form-control" id="pcNome" name="nome" required placeholder="Ex.: Química, Banco de Dados"></div>
        <div class="col-lg-3"><label class="form-label">Tipo</label>
            <div class="ms-seg pc-tipo"><label><input type="radio" name="tipo" value="base_comum" checked><span><i class="bi bi-book"></i>Base comum</span></label><label><input type="radio" name="tipo" value="tecnica"><span><i class="bi bi-tools"></i>Técnica</span></label></div></div>
        <div class="col-lg-3 pc-curso"><label class="form-label" for="pcCurso">Curso</label><select class="form-select" id="pcCurso" name="curso_id">
            <?php foreach ($cursos as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-lg-1"><label class="form-label" for="pcCh">Carga h.</label><input class="form-control" type="number" id="pcCh" name="carga_horaria" min="0" placeholder="—"></div>
        <div class="col-6 col-lg-1"><button class="btn btn-primary w-100" type="submit">Salvar</button></div>
    </div>
</div></form></div>

<?php foreach ($grupos as $g): ?>
<section class="pc-grupo" style="--tc:<?= $g['cor'] ?>">
    <h3><span class="pc-ic"><i class="bi bi-<?= $g['tipo'] === 'tecnica' ? 'tools' : 'book' ?>"></i></span><?= e($g['tipo'] === 'tecnica' ? 'Formação técnica · ' . $g['nome'] : $g['nome']) ?><small><?= count($g['itens']) ?></small></h3>
    <div class="pc-grade">
        <?php foreach ($g['itens'] as $d): ?>
        <article class="pc-item<?= $d['ativo'] ? '' : ' inativo' ?>" data-nome="<?= e(mb_strtolower($d['nome'])) ?>">
            <div class="pc-topo">
                <strong><?= e($d['nome']) ?></strong>
                <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="disc_status"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <label class="pc-switch" title="<?= $d['ativo'] ? 'Ativo: clique para desativar' : 'Inativo: clique para ativar' ?>"><input type="checkbox" <?= $d['ativo'] ? 'checked' : '' ?> onchange="this.form.submit()"><i></i></label></form>
            </div>
            <div class="pc-info"><span><i class="bi bi-clock"></i><?= $d['carga_horaria'] ? (int)$d['carga_horaria'] . ' h' : 'carga não informada' ?></span>
                <span><i class="bi bi-person-video3"></i><?= (int)$d['profs'] ?> professor<?= $d['profs'] == 1 ? '' : 'es' ?></span></div>
            <details class="pc-editar"><summary><i class="bi bi-pencil"></i>Editar</summary>
                <form method="post" class="d-flex gap-2 mt-2"><?= csrf_campo() ?><input type="hidden" name="acao" value="disc_editar"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <input class="form-control form-control-sm" name="nome" value="<?= e($d['nome']) ?>" aria-label="Nome">
                    <input class="form-control form-control-sm" type="number" name="carga_horaria" value="<?= e((string)$d['carga_horaria']) ?>" placeholder="h" style="width:70px" aria-label="Carga horária">
                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2"></i></button></form></details>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<?php $script_pagina = '<script>
(function () {
  const i = document.getElementById("filtroComp");
  i.addEventListener("input", () => { const v = i.value.trim().toLowerCase();
    document.querySelectorAll(".pc-item").forEach(el => el.style.display = !v || el.dataset.nome.includes(v) ? "" : "none");
    document.querySelectorAll(".pc-grupo").forEach(g => g.style.display = [...g.querySelectorAll(".pc-item")].some(x => x.style.display !== "none") ? "" : "none"); });
  const tipo = () => document.querySelector(".pc-curso").style.display = document.querySelector("[name=tipo]:checked").value === "tecnica" ? "" : "none";
  document.querySelectorAll("[name=tipo]").forEach(r => r.addEventListener("change", tipo)); tipo();
})();
</script>'; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_rodape.php';
