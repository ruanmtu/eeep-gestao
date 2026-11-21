<?php
require __DIR__ . '/../includes/app.php';
exigir('config');

$aba = in_array($_GET['aba'] ?? '', ['turmas', 'cursos'], true) ? $_GET['aba'] : 'turmas';
if (in_array($_GET['aba'] ?? '', ['disciplinas', 'atribuicoes'], true)) redirecionar('professores.php?aba=' . $_GET['aba']);
if (($_GET['aba'] ?? '') === 'ia') redirecionar('assistente_ia.php');

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    try {
        switch ($acao) {
            case 'turma_add':
                $curso = (int)$_POST['curso_id']; $serie = (int)$_POST['serie']; $letra = mb_strtoupper(trim((string)$_POST['letra']));
                $ano = (int)$_POST['ano_letivo'];
                if (!$curso || $serie < 1 || $serie > 3 || !preg_match('/^[A-Z]$/', $letra) || $ano < 2000) { flash('warning', 'Preencha curso, série (1 a 3), letra e ano.'); break; }
                if (q_valor('SELECT id FROM turmas WHERE curso_id = ? AND serie = ? AND letra = ? AND ano_letivo = ?', [$curso, $serie, $letra, $ano])) { flash('warning', 'Essa turma já existe.'); break; }
                q('INSERT INTO turmas (curso_id, serie, letra, ano_letivo, turno) VALUES (?, ?, ?, ?, ?)', [$curso, $serie, $letra, $ano, trim((string)$_POST['turno']) ?: 'Integral']);
                registrar_log('turma_criada', "{$serie}º $letra / $ano");
                flash('success', "Turma {$serie}º Ano $letra criada.");
                break;
            case 'turma_dt':
                $dt = (int)($_POST['diretor_turma_id'] ?? 0);
                if ($dt && !q_valor('SELECT id FROM usuarios WHERE id = ? AND ativo = 1', [$dt])) { flash('warning', 'Usuário não encontrado.'); break; }
                q('UPDATE turmas SET diretor_turma_id = ? WHERE id = ?', [$dt ?: null, (int)$_POST['id']]);
                registrar_log('turma_dt', $dt ? 'Diretor(a) de turma definido(a)' : 'Diretor(a) de turma retirado(a)');
                flash('success', $dt ? 'Diretor(a) de turma salvo(a). Essa pessoa passa a receber os avisos de ocorrências da turma.' : 'A turma ficou sem diretor(a) de turma.');
                break;
            case 'turma_status':
                q('UPDATE turmas SET ativo = 1 - ativo WHERE id = ?', [(int)$_POST['id']]);
                flash('success', 'Situação da turma alterada.');
                break;
            case 'curso_add':
                $nome = trim((string)$_POST['nome']); $sigla = mb_strtoupper(trim((string)$_POST['sigla']));
                if (mb_strlen($nome) < 3 || $sigla === '') { flash('warning', 'Informe nome e sigla do curso.'); break; }
                q('INSERT INTO cursos (nome, sigla) VALUES (?, ?)', [$nome, mb_substr($sigla, 0, 10)]);
                flash('success', "Curso $nome cadastrado.");
                break;
            case 'curso_coord':
                $co = (int)($_POST['coordenador_id'] ?? 0);
                if ($co && !q_valor('SELECT id FROM usuarios WHERE id = ? AND ativo = 1', [$co])) { flash('warning', 'Usuário não encontrado.'); break; }
                q('UPDATE cursos SET coordenador_id = ? WHERE id = ?', [$co ?: null, (int)$_POST['id']]);
                registrar_log('curso_coordenador', $co ? 'Coordenador(a) de curso definido(a)' : 'Coordenador(a) de curso retirado(a)');
                flash('success', $co ? 'Coordenador(a) do curso salvo(a). Essa pessoa pode emitir a liberação de saída dos alunos do curso.' : 'O curso ficou sem coordenador(a).');
                break;
            case 'curso_editar':
                q('UPDATE cursos SET nome = ?, sigla = ?, ativo = ? WHERE id = ?',
                  [trim((string)$_POST['nome']), mb_substr(mb_strtoupper(trim((string)$_POST['sigla'])), 0, 10), !empty($_POST['ativo']) ? 1 : 0, (int)$_POST['id']]);
                flash('success', 'Curso atualizado.');
                break;
        }
    } catch (PDOException $e) {
        flash('danger', 'Não foi possível salvar: já existe um registro com esses dados.');
    }
    redirecionar('configuracoes.php?aba=' . $aba);
}

$cursos = cursos_lista(false);
$titulo = 'Cursos e turmas';
$subtitulo = 'Estrutura da escola: cursos técnicos e turmas por ano letivo, com o(a) diretor(a) de cada turma.';
$menu = 'config';
require __DIR__ . '/../includes/layout_topo.php';
?>

<ul class="nav nav-abas mb-3">
    <?php foreach (['turmas' => ['diagram-3', 'Turmas'], 'cursos' => ['mortarboard', 'Cursos']] as $k => [$ic, $v]): ?>
        <li class="nav-item"><a class="nav-link<?= $aba === $k ? ' active' : '' ?>" href="?aba=<?= $k ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= $v ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($aba === 'turmas'):
    $usuarios_dt = q_todos("SELECT id, nome, foto, perfil FROM usuarios WHERE ativo = 1 AND perfil <> 'admin' ORDER BY nome");
    $ud_por_id = array_column($usuarios_dt, null, 'id');
    $ano_ver = (int)($_GET['ano'] ?? ANO_LETIVO);
    $turmas = q_todos("SELECT t.*, c.nome AS curso, c.sigla, (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id AND a.situacao = 'ativo') AS alunos
                         FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.ano_letivo = ? ORDER BY t.serie, t.letra", [$ano_ver]);
    $cor_curso = []; foreach ($cursos as $c) $cor_curso[$c['id']] = cor_curso($c['nome']);
    $sem_dt = count(array_filter($turmas, fn($t) => $t['ativo'] && empty($t['diretor_turma_id']))); ?>
<div class="cf-topo">
    <div class="cf-resumo">
        <span><b><?= count($turmas) ?></b> turmas</span>
        <span><b><?= array_sum(array_column($turmas, 'alunos')) ?></b> alunos</span>
        <span class="<?= $sem_dt ? 'ambar' : 'ok' ?>"><b><?= $sem_dt ?></b> sem diretor(a) de turma</span>
    </div>
    <form class="d-flex gap-2 align-items-center ms-auto" method="get"><input type="hidden" name="aba" value="turmas">
        <label class="small text-secondary" for="anoVer">Ano letivo</label>
        <select class="form-select form-select-sm" id="anoVer" name="ano" onchange="this.form.submit()" style="width:auto">
            <?php for ($a = ANO_LETIVO + 1; $a >= ANO_LETIVO - 4; $a--): ?><option<?= $a === $ano_ver ? ' selected' : '' ?>><?= $a ?></option><?php endfor; ?></select></form>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#novaTurma"><i class="bi bi-plus-lg me-1"></i>Nova turma</button>
</div>

<div class="collapse mb-3" id="novaTurma"><div class="card"><div class="card-body">
    <form method="post" class="row g-3 align-items-end"><?= csrf_campo() ?><input type="hidden" name="acao" value="turma_add">
        <div class="col-md-4"><label class="form-label">Curso</label><select class="form-select" name="curso_id" required>
            <?php foreach ($cursos as $c) if ($c['ativo']): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endif; ?></select></div>
        <div class="col-4 col-md-2"><label class="form-label">Série</label><select class="form-select" name="serie"><option value="1">1º</option><option value="2">2º</option><option value="3">3º</option></select></div>
        <div class="col-4 col-md-1"><label class="form-label">Letra</label><input class="form-control text-uppercase" name="letra" maxlength="1" required></div>
        <div class="col-4 col-md-2"><label class="form-label">Ano</label><input class="form-control" type="number" name="ano_letivo" value="<?= $ano_ver ?>" required></div>
        <div class="col-md-2"><label class="form-label">Turno</label><input class="form-control" name="turno" value="Integral"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Criar</button></div>
    </form>
    <p class="form-text mt-3 mb-0">Na virada do ano, crie as turmas do novo ano letivo, mude <code>ANO_LETIVO</code> em <code>includes/config.php</code> e atualize a turma dos alunos.</p>
</div></div></div>

<?php if ($turmas): ?>
<div class="tc-grade">
    <?php foreach ($turmas as $t): $dt = $ud_por_id[(int)($t['diretor_turma_id'] ?? 0)] ?? null; ?>
    <article class="tc-card<?= $t['ativo'] ? '' : ' inativa' ?>" style="--tc:<?= $cor_curso[$t['curso_id']] ?? '#13692a' ?>">
        <header class="tc-cab">
            <span class="tc-serie"><?= $t['serie'] ?>º<b><?= e($t['letra']) ?></b></span>
            <div class="min-w-0"><strong><?= e($t['curso']) ?></strong><small><?= e($t['turno']) ?> · <?= $t['ano_letivo'] ?></small></div>
            <span class="tc-status"><?= $t['ativo'] ? 'Ativa' : 'Inativa' ?></span>
        </header>
        <div class="tc-meio">
            <a class="tc-alunos" href="alunos.php?turma=<?= $t['id'] ?>"><i class="bi bi-people"></i><b><?= $t['alunos'] ?></b> alunos</a>
            <a class="tc-link" href="mapa_sala.php?turma=<?= $t['id'] ?>" title="Mapa de turma"><i class="bi bi-grid-3x3"></i></a>
            <a class="tc-link" href="nota_conceitual.php?aba=ver&amp;turma=<?= $t['id'] ?>" title="Nota conceitual"><i class="bi bi-clipboard2-check"></i></a>
        </div>
        <form method="post" class="tc-dt<?= $dt ? '' : ' vazio-dt' ?>"><?= csrf_campo() ?><input type="hidden" name="acao" value="turma_dt"><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <span class="tc-dt-av"><?= $dt ? avatar($dt, 'usuario', 'avatar-sm') : '<i class="bi bi-person-plus"></i>' ?></span>
            <div class="min-w-0 flex-grow-1"><small>Diretor(a) de turma</small>
                <select class="tc-select" name="diretor_turma_id" onchange="this.form.submit()" aria-label="Diretor(a) de turma do <?= $t['serie'] ?>º <?= e($t['letra']) ?>">
                    <option value=""><?= $dt ? '— retirar —' : 'Escolher professor(a)…' ?></option>
                    <?php foreach ($usuarios_dt as $ud): ?><option value="<?= $ud['id'] ?>"<?= $dt && (int)$dt['id'] === (int)$ud['id'] ? ' selected' : '' ?>><?= e(primeiro_ultimo($ud['nome'])) ?></option><?php endforeach; ?>
                </select></div>
            <i class="bi bi-chevron-expand tc-seta"></i>
        </form>
        <footer class="tc-rodape"><form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="turma_status"><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="tc-acao" type="submit"><i class="bi bi-<?= $t['ativo'] ? 'pause-circle' : 'play-circle' ?>"></i><?= $t['ativo'] ? 'Desativar turma' : 'Reativar turma' ?></button></form></footer>
    </article>
    <?php endforeach; ?>
</div>
<p class="form-text mt-3"><i class="bi bi-info-circle me-1"></i>O(a) <strong>diretor(a) de turma</strong> recebe as notificações das ocorrências e pontos de atenção da turma, vê o painel "Minha turma" e registra a busca ativa.</p>
<?php else: ?><div class="card"><div class="vazio"><i class="bi bi-diagram-3"></i><strong>Nenhuma turma em <?= $ano_ver ?></strong>Clique em "Nova turma" para criar.</div></div><?php endif; ?>

<?php elseif ($aba === 'cursos'): ?>
<div class="pg-acoes"><?= janela_botao('novoCurso', 'Novo curso', 'mortarboard') ?></div>
<?= janela_abre('novoCurso', 'Novo curso', 'mortarboard', false, '') ?>
        <form method="post" class="row g-3"><?= csrf_campo() ?><input type="hidden" name="acao" value="curso_add">
            <div class="col-8"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
            <div class="col-4"><label class="form-label">Sigla</label><input class="form-control" name="sigla" maxlength="10" required></div>
            <div class="col-12"><button class="btn btn-primary w-100" type="submit">Cadastrar curso</button></div>
        </form>
<?= janela_fecha() ?>
<?php $usuarios_co = q_todos("SELECT id, nome, foto, perfil FROM usuarios WHERE ativo = 1 AND perfil <> 'admin' ORDER BY nome"); $uc_por_id = array_column($usuarios_co, null, 'id');
    $n_turmas = []; foreach (q_todos('SELECT curso_id, COUNT(*) AS n FROM turmas WHERE ano_letivo = ? GROUP BY curso_id', [ANO_LETIVO]) as $r) $n_turmas[(int)$r['curso_id']] = (int)$r['n']; ?>
<div class="tc-grade">
    <?php foreach ($cursos as $c): $co = $uc_por_id[(int)($c['coordenador_id'] ?? 0)] ?? null; ?>
    <article class="tc-card<?= $c['ativo'] ? '' : ' inativa' ?>" style="--tc:<?= cor_curso($c['nome']) ?>">
        <header class="tc-cab">
            <span class="tc-serie cc-sigla"><?= e($c['sigla']) ?></span>
            <div class="min-w-0"><strong><?= e($c['nome']) ?></strong><small><?= $n_turmas[(int)$c['id']] ?? 0 ?> turma(s) em <?= ANO_LETIVO ?></small></div>
            <span class="tc-status"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span>
        </header>
        <form method="post" class="tc-dt<?= $co ? '' : ' vazio-dt' ?>"><?= csrf_campo() ?><input type="hidden" name="acao" value="curso_coord"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <span class="tc-dt-av"><?= $co ? avatar($co, 'usuario', 'avatar-sm') : '<i class="bi bi-person-plus"></i>' ?></span>
            <div class="min-w-0 flex-grow-1"><small>Coordenador(a) do curso</small>
                <select class="tc-select" name="coordenador_id" onchange="this.form.submit()" aria-label="Coordenador(a) do curso <?= e($c['nome']) ?>">
                    <option value=""><?= $co ? '— retirar —' : 'Escolher pessoa…' ?></option>
                    <?php foreach ($usuarios_co as $uc): ?><option value="<?= $uc['id'] ?>"<?= $co && (int)$co['id'] === (int)$uc['id'] ? ' selected' : '' ?>><?= e(primeiro_ultimo($uc['nome'])) ?></option><?php endforeach; ?>
                </select></div>
            <i class="bi bi-chevron-expand tc-seta"></i>
        </form>
        <details class="cc-editar"><summary><i class="bi bi-pencil me-1"></i>Editar nome e sigla</summary>
            <form method="post" class="row g-2 align-items-center mt-1"><?= csrf_campo() ?><input type="hidden" name="acao" value="curso_editar"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <div class="col-8"><input class="form-control form-control-sm" name="nome" value="<?= e($c['nome']) ?>" aria-label="Nome do curso"></div>
                <div class="col-4"><input class="form-control form-control-sm" name="sigla" value="<?= e($c['sigla']) ?>" aria-label="Sigla"></div>
                <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="ca<?= $c['id'] ?>"<?= $c['ativo'] ? ' checked' : '' ?>><label class="form-check-label" for="ca<?= $c['id'] ?>">Ativo</label></div></div>
                <div class="col-6"><button class="btn btn-sm btn-light w-100" type="submit">Salvar</button></div>
            </form></details>
    </article>
    <?php endforeach; ?>
</div>
<p class="form-text mt-3"><i class="bi bi-info-circle me-1"></i>O(a) <strong>coordenador(a) do curso</strong> pode emitir a liberação de saída dos alunos daquele curso. O(a) diretor(a) de turma emite a dos alunos da turma dele(a).</p>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_rodape.php';
