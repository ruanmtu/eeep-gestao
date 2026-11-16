<?php
/* Assistente de IA (Sofia): chave do Google, cotas e relatório de uso */
require __DIR__ . '/../includes/app.php';
exigir('ia.config');

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    switch ($acao) {
            case 'ia_salvar':
                                $ch = ia_limpar_chave((string)($_POST['ia_chave'] ?? ''));
                if (!empty($_POST['ia_apagar'])) config_gravar('ia_chave', null);
                elseif ($ch !== '') {
                    if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $ch)) { flash('warning', 'A chave colada tem caracteres que não fazem parte de uma chave (' . mb_strlen($ch) . ' caracteres). No Google AI Studio, clique no ícone de copiar ao lado da chave e cole de novo, sem nada antes ou depois.'); break; }
                    config_gravar('ia_chave', $ch);
                }
                config_gravar('ia_modelo', preg_replace('/[^a-z0-9.\-]/', '', (string)($_POST['ia_modelo'] ?? '')) ?: null);
                config_gravar('ia_cota', (string)max(1, min(200, (int)($_POST['ia_cota'] ?? 15))));
                config_gravar('ia_cota_escola', (string)max(10, min(5000, (int)($_POST['ia_cota_escola'] ?? 400))));
                registrar_log('ia_config', 'Configuração do assistente de IA alterada');
                flash('success', 'Assistente de IA configurado.');
                break;
            case 'ia_testar':
                $r = ia_perguntar('Responda só com: "Assistente funcionando." ');
                flash($r['ok'] && $r['situacao'] === 'ok' ? 'success' : 'warning', $r['ok'] ? 'Teste: ' . mb_strimwidth($r['texto'], 0, 160, '…') : $r['texto']);
                break;
    }
    redirecionar('assistente_ia.php');
}

$iacfg = ia_config();
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
$ini = $mes . '-01 00:00:00'; $fim = date('Y-m-d H:i:s', strtotime($mes . '-01 +1 month'));
$hoje = date('Y-m-d') . ' 00:00:00';
$uso = []; $por_dia = []; $tot = ['ok' => 0, 'rec' => 0, 'bloq' => 0, 'err' => 0, 'n' => 0];
try {
    $uso = q_todos("SELECT u.id, u.nome, u.foto, u.perfil, u.cargo,
                           COUNT(*) AS n, SUM(i.situacao = 'ok') AS ok, SUM(i.situacao = 'recusada') AS rec, SUM(i.situacao = 'bloqueada') AS bloq,
                           SUM(i.situacao IN ('erro', 'sem_chave', 'cota')) AS err, SUM(i.criado_em >= ? AND i.situacao IN ('ok', 'recusada', 'erro')) AS hoje, MAX(i.criado_em) AS ultimo
                      FROM ia_uso i JOIN usuarios u ON u.id = i.usuario_id
                     WHERE i.criado_em >= ? AND i.criado_em < ? GROUP BY u.id, u.nome, u.foto, u.perfil, u.cargo ORDER BY n DESC", [$hoje, $ini, $fim]);
    foreach (q_todos("SELECT substr(criado_em, 1, 10) AS d, COUNT(*) AS n FROM ia_uso WHERE criado_em >= ? AND criado_em < ? AND situacao IN ('ok', 'recusada', 'erro') GROUP BY substr(criado_em, 1, 10)", [$ini, $fim]) as $l) $por_dia[$l['d']] = (int)$l['n'];
} catch (PDOException $e) {}
foreach ($uso as $l) { $tot['ok'] += $l['ok']; $tot['rec'] += $l['rec']; $tot['bloq'] += $l['bloq']; $tot['err'] += $l['err']; $tot['n'] += $l['n']; }
$escola_hoje = ia_uso_hoje()[1];
$pct_hoje = min(100, round($escola_hoje / max(1, $iacfg['escola']) * 100));
$chave_vis = $iacfg['chave'] ? substr($iacfg['chave'], 0, 6) . str_repeat('•', 10) . substr($iacfg['chave'], -4) : '';
$meses = []; for ($i = 0; $i < 6; $i++) { $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $meses[$m] = MESES[(int)substr($m, 5)] . ' de ' . substr($m, 0, 4); }

$titulo = 'Assistente de IA';
$subtitulo = 'A Sofia, assistente pedagógica da escola: chave do Google, limites de uso e quem está usando.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<section class="ai-capa mb-4">
    <img class="ai-foto" src="<?= RAIZ ?>assets/img/sofia.svg" alt="">
    <div class="min-w-0"><h2>Sofia <span>assistente pedagógica</span></h2>
        <p>Responde só temas de educação. Fora disso: "<?= e(IA_RECUSA) ?>". Bloqueia nome completo, CPF e telefone de aluno antes de enviar ao Google.</p></div>
    <span class="ai-status <?= $iacfg['chave'] ? 'ok' : 'falta' ?>"><i class="bi bi-<?= $iacfg['chave'] ? 'check-circle-fill' : 'key' ?>"></i><?= $iacfg['chave'] ? 'Ligada' : 'Falta a chave' ?></span>
</section>

<div class="ai-numeros mb-4">
    <div><span class="ai-ic verde"><i class="bi bi-chat-dots"></i></span><b><?= $tot['ok'] ?></b><small>perguntas respondidas no mês</small></div>
    <div><span class="ai-ic azul"><i class="bi bi-people"></i></span><b><?= count($uso) ?></b><small>professores e gestores usaram</small></div>
    <div><span class="ai-ic ambar"><i class="bi bi-sign-turn-right"></i></span><b><?= $tot['rec'] ?></b><small>perguntas fora do tema (recusadas)</small></div>
    <div><span class="ai-ic vermelho"><i class="bi bi-shield-lock"></i></span><b><?= $tot['bloq'] ?></b><small>bloqueadas por ter dado de aluno</small></div>
    <div class="ai-hoje"><small>Uso da escola hoje</small><b><?= $escola_hoje ?> <span>de <?= $iacfg['escola'] ?></span></b>
        <div class="pi-barra"><span style="width:<?= $pct_hoje ?>%"></span></div><small><?= max(0, $iacfg['escola'] - $escola_hoje) ?> perguntas ainda disponíveis hoje</small></div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-bar-chart-line me-1"></i>Uso por professor(a)</h2>
                <form class="acoes" method="get"><select class="form-select form-select-sm" name="mes" onchange="this.form.submit()" aria-label="Mês">
                    <?php foreach ($meses as $k => $v): ?><option value="<?= $k ?>"<?= $k === $mes ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></form></div>
            <?php if ($uso): ?>
            <div class="table-responsive"><table class="table ai-tabela">
                <thead><tr><th>Professor(a)</th><th class="num">Respondidas</th><th class="num">Fora do tema</th><th class="num">Bloqueadas</th><th class="num">Sem resposta</th><th class="num">Total no mês</th><th>Hoje</th><th>Último uso</th></tr></thead>
                <tbody><?php foreach ($uso as $l): $ph = min(100, round($l['hoje'] / max(1, $iacfg['cota']) * 100)); ?>
                    <tr><td><div class="ai-pessoa"><?= avatar($l, 'usuario', 'avatar-sm') ?><div><strong><?= e(primeiro_ultimo($l['nome'])) ?></strong><small><?= e(cargo_usuario($l)) ?></small></div></div></td>
                        <td class="num"><b class="txt-verde"><?= (int)$l['ok'] ?></b></td>
                        <td class="num"><?= (int)$l['rec'] ? '<span class="etiqueta ambar">' . (int)$l['rec'] . '</span>' : '0' ?></td>
                        <td class="num"><?= (int)$l['bloq'] ? '<span class="etiqueta vermelho">' . (int)$l['bloq'] . '</span>' : '0' ?></td>
                        <td class="num text-secondary"><?= (int)$l['err'] ?></td>
                        <td class="num"><b><?= (int)$l['n'] ?></b></td>
                        <td><div class="ai-mini"><span><?= (int)$l['hoje'] ?>/<?= $iacfg['cota'] ?></span><i><b style="width:<?= $ph ?>%"></b></i></div></td>
                        <td class="small text-secondary"><?= quando($l['ultimo']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <div class="ai-legenda">
                <span><b>Respondidas</b> perguntas de educação que a Sofia respondeu.</span>
                <span><b>Fora do tema</b> pedidos que não eram de educação: ela respondeu "<?= e(IA_RECUSA) ?>"</span>
                <span><b>Bloqueadas</b> perguntas com nome completo, CPF ou telefone de aluno: nem saíram da escola.</span>
                <span><b>Sem resposta</b> falha de internet, chave ausente ou cota acabada.</span>
                <span><b>Hoje</b> perguntas usadas hoje / limite por pessoa.</span>
            </div>
            <?php else: ?><div class="vazio"><i class="bi bi-stars"></i><strong>Ninguém usou a Sofia em <?= e($meses[$mes] ?? $mes) ?></strong>Quando os professores perguntarem, o uso aparece aqui por pessoa.</div><?php endif; ?>
        </div>
        <?php if ($por_dia): $mx = max($por_dia); ?>
        <div class="card mt-3"><div class="card-header"><h2><i class="bi bi-calendar3 me-1"></i>Perguntas por dia</h2></div>
            <div class="ai-dias"><?php for ($d = 1; $d <= (int)date('t', strtotime($mes . '-01')); $d++): $k = sprintf('%s-%02d', $mes, $d); $v = $por_dia[$k] ?? 0; ?>
                <span title="<?= $d ?>/<?= substr($mes, 5) ?>: <?= $v ?> pergunta(s)"><i style="height:<?= $v ? max(6, round($v / $mx * 100)) : 0 ?>%"></i><small><?= $d ?></small></span><?php endfor; ?></div></div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card"><div class="card-header"><h2><i class="bi bi-key me-1"></i>Chave do Google Gemini</h2></div><div class="card-body">
            <?php if (defined('IA_CHAVE') && IA_CHAVE): ?><div class="alert alert-success small">A chave está definida em <code>includes/config.php</code> (IA_CHAVE). Para trocar, edite o arquivo.</div><?php endif; ?>
            <form method="post" class="row g-3"><?= csrf_campo() ?><input type="hidden" name="acao" value="ia_salvar">
                <div class="col-12"><label class="form-label" for="iaChave">Chave da API</label>
                    <input class="form-control" id="iaChave" name="ia_chave" type="password" autocomplete="off" placeholder="<?= $chave_vis ? e($chave_vis) . ' (vazio = manter)' : 'AIza...' ?>">
                    <div class="form-text">No Google AI Studio › Chaves de API, clique no ícone de copiar e cole aqui. Os professores nunca veem a chave.</div></div>
                <div class="col-12"><label class="form-label" for="iaModelo">Modelo</label><input class="form-control" id="iaModelo" name="ia_modelo" value="<?= e($iacfg['modelo']) ?>">
                    <div class="form-text">Padrão: gemini-flash-lite-latest (gratuito e rápido).</div></div>
                <div class="col-6"><label class="form-label" for="iaCota">Limite por pessoa/dia</label><input class="form-control" type="number" id="iaCota" name="ia_cota" min="1" max="200" value="<?= $iacfg['cota'] ?>"></div>
                <div class="col-6"><label class="form-label" for="iaEsc">Limite da escola/dia</label><input class="form-control" type="number" id="iaEsc" name="ia_cota_escola" min="10" max="5000" value="<?= $iacfg['escola'] ?>"></div>
                <div class="col-12"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2 me-1"></i>Salvar</button></div>
            </form>
            <?php if ($iacfg['chave']): ?><div class="d-flex gap-2 flex-wrap mt-3 pt-3 border-top">
                <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ia_testar">
                    <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-plug me-1"></i>Testar conexão</button></form>
                <?php if (!(defined('IA_CHAVE') && IA_CHAVE)): ?><form method="post" data-confirmar="Remover a chave? A Sofia para de responder." data-botao="Remover"><?= csrf_campo() ?><input type="hidden" name="acao" value="ia_salvar"><input type="hidden" name="ia_apagar" value="1">
                    <input type="hidden" name="ia_modelo" value="<?= e($iacfg['modelo']) ?>"><input type="hidden" name="ia_cota" value="<?= $iacfg['cota'] ?>"><input type="hidden" name="ia_cota_escola" value="<?= $iacfg['escola'] ?>">
                    <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-trash me-1"></i>Remover chave</button></form><?php endif; ?>
            </div><?php endif; ?>
        </div></div>
        <div class="card mt-3"><div class="card-body small">
            <h3 class="h6"><i class="bi bi-info-circle me-1"></i>Como funciona o limite</h3>
            Cada pessoa pode fazer <b><?= $iacfg['cota'] ?></b> perguntas por dia. Se acabar, ela ainda usa a reserva da escola (até o dobro) enquanto a escola tiver usado menos de 75% de <b><?= $iacfg['escola'] ?></b>. Perguntas bloqueadas não contam.
        </div></div>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_rodape.php';
