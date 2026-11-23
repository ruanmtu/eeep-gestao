<?php
require __DIR__ . '/../includes/app.php';
exigir('saidas.autorizar');     // só a coordenação/direção imprime o cupom

$s = q_um('SELECT s.*, a.nome, a.matricula, t.serie, t.letra, c.nome AS curso FROM saidas s JOIN alunos a ON a.id = s.aluno_id
            LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) LEFT JOIN cursos c ON c.id = t.curso_id WHERE s.id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$s) { flash('warning', 'Autorização não encontrada.'); redirecionar('saidas.php'); }
if (($s['status'] ?? 'autorizada') !== 'autorizada') { flash('warning', 'Este pedido ainda não foi autorizado. Confirme-o antes de imprimir.'); redirecionar('saidas.php?ver=' . $s['id'] . '#pendentes'); }
$tipo = $s['tipo'] ?? 'saida';
[$rot_tipo, , $titulo_cupom] = tipo_autorizacao($s);
$largura = (int)($_GET['largura'] ?? 80) === 58 ? 58 : 80;
$codigo = implode('-', str_split($s['codigo'], 4));
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(mb_convert_case($titulo_cupom, MB_CASE_TITLE)) ?> nº <?= $s['id'] ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<style>
    @page { size: <?= $largura ?>mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9ecea; font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #000; }
    .cupom { width: <?= $largura ?>mm; margin: 16px auto; background: #fff; padding: 4mm <?= $largura === 58 ? 3 : 4 ?>mm 5mm; font-size: <?= $largura === 58 ? 10 : 11.5 ?>px; line-height: 1.35; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
    .centro { text-align: center; }
    /* cabeçalho: logo menor, nome curto e endereço */
    .logo { width: <?= $largura === 58 ? 30 : 32 ?>mm; margin: 0 auto 1.2mm; display: block; filter: grayscale(1) contrast(1.6); }
    .escola { font-weight: 800; font-size: 1em; }
    .peq { font-size: .84em; }
    .corte { border: 0; border-top: 1.5px dashed #000; margin: 2.6mm 0; }
    h1 { font-size: 1.4em; margin: 0; letter-spacing: .03em; line-height: 1.2; }
    .num { display: inline-block; margin-top: 1.4mm; border: 1.5px solid #000; border-radius: 99px; padding: .2mm 3mm; font-weight: 800; font-size: .92em; }
    .rot { display: block; font-size: .74em; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
    .aluno { margin: 1mm 0 1.6mm; }
    .aluno .nome-aluno { font-size: 1.3em; font-weight: 800; line-height: 1.2; }
    /* linhas: rótulo à esquerda, valor à direita */
    .linha { display: flex; justify-content: space-between; align-items: baseline; gap: 3mm; padding: 1.3mm 0; border-bottom: 1px dotted #000; }
    .linha .rot { flex-shrink: 0; }
    .linha .val { font-size: 1.08em; font-weight: 600; text-align: right; }
    .bloco { padding: 1.3mm 0; border-bottom: 1px dotted #000; }
    .bloco .val { font-size: 1.02em; font-weight: 600; }
    /* assinatura com o código dentro */
    .assinatura { border: 1.5px solid #000; border-radius: 2mm; padding: 2.4mm 2.6mm; margin-top: 3mm; }
    .assinatura .titulo { font-size: .74em; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
    .assinatura .nome { font-size: 1.18em; font-weight: 800; margin-top: .6mm; }
    .assinatura .cod { margin-top: 2mm; padding-top: 1.8mm; border-top: 1px dashed #000; }
    .codigo { font-family: "Courier New", monospace; font-weight: 700; font-size: 1.3em; letter-spacing: .08em; }
    .botoes { text-align: center; margin: 14px; font-family: Arial, sans-serif; }
    .botoes a, .botoes button { font: 600 14px "Plus Jakarta Sans", Arial, sans-serif; padding: 9px 14px; border-radius: 10px; border: 1px solid #0e1a12; background: #0e1a12; color: #fff; cursor: pointer; text-decoration: none; margin: 4px; display: inline-block; }
    .botoes a.claro { background: #fff; color: #0e1a12; }
    @media print { body { background: #fff; } .cupom { margin: 0; box-shadow: none; } .botoes { display: none; } }
</style>
</head>
<body>
<div class="botoes">
    <button onclick="window.print()">Imprimir cupom</button>
    <a class="claro" href="saidas.php#pendentes">Voltar às autorizações</a>
    <a class="claro" href="aluno.php?id=<?= $s['aluno_id'] ?>#autorizacoes">Ficha do aluno</a>
    <a class="claro" href="?id=<?= $s['id'] ?>&largura=<?= $largura === 80 ? 58 : 80 ?>">Papel de <?= $largura === 80 ? 58 : 80 ?> mm</a>
</div>

<article class="cupom">
    <div class="centro">
        <img class="logo" src="../assets/img/logo-doc-2026.png" alt="">
        <div class="escola"><?= e($ESCOLA['nome']) ?></div>
        <div class="peq"><?= e($ESCOLA['endereco']) ?><br><?= e($ESCOLA['cidade']) ?></div>
    </div>
    <hr class="corte">
    <div class="centro">
        <h1><?= e($tipo === 'outra' && $s['titulo'] ? mb_strtoupper($s['titulo']) : $titulo_cupom) ?></h1>
        <span class="num">Nº <?= str_pad((string)$s['id'], 6, '0', STR_PAD_LEFT) ?></span>
    </div>
    <hr class="corte">

    <div class="aluno"><span class="rot">Aluno(a)</span><div class="nome-aluno"><?= e(nome_proprio($s['nome'])) ?></div><div class="peq"><?= e(turma_nome($s)) ?></div></div>
    <?php $resp = $s['responsavel_nome'] ? e($s['responsavel_nome']) . ($s['responsavel_parentesco'] ? ' (' . e($s['responsavel_parentesco']) . ')' : '') : ''; ?>
    <?php if ($tipo === 'saida'): ?>
    <div class="linha"><span class="rot">Data</span><span class="val"><?= date('d/m/Y', strtotime($s['data_hora'])) ?></span></div>
    <div class="linha"><span class="rot">Hora da saída</span><span class="val"><?= date('H:i', strtotime($s['data_hora'])) ?></span></div>
    <div class="linha"><span class="rot">Motivo</span><span class="val"><?= e($s['motivo']) ?></span></div>
    <div class="linha"><span class="rot">Responsável</span><span class="val"><?= $resp ?></span></div>
    <?php elseif ($tipo === 'tablet'): ?>
    <div class="linha"><span class="rot">Aparelho</span><span class="val">Tablet pessoal<?= $s['item'] ? ' · ' . e($s['item']) : '' ?></span></div>
    <div class="linha"><span class="rot">Uso permitido</span><span class="val"><?= e($s['local'] ?: 'Centro de Multimeios') ?> · <?= e($s['horario'] ?: 'horário do almoço') ?></span></div>
    <div class="linha"><span class="rot">Validade</span><span class="val"><?= e(vigencia_texto($s)) ?></span></div>
    <div class="bloco"><span class="rot">Finalidade</span><div class="val"><?= e($s['motivo']) ?></div></div>
    <p class="peq" style="margin:2mm 0 0"><?= e(TERMO_TABLET) ?></p>
    <?php else: ?>
    <div class="linha"><span class="rot">Validade</span><span class="val"><?= e(vigencia_texto($s)) ?></span></div>
    <?php if ($s['local'] || $s['horario']): ?><div class="linha"><span class="rot">Local / horário</span><span class="val"><?= e(trim(($s['local'] ?? '') . ($s['local'] && $s['horario'] ? ' · ' : '') . ($s['horario'] ?? ''))) ?></span></div><?php endif; ?>
    <div class="bloco"><span class="rot">Descrição</span><div class="val"><?= e($s['motivo']) ?></div></div>
    <?php if ($resp): ?><div class="linha"><span class="rot">Responsável ciente</span><span class="val"><?= $resp ?></span></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($s['solicitado_nome'])): ?><div class="linha"><span class="rot">Pedido por</span><span class="val"><?= e($s['solicitado_nome']) ?><?= $s['solicitado_em'] ? ' · ' . date('H:i', strtotime($s['solicitado_em'])) : '' ?></span></div><?php endif; ?>

    <div class="assinatura">
        <div class="titulo">✔ Assinado digitalmente por</div>
        <div class="nome"><?= e($s['autorizado_nome']) ?></div>
        <div class="peq"><?= e($s['autorizado_cargo']) ?> · <?= date('d/m/Y \à\s H:i', strtotime($s['criado_em'])) ?></div>
        <div class="cod"><div class="peq">Código de autenticação</div><div class="codigo"><?= e($codigo) ?></div></div>
    </div>

    <hr class="corte">
    <div class="centro peq">
        Confira em Autorizações › consultar código.<br>
        <?= e(impresso_por()) ?>
    </div>
</article>

<?php if (!empty($_GET['imprimir'])): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</body>
</html>
