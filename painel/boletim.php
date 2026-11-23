<?php
require __DIR__ . '/../includes/app.php';
exigir('alunos.ver');

$aluno = aluno_buscar((int)($_GET['id'] ?? 0));
if (!$aluno) { flash('warning', 'Aluno não encontrado.'); redirecionar('alunos.php'); }
$ano = in_array((int)($_GET['ano'] ?? 0), anos_letivos(), true) ? (int)$_GET['ano'] : ANO_VER;
$boletim = boletim_aluno((int)$aluno['id'], $ano);
$faltas = q_um('SELECT COUNT(*) AS n, SUM(CASE WHEN justificada = 1 THEN 1 ELSE 0 END) AS j FROM faltas WHERE aluno_id = ? AND data_falta BETWEEN ? AND ?',
               [$aluno['id'], "$ano-01-01", "$ano-12-31"]);
$faltas['n'] = (int)$faltas['n'] + faltas_planilha((int)$aluno['id'], $ano);
registrar_log('boletim', 'Boletim ' . $ano . ' gerado', (int)$aluno['id']);
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Boletim · <?= e(nome_proprio($aluno['nome'])) ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<style>
    @page { size: A4; margin: 14mm; }
    body { font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #152818; margin: 0; background: #eef4ee; }
    .folha { background: #fff; max-width: 800px; margin: 24px auto; padding: 34px 40px; box-shadow: 0 2px 14px rgba(0,0,0,.08); }
    header { display: flex; gap: 18px; align-items: center; border-bottom: 3px solid #13692a; padding-bottom: 14px; position: relative; }
    header::after { content: ""; position: absolute; right: 0; bottom: -3px; width: 40%; height: 3px; background: #e8391a; }
    header img { width: 58px; }
    header h1 {  font-size: 20px; margin: 0; color: #13692a; }
    header p { margin: 2px 0 0; font-size: 13px; color: #555; }
    h2 {  font-size: 22px; margin: 22px 0 4px; }
    .dados { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 18px; font-size: 14px; margin: 14px 0 18px; }
    .dados span { display: block; color: #666; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { border: 1px solid #cfdcd0; padding: 7px 9px; }
    th { background: #e1f3e2; text-align: center; font-weight: 700; }
    td.n { text-align: center; font-variant-numeric: tabular-nums; }
    td.grupo { background: #f4faf4; font-weight: 700; font-size: 12px; color: #3c5240; }
    .baixo { color: #b42318; font-weight: 700; }
    .resumo { display: flex; gap: 14px; margin-top: 16px; font-size: 14px; }
    .resumo div { flex: 1; border: 1px solid #cfdcd0; border-radius: 8px; padding: 10px 12px; }
    .resumo strong { display: block; font-size: 20px;  }
    .assinaturas { display: flex; gap: 40px; margin-top: 60px; font-size: 13px; text-align: center; }
    .assinaturas div { flex: 1; border-top: 1px solid #333; padding-top: 6px; }
    .rodape { font-size: 11px; color: #777; margin-top: 26px; text-align: center; }
    .botoes { text-align: center; margin: 18px; }
    .botoes button, .botoes a { font: inherit; font-weight: 600; padding: 9px 18px; border-radius: 8px; border: 1px solid #13692a; background: #13692a; color: #fff; cursor: pointer; text-decoration: none; margin: 0 4px; }
    .botoes a { background: #fff; color: #13692a; }
    @media print { body { background: #fff; } .folha { box-shadow: none; margin: 0; padding: 0; max-width: none; } .botoes { display: none; } }
</style>
</head>
<body>
<div class="botoes"><button onclick="window.print()">Imprimir ou salvar em PDF</button><a href="aluno.php?id=<?= $aluno['id'] ?>">Voltar à ficha</a></div>
<div class="folha">
    <header>
        <img src="../assets/img/logo-doc-2026.png" alt="<?= e($ESCOLA['nome']) ?>" style="width:auto;height:56px">
        <div style="margin-left:auto;text-align:right"><p><?= e($ESCOLA['nome_oficial']) ?></p><p><?= e($ESCOLA['endereco']) ?> · <?= e($ESCOLA['cidade']) ?></p></div>
    </header>
    <h2>Boletim escolar <?= $ano ?></h2>
    <div class="dados">
        <div style="grid-column: span 2"><span>Aluno(a)</span><strong><?= e(nome_proprio($aluno['nome'])) ?></strong></div>
        <div><span>Matrícula</span><?= e($aluno['matricula'] ?: '—') ?></div>
        <div><span>Curso</span><?= e($aluno['curso'] ?? '—') ?></div>
        <div><span>Turma</span><?= e(turma_nome($aluno, false)) ?></div>
        <div><span>Situação</span><?= e(SITUACOES[$aluno['situacao']]) ?></div>
    </div>
    <table>
        <thead><tr><th style="text-align:left">Disciplina</th><th>1º bim.</th><th>2º bim.</th><th>3º bim.</th><th>4º bim.</th><th>Média</th></tr></thead>
        <tbody>
        <?php if (!$boletim): ?><tr><td colspan="6" class="n">Nenhuma nota lançada em <?= $ano ?>.</td></tr><?php endif; ?>
        <?php $g = null; foreach ($boletim as $d):
            if ($d['tipo'] !== $g): $g = $d['tipo']; ?><tr><td colspan="6" class="grupo"><?= $g === 'tecnica' ? 'Formação técnica' : 'Base nacional comum' ?></td></tr><?php endif; ?>
            <tr><td><?= e($d['nome']) ?></td>
                <?php for ($b = 1; $b <= 4; $b++): $n = $d['b'][$b] ?? null; ?><td class="n<?= $n !== null && $n < MEDIA_APROVACAO ? ' baixo' : '' ?>"><?= $n === null ? '—' : num($n) ?></td><?php endfor; ?>
                <td class="n<?= $d['media'] < MEDIA_APROVACAO ? ' baixo' : '' ?>"><strong><?= num($d['media']) ?></strong></td></tr>
        <?php endforeach; ?>
        <?php $nc = []; if ($aluno['turma_id'] && $ano === ano_hoje()) for ($b = 1; $b <= 4; $b++) $nc[$b] = nc_media_aluno((int)$aluno['id'], (int)$aluno['turma_id'], $b);
        if (array_filter($nc, fn($v) => $v !== null)): $ncv = array_filter($nc, fn($v) => $v !== null); ?>
            <tr><td class="grupo" colspan="6">Nota conceitual (média de todos os professores da turma)</td></tr>
            <tr><td>Média conceitual</td><?php for ($b = 1; $b <= 4; $b++): ?><td class="n"><?= $nc[$b] === null ? '—' : num($nc[$b]) ?></td><?php endfor; ?><td class="n"><strong><?= num(array_sum($ncv) / count($ncv)) ?></strong></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="resumo">
        <div><span>Faltas no ano</span><strong><?= (int)$faltas['n'] ?></strong></div>
        <div><span>Faltas justificadas</span><strong><?= (int)$faltas['j'] ?></strong></div>
        <div><span>Média geral</span><strong><?= $boletim ? num(array_sum(array_column($boletim, 'media')) / count($boletim)) : '—' ?></strong></div>
    </div>
    <div class="assinaturas"><div>Secretaria escolar</div><div>Coordenação pedagógica</div><div>Responsável</div></div>
    <p class="rodape">Média para aprovação: <?= num(MEDIA_APROVACAO) ?> · <?= e(impresso_por()) ?></p>
</div>
</body>
</html>
