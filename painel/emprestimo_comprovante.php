<?php
require __DIR__ . '/../includes/app.php';
exigir('biblioteca');

$s = q_um('SELECT e.*, l.titulo, l.autor, l.localizacao, a.nome, t.serie, t.letra, c.nome AS curso, f.numero AS ficha, u.nome AS por_nome, u.cargo AS por_cargo, u.perfil AS por_perfil
             FROM emprestimos e JOIN livros l ON l.id = e.livro_id JOIN alunos a ON a.id = e.aluno_id
             LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
             LEFT JOIN biblioteca_fichas f ON f.aluno_id = a.id LEFT JOIN usuarios u ON u.id = e.registrado_por WHERE e.id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$s) { flash('warning', 'Empréstimo não encontrado.'); redirecionar('biblioteca.php'); }
$largura = (int)($_GET['largura'] ?? 80) === 58 ? 58 : 80;
$cargo = cargo_usuario(['cargo' => $s['por_cargo'], 'perfil' => $s['por_perfil']]);
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprovante de empréstimo (2 vias) nº <?= $s['id'] ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<style>
    @page { size: <?= $largura ?>mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9ecea; font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #000; }
    .cupom { width: <?= $largura ?>mm; margin: 16px auto; background: #fff; padding: 4mm <?= $largura === 58 ? 3 : 4 ?>mm 6mm; font-size: <?= $largura === 58 ? 10 : 11.5 ?>px; line-height: 1.35; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
    .centro { text-align: center; }
    .logo { width: <?= $largura === 58 ? 30 : 32 ?>mm; margin: 0 auto 1.2mm; display: block; filter: grayscale(1) contrast(1.6); }
    .escola { font-weight: 800; font-size: 1em; }
    .peq { font-size: .86em; }
    .corte { border: 0; border-top: 1.5px dashed #000; margin: 2.6mm 0; }
    h1 { font-size: 1.4em; margin: 0; letter-spacing: .03em; line-height: 1.2; }
    .num { display: inline-block; margin-top: 1.4mm; border: 1.5px solid #000; border-radius: 99px; padding: .2mm 3mm; font-weight: 800; font-size: .92em; }
    .rot { display: block; font-size: .74em; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
    .linha { display: flex; justify-content: space-between; align-items: baseline; gap: 3mm; padding: 1.3mm 0; border-bottom: 1px dotted #000; }
    .linha .rot { flex-shrink: 0; } .linha .val { font-size: 1.08em; font-weight: 600; text-align: right; }
    .destaque { margin: 1mm 0 1.6mm; } .destaque .grande { font-size: 1.25em; font-weight: 800; line-height: 1.2; }
    .assinatura .cod { margin-top: 2mm; padding-top: 1.8mm; border-top: 1px dashed #000; }
    .campo { margin: 1.6mm 0; }
    .campo b { display: block; font-size: .78em; text-transform: uppercase; letter-spacing: .04em; }
    .campo span { font-size: 1.08em; font-weight: 600; }
    .grade { display: grid; grid-template-columns: 1fr 1fr; gap: 0 3mm; }
    .assinatura { border: 1.5px solid #000; border-radius: 2mm; padding: 2.5mm; margin-top: 3mm; }
    .assinatura .titulo { font-size: .78em; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: 4px; }
    .assinatura .nome { font-size: 1.2em; font-weight: 800; margin-top: 1mm; }
    .assinatura .cargo { font-weight: 600; }
    .codigo { font-family: "Courier New", monospace; font-weight: 700; font-size: 1.3em; letter-spacing: .08em; }
    .botoes { text-align: center; margin: 14px; font-family: Arial, sans-serif; }
    .botoes a, .botoes button { font: 600 14px "Plus Jakarta Sans", Arial, sans-serif; padding: 9px 14px; border-radius: 10px; border: 1px solid #0e1a12; background: #0e1a12; color: #fff; cursor: pointer; text-decoration: none; margin: 4px; display: inline-block; }
    .botoes a.claro { background: #fff; color: #0e1a12; }
    .via { text-align: center; font-weight: 800; font-size: .82em; letter-spacing: .12em; border: 1.5px solid #000; border-radius: 99px; padding: .6mm 0; margin-bottom: 2.5mm; }
    .linha-assinatura { border-bottom: 1px solid #000; height: 12mm; margin: 0 4mm 1mm; }
    .codigo { font-family: "Courier New", monospace; font-weight: 700; font-size: 1.3em; letter-spacing: .08em; }
    .tesoura { width: <?= $largura ?>mm; margin: 0 auto; text-align: center; font-size: 11px; color: #555; padding: 2mm 0; background: #fff; }
    @media print { body { background: #fff; } .cupom { margin: 0; box-shadow: none; } .botoes { display: none; } }
</style>
</head>
<body>
<div class="botoes">
    <button onclick="window.print()">Imprimir comprovante</button>
    <a class="claro" href="biblioteca.php">Voltar ao Centro de Multimeios</a>
    <a class="claro" href="?id=<?= $s['id'] ?>&largura=<?= $largura === 80 ? 58 : 80 ?>">Papel de <?= $largura === 80 ? 58 : 80 ?> mm</a>
</div>

<?php
$dia_sem = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'][(int)date('w', strtotime($s['prazo']))];
$codigo = strtoupper(substr(hash('sha256', $s['id'] . '|' . $s['retirada'] . '|' . $s['aluno_id'] . '|' . $s['livro_id'] . '|' . $s['registrado_por']), 0, 12));
foreach (['escola' => 'VIA DA ESCOLA', 'aluno' => 'VIA DO ESTUDANTE'] as $via => $rot_via): ?>
<article class="cupom">
    <div class="via"><?= $rot_via ?></div>
    <div class="centro">
        <img class="logo" src="../assets/img/logo-doc-2026.png" alt="">
        <div class="escola"><?= e($ESCOLA['nome']) ?></div>
        <div class="peq">Centro de Multimeios<br><?= e($ESCOLA['endereco']) ?><br><?= e($ESCOLA['cidade']) ?></div>
    </div>
    <hr class="corte">
    <div class="centro">
        <h1>EMPRÉSTIMO DE LIVRO</h1>
        <span class="num">Nº <?= str_pad((string)$s['id'], 6, '0', STR_PAD_LEFT) ?></span>
    </div>
    <hr class="corte">

    <div class="destaque"><span class="rot">Livro</span><div class="grande"><?= e($s['titulo']) ?></div><div class="peq"><?= e($s['autor']) ?></div></div>
    <div class="destaque"><span class="rot">Aluno(a)</span><div class="grande"><?= e(nome_proprio($s['nome'])) ?></div><div class="peq"><?= e(turma_nome($s)) ?></div></div>
    <div class="linha"><span class="rot">Ficha</span><span class="val"><?= e($s['ficha'] ?? '—') ?></span></div>
    <div class="linha"><span class="rot">Retirada</span><span class="val"><?= date('d/m/Y \à\s H:i', strtotime($s['retirada'])) ?></span></div>

    <div class="assinatura centro">
        <div class="titulo" style="justify-content:center">Devolver até</div>
        <div class="nome" style="font-size:1.6em"><?= date('d/m/Y', strtotime($s['prazo'])) ?></div>
        <div class="peq"><?= $dia_sem ?><?= $s['renovacoes'] ? ' · renovado ' . (int)$s['renovacoes'] . 'x' : '' ?></div>
        <?php if ($s['devolvido_em']): ?><div class="peq"><strong>Devolvido em <?= date('d/m/Y H:i', strtotime($s['devolvido_em'])) ?></strong></div><?php endif; ?>
    </div>
    <?php if ($s['observacao']): ?><div class="campo"><b>Observação</b><span><?= e($s['observacao']) ?></span></div><?php endif; ?>

    <?php if ($via === 'escola'): ?>
        <p class="peq" style="margin:3mm 0 0">Declaro que recebi o livro acima em bom estado e me comprometo a devolvê-lo até a data indicada.</p>
        <div class="linha-assinatura"></div>
        <div class="centro peq"><strong><?= e(nome_proprio($s['nome'])) ?></strong><br>Assinatura do(a) estudante</div>
        <div class="campo" style="margin-top:3mm"><b>Registrado por</b><span><?= e($s['por_nome'] ?? '—') ?></span><div class="peq"><?= e($cargo) ?></div></div>
    <?php else: ?>
        <div class="assinatura" style="margin-top:3mm">
            <div class="titulo">✔ Assinado digitalmente por</div>
            <div class="nome"><?= e($s['por_nome'] ?? '—') ?></div>
            <div class="peq"><?= e($cargo) ?> · <?= date('d/m/Y \à\s H:i', strtotime($s['retirada'])) ?></div>
            <div class="cod"><div class="peq">Código de autenticação</div><div class="codigo"><?= e(implode('-', str_split($codigo, 4))) ?></div></div>
        </div>
    <?php endif; ?>

    <hr class="corte">
    <div class="centro peq">
        <?= $via === 'aluno' ? 'Cuide bem do livro e devolva no prazo.<br>Boa leitura!<br><br>' : 'Guardar no Centro de Multimeios.<br><br>' ?>
        <?= e(impresso_por()) ?>
    </div>
</article>
<?php if ($via === 'escola'): ?><div class="tesoura">✂ - - - - - - - corte aqui - - - - - - -</div><?php endif; ?>
<?php endforeach; ?>

<?php if (!empty($_GET['imprimir'])): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</body>
</html>
