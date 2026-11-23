<?php
require __DIR__ . '/../includes/app.php';
exigir('ocorrencias');
/* Gestão vê tudo (números e todos os registros); professor vê só o que ele registrou */
$ve_tudo = ocorrencias_ve_tudo();

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $volta = (string)($_POST['volta'] ?? '');
    if ($acao === 'ocorrencia_add') {
        [$t, $m, $ids] = ocorrencia_salvar($_POST);
        flash($t, $m);
        if ($ids && !empty($_POST['imprimir'])) redirecionar('ocorrencia_termo.php?id=' . implode(',', $ids) . '&imprimir=1');
    } elseif ($acao === 'remover' && pode('ocorrencias.excluir')) {
        $o = q_um('SELECT aluno_id FROM ocorrencias WHERE id = ?', [(int)$_POST['id']]);
        if ($o) {
            q('DELETE FROM ocorrencias WHERE id = ?', [(int)$_POST['id']]);
            registrar_log('ocorrencia_removida', null, (int)$o['aluno_id']);
            flash('success', 'Registro apagado.');
        }
    }
    if (preg_match('/^aluno\.php\?id=\d+$/', $volta)) redirecionar($volta . '#ocorrencias');
    redirecionar('ocorrencias.php?' . http_build_query(array_intersect_key($_GET, array_flip(['categoria', 'gravidade', 'turma', 'de', 'ate', 'busca']))));
}

$f = [
    'categoria' => isset(CATEGORIAS_OCORRENCIA[$_GET['categoria'] ?? '']) ? $_GET['categoria'] : '',
    'gravidade' => isset(GRAVIDADES[$_GET['gravidade'] ?? '']) ? $_GET['gravidade'] : '',
    'turma'     => (int)($_GET['turma'] ?? 0),
    'de'        => data_valida($_GET['de'] ?? '') && substr($_GET['de'], 0, 4) == ANO_VER ? $_GET['de'] : ano_inicio(),
    'ate'       => data_valida($_GET['ate'] ?? '') && substr($_GET['ate'], 0, 4) == ANO_VER ? $_GET['ate'] : (vendo_outro_ano() ? ano_fim() : date('Y-m-d')),
    'busca'     => trim((string)($_GET['busca'] ?? '')),
];
$w = ['o.data_ocorrencia BETWEEN ? AND ?']; $p = [$f['de'], $f['ate']];
if ($f['categoria']) { $w[] = 'o.categoria = ?'; $p[] = $f['categoria']; }
if ($f['gravidade']) { $w[] = 'o.gravidade = ?'; $p[] = $f['gravidade']; }
if ($f['turma']) { $w[] = 'a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ?)'; $p[] = $f['turma']; }
if ($f['busca'] !== '') { $w[] = 'a.nome LIKE ?'; $p[] = '%' . $f['busca'] . '%'; }
if (!$ve_tudo) { $w[] = 'o.registrado_por = ?'; $p[] = uid(); }
$lista = ocorrencias_lista(implode(' AND ', $w), $p);

if (($_GET['exportar'] ?? '') === 'csv') {
    enviar_csv('registro-ocorrencias-' . date('Y-m-d') . '.csv', ['Data', 'Hora', 'Aluno', 'Turma', 'Ocorrência', 'Gravidade', 'Local', 'Descrição', 'Providência', 'Suspensão', 'Responsável avisado', 'Registrado por'],
        array_map(fn($o) => [data_br($o['data_ocorrencia']), $o['hora'] ? substr($o['hora'], 0, 5) : '', nome_proprio($o['nome']), turma_nome($o), categoria_ocorrencia($o)[0], GRAVIDADES[$o['gravidade']],
                             $o['local'], $o['descricao'], $o['providencias'], $o['suspensao_inicio'] ? data_br($o['suspensao_inicio']) . ' a ' . data_br($o['suspensao_fim']) : '',
                             $o['responsavel_notificado'] ? 'Sim' : 'Não', $o['por']], $lista));
}

/* Números do período */
$hoje = date('Y-m-d');
$ini_mes = date('Y-m-01');
$n_mes = $ve_tudo ? (int)q_valor('SELECT COUNT(*) FROM ocorrencias WHERE data_ocorrencia >= ?', [$ini_mes]) : 0;
$n_alunos = count(array_unique(array_column($lista, 'aluno_id')));
$suspensos = !$ve_tudo ? [] : ocorrencias_lista("o.categoria = 'suspensao' AND o.suspensao_inicio <= ? AND o.suspensao_fim >= ?", [$hoje, $hoje], 20);
$n_graves = count(array_filter($lista, fn($o) => $o['gravidade'] === 'grave'));
$por_cat = array_count_values(array_column($lista, 'categoria'));
arsort($por_cat);
$por_aluno = [];
foreach ($lista as $o) { $por_aluno[$o['aluno_id']] ??= ['n' => 0, 'o' => $o]; $por_aluno[$o['aluno_id']]['n']++; }
uasort($por_aluno, fn($a, $b) => $b['n'] <=> $a['n']);

/* Atalho vindo de outra tela (ex.: Mapa de turma › aluno fora do mapa) */
$pre = ['categoria' => isset(CATEGORIAS_OCORRENCIA[$_GET['nova'] ?? '']) ? $_GET['nova'] : null];
if ($pre['categoria'] === 'fora_mapa') $pre['descricao'] = 'Aluno(a) fora do lugar definido no mapa de turma' . (preg_match('/^F\d+·\d+$/u', (string)($_GET['lugar'] ?? '')) ? ' (o lugar dele(a) é a carteira ' . $_GET['lugar'] . ')' : '') . '.';
$pre_aluno = (int)($_GET['aluno'] ?? 0) ? aluno_buscar((int)$_GET['aluno']) : null;
if ($pre_aluno && ($pre_aluno['situacao'] ?? 'ativo') !== 'ativo') $pre_aluno = null;

$titulo = $ve_tudo ? 'Registro de ocorrências' : 'Minhas ocorrências';
$abrir_form = $pre_aluno || $pre['categoria'];
$subtitulo = 'Advertências, retiradas de sala, suspensões e demais ocorrências do estudante. Cada registro vai para a ficha do aluno.';
$menu = 'ocorrencias';
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="pg-acoes">
    <div><h2><?= $ve_tudo ? 'Ocorrências da escola' : 'Ocorrências que você registrou' ?></h2>
        <small><?= $ve_tudo ? 'Números e registros de todos os estudantes.' : 'Aqui aparecem só os registros feitos por você. A gestão acompanha todos.' ?></small></div>
    <?= janela_botao('nova', 'Registrar ocorrência', 'shield-plus') ?>
</div>

<?php if ($ve_tudo): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="indicador indicador-destaque"><div class="ic"><i class="bi bi-shield-exclamation"></i></div>
        <div><div class="rotulo">Registros no mês</div><div class="valor"><?= $n_mes ?></div><div class="nota"><?= mb_strtolower(MESES[(int)date('n')]) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="indicador"><div class="ic"><i class="bi bi-people"></i></div>
        <div><div class="rotulo">Alunos envolvidos</div><div class="valor"><?= $n_alunos ?></div><div class="nota">no período filtrado</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="indicador<?= $suspensos ? ' indicador-alerta' : '' ?>"><div class="ic vermelho"><i class="bi bi-slash-circle"></i></div>
        <div><div class="rotulo">Suspensos hoje</div><div class="valor"><?= count($suspensos) ?></div><div class="nota"><?= $suspensos ? e(implode(', ', array_map(fn($o) => primeiro_ultimo(nome_proprio($o['nome'])), array_slice($suspensos, 0, 2)))) . (count($suspensos) > 2 ? '…' : '') : 'nenhuma suspensão vigente' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="indicador"><div class="ic ambar"><i class="bi bi-exclamation-octagon"></i></div>
        <div><div class="rotulo">Graves</div><div class="valor"><?= $n_graves ?></div><div class="nota">no período filtrado</div></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($ve_tudo && ($por_aluno || $por_cat)): ?>
    <div class="col-xl-4 order-xl-2">
        <?php if ($por_aluno): ?>
        <div class="card mb-3"><div class="card-header"><h2>Alunos com mais registros</h2><span class="small text-secondary">no período</span></div>
            <ul class="lista-pessoas">
                <?php foreach (array_slice($por_aluno, 0, 8, true) as $aid => $x): ?>
                    <li><?= avatar(['id' => $aid, 'nome' => $x['o']['nome'], 'foto' => $x['o']['foto']], 'aluno', 'avatar-sm') ?>
                        <a class="nome-link" href="aluno.php?id=<?= $aid ?>#ocorrencias"><?= e(nome_proprio($x['o']['nome'])) ?></a><small><?= e($x['o']['serie'] . 'º ' . $x['o']['letra']) ?></small>
                        <span class="etiqueta <?= $x['n'] >= 3 ? 'vermelho' : 'cinza' ?> ms-auto"><?= $x['n'] ?></span></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
        <?php if ($por_cat): $max = max($por_cat); ?>
        <div class="card"><div class="card-header"><h2>Por tipo</h2></div><div class="card-body py-2"><ul class="lista-linhas">
            <?php foreach ($por_cat as $c => $n): ?>
                <li><a href="?<?= e(http_build_query(['categoria' => $c] + $f)) ?>" style="width:190px" class="text-truncate text-reset"><?= e(CATEGORIAS_OCORRENCIA[$c][0] ?? $c) ?></a><span class="barra-prop"><span style="width:<?= round($n / $max * 100) ?>%"></span></span><strong style="width:28px;text-align:right"><?= $n ?></strong></li>
            <?php endforeach; ?></ul></div></div>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
    <?php else: ?>
    <div class="col-12">
    <?php endif; ?>
        <form class="filtros row g-2 align-items-end" method="get">
            <div class="col-md-6"><label class="form-label" for="f_busca">Aluno</label><input class="form-control" id="f_busca" name="busca" value="<?= e($f['busca']) ?>" placeholder="Nome do aluno"></div>
            <div class="col-md-6"><label class="form-label" for="f_cat">Ocorrência</label>
                <select class="form-select" id="f_cat" name="categoria"><option value="">Todas</option>
                <?php foreach (CATEGORIAS_OCORRENCIA as $k => [$v]): ?><option value="<?= $k ?>"<?= $f['categoria'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label" for="f_grav">Gravidade</label>
                <select class="form-select" id="f_grav" name="gravidade"><option value="">Todas</option>
                <?php foreach (GRAVIDADES as $k => $v): ?><option value="<?= $k ?>"<?= $f['gravidade'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <?php if ($ve_tudo): ?><div class="col-6 col-md-3"><label class="form-label" for="f_turma">Turma</label>
                <select class="form-select" id="f_turma" name="turma"><option value="">Todas</option>
                <?php foreach (turmas_lista() as $t): ?><option value="<?= $t['id'] ?>"<?= $f['turma'] === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['serie'] . 'º ' . $t['letra'] . ' · ' . ($t['sigla'] ?? $t['curso'])) ?></option><?php endforeach; ?></select></div>
            <?php else: ?><input type="hidden" name="turma" value=""><?php endif; ?>
            <div class="col-6 col-md-3"><label class="form-label" for="f_de">De</label><input type="date" class="form-control" id="f_de" name="de" value="<?= e($f['de']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="f_ate">Até</label><input type="date" class="form-control" id="f_ate" name="ate" value="<?= e($f['ate']) ?>"></div>
            <div class="col-12 d-flex gap-2 justify-content-end">
                <a class="btn btn-light" href="?<?= e(http_build_query($f)) ?>&exportar=csv"><i class="bi bi-filetype-csv me-1"></i>Exportar</a>
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>
        <?php if ($lista): ?>
            <p class="small text-secondary mb-2"><?= count($lista) ?> registro(s)<?= count($lista) >= 300 ? ' (mostrando os 300 mais recentes)' : '' ?></p>
            <?php foreach ($lista as $o) echo ocorrencia_cartao($o); ?>
        <?php else: ?>
            <div class="card"><div class="vazio"><i class="bi bi-clipboard-check"></i><strong>Nenhum registro no período</strong>Toque em "Registrar ocorrência" para registrar.</div></div>
        <?php endif; ?>
    </div>
</div>
<?= janela_abre('nova', 'Registrar ocorrência', 'shield-plus', (bool)$abrir_form) ?>
    <?php if ($pre_aluno): ?><div class="oc-pre"><?= avatar($pre_aluno, 'aluno', 'avatar-md') ?><div><strong><?= e(nome_proprio($pre_aluno['nome'])) ?></strong><small><?= e(turma_nome($pre_aluno)) ?></small></div>
        <a class="ms-auto small" href="ocorrencias.php<?= $pre['categoria'] ? '?nova=' . e($pre['categoria']) : '' ?>#nova">trocar aluno</a></div><?php endif; ?>
    <?= form_ocorrencia($pre_aluno, '', $pre) ?>
<?= janela_fecha() ?>
<?php require __DIR__ . '/../includes/layout_rodape.php';
