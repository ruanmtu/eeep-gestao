<?php
require __DIR__ . '/../includes/app.php';
exigir('importar');

$imp = $_SESSION['imp'] ?? null;
if ($imp && !is_file($imp['arquivo'])) { unset($_SESSION['imp']); $imp = null; }

/* ---------- Modelos para baixar ---------- */
if (isset($_GET['modelo'])) {
    $t = turmas_lista();
    $tn = fn($i) => isset($t[$i]) ? $t[$i]['serie'] . 'º ' . $t[$i]['letra'] . ' ' . $t[$i]['curso'] : '1º A Administração';
    if ($_GET['modelo'] === 'longa') {
        enviar_csv('modelo_uma_linha_por_nota.csv', ['Matrícula', 'Aluno', 'Turma', 'Bimestre', 'Disciplina', 'Nota', 'Faltas'], [
            ['2026001', 'Ana Maria da Silva', $tn(0), '1', 'Língua Portuguesa', '8,5', '2'],
            ['2026001', 'Ana Maria da Silva', $tn(0), '1', 'Matemática', '7,0', '0'],
            ['2026002', 'Bruno Lima Souza', $tn(0), '1', 'Língua Portuguesa', '6,5', '4'],
        ]);
    }
    enviar_csv('modelo_uma_linha_por_aluno.csv', ['Matrícula', 'Aluno', 'Turma', 'Faltas', 'Língua Portuguesa', 'Matemática', 'Física', 'Química', 'Biologia', 'História', 'Geografia'], [
        ['2026001', 'Ana Maria da Silva', $tn(0), '3', '8,5', '7,0', '6,8', '7,5', '9,0', '8,0', '7,7'],
        ['2026002', 'Bruno Lima Souza', $tn(0), '7', '6,5', '5,0', '5,5', '6,0', '7,0', '6,8', '7,2'],
    ]);
}

/* ---------- Ações ---------- */
if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'cancelar') {
        if ($imp) @unlink($imp['arquivo']);
        unset($_SESSION['imp'], $_SESSION['imp_previa']);
        redirecionar('importar.php');
    }

    if ($acao === 'enviar') {
        $f = $_FILES['planilha'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $msg = [UPLOAD_ERR_INI_SIZE => 'O arquivo é maior que o limite do servidor (' . ini_get('upload_max_filesize') . ').', UPLOAD_ERR_FORM_SIZE => 'O arquivo é grande demais.', UPLOAD_ERR_NO_FILE => 'Escolha o arquivo da planilha.'][$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Não foi possível receber o arquivo.';
            flash('warning', $msg); redirecionar('importar.php');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xls') { flash('warning', 'Arquivos .xls (Excel antigo) não são lidos. No Excel, use "Salvar como" e escolha .xlsx ou CSV.'); redirecionar('importar.php'); }
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) { flash('warning', 'Envie um arquivo CSV ou Excel (.xlsx).'); redirecionar('importar.php'); }
        if ($imp) @unlink($imp['arquivo']);
        $destino = importacao_pasta() . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $destino)) { flash('danger', 'Não foi possível salvar o arquivo no servidor.'); redirecionar('importar.php'); }
        try { $abas = planilha_ler($destino, $ext); }
        catch (Throwable $e) { @unlink($destino); flash('danger', $e->getMessage()); redirecionar('importar.php'); }
        $abas = array_filter($abas, fn($l) => count($l) > 1) ?: $abas;
        $aba = array_key_first($abas);
        $_SESSION['imp'] = ['arquivo' => $destino, 'nome' => mb_substr($f['name'], 0, 200), 'ext' => $ext, 'aba' => $aba, 'cab' => planilha_cabecalho($abas[$aba])];
        unset($_SESSION['imp_previa']);
        redirecionar('importar.php');
    }

    if (!$imp) redirecionar('importar.php');
    $abas = planilha_ler($imp['arquivo'], $imp['ext']);

    if ($acao === 'aba') {
        $aba = (string)($_POST['aba'] ?? '');
        if (isset($abas[$aba])) { $_SESSION['imp']['aba'] = $aba; $_SESSION['imp']['cab'] = planilha_cabecalho($abas[$aba]); }
        if (isset($_POST['cab'])) $_SESSION['imp']['cab'] = max(0, (int)$_POST['cab']);
        unset($_SESSION['imp']['mapa'], $_SESSION['imp_previa']);
        redirecionar('importar.php');
    }

    if ($acao === 'previa' || $acao === 'importar') {
        if ($acao === 'previa') {
            $validos = array_merge(array_keys(IMPORT_CAMPOS), ['ignorar']);
            $mapa = [];
            foreach ((array)($_POST['mapa'] ?? []) as $i => $k) {
                $k = (string)$k;
                $mapa[(int)$i] = in_array($k, $validos, true) || preg_match('/^nota:[a-z0-9 ]+:[0-4]$/', $k) ? $k : 'ignorar';
            }
            $op = [
                'ano'          => max(2000, min(2100, (int)($_POST['ano'] ?? ANO_LETIVO))),
                'bimestre'     => max(1, min(4, (int)($_POST['bimestre'] ?? 1))),
                'tipo_nota'    => in_array($_POST['tipo_nota'] ?? '', ['media', 'global', 'parcial', 'conceitual'], true) ? $_POST['tipo_nota'] : 'media',
                'criar'        => !empty($_POST['criar']),
                'atualizar'    => in_array($_POST['atualizar'] ?? '', ['vazios', 'substituir', 'nao'], true) ? $_POST['atualizar'] : 'vazios',
                'turma_padrao' => (int)($_POST['turma_padrao'] ?? 0),
            ];
            $_SESSION['imp']['mapa'] = $mapa;
            $_SESSION['imp']['op'] = $op;
            $usados = array_count_values(array_filter($mapa, fn($k) => $k !== 'ignorar' && !str_starts_with($k, 'nota:')));
            if (!in_array('nome', $mapa, true) && !in_array('matricula', $mapa, true)) { flash('warning', 'Indique qual coluna tem o nome do aluno (ou a matrícula).'); redirecionar('importar.php'); }
            if ($rep = array_keys(array_filter($usados, fn($n) => $n > 1))) { flash('warning', 'Cada informação só pode estar em uma coluna. Repetido: ' . implode(', ', array_map('importacao_rotulo', $rep)) . '.'); redirecionar('importar.php'); }
        }
        $mapa = $_SESSION['imp']['mapa'] ?? null; $op = $_SESSION['imp']['op'] ?? null;
        if (!$mapa || !$op) redirecionar('importar.php');
        $linhas = array_slice($abas[$imp['aba']] ?? [], $imp['cab'] + 1, null, true);
        try { $res = importacao_processar($linhas, $mapa, $op, $acao === 'importar'); }
        catch (Throwable $e) { flash('danger', 'A importação parou com um erro: ' . $e->getMessage()); redirecionar('importar.php'); }
        if ($acao === 'previa') { $_SESSION['imp_previa'] = $res; redirecionar('importar.php?etapa=previa'); }

        $resumo = array_diff_key($res, ['problemas' => 1]) + ['n_problemas' => count($res['problemas']), 'op' => $op, 'aba' => $imp['aba']];
        q('INSERT INTO importacoes (arquivo, linhas, resumo, usuario_id) VALUES (?, ?, ?, ?)', [$imp['nome'], $res['linhas'], json_encode($resumo, JSON_UNESCAPED_UNICODE), uid()]);
        registrar_log('importacao', $imp['nome'] . ': ' . $res['alunos'] . ' alunos, ' . $res['notas'] . ' notas, ' . ($res['faltas_dias'] + $res['faltas_total']) . ' faltas');
        @unlink($imp['arquivo']);
        $_SESSION['imp_feito'] = $res + ['arquivo' => $imp['nome']];
        unset($_SESSION['imp'], $_SESSION['imp_previa']);
        redirecionar('importar.php?etapa=feito');
    }
    redirecionar('importar.php');
}

$etapa = $_GET['etapa'] ?? '';
$titulo = 'Importar planilha';
$subtitulo = 'Suba a planilha que alimenta o Power BI e o sistema preenche alunos, faltas e notas sozinho.';
$menu = 'importar';
require __DIR__ . '/../includes/layout_topo.php';

/* Resumo em números (prévia e resultado) */
function quadro_resultado(array $r, bool $previa): string
{
    $itens = [
        ['people', 'Alunos identificados', $r['alunos'], $r['turmas'] . ' turma(s)'],
        ['person-plus', 'Alunos novos', $r['novos'], $previa ? 'serão cadastrados' : 'cadastrados'],
        ['pencil', 'Cadastros atualizados', $r['atualizados'], 'dados pessoais / turma'],
        ['journal-check', 'Notas', $r['notas'], $r['avaliacoes_criadas'] ? $r['avaliacoes_criadas'] . ' avaliação(ões) criadas' : 'médias atualizadas'],
        ['calendar-x', 'Faltas', $r['faltas_dias'] + $r['faltas_total'], $r['faltas_dias'] ? $r['faltas_dias'] . ' por data' : 'total do bimestre'],
        ['exclamation-triangle', 'Avisos', count($r['problemas']) + count($r['disc_desconhecidas']), $r['linhas'] . ' linha(s) lidas'],
    ];
    $h = '<div class="row g-3 mb-3">';
    foreach ($itens as $i => [$ic, $rot, $val, $nota]) {
        $classe = $i === 0 ? ' indicador-destaque' : ($i === 5 && $val ? ' indicador-alerta' : '');
        $h .= '<div class="col-6 col-lg-4 col-xxl-2"><div class="indicador' . $classe . '"><div class="ic' . ($i === 5 ? ' ambar' : '') . '"><i class="bi bi-' . $ic . '"></i></div><div><div class="rotulo">' . $rot . '</div><div class="valor">' . number_format($val, 0, ',', '.') . '</div><div class="nota">' . e($nota) . '</div></div></div></div>';
    }
    return $h . '</div>';
}
function lista_avisos(array $r): string
{
    $h = '';
    if ($r['disc_desconhecidas']) {
        $h .= '<div class="alert alert-warning"><strong>Disciplinas não reconhecidas</strong> (as notas delas não entram): ';
        $h .= implode(', ', array_map(fn($d, $n) => '<span class="etiqueta ambar">' . e($d) . ' · ' . $n . '</span>', array_keys($r['disc_desconhecidas']), $r['disc_desconhecidas']));
        $h .= '<br><small>Cadastre a disciplina em <a href="configuracoes.php">Cursos e turmas</a> com o mesmo nome, ou confira se a turma do aluno está certa (disciplinas técnicas valem só para o curso delas).</small></div>';
    }
    if ($r['problemas']) {
        $h .= '<div class="card"><div class="card-header"><h2>Linhas com aviso</h2><span class="etiqueta ambar">' . count($r['problemas']) . (count($r['problemas']) >= 300 ? '+' : '') . '</span>'
            . '<span class="small text-secondary ms-auto">o resto da planilha é importado normalmente</span></div><div class="table-responsive tabela-rolagem" style="max-height:340px"><table class="table table-sm mb-0"><thead><tr><th style="width:90px">Linha</th><th>O que aconteceu</th></tr></thead><tbody>';
        foreach ($r['problemas'] as $p) $h .= '<tr><td class="text-secondary">' . $p['linha'] . '</td><td>' . e($p['msg']) . '</td></tr>';
        $h .= '</tbody></table></div></div>';
    }
    return $h;
}
?>

<?php if ($etapa === 'feito' && ($r = $_SESSION['imp_feito'] ?? null)): ?>
    <div class="cartao-sucesso mb-3">
        <i class="bi bi-check-circle-fill"></i>
        <div><h2>Planilha importada</h2><p class="mb-0"><?= e($r['arquivo']) ?> · os dados já aparecem nas fichas, no boletim, nos relatórios e na apresentação.</p></div>
    </div>
    <?= quadro_resultado($r, false) ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-primary" href="apresentacao.php"><i class="bi bi-easel me-1"></i>Abrir a apresentação</a>
        <a class="btn btn-light" href="relatorios.php"><i class="bi bi-bar-chart-line me-1"></i>Relatórios</a>
        <a class="btn btn-light" href="alunos.php"><i class="bi bi-people me-1"></i>Alunos</a>
        <a class="btn btn-light ms-auto" href="importar.php"><i class="bi bi-cloud-arrow-up me-1"></i>Importar outra planilha</a>
    </div>
    <?= lista_avisos($r) ?>

<?php elseif ($imp && $etapa === 'previa' && ($r = $_SESSION['imp_previa'] ?? null)): ?>
    <ol class="passos-importacao mb-3"><li class="feito">Enviar</li><li class="feito">Conferir colunas</li><li class="atual">Prévia</li><li>Importar</li></ol>
    <div class="alert alert-info d-flex gap-2"><i class="bi bi-eye"></i><div><strong>Prévia: nada foi gravado ainda.</strong> Veja abaixo o que vai acontecer quando você confirmar.</div></div>
    <?= quadro_resultado($r, true) ?>
    <form method="post" class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <?= csrf_campo() ?>
        <a class="btn btn-light" href="importar.php"><i class="bi bi-arrow-left me-1"></i>Voltar e ajustar</a>
        <span class="small text-secondary"><?= e($imp['nome']) ?> · <?= ['media' => 'notas como média do bimestre', 'global' => 'notas como avaliação global', 'parcial' => 'notas como avaliação parcial', 'conceitual' => 'notas como nota conceitual'][$imp['op']['tipo_nota']] ?> · <?= $imp['op']['bimestre'] ?>º bimestre padrão</span>
        <button class="btn btn-primary ms-auto" name="acao" value="importar" type="submit" <?= !$r['alunos'] ? 'disabled' : '' ?>><i class="bi bi-cloud-check me-1"></i>Confirmar e importar</button>
    </div></form>
    <?= lista_avisos($r) ?>

<?php elseif ($imp):
    $abas = planilha_ler($imp['arquivo'], $imp['ext']);
    $linhas = $abas[$imp['aba']] ?? [];
    $cab = $linhas[$imp['cab']] ?? [];
    $dados = array_values(array_filter(array_slice($linhas, $imp['cab'] + 1), fn($l) => array_filter($l, 'strlen')));
    $ncol = max(count($cab), ...array_map('count', array_slice($dados, 0, 30) ?: [[]]));
    $mapa = $imp['mapa'] ?? importacao_sugerir(array_pad($cab, $ncol, ''));
    $op = $imp['op'] ?? ['ano' => ANO_LETIVO, 'bimestre' => 1, 'tipo_nota' => 'media', 'criar' => true, 'atualizar' => 'vazios', 'turma_padrao' => 0];
    $reconhecidos = array_filter($mapa, fn($k) => $k !== 'ignorar');
    $n_disc = count(array_filter($reconhecidos, fn($k) => str_starts_with($k, 'nota:')));
    $letra = function (int $i) { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $opcoes = function (string $atual) {
        $h = '<option value="ignorar">— não importar —</option>';
        foreach (IMPORT_GRUPOS as $g) {
            $h .= '<optgroup label="' . e($g) . '">';
            foreach (IMPORT_CAMPOS as $k => [$rot, $gr]) if ($gr === $g) $h .= '<option value="' . $k . '"' . ($atual === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
            $h .= '</optgroup>';
        }
        $h .= '<optgroup label="Nota de uma disciplina (uma coluna por disciplina)">';
        $tem = false;
        foreach (disciplinas_nomes() as $n => $nome) {
            $k = "nota:$n:0";
            $tem = $tem || $atual === $k;
            $h .= '<option value="' . e($k) . '"' . ($atual === $k ? ' selected' : '') . '>Nota de ' . e($nome) . '</option>';
        }
        if (str_starts_with($atual, 'nota:') && !$tem) $h .= '<option value="' . e($atual) . '" selected>' . e(importacao_rotulo($atual)) . '</option>';
        return $h . '</optgroup>';
    };
    ?>
    <ol class="passos-importacao mb-3"><li class="feito">Enviar</li><li class="atual">Conferir colunas</li><li>Prévia</li><li>Importar</li></ol>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <div><div class="rotulo-arquivo"><i class="bi bi-file-earmark-spreadsheet text-success"></i> <?= e($imp['nome']) ?></div>
                <small class="text-secondary"><?= count($dados) ?> linha(s) de dados · <?= $ncol ?> coluna(s)</small></div>
            <form method="post" class="d-flex gap-2 align-items-end ms-auto flex-wrap">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="aba">
                <?php if (count($abas) > 1): ?>
                <div><label class="form-label" for="aba">Aba</label><select class="form-select" id="aba" name="aba" onchange="this.form.submit()">
                    <?php foreach ($abas as $nome => $l): ?><option<?= $nome === $imp['aba'] ? ' selected' : '' ?>><?= e($nome) ?></option><?php endforeach; ?></select></div>
                <?php else: ?><input type="hidden" name="aba" value="<?= e($imp['aba']) ?>"><?php endif; ?>
                <div><label class="form-label" for="cab">Linha do cabeçalho</label><select class="form-select" id="cab" name="cab" onchange="this.form.submit()">
                    <?php foreach (array_slice($linhas, 0, 15, true) as $i => $l): ?><option value="<?= $i ?>"<?= $i === $imp['cab'] ? ' selected' : '' ?>>Linha <?= $i + 1 ?>: <?= e(mb_strimwidth(implode(' · ', array_filter($l, 'strlen')), 0, 60, '…')) ?></option><?php endforeach; ?></select></div>
            </form>
            <form method="post"><?= csrf_campo() ?><button class="btn btn-light" name="acao" value="cancelar" type="submit">Trocar arquivo</button></form>
        </div>
    </div>

    <form method="post" id="formMapa">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="previa">
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header"><h2>O que tem em cada coluna</h2>
                        <span class="etiqueta"><?= count($reconhecidos) ?> de <?= $ncol ?> reconhecidas<?= $n_disc ? ' · ' . $n_disc . ' disciplina(s)' : '' ?></span></div>
                    <div class="table-responsive"><table class="table mapa-colunas mb-0">
                        <thead><tr><th style="width:44px"></th><th>Coluna da planilha</th><th>Exemplos</th><th style="width:300px">Vai para</th></tr></thead>
                        <tbody>
                        <?php for ($i = 0; $i < $ncol; $i++): $ex = array_slice(array_filter(array_map(fn($l) => $l[$i] ?? '', array_slice($dados, 0, 40)), 'strlen'), 0, 3); ?>
                            <tr class="<?= ($mapa[$i] ?? 'ignorar') === 'ignorar' ? 'ignorada' : '' ?>">
                                <td><span class="letra-col"><?= $letra($i) ?></span></td>
                                <td><strong><?= e(($cab[$i] ?? '') !== '' ? $cab[$i] : '(sem título)') ?></strong></td>
                                <td class="small text-secondary"><?= e(mb_strimwidth(implode(' · ', $ex), 0, 70, '…')) ?: '—' ?></td>
                                <td><select class="form-select form-select-sm" name="mapa[<?= $i ?>]" aria-label="Destino da coluna <?= $letra($i) ?>"><?= $opcoes($mapa[$i] ?? 'ignorar') ?></select></td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header"><h2>Como importar</h2></div>
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label" for="o_ano">Ano letivo</label><input class="form-control" type="number" id="o_ano" name="ano" value="<?= (int)$op['ano'] ?>"></div>
                            <div class="col-6"><label class="form-label" for="o_bim">Bimestre</label><select class="form-select" id="o_bim" name="bimestre">
                                <?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>"<?= $op['bimestre'] === $b ? ' selected' : '' ?>><?= $b ?>º bimestre</option><?php endfor; ?></select></div>
                            <div class="col-12 form-text mt-0">Usado quando a planilha não tem coluna de bimestre.</div>
                        </div>
                        <label class="form-label">As notas da planilha são</label>
                        <?php foreach (['media' => ['Média do bimestre', 'a nota que já vem pronta do Power BI'], 'global' => ['Avaliação global', 'entra na média com as outras avaliações'], 'parcial' => ['Avaliação parcial', 'entra na média com as outras avaliações'], 'conceitual' => ['Nota conceitual', 'entra na média com as outras avaliações']] as $k => [$r1, $r2]): ?>
                            <div class="form-check"><input class="form-check-input" type="radio" name="tipo_nota" id="tn_<?= $k ?>" value="<?= $k ?>"<?= $op['tipo_nota'] === $k ? ' checked' : '' ?>>
                                <label class="form-check-label" for="tn_<?= $k ?>"><?= $r1 ?> <small class="text-secondary">· <?= $r2 ?></small></label></div>
                        <?php endforeach; ?>
                        <hr>
                        <label class="form-label" for="o_turma">Turma da planilha</label>
                        <select class="form-select mb-1" id="o_turma" name="turma_padrao"><option value="0">A planilha tem coluna de turma / várias turmas</option>
                            <?php foreach (turmas_lista() as $t): ?><option value="<?= $t['id'] ?>"<?= $op['turma_padrao'] === (int)$t['id'] ? ' selected' : '' ?>>Só a turma <?= e(turma_nome($t)) ?></option><?php endforeach; ?></select>
                        <div class="form-text mb-3">Use se a planilha for de uma turma só e não tiver coluna de turma.</div>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="o_criar" name="criar" value="1"<?= $op['criar'] ? ' checked' : '' ?>>
                            <label class="form-check-label" for="o_criar">Cadastrar alunos que ainda não estão no sistema</label></div>
                        <label class="form-label" for="o_atu">Dados de quem já está cadastrado</label>
                        <select class="form-select" id="o_atu" name="atualizar">
                            <option value="vazios"<?= $op['atualizar'] === 'vazios' ? ' selected' : '' ?>>Completar só o que estiver em branco</option>
                            <option value="substituir"<?= $op['atualizar'] === 'substituir' ? ' selected' : '' ?>>Substituir pelo que vier da planilha</option>
                            <option value="nao"<?= $op['atualizar'] === 'nao' ? ' selected' : '' ?>>Não mexer nos cadastros</option>
                        </select>
                    </div>
                    <div class="card-body pt-0"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-eye me-1"></i>Ver prévia (nada é gravado ainda)</button></div>
                </div>
                <div class="card"><div class="card-body small text-secondary">
                    <strong class="text-body d-block mb-1">Dica</strong>
                    O aluno é encontrado pela matrícula ou pelo nome. Se a mesma planilha for importada de novo, as notas e faltas são <em>atualizadas</em>, não duplicadas.
                </div></div>
            </div>
        </div>
    </form>

<?php else:
    $historico = q_todos('SELECT i.*, u.nome AS por FROM importacoes i LEFT JOIN usuarios u ON u.id = i.usuario_id ORDER BY i.id DESC LIMIT 15'); ?>
    <ol class="passos-importacao mb-3"><li class="atual">Enviar</li><li>Conferir colunas</li><li>Prévia</li><li>Importar</li></ol>
    <div class="row g-3">
        <div class="col-xl-7">
            <form method="post" enctype="multipart/form-data" class="card" id="formEnvio">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="enviar">
                <div class="card-body">
                    <label class="zona-envio" id="zona" for="arquivo">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <strong>Arraste a planilha aqui ou clique para escolher</strong>
                        <span>CSV ou Excel (.xlsx) · até <?= e(ini_get('upload_max_filesize')) ?></span>
                        <span class="arquivo-escolhido" id="escolhido" hidden></span>
                        <input type="file" id="arquivo" name="planilha" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                </div>
                <div class="barra-salvar"><span class="small text-secondary">Depois de enviar você confere as colunas e vê uma prévia antes de gravar.</span>
                    <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-arrow-right me-1"></i>Enviar planilha</button></div>
            </form>
        </div>
        <div class="col-xl-5">
            <div class="card h-100"><div class="card-header"><h2>O que o sistema reconhece</h2></div>
                <div class="card-body">
                    <ul class="lista-passos small">
                        <li><strong>Aluno:</strong> nome, matrícula, turma (ex.: “2º A”, “2A Informática”), série, curso, nascimento, telefones, responsável, endereço, situação.</li>
                        <li><strong>Faltas:</strong> uma coluna com o total de faltas, ou uma linha por falta com a data.</li>
                        <li><strong>Notas:</strong> uma coluna para cada disciplina (Português, Matemática…) ou uma linha por nota com as colunas Disciplina e Nota.</li>
                        <li><strong>Bimestre:</strong> coluna própria, no título da coluna (“Matemática 2º bim”) ou escolhido na hora.</li>
                    </ul>
                    <p class="small mb-2">Não precisa mudar a sua planilha: os nomes das colunas são reconhecidos sozinhos e você pode ajustar.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-light" href="?modelo=larga"><i class="bi bi-download me-1"></i>Modelo: uma linha por aluno</a>
                        <a class="btn btn-sm btn-light" href="?modelo=longa"><i class="bi bi-download me-1"></i>Modelo: uma linha por nota</a>
                    </div>
                </div></div>
        </div>
    </div>
    <?php if ($historico): ?>
    <div class="card mt-3"><div class="card-header"><h2>Importações anteriores</h2></div>
        <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Arquivo</th><th>Quando</th><th>Por</th><th class="num">Alunos</th><th class="num">Novos</th><th class="num">Notas</th><th class="num">Faltas</th></tr></thead><tbody>
        <?php foreach ($historico as $h): $r = json_decode((string)$h['resumo'], true) ?: []; ?>
            <tr><td><i class="bi bi-file-earmark-spreadsheet text-success me-1"></i><?= e($h['arquivo']) ?></td><td class="text-nowrap"><?= data_hora_br($h['criado_em']) ?></td><td><?= e($h['por'] ?? '—') ?></td>
                <td class="num"><?= (int)($r['alunos'] ?? 0) ?></td><td class="num"><?= (int)($r['novos'] ?? 0) ?></td><td class="num"><?= (int)($r['notas'] ?? 0) ?></td><td class="num"><?= (int)($r['faltas_dias'] ?? 0) + (int)($r['faltas_total'] ?? 0) ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  const z = document.getElementById("zona"), inp = document.getElementById("arquivo");
  if (z) {
    const mostrar = () => { const f = inp.files[0]; if (f) { escolhido.hidden = false; escolhido.textContent = f.name + " · " + (f.size / 1024).toFixed(0) + " KB"; z.classList.add("com-arquivo"); } };
    inp.addEventListener("change", mostrar);
    ["dragenter", "dragover"].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add("arrastando"); }));
    ["dragleave", "drop"].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove("arrastando"); }));
    z.addEventListener("drop", e => { if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; mostrar(); } });
  }
  document.querySelectorAll(".mapa-colunas select").forEach(s => s.addEventListener("change", () => s.closest("tr").classList.toggle("ignorada", s.value === "ignorar")));
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
