<?php
require __DIR__ . '/../includes/app.php';
exigir('biblioteca');

$aba = in_array($_GET['aba'] ?? '', ['painel', 'emprestimos', 'acervo', 'leitores'], true) ? $_GET['aba'] : 'painel';
$hoje = date('Y-m-d');

/* =================== Ações =================== */
if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $volta = 'biblioteca.php?aba=' . ($_POST['volta'] ?? $aba);

    if ($acao === 'emprestar') {
        $livro = livro_buscar((int)($_POST['livro_id'] ?? 0));
        $aluno = aluno_buscar((int)($_POST['aluno_id'] ?? 0));
        $ret = str_replace('T', ' ', (string)($_POST['retirada'] ?? ''));
        $prazo = (string)($_POST['prazo'] ?? '');
        $erro = null;
        if (!$livro) $erro = 'Escolha o livro.';
        elseif (!$aluno) $erro = 'Escolha o aluno.';
        elseif ($livro['disponiveis'] < 1) $erro = 'Não há exemplar disponível de "' . $livro['titulo'] . '".';
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $ret) || !data_valida(substr($ret, 0, 10))) $erro = 'Informe a data e a hora da retirada.';
        elseif (!data_valida($prazo) || $prazo < substr($ret, 0, 10)) $erro = 'O prazo de devolução precisa ser depois da retirada.';
        else {
            $ficha = ficha_do_aluno((int)$aluno['id']);
            if ($ficha && $ficha['bloqueada']) $erro = 'A ficha de ' . nome_proprio($aluno['nome']) . ' está bloqueada para empréstimos.';
            elseif ((int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ? AND devolvido_em IS NULL', [$aluno['id']]) >= MAX_LIVROS_POR_ALUNO) $erro = nome_proprio($aluno['nome']) . ' já está com ' . MAX_LIVROS_POR_ALUNO . ' livros. Registre uma devolução antes.';
        }
        if ($erro) { flash('warning', $erro); redirecionar($volta); }
        $nova = !$ficha;
        $ficha = garantir_ficha((int)$aluno['id']);
        q('INSERT INTO emprestimos (livro_id, aluno_id, retirada, prazo, observacao, registrado_por) VALUES (?, ?, ?, ?, ?, ?)',
          [$livro['id'], $aluno['id'], $ret . ':00', $prazo, trim((string)($_POST['observacao'] ?? '')) ?: null, uid()]);
        $eid = (int)db()->lastInsertId();
        registrar_log('emprestimo', '"' . $livro['titulo'] . '" até ' . data_br($prazo), (int)$aluno['id']);
        flash('success', '"' . $livro['titulo'] . '" emprestado para ' . nome_proprio($aluno['nome']) . ' até ' . data_br($prazo) . '.' . ($nova ? ' Ficha de leitor nº ' . $ficha['numero'] . ' criada.' : ''));
        if (!empty($_POST['comprovante'])) redirecionar('emprestimo_comprovante.php?id=' . $eid . '&imprimir=1');
        redirecionar($volta);
    }
    if ($acao === 'devolver' || $acao === 'renovar') {
        $e = q_um('SELECT e.*, l.titulo FROM emprestimos e JOIN livros l ON l.id = e.livro_id WHERE e.id = ? AND e.devolvido_em IS NULL', [(int)$_POST['id']]);
        if ($e && $acao === 'devolver') {
            q('UPDATE emprestimos SET devolvido_em = ?, devolvido_por = ? WHERE id = ?', [date('Y-m-d H:i:s'), uid(), $e['id']]);
            registrar_log('devolucao', '"' . $e['titulo'] . '"', (int)$e['aluno_id']);
            flash('success', '"' . $e['titulo'] . '" devolvido' . ($e['prazo'] < $hoje ? ' com atraso.' : ' no prazo.'));
        } elseif ($e) {
            $novo = date('Y-m-d', strtotime(max($e['prazo'], $hoje) . ' +' . PRAZO_EMPRESTIMO_DIAS . ' days'));
            q('UPDATE emprestimos SET prazo = ?, renovacoes = renovacoes + 1 WHERE id = ?', [$novo, $e['id']]);
            registrar_log('renovacao', '"' . $e['titulo'] . '" até ' . data_br($novo), (int)$e['aluno_id']);
            flash('success', 'Empréstimo renovado até ' . data_br($novo) . '.');
        }
        redirecionar($volta);
    }
    if ($acao === 'livro_salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $d = [];
        foreach (['titulo', 'autor', 'editora', 'isbn', 'categoria', 'localizacao', 'observacao'] as $c) $d[$c] = trim((string)($_POST[$c] ?? '')) ?: null;
        $d['ano'] = (int)($_POST['ano'] ?? 0) ?: null;
        $d['exemplares'] = max(1, (int)($_POST['exemplares'] ?? 1));
        if (!$d['titulo'] || !$d['autor']) { flash('warning', 'Informe o título e o autor do livro.'); redirecionar('biblioteca.php?aba=acervo'); }
        if ($id) {
            $emp = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE livro_id = ? AND devolvido_em IS NULL', [$id]);
            if ($d['exemplares'] < $emp) $d['exemplares'] = $emp;
            q('UPDATE livros SET titulo = ?, autor = ?, editora = ?, isbn = ?, categoria = ?, localizacao = ?, observacao = ?, ano = ?, exemplares = ? WHERE id = ?', [...array_values($d), $id]);
            flash('success', '"' . $d['titulo'] . '" atualizado.');
        } else {
            q('INSERT INTO livros (titulo, autor, editora, isbn, categoria, localizacao, observacao, ano, exemplares) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values($d));
            flash('success', '"' . $d['titulo'] . '" cadastrado no acervo com ' . $d['exemplares'] . ' exemplar(es).');
        }
        registrar_log('livro', $d['titulo']);
        redirecionar('biblioteca.php?aba=acervo');
    }
    if ($acao === 'livro_excluir' && pode('biblioteca.excluir')) {
        $l = livro_buscar((int)$_POST['id']);
        if ($l && $l['emprestados']) flash('warning', 'Este livro tem exemplares emprestados. Registre as devoluções antes.');
        elseif ($l && q_valor('SELECT COUNT(*) FROM emprestimos WHERE livro_id = ?', [$l['id']])) { q('UPDATE livros SET ativo = 0 WHERE id = ?', [$l['id']]); flash('success', '"' . $l['titulo'] . '" saiu do acervo (o histórico de empréstimos foi mantido).'); }
        elseif ($l) { q('DELETE FROM livros WHERE id = ?', [$l['id']]); flash('success', '"' . $l['titulo'] . '" excluído.'); }
        redirecionar('biblioteca.php?aba=acervo');
    }
    if ($acao === 'importar_acervo' || $acao === 'importar_leitores') {
        /* etapa 1: só lê a planilha e guarda para conferência (nada é gravado ainda) */
        $ac = $acao === 'importar_acervo';
        try {
            $linhas = bib_ler_planilha($_FILES['planilha'] ?? []);
            if (count($linhas) > 5000) throw new RuntimeException('A planilha tem mais de 5.000 linhas. Divida em partes menores.');
            $ac ? bib_analisar_acervo($linhas) : bib_analisar_leitores($linhas);   // valida as colunas já aqui
            $_SESSION['bib_imp'] = ['tipo' => $ac ? 'acervo' : 'leitores', 'arquivo' => mb_substr(basename((string)($_FILES['planilha']['name'] ?? 'planilha')), 0, 120),
                                    'linhas' => $linhas, 'token' => bin2hex(random_bytes(8)), 'quando' => time()];
            redirecionar('biblioteca.php?aba=' . ($ac ? 'acervo' : 'leitores') . '&conferir=1');
        } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
        redirecionar('biblioteca.php?aba=' . ($ac ? 'acervo' : 'leitores'));
    }
    if ($acao === 'importar_confirmar' || $acao === 'importar_cancelar') {
        $imp = $_SESSION['bib_imp'] ?? null;
        $volta = 'biblioteca.php?aba=' . ($imp['tipo'] ?? 'acervo');
        if (!$imp || !hash_equals($imp['token'], (string)($_POST['token'] ?? ''))) { flash('warning', 'Essa conferência expirou. Envie a planilha de novo.'); redirecionar($volta); }
        unset($_SESSION['bib_imp']);
        if ($acao === 'importar_cancelar') { flash('info', 'Importação cancelada. Nada foi gravado.'); redirecionar($volta); }
        /* etapa 2: grava de verdade (a análise é refeita com o banco de agora) */
        $ac = $imp['tipo'] === 'acervo';
        try {
            [$n1, $n2, $ign] = $ac ? bib_gravar_acervo(bib_analisar_acervo($imp['linhas'])) : bib_gravar_leitores(bib_analisar_leitores($imp['linhas']));
            flash($n1 + $n2 ? 'success' : 'warning', $ac ? "Acervo importado: $n1 livro(s) novo(s) e $n2 atualizado(s)." : "Leitores importados: $n1 ficha(s) nova(s) e $n2 atualizada(s).");
            if ($ign) flash('warning', count($ign) . ' linha(s) não entraram: ' . implode('; ', array_slice($ign, 0, 8)) . (count($ign) > 8 ? '…' : '') . '.');
        } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
        redirecionar($volta);
    }
    if ($acao === 'ficha_criar') {
        $a = aluno_buscar((int)($_POST['aluno_id'] ?? 0));
        if ($a) { $f = garantir_ficha((int)$a['id']); flash('success', 'Ficha nº ' . $f['numero'] . ' de ' . nome_proprio($a['nome']) . ' pronta.'); }
        redirecionar('biblioteca.php?aba=leitores');
    }
    if ($acao === 'ficha_bloquear') {
        q('UPDATE biblioteca_fichas SET bloqueada = 1 - bloqueada WHERE id = ?', [(int)$_POST['id']]);
        flash('success', 'Situação da ficha alterada.');
        redirecionar('biblioteca.php?aba=leitores' . (isset($_POST['aluno']) ? '&aluno=' . (int)$_POST['aluno'] : ''));
    }
    redirecionar($volta);
}

/* =================== Dados =================== */
$titulos = (int)q_valor('SELECT COUNT(*) FROM livros WHERE ativo = 1');
$exemplares = (int)q_valor('SELECT COALESCE(SUM(exemplares), 0) FROM livros WHERE ativo = 1');
$abertos = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE devolvido_em IS NULL');
$atrasados = emprestimos_lista('e.devolvido_em IS NULL AND e.prazo < ?', [$hoje], 'e.prazo');
$vencendo = emprestimos_lista('e.devolvido_em IS NULL AND e.prazo BETWEEN ? AND ?', [$hoje, date('Y-m-d', strtotime('+2 days'))], 'e.prazo');
$leitores = (int)q_valor('SELECT COUNT(*) FROM biblioteca_fichas');
$mes_ini = date('Y-m-01');
$no_mes = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE retirada >= ?', [$mes_ini . ' 00:00:00']);

if ($aba === 'emprestimos') {
    $filtro = in_array($_GET['filtro'] ?? '', ['abertos', 'atrasados', 'devolvidos', 'todos'], true) ? $_GET['filtro'] : 'abertos';
    $busca = trim((string)($_GET['busca'] ?? ''));
    $w = ['1=1']; $p = [];
    if ($filtro === 'abertos') $w[] = 'e.devolvido_em IS NULL';
    if ($filtro === 'atrasados') { $w[] = 'e.devolvido_em IS NULL AND e.prazo < ?'; $p[] = $hoje; }
    if ($filtro === 'devolvidos') $w[] = 'e.devolvido_em IS NOT NULL';
    if ($busca !== '') { $w[] = '(l.titulo LIKE ? OR l.autor LIKE ? OR a.nome LIKE ?)'; array_push($p, "%$busca%", "%$busca%", "%$busca%"); }
    $lista = emprestimos_lista(implode(' AND ', $w), $p, $filtro === 'devolvidos' ? 'e.devolvido_em DESC' : 'e.prazo, e.retirada');
    if (($_GET['exportar'] ?? '') === 'csv') {
        enviar_csv('emprestimos-' . date('Y-m-d') . '.csv', ['Livro', 'Autor', 'Aluno', 'Turma', 'Ficha', 'Retirada', 'Prazo', 'Devolvido em', 'Situação'],
            array_map(fn($e) => [$e['titulo'], $e['autor'], nome_proprio($e['nome']), turma_nome($e), $e['ficha'], data_hora_br($e['retirada']), data_br($e['prazo']),
                                 $e['devolvido_em'] ? data_hora_br($e['devolvido_em']) : '', ['devolvido' => 'Devolvido', 'atrasado' => 'Atrasado', 'vence_hoje' => 'Vence hoje', 'no_prazo' => 'No prazo'][situacao_emprestimo($e)]], $lista));
    }
}
if ($aba === 'acervo') {
    $busca = trim((string)($_GET['busca'] ?? ''));
    $cat = (string)($_GET['categoria'] ?? '');
    $w = ['l.ativo = 1']; $p = [];
    if ($busca !== '') { $w[] = '(l.titulo LIKE ? OR l.autor LIKE ? OR l.isbn = ?)'; array_push($p, "%$busca%", "%$busca%", $busca); }
    if ($cat !== '') { $w[] = 'l.categoria = ?'; $p[] = $cat; }
    $livros = q_todos('SELECT l.*, (SELECT COUNT(*) FROM emprestimos e WHERE e.livro_id = l.id AND e.devolvido_em IS NULL) AS emprestados,
                              (SELECT COUNT(*) FROM emprestimos e WHERE e.livro_id = l.id) AS vezes
                         FROM livros l WHERE ' . implode(' AND ', $w) . ' ORDER BY l.titulo LIMIT 500', $p);
    $ed = isset($_GET['editar']) ? livro_buscar((int)$_GET['editar']) : null;
    if (($_GET['exportar'] ?? '') === 'csv') {
        enviar_csv('acervo-' . date('Y-m-d') . '.csv', ['Título', 'Autor', 'Editora', 'Ano', 'ISBN', 'Categoria', 'Localização', 'Exemplares', 'Emprestados', 'Vezes emprestado'],
            array_map(fn($l) => [$l['titulo'], $l['autor'], $l['editora'], $l['ano'], $l['isbn'], $l['categoria'], $l['localizacao'], $l['exemplares'], $l['emprestados'], $l['vezes']], $livros));
    }
}

if ($aba === 'leitores' && ($_GET['exportar'] ?? '') === 'csv') {
    $fx = q_todos("SELECT f.*, a.nome, t.serie, t.letra, c.nome AS curso,
                          (SELECT COUNT(*) FROM emprestimos e WHERE e.aluno_id = f.aluno_id) AS total,
                          (SELECT COUNT(*) FROM emprestimos e WHERE e.aluno_id = f.aluno_id AND e.devolvido_em IS NULL) AS abertos
                     FROM biblioteca_fichas f JOIN alunos a ON a.id = f.aluno_id LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) LEFT JOIN cursos c ON c.id = t.curso_id ORDER BY f.numero");
    enviar_csv('leitores-' . date('Y-m-d') . '.csv', ['Ficha', 'Aluno', 'Turma', 'Situação', 'Observação', 'Livros lidos', 'Com ele agora'],
        array_map(fn($f) => [$f['numero'], nome_proprio($f['nome']), turma_nome($f), $f['bloqueada'] ? 'Bloqueada' : 'Regular', $f['observacao'], $f['total'], $f['abertos']], $fx));
}

$titulo = 'Centro de Multimeios';
$subtitulo = 'Acervo da biblioteca, fichas de leitor e empréstimos com prazo de devolução.';
$menu = 'biblioteca';
/* conferência da planilha antes de importar */
$bb_conf = null;
if (!empty($_GET['conferir']) && !empty($_SESSION['bib_imp']) && $_SESSION['bib_imp']['tipo'] === $aba) {
    $imp = $_SESSION['bib_imp'];
    try {
        $itens = $aba === 'acervo' ? bib_analisar_acervo($imp['linhas']) : bib_analisar_leitores($imp['linhas']);
        $cont = ['novo' => 0, 'atualiza' => 0, 'repetido' => 0, 'erro' => 0, 'aviso' => 0];
        foreach ($itens as $it) { $st = $it['status'] === 'nova' ? 'novo' : $it['status']; $cont[$st]++; if ($it['avisos'] && $st !== 'erro') $cont['aviso']++; }
        $bb_conf = ['imp' => $imp, 'itens' => $itens, 'cont' => $cont];
    } catch (RuntimeException $e) { unset($_SESSION['bib_imp']); flash('warning', $e->getMessage()); }
}
$com_ano = true;   // v75: campo de ano letivo nesta página
require __DIR__ . '/../includes/layout_topo.php';

function linha_emprestimo(array $e, string $volta, bool $compacto = false): string
{
    ob_start(); $sit = situacao_emprestimo($e); ?>
    <tr<?= $sit === 'atrasado' ? ' class="table-danger"' : '' ?>>
        <?php if ($compacto): ?>
        <td><strong><?= e($e['titulo']) ?></strong><br><small class="text-secondary"><a class="nome-link" href="biblioteca.php?aba=leitores&aluno=<?= $e['aluno_id'] ?>"><?= e(nome_proprio($e['nome'])) ?></a> <span class="text-nowrap">· <?= e($e['serie'] ? $e['serie'] . 'º ' . $e['letra'] : '') ?></span></small></td>
        <?php else: ?>
        <td><strong><?= e($e['titulo']) ?></strong><br><small class="text-secondary"><?= e($e['autor']) ?></small></td>
        <td><a class="nome-link" href="biblioteca.php?aba=leitores&aluno=<?= $e['aluno_id'] ?>"><?= e(nome_proprio($e['nome'])) ?></a><br><small class="text-secondary"><?= e(turma_nome($e)) ?><?= $e['ficha'] ? ' · ficha ' . e($e['ficha']) : '' ?></small></td>
        <td class="text-nowrap"><?= date('d/m/Y', strtotime($e['retirada'])) ?><br><small class="text-secondary"><?= date('H:i', strtotime($e['retirada'])) ?></small></td>
        <?php endif; ?>
        <td class="text-nowrap"><?= etiqueta_emprestimo($e) ?><?php if ($e['renovacoes']): ?><br><small class="text-secondary"><?= $e['renovacoes'] ?> renovação(ões)</small><?php endif; ?>
            <?php if ($e['devolvido_em']): ?><br><small class="text-secondary"><?= data_hora_br($e['devolvido_em']) ?></small><?php endif; ?></td>
        <td class="td-acoes text-nowrap">
            <?php if (!$e['devolvido_em']): ?>
                <?php if ($sit === 'atrasado' && ($w = link_whatsapp($e['responsavel_telefone'] ?: $e['telefone'], 'Olá! Aqui é do Centro de Multimeios da escola. O livro "' . $e['titulo'] . '" emprestado para ' . nome_proprio($e['nome']) . ' venceu em ' . data_br($e['prazo']) . '. Pedimos a devolução, por favor.'))): ?>
                    <a class="btn btn-sm btn-light" href="<?= e($w) ?>" target="_blank" rel="noopener" title="Avisar pelo WhatsApp"><i class="bi bi-whatsapp text-success"></i></a>
                <?php endif; ?>
                <form method="post" class="d-inline"><?= csrf_campo() ?><input type="hidden" name="acao" value="renovar"><input type="hidden" name="id" value="<?= $e['id'] ?>"><input type="hidden" name="volta" value="<?= e($volta) ?>">
                    <button class="btn btn-sm btn-light" type="submit" title="Renovar por mais <?= PRAZO_EMPRESTIMO_DIAS ?> dias"><i class="bi bi-arrow-repeat"></i></button></form>
                <form method="post" class="d-inline"><?= csrf_campo() ?><input type="hidden" name="acao" value="devolver"><input type="hidden" name="id" value="<?= $e['id'] ?>"><input type="hidden" name="volta" value="<?= e($volta) ?>">
                    <button class="btn btn-sm btn-primary" type="submit" title="Registrar devolução"><i class="bi bi-box-arrow-in-down-left"></i><?= $compacto ? '' : '<span class="ms-1">Devolver</span>' ?></button></form>
            <?php endif; ?>
            <a class="btn btn-sm btn-light" href="emprestimo_comprovante.php?id=<?= $e['id'] ?>" target="_blank" title="Comprovante"><i class="bi bi-printer"></i></a>
        </td>
    </tr>
    <?php return ob_get_clean();
}
?>

<ul class="nav nav-abas mb-3">
    <?php foreach (['painel' => ['grid-1x2', 'Visão geral'], 'emprestimos' => ['arrow-left-right', 'Empréstimos'], 'acervo' => ['bookshelf', 'Acervo'], 'leitores' => ['person-vcard', 'Leitores']] as $k => [$ic, $v]): ?>
        <li class="nav-item"><a class="nav-link<?= $aba === $k ? ' active' : '' ?>" href="?aba=<?= $k ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= $v ?><?= $k === 'emprestimos' && $atrasados ? '<span class="contador" style="background:var(--vermelho);color:#fff">' . count($atrasados) . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="pg-acoes">
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <?php if ($aba === 'acervo'): ?><?= janela_botao('novoLivro', 'Cadastrar livro', 'journal-plus') ?>
        <?php elseif ($aba === 'leitores'): ?><?= janela_botao('novaFicha', 'Nova ficha de leitor', 'person-vcard') ?><?php endif; ?>
        <?= janela_botao('novoEmprestimo', 'Novo empréstimo', 'plus-circle', $aba === 'painel' || $aba === 'emprestimos' ? 'btn btn-primary' : 'btn btn-light') ?>
    </div>
</div>

<?php if ($bb_conf): include __DIR__ . '/../includes/biblioteca_conferir.php'; ?>
<?php elseif ($aba === 'painel'): ?>
<?php
$ini_ano = ANO_VER . '-01-01 00:00:00';
$fim_ano = ANO_VER . '-12-31 23:59:59';
$top_livros = q_todos('SELECT l.titulo, l.autor, COUNT(e.id) AS n FROM emprestimos e JOIN livros l ON l.id = e.livro_id WHERE e.retirada BETWEEN ? AND ? GROUP BY l.id, l.titulo, l.autor ORDER BY n DESC, l.titulo LIMIT 5', [$ini_ano, $fim_ano]);
$top_autores = q_todos("SELECT l.autor, COUNT(e.id) AS n, COUNT(DISTINCT l.id) AS obras FROM emprestimos e JOIN livros l ON l.id = e.livro_id WHERE e.retirada BETWEEN ? AND ? AND l.autor <> '' GROUP BY l.autor ORDER BY n DESC, l.autor LIMIT 5", [$ini_ano, $fim_ano]);
$top_leitores = q_todos('SELECT a.id, a.nome, t.serie, t.letra, c.nome AS curso, COUNT(e.id) AS n FROM emprestimos e JOIN alunos a ON a.id = e.aluno_id LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) LEFT JOIN cursos c ON c.id = t.curso_id WHERE e.retirada BETWEEN ? AND ? GROUP BY a.id, a.nome, t.serie, t.letra, c.nome ORDER BY n DESC, a.nome LIMIT 5', [$ini_ano, $fim_ano]);
$top_turmas = q_todos('SELECT t.serie, t.letra, c.nome AS curso, COUNT(e.id) AS n FROM emprestimos e JOIN alunos a ON a.id = e.aluno_id JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id WHERE e.retirada BETWEEN ? AND ? GROUP BY t.id, t.serie, t.letra, c.nome ORDER BY n DESC LIMIT 5', [$ini_ano, $fim_ano]);
$lidos_ano = (int)q_valor('SELECT COUNT(*) FROM emprestimos WHERE retirada BETWEEN ? AND ?', [$ini_ano, $fim_ano]);
?>
<section class="bb-hero">
    <div class="bb-hero-txt"><span><i class="bi bi-book-half"></i>Centro de Multimeios · <?= ANO_VER ?></span>
        <h2><?= $lidos_ano ?> <small>empréstimo<?= $lidos_ano === 1 ? '' : 's' ?> no ano</small></h2>
        <p><?= $no_mes ?> em <?= mb_strtolower(MESES[(int)date('n')]) ?> · <?= $titulos ?> títulos e <?= $exemplares ?> exemplares no acervo</p></div>
    <div class="bb-kpis">
        <div><i class="bi bi-book"></i><b><?= $exemplares ?></b><span>no acervo</span></div>
        <div><i class="bi bi-arrow-left-right"></i><b><?= $abertos ?></b><span>emprestados agora</span></div>
        <div class="<?= $atrasados ? 'alerta' : '' ?>"><i class="bi bi-alarm"></i><b><?= count($atrasados) ?></b><span>atrasados</span></div>
        <div><i class="bi bi-person-vcard"></i><b><?= $leitores ?></b><span>leitores com ficha</span></div>
    </div>
</section>

<div class="bb-ranks">
    <?php foreach ([['Livros mais lidos', 'trophy', 'ouro', $top_livros, fn($r) => '<b>' . e($r['titulo']) . '</b><small>' . e($r['autor']) . '</small>', 'leitura'],
                    ['Autores mais lidos', 'feather', 'laranja', $top_autores, fn($r) => '<b>' . e($r['autor']) . '</b><small>' . (int)$r['obras'] . ' obra' . ((int)$r['obras'] > 1 ? 's' : '') . ' lida' . ((int)$r['obras'] > 1 ? 's' : '') . '</small>', 'leitura'],
                    ['Alunos que mais leem', 'star', 'verde', $top_leitores, fn($r) => '<b><a href="?aba=leitores&aluno=' . $r['id'] . '">' . e(nome_proprio($r['nome'])) . '</a></b><small>' . e(turma_nome($r)) . '</small>', 'livro'],
                    ['Turmas que mais leem', 'people', 'azul', $top_turmas, fn($r) => '<b>' . e($r['serie'] . 'º Ano ' . $r['letra']) . '</b><small>' . e($r['curso']) . '</small>', 'livro']] as [$tit, $ic, $cor, $lista, $fmt, $un]): $max = $lista ? max(array_column($lista, 'n')) : 1; ?>
    <section class="bb-rank <?= $cor ?>">
        <header><span><i class="bi bi-<?= $ic ?>"></i></span><h2><?= $tit ?></h2><small><?= ANO_VER ?></small></header>
        <?php if ($lista): ?><ol><?php foreach ($lista as $i => $r): ?>
            <li class="p<?= $i + 1 ?>"><em><?= $i + 1 ?></em><div><?= $fmt($r) ?><i style="width:<?= round($r['n'] / $max * 100) ?>%"></i></div><strong><?= (int)$r['n'] ?><small><?= $r['n'] == 1 ? $un : $un . 's' ?></small></strong></li>
        <?php endforeach; ?></ol>
        <?php else: ?><div class="bb-vazio"><i class="bi bi-<?= $ic ?>"></i>Aparece aqui com os primeiros empréstimos.</div><?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 d-flex flex-column gap-3">
        <?php if ($atrasados): ?>
        <div class="alerta-sequencia">
            <div class="alerta-topo"><span class="pulso"></span><h2><?= count($atrasados) ?> livro(s) com devolução atrasada</h2></div>
            <div class="table-responsive"><table class="table mb-0"><tbody><?php foreach (array_slice($atrasados, 0, 8) as $e) echo linha_emprestimo($e, 'painel', true); ?></tbody></table></div>
            <?php if (count($atrasados) > 8): ?><div class="p-2 text-center"><a href="?aba=emprestimos&filtro=atrasados">Ver todos</a></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h2>Vencem hoje e nos próximos 2 dias</h2><span class="etiqueta cinza"><?= count($vencendo) ?></span></div>
            <?php if ($vencendo): ?><div class="table-responsive"><table class="table mb-0"><tbody><?php foreach ($vencendo as $e) echo linha_emprestimo($e, 'painel', true); ?></tbody></table></div>
            <?php else: ?><div class="vazio py-4"><i class="bi bi-calendar-check"></i>Nenhuma devolução marcada para os próximos dias.</div><?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($aba === 'emprestimos'): ?>
    <form class="filtros d-flex flex-wrap gap-2 align-items-end" method="get">
        <input type="hidden" name="aba" value="emprestimos">
        <div class="btn-group" role="group">
            <?php foreach (['abertos' => 'Em aberto', 'atrasados' => 'Atrasados', 'devolvidos' => 'Devolvidos', 'todos' => 'Todos'] as $k => $v): ?>
                <a class="btn <?= $filtro === $k ? 'btn-dark' : 'btn-light' ?>" href="?aba=emprestimos&filtro=<?= $k ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <input class="form-control ms-auto" style="max-width:280px" name="busca" value="<?= e($busca) ?>" placeholder="Livro, autor ou aluno" aria-label="Buscar">
        <button class="btn btn-light" type="submit"><i class="bi bi-search"></i></button>
        <a class="btn btn-light" href="?aba=emprestimos&filtro=<?= $filtro ?>&busca=<?= e(urlencode($busca)) ?>&exportar=csv" title="Exportar"><i class="bi bi-filetype-csv"></i></a>
    </form>
    <div class="card">
        <?php if ($lista): ?>
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Livro</th><th>Aluno</th><th>Retirada</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody><?php foreach ($lista as $e) echo linha_emprestimo($e, 'emprestimos'); ?></tbody>
        </table></div>
        <?php else: ?><div class="vazio"><i class="bi bi-inbox"></i><strong>Nenhum empréstimo encontrado</strong>Os empréstimos são registrados na Visão geral.</div><?php endif; ?>
    </div>

<?php elseif ($aba === 'acervo'): ?>
    <div class="row g-3">
        <div class="col-12">
            <form class="filtros d-flex flex-wrap gap-2" method="get">
                <input type="hidden" name="aba" value="acervo">
                <input class="form-control" style="max-width:300px" name="busca" value="<?= e($busca) ?>" placeholder="Título, autor ou ISBN" aria-label="Buscar livro">
                <select class="form-select" style="max-width:240px" name="categoria" onchange="this.form.submit()" aria-label="Categoria"><option value="">Todas as categorias</option>
                    <?php foreach (CATEGORIAS_LIVRO as $c): ?><option<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
                <button class="btn btn-light" type="submit"><i class="bi bi-search"></i></button>
                <a class="btn btn-light ms-auto" href="?aba=acervo&busca=<?= e(urlencode($busca)) ?>&categoria=<?= e(urlencode($cat)) ?>&exportar=csv"><i class="bi bi-download me-1"></i>Exportar acervo</a>
                <?= janela_botao('importarAcervo', 'Importar acervo', 'upload', 'btn btn-light') ?>
            </form>
            <div class="card">
                <div class="card-header"><h2>Acervo</h2><span class="etiqueta cinza"><?= count($livros) ?> título(s)</span></div>
                <?php if ($livros): ?>
                <div class="table-responsive tabela-rolagem"><table class="table table-hover">
                    <thead><tr><th>Livro</th><th>Categoria</th><th class="num">Disponíveis</th><th class="num">Lido</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($livros as $l): $disp = max(0, $l['exemplares'] - $l['emprestados']); ?>
                        <tr>
                            <td><strong><?= e($l['titulo']) ?></strong><br><small class="text-secondary"><?= e($l['autor']) ?><?= $l['editora'] ? ' · ' . e($l['editora']) : '' ?><?= $l['ano'] ? ' · ' . $l['ano'] : '' ?><?= $l['localizacao'] ? ' · <i class="bi bi-geo"></i> ' . e($l['localizacao']) : '' ?></small></td>
                            <td class="small"><?= e($l['categoria'] ?? '—') ?></td>
                            <td class="num"><span class="etiqueta <?= $disp ? '' : 'vermelho' ?>"><?= $disp ?> de <?= $l['exemplares'] ?></span></td>
                            <td class="num"><?= $l['vezes'] ?>×</td>
                            <td class="td-acoes">
                                <a class="btn btn-sm btn-light" href="?aba=acervo&editar=<?= $l['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php if (pode('biblioteca.excluir')): ?>
                                <form method="post" class="d-inline" data-confirmar="Tirar &quot;<?= e($l['titulo']) ?>&quot; do acervo?" data-botao="Tirar do acervo">
                                    <?= csrf_campo() ?><input type="hidden" name="acao" value="livro_excluir"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-sm btn-light" type="submit" aria-label="Excluir"><i class="bi bi-trash"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php else: ?><div class="vazio"><i class="bi bi-bookshelf"></i><strong><?= $busca || $cat ? 'Nenhum livro com esses filtros' : 'O acervo ainda está vazio' ?></strong>Cadastre os livros no formulário ao lado.</div><?php endif; ?>
            </div>
        </div>
    </div>

<?php else: /* leitores */
    $aluno_id = (int)($_GET['aluno'] ?? 0);
    if ($aluno_id && ($al = aluno_buscar($aluno_id))):
        $ficha = ficha_do_aluno($aluno_id);
        $hist = emprestimos_lista('e.aluno_id = ?', [$aluno_id], 'e.devolvido_em IS NULL DESC, e.retirada DESC'); ?>
        <div class="d-flex gap-2 mb-3"><a class="btn btn-light" href="?aba=leitores"><i class="bi bi-arrow-left me-1"></i>Leitores</a>
            <a class="btn btn-light" href="aluno.php?id=<?= $aluno_id ?>">Ficha escolar</a></div>
        <section class="ficha-cabecalho mb-3">
            <span class="avatar avatar-lg"><?= e(iniciais($al['nome'])) ?></span>
            <div><h2><?= e(nome_proprio($al['nome'])) ?></h2>
                <div class="ficha-meta"><span class="etiqueta contorno"><?= e(turma_nome($al)) ?></span>
                    <?= $ficha ? '<span class="etiqueta escura">Ficha ' . e($ficha['numero']) . '</span>' . ($ficha['bloqueada'] ? '<span class="etiqueta vermelho">Bloqueada</span>' : '') : '<span class="etiqueta ambar">Sem ficha</span>' ?></div></div>
            <div class="ficha-numeros">
                <div><strong><?= count($hist) ?></strong><span>livros lidos</span></div>
                <div><strong><?= count(array_filter($hist, fn($e) => !$e['devolvido_em'])) ?></strong><span>com ele agora</span></div>
                <div><strong><?= count(array_filter($hist, fn($e) => situacao_emprestimo($e) === 'atrasado')) ?></strong><span>atrasados</span></div>
            </div>
            <?php if ($ficha): ?>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ficha_bloquear"><input type="hidden" name="id" value="<?= $ficha['id'] ?>"><input type="hidden" name="aluno" value="<?= $aluno_id ?>">
                <button class="btn btn-sm btn-light" type="submit"><?= $ficha['bloqueada'] ? 'Desbloquear ficha' : 'Bloquear ficha' ?></button></form>
            <?php endif; ?>
        </section>
        <div class="card"><div class="card-header"><h2>Histórico de leitura</h2></div>
            <?php if ($hist): ?><div class="table-responsive"><table class="table"><thead><tr><th>Livro</th><th>Aluno</th><th>Retirada</th><th>Situação</th><th></th></tr></thead>
                <tbody><?php foreach ($hist as $e) echo linha_emprestimo($e, 'leitores'); ?></tbody></table></div>
            <?php else: ?><div class="vazio"><i class="bi bi-book"></i>Ainda não pegou livros.</div><?php endif; ?>
        </div>
    <?php else:
        $busca = trim((string)($_GET['busca'] ?? ''));
        $w = '1=1'; $p = [];
        if ($busca !== '') { $w = '(a.nome LIKE ? OR f.numero = ?)'; $p = ["%$busca%", strtoupper($busca)]; }
        $fichas = q_todos("SELECT f.*, a.nome, t.serie, t.letra, c.nome AS curso,
                                  (SELECT COUNT(*) FROM emprestimos e WHERE e.aluno_id = f.aluno_id) AS total,
                                  (SELECT COUNT(*) FROM emprestimos e WHERE e.aluno_id = f.aluno_id AND e.devolvido_em IS NULL) AS abertos,
                                  (SELECT COUNT(*) FROM emprestimos e WHERE e.aluno_id = f.aluno_id AND e.devolvido_em IS NULL AND e.prazo < ?) AS atrasados
                             FROM biblioteca_fichas f JOIN alunos a ON a.id = f.aluno_id LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) LEFT JOIN cursos c ON c.id = t.curso_id
                            WHERE $w ORDER BY f.numero", [$hoje, ...$p]); ?>
        <div class="row g-3">
            <div class="col-12">
                <form class="filtros d-flex flex-wrap gap-2" method="get"><input type="hidden" name="aba" value="leitores">
                    <input class="form-control" style="max-width:340px" name="busca" value="<?= e($busca) ?>" placeholder="Nome do aluno ou nº da ficha" aria-label="Buscar leitor"><button class="btn btn-light" type="submit"><i class="bi bi-search"></i></button>
                    <a class="btn btn-light ms-auto" href="?aba=leitores&amp;exportar=csv"><i class="bi bi-download me-1"></i>Exportar leitores</a>
                    <?= janela_botao('importarLeitores', 'Importar leitores', 'upload', 'btn btn-light') ?></form>
                <div class="card"><div class="card-header"><h2>Fichas de leitor</h2><span class="etiqueta cinza"><?= count($fichas) ?></span></div>
                    <?php if ($fichas): ?><div class="table-responsive tabela-rolagem"><table class="table table-hover">
                        <thead><tr><th>Ficha</th><th>Aluno</th><th class="num">Lidos</th><th class="num">Com ele</th><th>Situação</th></tr></thead>
                        <tbody><?php foreach ($fichas as $f): ?>
                            <tr><td><strong><?= e($f['numero']) ?></strong></td>
                                <td><a class="nome-link" href="?aba=leitores&aluno=<?= $f['aluno_id'] ?>"><?= e(nome_proprio($f['nome'])) ?></a><br><small class="text-secondary"><?= e(turma_nome($f)) ?></small></td>
                                <td class="num"><?= $f['total'] ?></td><td class="num"><?= $f['abertos'] ?></td>
                                <td><?= $f['bloqueada'] ? '<span class="etiqueta vermelho">Bloqueada</span>' : ($f['atrasados'] ? '<span class="etiqueta vermelho">' . $f['atrasados'] . ' atrasado(s)</span>' : '<span class="etiqueta">Regular</span>') ?></td></tr>
                        <?php endforeach; ?></tbody></table></div>
                    <?php else: ?><div class="vazio"><i class="bi bi-person-vcard"></i><strong>Nenhuma ficha ainda</strong>A ficha é criada no botão "Nova ficha de leitor" ou no primeiro empréstimo do aluno.</div><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?= janela_abre('novoEmprestimo', 'Novo empréstimo', 'plus-circle') ?>
        <form method="post" class="card h-100" id="formEmprestimo" autocomplete="off">
            <div class="card-header"><h2><i class="bi bi-plus-circle me-1"></i>Novo empréstimo</h2></div>
            <div class="card-body">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="emprestar"><input type="hidden" name="volta" value="painel">
                <input type="hidden" name="livro_id" id="livroId"><input type="hidden" name="aluno_id" id="alunoId">
                <label class="form-label obrigatorio" for="buscaLivro">Livro</label>
                <div class="coleta-busca mb-2"><i class="bi bi-book"></i>
                    <input type="search" class="form-control" id="buscaLivro" placeholder="Título, autor ou ISBN" style="height:46px;font-size:1rem" role="combobox" aria-expanded="false">
                    <div class="busca-resultados" id="listaLivros" hidden></div></div>
                <div class="registro mb-3" id="livroEscolhido" hidden></div>

                <label class="form-label obrigatorio" for="buscaAluno">Aluno</label>
                <div class="coleta-busca mb-2"><i class="bi bi-person"></i>
                    <input type="search" class="form-control" id="buscaAluno" placeholder="Nome do aluno" style="height:46px;font-size:1rem" role="combobox" aria-expanded="false">
                    <div class="busca-resultados" id="listaAlunos" hidden></div></div>
                <div class="registro mb-3" id="alunoEscolhido" hidden></div>

                <div class="row g-2">
                    <div class="col-sm-6"><label class="form-label obrigatorio" for="retirada">Retirada (data e hora)</label>
                        <input class="form-control" type="datetime-local" id="retirada" name="retirada" value="<?= date('Y-m-d\TH:i') ?>" required></div>
                    <div class="col-sm-6"><label class="form-label obrigatorio" for="prazo">Devolver até</label>
                        <input class="form-control" type="date" id="prazo" name="prazo" value="<?= date('Y-m-d', strtotime('+' . PRAZO_EMPRESTIMO_DIAS . ' days')) ?>" required>
                        <div class="d-flex gap-1 mt-1"><?php foreach ([7, 14, 21] as $d): ?><button type="button" class="btn btn-sm btn-light py-0 flex-fill text-nowrap" data-prazo="<?= $d ?>"><?= $d ?> dias</button><?php endforeach; ?></div></div>
                    <div class="col-12"><label class="form-label" for="obs">Observação</label><input class="form-control" id="obs" name="observacao" maxlength="255" placeholder="Opcional (ex.: livro com capa danificada)"></div>
                </div>
            </div>
            <div class="barra-salvar">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="comprovante" value="1" id="comp"><label class="form-check-label small" for="comp">Imprimir comprovante</label></div>
                <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-check2 me-1"></i>Registrar empréstimo</button>
            </div>
        </form>
<?= janela_fecha() ?>
<?php if ($aba === 'acervo'): ?>
<?= janela_abre('novoLivro', $ed ? 'Editar livro' : 'Cadastrar livro', $ed ? 'pencil' : 'journal-plus', (bool)$ed) ?>
            <form method="post" class="card">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="livro_salvar"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
                <div class="card-header"><h2><?= $ed ? 'Editar livro' : 'Cadastrar livro' ?></h2><?php if ($ed): ?><a class="ms-auto small" href="?aba=acervo">Novo</a><?php endif; ?></div>
                <div class="card-body row g-2">
                    <div class="col-12"><label class="form-label obrigatorio" for="l_tit">Título</label><input class="form-control" id="l_tit" name="titulo" value="<?= e($ed['titulo'] ?? '') ?>" maxlength="200" required></div>
                    <div class="col-12"><label class="form-label obrigatorio" for="l_aut">Autor</label><input class="form-control" id="l_aut" name="autor" value="<?= e($ed['autor'] ?? '') ?>" maxlength="150" required></div>
                    <div class="col-8"><label class="form-label" for="l_edi">Editora</label><input class="form-control" id="l_edi" name="editora" value="<?= e($ed['editora'] ?? '') ?>" maxlength="100"></div>
                    <div class="col-4"><label class="form-label" for="l_ano">Ano</label><input class="form-control" type="number" id="l_ano" name="ano" value="<?= e($ed['ano'] ?? '') ?>" min="1500" max="<?= date('Y') ?>"></div>
                    <div class="col-12"><label class="form-label" for="l_cat">Categoria</label><select class="form-select" id="l_cat" name="categoria"><option value="">—</option>
                        <?php foreach (CATEGORIAS_LIVRO as $c): ?><option<?= ($ed['categoria'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
                    <div class="col-7"><label class="form-label" for="l_isbn">ISBN</label><input class="form-control" id="l_isbn" name="isbn" value="<?= e($ed['isbn'] ?? '') ?>" maxlength="20"></div>
                    <div class="col-5"><label class="form-label obrigatorio" for="l_ex">Exemplares</label><input class="form-control" type="number" id="l_ex" name="exemplares" value="<?= (int)($ed['exemplares'] ?? 1) ?>" min="1" max="999" required></div>
                    <div class="col-12"><label class="form-label" for="l_loc">Localização</label><input class="form-control" id="l_loc" name="localizacao" value="<?= e($ed['localizacao'] ?? '') ?>" maxlength="40" placeholder="Ex.: Estante 3, prateleira B"></div>
                    <div class="col-12"><label class="form-label" for="l_obs">Observação</label><input class="form-control" id="l_obs" name="observacao" value="<?= e($ed['observacao'] ?? '') ?>" maxlength="255"></div>
                </div>
                <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-check2 me-1"></i><?= $ed ? 'Salvar alterações' : 'Cadastrar no acervo' ?></button></div>
            </form>
<?= janela_fecha() ?>
<?php endif; ?>
<?php foreach (['acervo' => ['importarAcervo', 'importar_acervo', 'Importar acervo', 'Título; Autor; Editora; Ano; ISBN; Categoria; Localização; Exemplares', 'Livro que já existe (mesmo ISBN, ou mesmo título e autor) é atualizado, sem duplicar. As colunas "Emprestados" e "Vezes emprestado" são ignoradas.'],
                 'leitores' => ['importarLeitores', 'importar_leitores', 'Importar leitores', 'Ficha; Aluno; Turma; Situação; Observação', 'O aluno é encontrado pelo nome (e pela turma, se houver nomes iguais). Quem já tem ficha só tem a situação e a observação atualizadas.']] as $ab => [$jid, $jac, $jt, $jcols, $jtx]):
    if ($aba !== $ab || !empty($_GET['aluno'])) continue; ?>
<?= janela_abre($jid, $jt, 'upload', false, '') ?>
    <form method="post" enctype="multipart/form-data" class="bb-imp">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="<?= $jac ?>">
        <label class="sa-solta bb-solta"><input type="file" name="planilha" accept=".csv,.txt,.xlsx" required onchange="this.closest('label').querySelector('b').textContent = this.files[0] ? this.files[0].name : 'Escolher a planilha'; this.closest('label').classList.add('tem')">
            <i class="bi bi-filetype-csv"></i><b>Escolher a planilha</b><small>CSV no mesmo modelo do "Exportar" (também aceita XLSX)</small></label>
        <div class="bb-imp-cols"><span>Colunas</span><code><?= e($jcols) ?></code></div>
        <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1"></i><?= e($jtx) ?> Dica: exporte primeiro, ajuste no Excel e importe de volta.</p>
        <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-search me-1"></i>Conferir a planilha</button>
        <p class="small text-secondary text-center mt-2 mb-0">Nada é gravado agora: primeiro você confere linha por linha.</p>
    </form>
<?= janela_fecha() ?>
<?php endforeach; ?>
<?php if ($aba === 'leitores' && empty($_GET['aluno'])): ?>
<?= janela_abre('novaFicha', 'Nova ficha de leitor', 'person-vcard', false, '') ?>
                <form method="post" class="card" autocomplete="off">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="ficha_criar"><input type="hidden" name="aluno_id" id="fichaAluno">
                    <div class="card-header"><h2>Nova ficha de leitor</h2></div>
                    <div class="card-body">
                        <div class="coleta-busca"><i class="bi bi-person"></i>
                            <input type="search" class="form-control" id="buscaFicha" placeholder="Nome do aluno" role="combobox" aria-expanded="false" style="height:46px">
                            <div class="busca-resultados" id="listaFicha" hidden></div></div>
                        <div class="registro mt-2" id="fichaEscolhido" hidden></div>
                        <p class="form-text mt-2 mb-0">O número da ficha é gerado automaticamente (próximo: <?= e(proximo_numero_ficha()) ?>).</p>
                    </div>
                    <div class="barra-salvar"><button class="btn btn-primary ms-auto" type="submit">Criar ficha</button></div>
                </form>
<?= janela_fecha() ?>
<?php endif; ?>

<?php
$script_pagina = '<script>
(function () {
  /* Empréstimo: escolher livro e aluno */
  const fl = document.getElementById("formEmprestimo");
  if (fl) {
    comboBusca({ input: buscaLivro, lista: listaLivros, url: q => "api.php?acao=livros&q=" + encodeURIComponent(q), vazio: "Livro não encontrado no acervo.",
      item: l => `<i class="bi bi-book"></i><span><strong>${esc(l.titulo)}</strong><br><small class="text-secondary">${esc(l.autor)}</small></span><span class="turma ${l.disponiveis ? "" : "text-danger"}">${l.disponiveis} de ${l.exemplares} disponível</span>`,
      escolher: l => {
        livroId.value = l.disponiveis ? l.id : ""; buscaLivro.value = "";
        livroEscolhido.hidden = false;
        livroEscolhido.innerHTML = `<div class="topo-registro"><i class="bi bi-book-fill text-success"></i><strong>${esc(l.titulo)}</strong><span class="text-secondary small">${esc(l.autor)}</span>
          <button type="button" class="btn btn-sm btn-light ms-auto" onclick="livroId.value=\'\';livroEscolhido.hidden=true;buscaLivro.focus()">Trocar</button></div>
          <div class="rodape-registro">${l.disponiveis ? l.disponiveis + " exemplar(es) na estante" + (l.local ? " · " + esc(l.local) : "") : "<strong class=\\"text-danger\\">Todos os exemplares estão emprestados</strong>"}</div>`;
        if (l.disponiveis) buscaAluno.focus();
      } });
    comboBusca({ input: buscaAluno, lista: listaAlunos, url: q => "api.php?acao=buscar&q=" + encodeURIComponent(q), vazio: "Nenhum aluno ativo encontrado.",
      item: a => `<i class="bi bi-person"></i><span>${esc(a.nome)}</span><span class="turma">${esc(a.turma)}</span>`,
      escolher: async a => {
        const j = await (await fetch("api.php?acao=leitor&id=" + a.id)).json(); if (!j.ok) return;
        const l = j.leitor; alunoId.value = l.bloqueada ? "" : l.id; buscaAluno.value = "";
        alunoEscolhido.hidden = false;
        alunoEscolhido.innerHTML = `<div class="topo-registro"><i class="bi bi-person-check-fill text-success"></i><strong>${esc(l.nome)}</strong><span class="etiqueta contorno">${esc(l.turma)}</span>
          <span class="etiqueta ${l.ficha ? "escura" : "ambar"}">${l.ficha ? "Ficha " + esc(l.ficha) : "Ficha será criada"}</span>
          <button type="button" class="btn btn-sm btn-light ms-auto" onclick="alunoId.value=\'\';alunoEscolhido.hidden=true;buscaAluno.focus()">Trocar</button></div>
          <div class="rodape-registro">${l.bloqueada ? "<strong class=\\"text-danger\\">Ficha bloqueada para empréstimos</strong>" :
            (l.abertos.length ? "Está com: " + l.abertos.map(x => esc(x.titulo) + " (até " + x.prazo + (x.atrasado ? ", <strong class=\\"text-danger\\">atrasado</strong>" : "") + ")").join("; ") : "Nenhum livro com ele agora.")}</div>`;
      } });
    fl.querySelectorAll("[data-prazo]").forEach(b => b.addEventListener("click", () => {
      const d = new Date(retirada.value.slice(0, 10) + "T12:00"); d.setDate(d.getDate() + +b.dataset.prazo);
      prazo.value = d.toISOString().slice(0, 10);
    }));
    fl.addEventListener("submit", e => {
      if (!livroId.value) { e.preventDefault(); buscaLivro.focus(); return; }
      if (!alunoId.value) { e.preventDefault(); buscaAluno.focus(); }
    });
  }
  /* Nova ficha */
  const bf = document.getElementById("buscaFicha");
  if (bf) comboBusca({ input: bf, lista: listaFicha, url: q => "api.php?acao=buscar&q=" + encodeURIComponent(q),
    item: a => `<i class="bi bi-person"></i><span>${esc(a.nome)}</span><span class="turma">${esc(a.turma)}</span>`,
    escolher: a => { fichaAluno.value = a.id; bf.value = ""; fichaEscolhido.hidden = false; fichaEscolhido.innerHTML = `<i class="bi bi-person-check-fill text-success me-1"></i><strong>${esc(a.nome)}</strong> · ${esc(a.turma)}`; } });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
