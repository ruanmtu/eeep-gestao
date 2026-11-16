<?php
/* Impressão do mapa de turma: uma folha A4 (só frente), sem fotos — nome e número de cada aluno na carteira */
require __DIR__ . '/../includes/app.php';
exigir('alunos.ver');

$opcoes = eh_gestao() ? turmas_lista() : array_values(array_merge(turmas_dirigidas(), turmas_do_usuario()));
$ids = array_map('intval', array_column($opcoes, 'id'));
$tid = (int)($_GET['turma'] ?? 0);
if (!in_array($tid, $ids, true)) { http_response_code(403); exit('Você não tem acesso ao mapa desta turma.'); }
$turma = q_um('SELECT t.*, c.nome AS curso, c.sigla FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?', [$tid]);
$mapa = mapa_sala_ler($tid);

/* número de chamada (ordem alfabética), igual à tela do mapa */
$alunos = q_todos("SELECT id, nome FROM alunos WHERE turma_id = ? AND situacao = 'ativo' ORDER BY nome", [$tid]);
if (class_exists('Collator')) { $col = new Collator('pt_BR'); usort($alunos, fn($x, $y) => $col->compare($x['nome'], $y['nome'])); }
$dados = [];
foreach ($alunos as $i => $a) $dados[(int)$a['id']] = ['nome' => primeiro_ultimo(nome_proprio($a['nome']))];

/* tamanho da sala: F filas (da porta até as janelas) e P carteiras por fila (da frente para o fundo) */
$F = 1; $P = 1;
foreach ($mapa['cadeiras'] as $k) { [$f, $p] = array_map('intval', explode('-', $k)); $F = max($F, $f + 1); $P = max($P, $p + 1); }
$GAP = 3; $LARG = 166; $ALT = 196;
$LADO = round(min(34, ($LARG - $GAP * ($F - 1)) / $F, ($ALT - $GAP * ($P - 1)) / $P), 2);
$GAP_F = $P > 1 ? round(min(14, max($GAP, ($ALT - 8 - $LADO * $P) / ($P - 1))), 2) : $GAP;   // espaço entre as fileiras, para ocupar a sala
$ocupadas = array_filter($mapa['alunos'], fn($id) => isset($dados[$id]));
$dt_nome = !empty($turma['diretor_turma_id']) ? q_valor('SELECT nome FROM usuarios WHERE id = ?', [$turma['diretor_turma_id']]) : null;
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Mapa de turma · <?= e(turma_nome($turma)) ?></title>
<link href="<?= RAIZ ?>assets/css/fontes.css?v=3" rel="stylesheet">
<link href="<?= RAIZ ?>assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
    @page { size: A4 portrait; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; background: #d9dcd9; font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .folha { width: 210mm; height: 297mm; margin: 14px auto; background: #fff; padding: 11mm 11mm 9mm; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.18); }
    /* topo: logo à esquerda, escola em cinza à direita, linha fina verde/vermelha */
    .cab { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 9px; position: relative; }
    .cab::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: linear-gradient(90deg, #1e9a2b 0 50%, #e8391a 50% 100%); }
    .cab img { height: 15mm; }
    .cab .esc { text-align: right; color: #8a8f8b; font-size: 10.5px; line-height: 1.45; max-width: 105mm; }
    .cab .esc b { display: block; font-weight: 700; font-size: 11px; color: #7a807c; }
    /* turma e diretor(a) logo abaixo */
    .tit { margin: 6mm 0 4.5mm; }
    .tit small { display: block; font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #1e7a2b; }
    .tit h1 { margin: 2px 0 6px; font-size: 25px; line-height: 1.1; letter-spacing: -.02em; font-weight: 800; }
    .tit .dt { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; }
    .tit .dt span { font-size: 12px; color: #444; }
    .tit .dt b { font-weight: 800; }
    /* sala: porta na lateral esquerda (só o nome), carteiras quadradas, janelas finas à direita, fundo tracejado */
    .sala { flex: 1; min-height: 0; display: grid; grid-template-columns: 7mm minmax(0, 1fr) 5mm; grid-template-rows: minmax(0, 1fr) auto; gap: 0 3mm; }
    .lado-porta { position: relative; border-left: 1.5px solid #000; }
    .porta { position: absolute; left: -1.5px; top: 10mm; height: 26mm; width: 6mm; border-left: 3px solid #fff; display: flex; align-items: center; justify-content: center; }
    .porta::before, .porta::after { content: ""; position: absolute; left: -4px; width: 7px; height: 1.5px; background: #000; }
    .porta::before { top: 0; } .porta::after { bottom: 0; }
    .porta span { writing-mode: vertical-rl; transform: rotate(180deg); display: flex; align-items: center; gap: 4px; font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    .porta i { transform: rotate(90deg); font-size: 11px; }
    .chao { display: flex; flex-direction: column; min-height: 0; }
    .frente { display: flex; align-items: center; height: 13mm; flex-shrink: 0; }
    .frente.mesa-esquerda { justify-content: flex-start; } .frente.mesa-centro { justify-content: center; } .frente.mesa-direita { justify-content: flex-end; }
    .mesa { display: flex; align-items: center; gap: 8px; padding: 5px 16px; border: 1.5px solid #000; border-radius: 8px; font-weight: 800; font-size: 12px; }
    .mesa i { font-size: 15px; }
    .grade { flex: 1; min-height: 0; display: grid; justify-content: center; align-content: start; margin-top: 5mm; grid-template-columns: repeat(<?= $F ?>, <?= $LADO ?>mm); grid-template-rows: repeat(<?= $P ?>, <?= $LADO ?>mm); gap: <?= $GAP_F ?>mm <?= $GAP ?>mm; }
    .cad { border: 1.3px solid #000; border-radius: 6px; display: flex; align-items: center; justify-content: center; padding: 1mm; text-align: center; overflow: hidden; }
    .cad b { font-size: <?= $LADO >= 26 ? 15 : ($LADO >= 20 ? 13 : ($LADO >= 16 ? 11 : 9.5)) ?>px; line-height: 1.15; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .janelas { position: relative; border: 1px solid #8fb7cf; border-radius: 3px; background: linear-gradient(90deg, #e3f2fb, #f4fbff 55%, #d7ebf7); display: flex; align-items: center; justify-content: center; }
    .janelas::before { content: ""; position: absolute; inset: 0; background: repeating-linear-gradient(180deg, transparent 0 22mm, #8fb7cf 22mm 22.6mm); }
    .janelas span { position: relative; writing-mode: vertical-rl; transform: rotate(180deg); display: flex; align-items: center; gap: 6px; padding: 6px 0; background: #eef8fd; font-size: 9px; font-weight: 800; letter-spacing: .22em; text-transform: uppercase; color: #2e6a8c; }
    .janelas i { transform: rotate(90deg); font-size: 11px; }
    .fundo { grid-column: 1 / -1; margin-top: 3mm; padding-top: 2mm; border-top: 1px dashed #999; text-align: center; font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: #777; }
    .vazio { margin: auto; text-align: center; color: #777; font-size: 13px; }
    .botoes { text-align: center; margin: 14px; }
    .botoes button, .botoes a { font: 600 14px "Plus Jakarta Sans", Arial, sans-serif; padding: 9px 16px; border-radius: 10px; border: 1px solid #0e1a12; background: #0e1a12; color: #fff; cursor: pointer; text-decoration: none; margin: 4px; display: inline-block; }
    .botoes a { background: #fff; color: #0e1a12; }
    @media print { html, body { background: #fff; } .folha { margin: 0; box-shadow: none; } .botoes { display: none; } }
</style>
</head>
<body>
<div class="botoes"><button type="button" onclick="window.print()">Imprimir</button><a href="mapa_sala.php?turma=<?= $tid ?>">Voltar ao mapa</a></div>
<main class="folha">
    <header class="cab">
        <img src="<?= RAIZ ?>assets/img/logo-escola-2026.png" alt="<?= e($ESCOLA['nome']) ?>">
        <div class="esc"><b><?= e($ESCOLA['nome_oficial']) ?></b><?= e($ESCOLA['cidade']) ?></div>
    </header>
    <section class="tit">
        <small>Mapa de turma</small>
        <h1><?= e($turma['serie'] . 'º Ano ' . $turma['letra'] . ' · ' . $turma['curso']) ?></h1>
        <div class="dt"><span>Diretor(a) de turma:</span><b><?= e($dt_nome ? nome_proprio($dt_nome) : '—') ?></b></div>
    </section>
    <section class="sala">
        <div class="lado-porta"><div class="porta"><span><i class="bi bi-door-open"></i>Porta</span></div></div>
        <div class="chao">
            <div class="frente mesa-<?= e($mapa['mesa']) ?>"><div class="mesa"><i class="bi bi-person-workspace"></i>Mesa do professor</div></div>
            <?php if ($ocupadas): ?>
            <div class="grade">
                <?php for ($p = 0; $p < $P; $p++) for ($f = 0; $f < $F; $f++):
                    $id = $ocupadas["$f-$p"] ?? null;
                    if (!$id): ?><div></div><?php continue; endif; ?>
                    <div class="cad"><b><?= e($dados[$id]['nome']) ?></b></div>
                <?php endfor; ?>
            </div>
            <?php else: ?><p class="vazio">O mapa desta turma ainda não tem alunos nas carteiras.</p><?php endif; ?>
        </div>
        <div class="janelas"><span><i class="bi bi-window"></i>Janelas</span></div>
        <div class="fundo"><span>Fundo da sala</span></div>
    </section>
</main>
<?php if (!empty($_GET['imprimir'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 300));</script><?php endif; ?>
</body>
</html>
