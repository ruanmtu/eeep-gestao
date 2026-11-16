<?php
require __DIR__ . '/../includes/app.php';
exigir('ocorrencias');

$ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['id'] ?? '')))));
if (!$ids) redirecionar('ocorrencias.php');
$lista = ocorrencias_lista('o.id IN (' . implode(',', $ids) . ')' . (ocorrencias_ve_tudo() ? '' : ' AND o.registrado_por = ' . (int)uid()), []);
if (!$lista) { flash('warning', 'Registro não encontrado.'); redirecionar('ocorrencias.php'); }
registrar_log('impressao', 'Termo de ocorrência #' . implode(',', $ids));
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termo nº <?= e(implode(', ', $ids)) ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<link href="../assets/css/impressao.css?v=3" rel="stylesheet">
<style>
    .folha + .folha { margin-top: 24px; }
    .folha { color: #000; width: 210mm; max-width: none; height: 297mm; padding: 12mm 14mm; overflow: hidden; border-radius: 0; }
    .fit { width: 182mm; transform-origin: 0 0; }
    /* cabeçalho: logo, escola em preto e linha fina verde/vermelha */
    .cab { border-bottom: 0; padding-bottom: 14px; }
    .cab::after { bottom: 0; height: 2px; background: linear-gradient(90deg, #1e9a2b 0 50%, #e8391a 50% 100%); }
    .cab-dir { color: #000; line-height: 1.5; }
    .cab-dir b { font-size: 12px; }
    /* bloco do título */
    .tt { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin: 20px 0 14px; }
    .tt-sobre { font-size: 10.5px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: #1e7a2b; margin: 0 0 4px; }
    .tt h1 { font-size: 30px; line-height: 1.05; letter-spacing: -.025em; margin: 0; font-weight: 800; }
    .tt h1 small { display: block; margin-top: 6px; font-size: 13px; font-weight: 600; letter-spacing: 0; color: #333; }
    .tt-num { flex-shrink: 0; border: 1.5px solid #000; border-radius: 10px; overflow: hidden; text-align: center; min-width: 150px; }
    .tt-num span { display: block; background: #000; color: #fff; font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; padding: 4px 10px; }
    .tt-num b { display: block; font-size: 20px; letter-spacing: .04em; padding: 5px 10px 2px; font-variant-numeric: tabular-nums; }
    .tt-num em { display: block; font-style: normal; font-size: 11px; padding: 0 10px 6px; }
    /* seções numeradas */
    .sec { margin: 0 0 11px; break-inside: avoid; }
    .sec-t { display: flex; align-items: center; gap: 10px; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; margin: 0 0 8px; }
    .sec-t i { font-style: normal; width: 22px; height: 22px; border-radius: 6px; background: #000; color: #fff; display: grid; place-items: center; font-size: 11px; letter-spacing: 0; }
    .sec-t::after { content: ""; flex: 1; height: 1px; background: #000; }
    .grade { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid #000; border-radius: 8px; overflow: hidden; }
    .grade > div { padding: 7px 10px 8px; border-right: 1px solid #000; border-bottom: 1px solid #000; min-width: 0; }
    .grade > div.l2 { grid-column: span 2; } .grade > div.l4 { grid-column: span 4; } .grade > div.l3 { grid-column: span 3; }
    .grade > div.fim { border-right: 0; } .grade > div.base { border-bottom: 0; }
    .grade span { display: block; font-size: 9.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #333; }
    .grade strong { display: block; font-size: 13.5px; margin-top: 1px; }
    .grav { display: inline-block; font-style: normal; padding: 0 9px; border-radius: 99px; border: 1.5px solid #000; font-size: 12px; font-weight: 800; }
    .grav.grave { background: #000; color: #fff; }
    .texto { border: 1px solid #000; border-radius: 8px; padding: 8px 12px; min-height: 58px; font-size: 13px; line-height: 1.6;
             background-image: repeating-linear-gradient(transparent 0 25px, #e3e3e3 25px 26px); background-position: 0 10px; }
    .texto.vazio { min-height: 74px; }
    .ciencia { font-size: 12.8px; line-height: 1.75; text-align: justify; margin: 0; }
    .local-data { text-align: right; margin: 8px 0 0; font-size: 12.8px; }
    .assin { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 44px; text-align: center; font-size: 12px; break-inside: avoid; }
    .assin div { border-top: 1px solid #000; padding-top: 6px; }
    .assin b { display: block; font-size: 12.5px; }
    .assin small { color: #333; }
    .rodape-doc { display: flex; justify-content: space-between; gap: 12px; color: #333; border-top: 1px solid #000; text-align: left; margin-top: 18px; font-size: 10px; }
    @media print {
        @page { size: A4; margin: 0; }
        .folha { width: 210mm; height: 297mm; padding: 12mm 14mm; margin: 0; overflow: hidden; page-break-after: always; break-after: page; } .folha:last-of-type { page-break-after: auto; }
        .tt-num span, .sec-t i, .grav.grave { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .texto { background-image: none; }
    }
</style>
</head>
<body>
<div class="botoes"><button onclick="window.print()">Imprimir ou salvar em PDF</button><a href="ocorrencias.php">Voltar</a></div>
<?php
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
foreach ($lista as $o): [$rot] = categoria_ocorrencia($o);
    $cat = (string)($o['categoria'] ?? '');
    $titulo_termo = str_starts_with($cat, 'suspensao') ? 'Termo de suspensão' : (str_starts_with($cat, 'advertencia') ? 'Termo de advertência' : 'Termo de ocorrência');
    $num = str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT);
    $dt = strtotime($o['data_ocorrencia']);
    $aluno = nome_proprio($o['nome']);
    $susp = !empty($o['suspensao_inicio']);
    $dias = $susp ? (int)round((strtotime($o['suspensao_fim']) - strtotime($o['suspensao_inicio'])) / 86400) + 1 : 0; ?>
<main class="folha"><div class="fit">
    <header class="cab">
        <img src="../assets/img/logo-doc-2026.png" alt="<?= e($ESCOLA['nome']) ?>">
        <div class="cab-dir"><b><?= e($ESCOLA['nome_oficial']) ?></b><br><?= e($ESCOLA['endereco']) ?> · <?= e($ESCOLA['cidade']) ?></div>
    </header>

    <section class="tt">
        <div>
            <p class="tt-sobre">Registro disciplinar do estudante</p>
            <h1><?= $titulo_termo ?><?php if (!str_starts_with($cat, 'suspensao')): ?><small><?= e($rot) ?></small><?php endif; ?></h1>
        </div>
        <div class="tt-num"><span>Registro nº</span><b><?= $num ?></b><em><?= date('d/m/Y', $dt) ?></em></div>
    </section>

    <section class="sec">
        <h2 class="sec-t"><i>1</i>Identificação do estudante</h2>
        <div class="grade">
            <div class="l3"><span>Nome do(a) estudante</span><strong><?= e($aluno) ?></strong></div>
            <div class="fim"><span>Matrícula</span><strong><?= e($o['matricula'] ?? '—') ?: '—' ?></strong></div>
            <div class="l2 base"><span>Turma</span><strong><?= e(turma_nome($o)) ?></strong></div>
            <div class="l2 fim base"><span>Telefone do responsável</span><strong><?= e(($o['responsavel_telefone'] ?? '') ?: '—') ?></strong></div>
        </div>
    </section>

    <section class="sec">
        <h2 class="sec-t"><i>2</i>Dados da ocorrência</h2>
        <div class="grade">
            <div><span>Data</span><strong><?= date('d/m/Y', $dt) ?></strong></div>
            <div><span>Horário</span><strong><?= $o['hora'] ? substr($o['hora'], 0, 5) : '—' ?></strong></div>
            <div><span>Local</span><strong><?= e($o['local'] ?: '—') ?></strong></div>
            <div class="fim"><span>Gravidade</span><strong><em class="grav <?= e($o['gravidade']) ?>"><?= e(GRAVIDADES[$o['gravidade']]) ?></em></strong></div>
            <div class="l2<?= $susp ? '' : ' base' ?>"><span>Tipo de ocorrência</span><strong><?= e($rot) ?></strong></div>
            <div class="l2 fim<?= $susp ? '' : ' base' ?>"><span>Responsável comunicado</span><strong><?= $o['responsavel_notificado'] ? 'Sim' : 'Não' ?></strong></div>
            <?php if ($susp): ?><div class="l4 fim base"><span>Período da suspensão</span><strong>de <?= data_br($o['suspensao_inicio']) ?> a <?= data_br($o['suspensao_fim']) ?> · <?= $dias ?> dia<?= $dias > 1 ? 's' : '' ?> · retorno às aulas após o término do período</strong></div><?php endif; ?>
        </div>
    </section>

    <section class="sec">
        <h2 class="sec-t"><i>3</i>Descrição dos fatos</h2>
        <div class="texto"><?= nl2br(e($o['descricao'])) ?></div>
    </section>

    <section class="sec">
        <h2 class="sec-t"><i>4</i>Providências adotadas</h2>
        <div class="texto<?= $o['providencias'] ? '' : ' vazio' ?>"><?= $o['providencias'] ? nl2br(e($o['providencias'])) : '' ?></div>
    </section>

    <section class="sec">
        <h2 class="sec-t"><i>5</i>Ciência</h2>
        <p class="ciencia">Pelo presente termo, o(a) estudante <b><?= e($aluno) ?></b>, da turma <b><?= e(turma_nome($o)) ?></b>, e seu(sua) responsável legal declaram estar cientes do registro acima,
            que passa a constar na ficha escolar do(a) estudante, conforme o Regimento Escolar da <?= e($ESCOLA['nome']) ?>.<?= $susp ? ' Durante o período de suspensão, o(a) estudante não deverá comparecer às atividades escolares.' : '' ?></p>
        <p class="local-data"><?= e(preg_replace('/\s*-\s*CE$/', '', $ESCOLA['cidade'])) ?> - CE, <?= (int)date('d', $dt) ?> de <?= $meses[(int)date('n', $dt)] ?> de <?= date('Y', $dt) ?>.</p>
    </section>

    <div class="assin">
        <div><b><?= e($aluno) ?></b><small>Estudante</small></div>
        <div><b>&nbsp;</b><small>Responsável legal</small></div>
        <div><b><?= e($o['por'] ?? 'Gestão escolar') ?></b><small>Registrado por</small></div>
    </div>

    <div class="rodape-doc"><span><?= $titulo_termo ?> nº <?= $num ?> · registrado em <?= !empty($o['criado_em']) ? date('d/m/Y \\à\\s H:i', strtotime($o['criado_em'])) : '—' ?></span><span><?= e(impresso_por()) ?></span></div>
</div></main>
<?php endforeach; ?>
<script>
/* Garante uma folha só (frente): se o texto for grande, o termo inteiro diminui até caber na A4 */
(function () {
  const mm = v => v * 96 / 25.4, ALT = mm(297 - 24), LARG = 182;
  function ajustar() {
    document.querySelectorAll('.fit').forEach(f => {
      let z = 1;
      for (let i = 0; i < 6; i++) {
        f.style.width = (LARG / z) + 'mm'; f.style.transform = 'scale(' + z + ')';
        const h = f.scrollHeight * z;
        if (h <= ALT + 0.5) break;
        z = Math.max(0.5, z * ALT / h * 0.995);
      }
    });
  }
  ajustar(); addEventListener('load', ajustar); addEventListener('beforeprint', ajustar);
  if (document.fonts) document.fonts.ready.then(ajustar);
})();
</script>
<?php if (!empty($_GET['imprimir'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 300));</script><?php endif; ?>
</body>
</html>
