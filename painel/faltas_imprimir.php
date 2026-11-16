<?php
/* Relatório de faltas do dia (coleta de faltas) para imprimir: quem faltou, por turma.
   O professor usa esta folha para fazer a chamada no sistema Professor Online. */
require __DIR__ . '/../includes/app.php';
exigir('frequencia');

$data = (string)($_GET['data'] ?? date('Y-m-d'));
if (!data_valida($data) || $data > date('Y-m-d')) $data = date('Y-m-d');
$turmas = turmas_lista();
$tid = (int)($_GET['turma'] ?? 0);
if ($tid && !in_array($tid, array_map('intval', array_column($turmas, 'id')), true)) $tid = 0;

$p = [$data]; $w = '';
if ($tid) { $w = ' AND a.turma_id = ?'; $p[] = $tid; }
$faltas = q_todos("SELECT a.nome, a.turma_id, f.justificada FROM faltas f JOIN alunos a ON a.id = f.aluno_id WHERE f.data_falta = ?$w ORDER BY a.nome", $p);
$grupos = [];
foreach ($faltas as $f) $grupos[(int)$f['turma_id']][] = $f;
if (class_exists('Collator')) { $col = new Collator('pt_BR'); foreach ($grupos as &$g) usort($g, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); unset($g); }
$total_alunos = [];
foreach (q_todos("SELECT turma_id, COUNT(*) AS n FROM alunos WHERE situacao = 'ativo' GROUP BY turma_id") as $r) $total_alunos[(int)$r['turma_id']] = (int)$r['n'];
$com_falta = array_values(array_filter($turmas, fn($t) => isset($grupos[(int)$t['id']])));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faltas de <?= data_br($data) ?></title>
<link href="<?= RAIZ ?>assets/css/fontes.css?v=3" rel="stylesheet">
<link href="<?= RAIZ ?>assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
    @page { size: A4 portrait; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; background: #d9dcd9; font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .folha { width: 210mm; min-height: 297mm; margin: 14px auto; background: #fff; padding: 11mm 12mm 10mm; box-shadow: 0 4px 24px rgba(0,0,0,.18); }
    .cab { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 9px; position: relative; }
    .cab::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: linear-gradient(90deg, #1e9a2b 0 50%, #e8391a 50% 100%); }
    .cab img { height: 14mm; }
    .cab .esc { text-align: right; color: #8a8f8b; font-size: 10.5px; line-height: 1.45; max-width: 105mm; }
    .cab .esc b { display: block; font-weight: 700; font-size: 11px; color: #7a807c; }
    .tit { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin: 6mm 0 5mm; }
    .tit small { display: block; font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #1e7a2b; }
    .tit h1 { margin: 2px 0 0; font-size: 24px; line-height: 1.1; letter-spacing: -.02em; font-weight: 800; }
    .tit .tot { text-align: right; font-size: 11px; color: #444; }
    .tit .tot b { display: block; font-size: 26px; line-height: 1; color: #000; }
    .turmas { columns: 2; column-gap: 7mm; }
    .tm { break-inside: avoid; border: 1.2px solid #000; border-radius: 8px; margin: 0 0 5mm; overflow: hidden; }
    .tm h2 { margin: 0; display: flex; align-items: center; gap: 8px; padding: 6px 10px; font-size: 12.5px; font-weight: 800; background: #f1f4f1; border-bottom: 1px solid #000; }
    .tm h2 span { margin-left: auto; font-size: 10.5px; font-weight: 700; color: #444; }
    .tm ol { margin: 0; padding: 4px 10px 6px 10px; list-style: none; counter-reset: n; }
    .tm li { counter-increment: n; display: flex; align-items: center; gap: 8px; padding: 4px 0; border-bottom: 1px dotted #bbb; font-size: 12px; font-weight: 600; }
    .tm li:last-child { border-bottom: 0; }
    .tm li::before { content: counter(n) "."; width: 18px; font-size: 10px; color: #666; font-weight: 700; }
    .tm li i { margin-left: auto; width: 12px; height: 12px; border: 1.2px solid #000; border-radius: 3px; flex-shrink: 0; }
    .tm li em { font-style: normal; font-size: 9px; font-weight: 800; color: #1e7a2b; }
    .vazio { text-align: center; padding: 30mm 0; color: #666; font-size: 14px; }
    .rodape { margin-top: 4mm; font-size: 9.5px; color: #777; display: flex; justify-content: space-between; }
    .botoes { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 8px; margin: 14px; }
    .botoes button, .botoes a, .botoes select { font: 600 14px "Plus Jakarta Sans", Arial, sans-serif; padding: 9px 14px; border-radius: 10px; border: 1px solid #0e1a12; background: #0e1a12; color: #fff; cursor: pointer; text-decoration: none; }
    .botoes a, .botoes select { background: #fff; color: #0e1a12; }
    @media print { html, body { background: #fff; } .folha { margin: 0; box-shadow: none; min-height: 0; } .botoes { display: none; } }
</style>
</head>
<body>
<form class="botoes" method="get">
    <input type="hidden" name="data" value="<?= e($data) ?>">
    <select name="turma" onchange="this.form.submit()" aria-label="Turma">
        <option value="0">Todas as turmas</option>
        <?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= $tid === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?>
    </select>
    <button type="button" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
    <a href="frequencia.php?data=<?= e($data) ?>">Voltar à coleta</a>
</form>
<main class="folha">
    <header class="cab">
        <img src="<?= RAIZ ?>assets/img/logo-escola-2026.png" alt="<?= e($ESCOLA['nome']) ?>">
        <div class="esc"><b><?= e($ESCOLA['nome_oficial']) ?></b><?= e($ESCOLA['cidade']) ?></div>
    </header>
    <section class="tit">
        <div><small>Coleta de faltas</small><h1><?= dia_semana($data) ?>, <?= data_br($data) ?></h1></div>
        <div class="tot"><b><?= count($faltas) ?></b>aluno<?= count($faltas) === 1 ? '' : 's' ?> faltaram</div>
    </section>
    <?php if (!$faltas): ?>
        <p class="vazio">Nenhuma falta lançada <?= $tid ? 'nesta turma ' : '' ?>neste dia.</p>
    <?php else: ?>
    <div class="turmas">
        <?php foreach ($com_falta as $t): $l = $grupos[(int)$t['id']]; ?>
        <section class="tm">
            <h2><?= e(turma_nome($t)) ?><span><?= count($l) ?> de <?= $total_alunos[(int)$t['id']] ?? '—' ?></span></h2>
            <ol><?php foreach ($l as $f): ?><li><?= e(nome_proprio($f['nome'])) ?><?php if ($f['justificada']): ?> <em>JUSTIFICADA</em><?php endif; ?><i></i></li><?php endforeach; ?></ol>
        </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="rodape"><span>Marque o quadradinho ao lançar a falta no Professor Online.</span><span>Impresso em <?= date('d/m/Y H:i') ?></span></div>
</main>
<?php if (!empty($_GET['imprimir'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 300));</script><?php endif; ?>
</body>
</html>
