<?php
require __DIR__ . '/../includes/app.php';
exigir_login();
$a = q_um('SELECT a.*, u.nome AS por, u.foto AS por_foto FROM arquivos a LEFT JOIN usuarios u ON u.id = a.enviado_por WHERE a.id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$a) { flash('warning', 'Arquivo não encontrado.'); redirecionar('arquivos.php'); }
$ext = arquivo_extensao($a['nome_original']);
[$ic, $cor, $tipo] = arquivo_icone($ext);
$url = 'arquivo.php?id=' . $a['id'];

$titulo = $a['titulo'];
$subtitulo = ($a['categoria'] ?: $tipo) . ' · ' . tamanho_fmt((int)$a['tamanho']) . ' · enviado em ' . data_br(substr($a['criado_em'], 0, 10));
$menu = $a['especial'] === 'calendario_sige' ? 'calendario' : 'arquivos';
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <a class="btn btn-light" href="<?= $a['especial'] ? 'calendario.php' : 'arquivos.php' ?>"><i class="bi bi-arrow-left me-1"></i><?= $a['especial'] ? 'Calendário' : 'Central de Arquivos' ?></a>
    <span class="arq-tipo <?= $cor ?>"><i class="bi bi-<?= $ic ?>"></i></span>
    <span class="small text-secondary"><?= e($a['nome_original']) ?> · <?= (int)$a['downloads'] ?> download(s)</span>
    <a class="btn btn-primary ms-auto" href="<?= $url ?>&baixar=1"><i class="bi bi-download me-1"></i>Baixar</a>
    <a class="btn btn-light" href="<?= $url ?>&inline=1" target="_blank" rel="noopener" title="Abrir em outra aba"><i class="bi bi-box-arrow-up-right"></i></a>
</div>
<?php if ($a['descricao']): ?><p class="mb-3"><?= nl2br(e($a['descricao'])) ?></p><?php endif; ?>

<?php if ($ext === 'pdf'): ?>
    <div class="visualizador-pdf alto" data-url="<?= $url ?>&inline=1"></div>
<?php elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)): ?>
    <div class="card"><div class="card-body text-center"><img src="<?= $url ?>&inline=1" alt="<?= e($a['titulo']) ?>" style="max-width:100%;max-height:78vh;border-radius:10px"></div></div>
<?php elseif ($ext === 'mp4'): ?>
    <div class="card"><div class="card-body"><video src="<?= $url ?>&inline=1" controls style="width:100%;max-height:78vh;border-radius:10px"></video></div></div>
<?php elseif ($ext === 'mp3'): ?>
    <div class="card"><div class="card-body"><audio src="<?= $url ?>&inline=1" controls style="width:100%"></audio></div></div>
<?php elseif (in_array($ext, ['txt', 'csv'], true)): ?>
    <div class="card"><pre class="card-body mb-0" style="white-space:pre-wrap;max-height:78vh;overflow:auto"><?= e(mb_substr((string)@file_get_contents(PASTA_DADOS . '/' . $a['caminho']), 0, 200000)) ?></pre></div>
<?php else: ?>
    <div class="card"><div class="vazio py-5"><i class="bi bi-<?= $ic ?>"></i><strong><?= e($tipo) ?> (.<?= e($ext) ?>)</strong>
        Este tipo de arquivo abre no programa do computador ou do celular (PowerPoint, Word, Excel…).<br>
        <a class="btn btn-primary mt-3" href="<?= $url ?>&baixar=1"><i class="bi bi-download me-1"></i>Baixar e abrir</a></div></div>
<?php endif; ?>
<p class="small text-secondary mt-3 d-flex align-items-center gap-2"><?= avatar(['id' => $a['enviado_por'], 'nome' => $a['por'] ?? '?', 'foto' => $a['por_foto']], 'usuario', 'avatar-xs') ?>Enviado por <?= e($a['por'] ?? '—') ?> em <?= data_hora_br($a['criado_em']) ?></p>
<?php
$script_pagina = '<script type="module" src="../assets/js/visualizador.js?v=10"></script>';
require __DIR__ . '/../includes/layout_rodape.php';
