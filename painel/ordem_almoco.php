<?php
require __DIR__ . '/../includes/app.php';
exigir('alunos.ver');

[$seg, $sex] = semana_param();
[$ordem, $bseg, $bsex] = ordem_almoco_semana($seg);
registrar_log('impressao', 'Ordem do almoço · semana ' . semana_rotulo($seg, $sex));
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ordem de liberação para o almoço · <?= e(semana_rotulo($seg, $sex)) ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<link href="../assets/css/impressao.css?v=3" rel="stylesheet">
</head>
<body>
<div class="botoes"><button onclick="window.print()">Imprimir ou salvar em PDF</button><a href="index.php?semana=<?= $seg ?>">Voltar ao painel</a></div>
<main class="folha">
    <header class="cab">
        <img src="../assets/img/logo-doc-2026.png" alt="<?= e($ESCOLA['nome']) ?>">
        <div class="cab-dir"><?= e($ESCOLA['nome_oficial']) ?><br><?= e($ESCOLA['endereco']) ?> · <?= e($ESCOLA['cidade']) ?></div>
    </header>
    <h1>Ordem de liberação para o almoço</h1>
    <p class="sub">Vale para a semana de <strong><?= e(semana_rotulo($seg, $sex)) ?></strong> · resultado das faltas da semana anterior (<?= e(semana_rotulo($bseg, $bsex)) ?>, de segunda a sexta): a turma com <strong>menos faltas</strong> é liberada primeiro e a com <strong>mais faltas</strong> por último.</p>

    <table class="tabela">
        <thead><tr><th style="width:70px">Ordem</th><th>Turma</th><th class="n">Faltas (<?= date('d/m', strtotime($bseg)) ?> a <?= date('d/m', strtotime($bsex)) ?>)</th><th class="n">Alunos faltosos</th><th class="n">Alunos na turma</th><th style="width:110px">Horário</th></tr></thead>
        <tbody>
        <?php foreach ($ordem as $i => $t): ?>
            <tr class="<?= $i === 0 ? 'primeira' : ($i === count($ordem) - 1 ? 'ultima' : '') ?>">
                <td class="pos"><?= $i + 1 ?>º</td>
                <td><strong><?= e($t['serie'] . 'º Ano ' . $t['letra']) ?></strong> · <?= e($t['curso']) ?></td>
                <td class="n"><strong><?= $t['faltas'] ?></strong></td>
                <td class="n"><?= $t['faltosos'] ?></td>
                <td class="n"><?= $t['alunos'] ?></td>
                <td class="linha-escrever"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="obs">Critério de desempate: menor número de alunos faltosos. A coluna "Horário" pode ser preenchida à mão pela gestão.</p>

    <div class="assinaturas"><div>Direção escolar</div><div>Responsável pela liberação</div></div>
    <footer class="rodape-doc"><?= e(impresso_por()) ?> · Sistema escolar <?= e($ESCOLA['nome']) ?></footer>
</main>
</body>
</html>
