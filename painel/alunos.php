<?php
require __DIR__ . '/../includes/app.php';
exigir('alunos.ver');

/* Exclusão (somente administrador) */
if (eh_post() && ($_POST['acao'] ?? '') === 'excluir') {
    csrf_verificar();
    if (!pode('alunos.excluir')) { flash('danger', 'Somente o administrador pode excluir alunos.'); redirecionar('alunos.php'); }
    $a = aluno_buscar((int)$_POST['id']);
    if ($a) {
        registrar_log('aluno_excluido', $a['nome']);
        q('DELETE FROM alunos WHERE id = ?', [$a['id']]);
        flash('success', 'Aluno ' . nome_proprio($a['nome']) . ' excluído, junto com faltas, notas e registros.');
    }
    redirecionar('alunos.php');
}

/* Filtros */
$f = [
    'busca'    => trim((string)($_GET['busca'] ?? '')),
    'curso'    => (int)($_GET['curso'] ?? 0),
    'turma'    => (int)($_GET['turma'] ?? 0),
    'serie'    => (int)($_GET['serie'] ?? 0),
    'situacao' => array_key_exists($_GET['situacao'] ?? 'ativo', SITUACOES) || ($_GET['situacao'] ?? '') === 'todas' ? ($_GET['situacao'] ?? 'ativo') : 'ativo',
];

$w = ['1=1'];
$p = [];
if ($f['busca'] !== '') { $w[] = '(a.nome LIKE ? OR a.matricula LIKE ?)'; $p[] = '%' . $f['busca'] . '%'; $p[] = $f['busca'] . '%'; }
if ($f['curso']) { $w[] = 't.curso_id = ?'; $p[] = $f['curso']; }
if ($f['turma']) { $w[] = 'a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?)'; $p[] = $f['turma']; }
if ($f['serie']) { $w[] = 't.serie = ?'; $p[] = $f['serie']; }
if ($f['situacao'] !== 'todas') { $w[] = 'a.situacao = ?'; $p[] = $f['situacao']; }
if (vendo_outro_ano()) $w[] = 'EXISTS (SELECT 1 FROM aluno_turmas xa2 WHERE xa2.aluno_id = a.id AND xa2.ano_letivo = @ano_ver)';   // só quem estudou no ano escolhido

$alunos = q_todos('SELECT a.id, a.matricula, a.nome, a.foto, a.situacao, a.responsavel_telefone, t.serie, t.letra, c.nome AS curso,
                          (SELECT COUNT(*) FROM faltas f WHERE f.aluno_id = a.id AND f.data_falta BETWEEN ? AND ?)
                          + (SELECT COALESCE(SUM(fb.faltas), 0) FROM faltas_bimestre fb WHERE fb.aluno_id = a.id AND fb.ano_letivo = ?) AS faltas,
                          (SELECT COUNT(*) FROM ocorrencias o WHERE o.aluno_id = a.id AND o.tipo <> \'elogio\' AND o.data_ocorrencia BETWEEN ? AND ?) AS ocorrencias
                     FROM alunos a
                     LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id)
                     LEFT JOIN cursos c ON c.id = t.curso_id
                    WHERE ' . implode(' AND ', $w) . '
                    ORDER BY t.serie, t.letra, a.nome',
                  array_merge([ano_inicio(), ano_fim(), ANO_VER, ano_inicio(), ano_fim()], $p));

if (($_GET['exportar'] ?? '') === 'csv') {
    registrar_log('exportacao', 'Lista de alunos (' . count($alunos) . ')');
    enviar_csv('alunos-' . date('Y-m-d') . '.csv',
        ['Matrícula', 'Nome', 'Curso', 'Turma', 'Situação', 'Faltas no ano', 'Ocorrências', 'Telefone do responsável'],
        array_map(fn($a) => [$a['matricula'], nome_proprio($a['nome']), $a['curso'], $a['serie'] ? $a['serie'] . 'º ' . $a['letra'] : '',
                             SITUACOES[$a['situacao']], $a['faltas'], $a['ocorrencias'], $a['responsavel_telefone']], $alunos));
}

$em_alerta = array_column(alertas_sequencia(true), 'dias', 'aluno_id');
$titulo = 'Alunos';
$subtitulo = count($alunos) . ' ' . (count($alunos) === 1 ? 'aluno encontrado' : 'alunos encontrados');
$menu = 'alunos';
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';
$qs = http_build_query(array_filter($f));
?>

<form class="filtros row g-2 align-items-end" method="get">
    <div class="col-md-4 col-lg-3">
        <label class="form-label" for="f_busca">Nome ou matrícula</label>
        <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="f_busca" name="busca" value="<?= e($f['busca']) ?>" placeholder="Buscar aluno">
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <label class="form-label" for="f_curso">Curso</label>
        <select class="form-select" id="f_curso" name="curso">
            <option value="">Todos</option>
            <?php foreach (cursos_lista() as $c): ?><option value="<?= $c['id'] ?>"<?= $f['curso'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <label class="form-label" for="f_serie">Série</label>
        <select class="form-select" id="f_serie" name="serie">
            <option value="">Todas</option>
            <?php foreach ([1, 2, 3] as $s): ?><option value="<?= $s ?>"<?= $f['serie'] === $s ? ' selected' : '' ?>><?= $s ?>º Ano</option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <label class="form-label" for="f_turma">Turma</label>
        <select class="form-select" id="f_turma" name="turma">
            <option value="">Todas</option>
            <?php foreach (turmas_lista() as $t): ?><option value="<?= $t['id'] ?>"<?= $f['turma'] === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-4 col-lg-1">
        <label class="form-label" for="f_sit">Situação</label>
        <select class="form-select" id="f_sit" name="situacao">
            <?php foreach (SITUACOES as $k => $v): ?><option value="<?= $k ?>"<?= $f['situacao'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            <option value="todas"<?= $f['situacao'] === 'todas' ? ' selected' : '' ?>>Todas</option>
        </select>
    </div>
    <div class="col-md-4 col-lg-2 d-flex gap-2">
        <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        <a class="btn btn-light" href="alunos.php" title="Limpar filtros" aria-label="Limpar filtros"><i class="bi bi-x-lg"></i></a>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <h2>Lista de alunos</h2>
        <div class="acoes">
            <a class="btn btn-sm btn-light" href="?<?= $qs ?>&exportar=csv"><i class="bi bi-filetype-csv me-1"></i>Exportar planilha</a>
            <?php if (pode('alunos.editar')): ?><a class="btn btn-sm btn-primary" href="aluno_form.php"><i class="bi bi-person-plus me-1"></i>Inserir aluno</a><?php endif; ?>
        </div>
    </div>
    <?php if ($alunos): ?>
    <div class="table-responsive tabela-rolagem">
        <table class="table table-hover">
            <thead><tr>
                <th>Aluno</th><th>Turma</th><th>Situação</th>
                <th class="num" title="Faltas em <?= ANO_VER ?>">Faltas</th><th class="num">Ocorrências</th><th class="text-end">Ações</th>
            </tr></thead>
            <tbody>
            <?php foreach ($alunos as $a): ?>
                <tr>
                    <td><div class="d-flex gap-2 align-items-center"><?= avatar($a, 'aluno', 'avatar-sm') ?><div>
                        <a class="nome-link" href="aluno.php?id=<?= $a['id'] ?>"><?= e(nome_proprio($a['nome'])) ?></a>
                        <?php if (isset($em_alerta[$a['id']])): ?><span class="selo-sequencia ms-1" title="<?= e(dias_curtos($em_alerta[$a['id']])) ?>"><i class="bi bi-exclamation-triangle-fill"></i><?= count($em_alerta[$a['id']]) ?> seguidas</span><?php endif; ?>
                        <?php if ($a['matricula']): ?><br><small class="text-secondary">Matrícula <?= e($a['matricula']) ?></small><?php endif; ?>
                    </div></div></td>
                    <td><span class="etiqueta contorno"><?= e(turma_nome($a)) ?></span></td>
                    <td><span class="etiqueta <?= $a['situacao'] === 'ativo' ? '' : 'cinza' ?>"><?= e(SITUACOES[$a['situacao']]) ?></span></td>
                    <td class="num"><?= badge_faltas((int)$a['faltas']) ?></td>
                    <td class="num"><?= (int)$a['ocorrencias'] ?: '<span class="text-secondary">0</span>' ?></td>
                    <td class="td-acoes">
                        <a class="btn btn-sm btn-light" href="aluno.php?id=<?= $a['id'] ?>" title="Abrir ficha"><i class="bi bi-person-vcard"></i><span class="d-none d-xl-inline ms-1">Ficha</span></a>
                        <?php if (pode('alunos.editar')): ?>
                            <a class="btn btn-sm btn-light" href="aluno_form.php?id=<?= $a['id'] ?>" title="Editar dados" aria-label="Editar dados"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if (pode('alunos.excluir')): ?>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-excluir="<?= $a['id'] ?>" data-nome="<?= e(nome_proprio($a['nome'])) ?>" title="Excluir aluno" aria-label="Excluir aluno"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="vazio"><i class="bi bi-search"></i><strong>Nenhum aluno com esses filtros</strong>
            Ajuste a busca ou <a href="alunos.php">limpe os filtros</a>.</div>
    <?php endif; ?>
</div>
<?php if (pode('alunos.excluir')): ?>
<form method="post" id="formExcluir" data-confirmar="" data-botao="Excluir aluno">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" id="excluirId">
</form>
<?php endif; ?>
<p class="small text-secondary mt-3 mb-0">
    Cores das faltas: <span class="badge-faltas faltas-ok">0–<?= FALTAS_ATENCAO - 1 ?></span> regular ·
    <span class="badge-faltas faltas-atencao"><?= FALTAS_ATENCAO ?>+</span> atenção ·
    <span class="badge-faltas faltas-critico"><?= FALTAS_CRITICO ?>+</span> busca ativa
</p>

<?php
$script_pagina = '<script>
document.querySelectorAll("[data-excluir]").forEach(b => b.addEventListener("click", () => {
  const f = document.getElementById("formExcluir");
  document.getElementById("excluirId").value = b.dataset.excluir;
  f.dataset.confirmar = "Excluir " + b.dataset.nome + "? Todas as faltas, notas e ocorrências deste aluno também serão apagados. Esta ação não pode ser desfeita.";
  f.requestSubmit();
}));
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
