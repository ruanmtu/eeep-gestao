<?php
require __DIR__ . '/../includes/app.php';
exigir('provas');

$prova = prova_buscar((int)($_GET['id'] ?? 0));
if (!$prova || !pode_prova($prova)) { flash('warning', 'Prova não encontrada.'); redirecionar('provas.php'); }

$turma_id = (int)($_GET['turma'] ?? 0);
$branco = max(0, min(60, (int)($_GET['branco'] ?? 0)));
$alunos = [];
if ($turma_id) {
    $alunos = q_todos("SELECT a.id, a.nome, a.turma_id, t.serie, t.letra, c.nome AS curso FROM alunos a JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) JOIN cursos c ON c.id = t.curso_id
                        WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) AND t.serie = ? ORDER BY a.nome", [$turma_id, $prova['serie']]);
    /* nº do aluno = posição na lista da turma em ordem alfabética */
    $col = class_exists('Collator') ? new Collator('pt_BR') : null;
    usort($alunos, fn($x, $y) => $col ? $col->compare($x['nome'], $y['nome']) : strcmp(remover_acentos_ordem($x['nome']), remover_acentos_ordem($y['nome'])));
    foreach ($alunos as $i => &$al) $al['numero'] = $i + 1;
    unset($al);
}
function remover_acentos_ordem(string $s): string { return strtolower(strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'])); }
$turmas = q_todos('SELECT t.id, t.letra, c.nome AS curso FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.serie = ? AND t.ano_letivo = ?'
                  . ($prova['curso_id'] ? ' AND t.curso_id = ' . (int)$prova['curso_id'] : '') . ' ORDER BY t.letra', [$prova['serie'], $prova['ano_letivo']]);
registrar_log('impressao', 'Folhas de resposta · ' . $prova['titulo'] . ($turma_id ? ' · turma ' . $turma_id : " · $branco em branco"));
?>
<!doctype html>
<html lang="pt-br">
<head>
<link rel="icon" href="../assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Folhas de resposta · <?= e($prova['titulo']) ?></title>
<link href="../assets/css/fontes.css?v=3" rel="stylesheet">
<style>
    @page { size: A4; margin: 0; }
    body { margin: 0; background: #e9ecea; font-family: "Plus Jakarta Sans", Arial, sans-serif; }
    .pagina { width: 210mm; height: 297mm; margin: 0 auto 10mm; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.12); overflow: hidden; break-after: page; }
    .pagina svg { display: block; }
    .barra { position: sticky; top: 0; background: #0e1a12; color: #fff; padding: 12px 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; font-size: 14px; }
    .barra button, .barra a { font: 600 14px "Plus Jakarta Sans", Arial, sans-serif; padding: 8px 14px; border-radius: 10px; border: 1px solid #fff; background: #fff; color: #0e1a12; cursor: pointer; text-decoration: none; }
    .barra a { background: transparent; color: #fff; }
    .barra span { opacity: .8; }
    @media print { body { background: #fff; } .barra { display: none; } .pagina { margin: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="barra">
    <button onclick="window.print()">Imprimir folhas</button>
    <a href="prova.php?id=<?= $prova['id'] ?>#folhas">Voltar</a>
    <span><?= count($alunos) + $branco ?> folha(s) · imprima em A4, tamanho real (100%), sem "ajustar à página".</span>
</div>
<?php foreach ($alunos as $a): ?>
    <div class="pagina"><?= omr_svg($prova, $a, $turmas) ?></div>
<?php endforeach; ?>
<?php for ($i = 0; $i < $branco; $i++): ?>
    <div class="pagina"><?= omr_svg($prova, null, $turmas) ?></div>
<?php endfor; ?>
<?php if (!$alunos && !$branco): ?>
    <p style="text-align:center;padding:40px">Nenhum aluno ativo nesta turma para esta série. <a href="prova.php?id=<?= $prova['id'] ?>#folhas">Voltar</a></p>
<?php endif; ?>
</body>
</html>
