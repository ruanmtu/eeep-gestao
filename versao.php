<?php
/* Confere se a atualização foi enviada por completo: compara cada arquivo do sistema com a versão esperada
   e limpa o cache do PHP (OPcache). Não mostra dados da escola. Pode apagar depois de conferir. */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$m = @include __DIR__ . '/includes/versao_manifesto.php';
$limpo = function_exists('opcache_reset') ? @opcache_reset() : null;
$fora = []; $faltam = [];
if (is_array($m)) foreach ($m['arquivos'] as $f => $h) {
    $p = __DIR__ . '/' . $f;
    if (!is_file($p)) $faltam[] = $f; elseif (md5_file($p) !== $h) $fora[] = $f;
}
$ok = is_array($m) && !$fora && !$faltam;
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Versão do sistema</title>
<div style="font:15px/1.5 system-ui,sans-serif;max-width:720px;margin:6vh auto;padding:0 16px">
<h2 style="margin:0 0 6px;color:<?= $ok ? '#13692a' : '#c0261b' ?>"><?= $ok ? '✔ Atualização completa' : '✖ A atualização não foi enviada por completo' ?></h2>
<p style="margin:0 0 14px;color:#555">Versão esperada: <b><?= htmlspecialchars($m['versao'] ?? '?') ?></b> · <?= is_array($m) ? count($m['arquivos']) : 0 ?> arquivos conferidos · cache do PHP: <?= $limpo === null ? 'não disponível' : ($limpo ? 'limpo agora' : 'não foi possível limpar') ?></p>
<?php if (!is_array($m)): ?><p>O arquivo <code>includes/versao_manifesto.php</code> não foi encontrado: a pasta includes não foi atualizada.</p><?php endif; ?>
<?php if ($fora): ?><p><b><?= count($fora) ?> arquivo(s) ainda estão na versão antiga</b> (o servidor não substituiu):</p><ul><?php foreach ($fora as $f): ?><li><code><?= htmlspecialchars($f) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($faltam): ?><p><b><?= count($faltam) ?> arquivo(s) não existem no servidor:</b></p><ul><?php foreach ($faltam as $f): ?><li><code><?= htmlspecialchars($f) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!$ok): ?><p style="background:#fff8e1;border:1px solid #e0c060;padding:10px 14px;border-radius:8px">Ao extrair o zip no Gerenciador de Arquivos da Hostinger, confirme a opção de <b>substituir</b> os arquivos existentes. O conteúdo da pasta <code>public_html</code> do zip vai direto dentro do <code>public_html</code> do servidor (sem criar <code>public_html/public_html</code>).</p><?php endif; ?>
</div>
