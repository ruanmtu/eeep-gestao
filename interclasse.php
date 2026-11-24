<?php
/* v77 · Página pública do interclasse: próximos jogos, placares, classificação, chaveamento,
 * artilharia, cartões, equipes (relação dos jogadores), dados dos jogadores e súmulas. */
require __DIR__ . '/includes/app.php';
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$organiza = pode('interclasse');
if (!ic_no_site() && !$organiza) { http_response_code(404); }

$mods = ic_modalidades(true);
$jogo = isset($_GET['jogo']) ? ic_jogo((int)$_GET['jogo']) : null;
$equipe = isset($_GET['equipe']) ? ic_equipe((int)$_GET['equipe']) : null;
$jogador = isset($_GET['jogador']) ? q_um('SELECT j.*, e.nome AS equipe, e.cor, e.modalidade_id, m.nome AS modalidade FROM ic_jogadores j JOIN ic_equipes e ON e.id = j.equipe_id JOIN ic_modalidades m ON m.id = e.modalidade_id WHERE j.id = ?', [(int)$_GET['jogador']]) : null;
$m_id = (int)($_GET['m'] ?? ($jogo['modalidade_id'] ?? ($equipe['modalidade_id'] ?? ($jogador['modalidade_id'] ?? ($mods[0]['id'] ?? 0)))));
$mod = null; foreach ($mods as $m) if ((int)$m['id'] === $m_id) $mod = $m;
if (!$mod && $mods) $mod = $mods[0];
$m_id = (int)($mod['id'] ?? 0);

$sigla = fn($n) => mb_strtoupper(mb_substr(preg_replace('/^\d+º\s*/u', '', (string)$n) ?: (string)$n, 0, 3));
$escudo = fn($nome, $cor, $cls = '') => '<span class="ix-escudo ' . $cls . '" style="--c:' . h($cor ?: '#13692a') . '">' . h(ic_iniciais((string)$nome)) . '</span>';
$placar = function ($g, $l) { return $g['placar_' . $l] === null ? '' : (int)$g['placar_' . $l]; };
$cartao_jogo = function ($g) use ($escudo, $placar) {
    $v = ic_vencedor($g);
    ob_start(); ?>
    <a class="ix-jogo st-<?= h($g['status']) ?>" href="interclasse.php?jogo=<?= (int)$g['id'] ?>">
        <span class="ix-jogo-cab"><span><?= h($g['fase'] === 'grupos' ? ($g['grupo'] ? 'Grupo ' . $g['grupo'] : 'Fase de grupos') : (IC_FASES[$g['fase']] ?? '')) ?></span>
            <?php if ($g['status'] === 'andamento'): ?><em class="ix-vivo"><i></i>Ao vivo</em><?php elseif (in_array($g['status'], ['encerrado', 'wo'], true)): ?><em><?= $g['status'] === 'wo' ? 'W.O.' : 'Fim de jogo' ?></em><?php elseif ($g['status'] === 'adiado'): ?><em class="ix-adiado">Adiado</em><?php else: ?><em><?= h(ic_quando($g['data_hora'])) ?></em><?php endif; ?></span>
        <?php foreach (['a', 'b'] as $l): ?>
        <span class="ix-lado<?= $v === $l ? ' venceu' : ($v && $v !== 'empate' ? ' perdeu' : '') ?>"><?= $escudo(ic_lado($g, $l), $g['cor_' . $l]) ?><b><?= h(ic_lado($g, $l)) ?></b><strong><?= $placar($g, $l) ?><?php if ($g['penaltis_' . $l] !== null): ?><small>(<?= (int)$g['penaltis_' . $l] ?>)</small><?php endif; ?></strong></span>
        <?php endforeach; ?>
        <span class="ix-jogo-pe"><?= $g['local'] ? '<i class="bi bi-geo-alt"></i>' . h($g['local']) : '' ?><?php if (in_array($g['status'], ['encerrado', 'wo', 'andamento'], true)): ?><span class="ms-auto">Súmula <i class="bi bi-arrow-right"></i></span><?php endif; ?></span>
    </a>
    <?php return ob_get_clean();
};

$pagina_titulo = ic_titulo() . ' · ' . $ESCOLA['nome'];
$pagina_classe = 'pagina-interna inicio-galeria';
$prefixo = 'index.php';
$cabeca_extra = '<link href="assets/css/inicio.css?v=26" rel="stylesheet"><link href="assets/css/interclasse.css?v=2" rel="stylesheet">';
require __DIR__ . '/includes/site_topo.php';
?>
<main id="conteudo" class="sg-pagina ix-pagina">
<section class="ix-hero">
    <div class="largura">
        <nav class="sg-migalha"><a href="<?= $jogo || $equipe || $jogador ? 'interclasse.php?m=' . $m_id : 'index.php' ?>"><i class="bi bi-arrow-left"></i><?= $jogo || $equipe || $jogador ? ic_titulo() : 'Início' ?></a></nav>
        <?php if (!ic_no_site() && $organiza): ?><div class="ix-previa"><i class="bi bi-eye"></i>Prévia: o interclasse ainda não está visível para o público. Ligue em Painel › Interclasse › Modalidades e configuração.</div><?php endif; ?>
        <span class="sg-sobre">Esporte na escola</span>
        <h1><?= h(ic_titulo()) ?></h1>
        <p><?= h(ic_subtitulo()) ?></p>
        <?php if (count($mods) > 1 && !$jogo && !$jogador): ?>
        <nav class="ix-mods" aria-label="Modalidades"><?php foreach ($mods as $m): ?><a href="interclasse.php?m=<?= (int)$m['id'] ?>"<?= (int)$m['id'] === $m_id ? ' class="ativo" aria-current="page"' : '' ?>><i class="bi bi-<?= h($m['icone'] ?: 'trophy') ?>"></i><?= h($m['nome']) ?></a><?php endforeach; ?></nav>
        <?php endif; ?>
    </div>
</section>

<div class="largura ix-corpo">
<?php if (!ic_no_site() && !$organiza): ?>
    <div class="sg-vazio"><i class="bi bi-trophy"></i>O interclasse ainda não foi publicado. Volte em breve!</div>

<?php elseif (!$mods): ?>
    <div class="sg-vazio"><i class="bi bi-trophy"></i>Os jogos do interclasse aparecem aqui assim que forem cadastrados.</div>

<?php elseif ($jogo): /* ================= SÚMULA DO JOGO ================= */
    $ev = ic_eventos((int)$jogo['id']);
    $v = ic_vencedor($jogo);
    $de = fn($l) => array_values(array_filter($ev, fn($e) => (int)$e['equipe_id'] === (int)$jogo['equipe_' . $l]));
    $ponto = $jogo['tipo_placar'] === 'gols' ? 'Gol' : 'Ponto'; ?>
    <article class="ix-sumula">
        <header class="ix-placar st-<?= h($jogo['status']) ?>">
            <div class="ix-placar-info"><span><i class="bi bi-<?= h($jogo['icone'] ?: 'trophy') ?>"></i><?= h($jogo['modalidade']) ?> · <?= h(IC_FASES[$jogo['fase']] ?? '') ?><?= $jogo['grupo'] ? ' · Grupo ' . h($jogo['grupo']) : '' ?></span>
                <span><i class="bi bi-calendar-event"></i><?= h(ic_quando($jogo['data_hora'])) ?><?= $jogo['local'] ? ' · ' . h($jogo['local']) : '' ?></span></div>
            <div class="ix-placar-l">
                <?php foreach (['a', 'b'] as $i => $l): ?>
                <?php if ($i === 1): ?><div class="ix-placar-num"><strong><?= $jogo['placar_a'] === null ? '–' : (int)$jogo['placar_a'] ?></strong><span>×</span><strong><?= $jogo['placar_b'] === null ? '–' : (int)$jogo['placar_b'] ?></strong>
                    <?php if ($jogo['penaltis_a'] !== null): ?><small>Pênaltis <?= (int)$jogo['penaltis_a'] ?> × <?= (int)$jogo['penaltis_b'] ?></small><?php endif; ?>
                    <em class="ix-st <?= h($jogo['status']) ?>"><?= $jogo['status'] === 'andamento' ? '<i></i>' : '' ?><?= h(IC_STATUS[$jogo['status']][0]) ?></em></div><?php endif; ?>
                <a class="ix-placar-eq<?= $v === $l ? ' venceu' : '' ?>" href="<?= $jogo['equipe_' . $l] ? 'interclasse.php?equipe=' . (int)$jogo['equipe_' . $l] : '#' ?>"><?= $escudo(ic_lado($jogo, $l), $jogo['cor_' . $l], 'grande') ?><b><?= h(ic_lado($jogo, $l)) ?></b><?= $v === $l ? '<small><i class="bi bi-trophy-fill"></i> Vencedor</small>' : '' ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <section class="ix-bloco">
            <h2><i class="bi bi-journal-text"></i>Súmula</h2>
            <?php if (!$ev): ?><p class="ix-nada">Nenhum <?= mb_strtolower($ponto) ?> ou cartão registrado neste jogo.</p>
            <?php else: ?>
            <div class="ix-linha-tempo">
                <?php foreach ($ev as $e): $l = (int)$e['equipe_id'] === (int)$jogo['equipe_a'] ? 'a' : 'b'; ?>
                <div class="ix-lt lado-<?= $l ?>"><span class="ix-lt-min"><?= h($e['minuto'] ?: '') ?></span>
                    <span class="ix-lt-c"><i class="ix-ic t-<?= h($e['tipo']) ?>"></i><span><b><?= $e['jogador'] ? '<a href="interclasse.php?jogador=' . (int)$e['jogador_id'] . '">' . h($e['jogador']) . '</a>' : h($e['equipe']) ?></b>
                    <small><?= h($e['tipo'] === 'gol' ? $ponto : IC_EVENTOS[$e['tipo']][0]) ?><?= $e['numero'] ? ' · nº ' . h($e['numero']) : '' ?> · <?= h($e['equipe']) ?></small></span></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <dl class="ix-dados">
                <?php if ($jogo['arbitro']): ?><div><dt>Árbitro</dt><dd><?= h($jogo['arbitro']) ?></dd></div><?php endif; ?>
                <?php if ($jogo['mesario']): ?><div><dt>Mesário</dt><dd><?= h($jogo['mesario']) ?></dd></div><?php endif; ?>
                <?php foreach (['a', 'b'] as $l): $c = $de($l); ?>
                <div><dt><?= h(ic_lado($jogo, $l)) ?></dt><dd><?= count(array_filter($c, fn($e) => $e['tipo'] === 'amarelo')) ?> amarelo(s) · <?= count(array_filter($c, fn($e) => $e['tipo'] === 'vermelho')) ?> vermelho(s)</dd></div>
                <?php endforeach; ?>
            </dl>
            <?php if ($jogo['observacoes']): ?><div class="ix-obs"><b>Observações</b><p><?= nl2br(h($jogo['observacoes'])) ?></p></div><?php endif; ?>
            <?php if ($jogo['sumula_arquivo'] && ($jogo['sumula_publica'] || $organiza)): ?><a class="ix-btn" href="interclasse_sumula.php?jogo=<?= (int)$jogo['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i>Ver a súmula em papel</a><?php endif; ?>
        </section>

        <?php if ($jogo['equipe_a'] && $jogo['equipe_b']): ?>
        <section class="ix-bloco">
            <h2><i class="bi bi-people"></i>Escalações</h2>
            <div class="ix-escalacoes">
                <?php foreach (['a', 'b'] as $l): ?>
                <div><h3><?= $escudo(ic_lado($jogo, $l), $jogo['cor_' . $l]) ?><?= h(ic_lado($jogo, $l)) ?></h3>
                    <ul class="ix-jogadores"><?php foreach (ic_jogadores((int)$jogo['equipe_' . $l]) as $j): ?><li><a href="interclasse.php?jogador=<?= (int)$j['id'] ?>"><span class="ix-num"><?= h($j['numero'] ?: '–') ?></span><b><?= h($j['nome']) ?><?= $j['capitao'] ? ' <em title="Capitão">C</em>' : '' ?></b><small><?= h($j['posicao'] ?: '') ?></small></a></li><?php endforeach; ?></ul></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </article>

<?php elseif ($jogador): /* ================= DADOS DO JOGADOR ================= */
    $st = ['gol' => 0, 'amarelo' => 0, 'vermelho' => 0];
    foreach (q_todos('SELECT tipo, COUNT(*) AS n FROM ic_eventos WHERE jogador_id = ? GROUP BY tipo', [$jogador['id']]) as $r) $st[$r['tipo']] = (int)$r['n'];
    $jogos_eq = ic_jogos(['equipe' => $jogador['equipe_id'], 'status' => ['encerrado', 'wo']]);
    $sus = ic_suspensos((int)$jogador['modalidade_id'])[(int)$jogador['id']] ?? null; ?>
    <article class="ix-atleta" style="--c:<?= h($jogador['cor'] ?: '#13692a') ?>">
        <div class="ix-atleta-cab"><span class="ix-atleta-num"><?= h($jogador['numero'] ?: '–') ?></span>
            <div><small><?= h($jogador['modalidade']) ?></small><h2><?= h($jogador['nome']) ?><?= $jogador['capitao'] ? ' <em class="ix-cap">Capitão</em>' : '' ?></h2>
            <a href="interclasse.php?equipe=<?= (int)$jogador['equipe_id'] ?>"><?= h($jogador['equipe']) ?></a><?= $jogador['posicao'] ? ' · ' . h($jogador['posicao']) : '' ?></div></div>
        <?php if ($sus): ?><div class="ix-suspenso"><i class="bi bi-slash-circle"></i>Suspenso no próximo jogo (<?= h($sus) ?>)</div><?php endif; ?>
        <div class="ix-atleta-nums">
            <div><b><?= count($jogos_eq) ?></b><small>jogos da equipe</small></div>
            <div><b><?= $st['gol'] ?></b><small><?= ($mod['placar'] ?? 'gols') === 'gols' ? 'gols' : 'pontos' ?></small></div>
            <div class="am"><b><?= $st['amarelo'] ?></b><small>amarelos</small></div>
            <div class="vm"><b><?= $st['vermelho'] ?></b><small>vermelhos</small></div>
        </div>
    </article>

<?php elseif ($equipe): /* ================= EQUIPE ================= */
    $js = ic_jogadores((int)$equipe['id']);
    $est = []; foreach (ic_estatisticas((int)$equipe['modalidade_id']) as $r) $est[(int)$r['id']] = $r;
    $sus = ic_suspensos((int)$equipe['modalidade_id']);
    $jogos_eq = ic_jogos(['equipe' => $equipe['id']]); ?>
    <div class="ix-equipe-cab" style="--c:<?= h($equipe['cor'] ?: '#13692a') ?>"><?= $escudo($equipe['nome'], $equipe['cor'], 'grande') ?><div><small><?= h($equipe['modalidade']) ?><?= $equipe['grupo'] ? ' · Grupo ' . h($equipe['grupo']) : '' ?></small><h2><?= h($equipe['nome']) ?></h2><span><?= count($js) ?> jogador(es)</span></div></div>
    <div class="ix-duas">
        <section class="ix-bloco">
            <h2><i class="bi bi-person-lines-fill"></i>Relação dos jogadores</h2>
            <?php if (!$js): ?><p class="ix-nada">Relação ainda não publicada.</p><?php else: ?>
            <div class="ix-tabela"><table><thead><tr><th>Nº</th><th>Jogador</th><th>Posição</th><th title="<?= $equipe['placar'] === 'gols' ? 'Gols' : 'Pontos' ?>"><?= $equipe['placar'] === 'gols' ? 'G' : 'P' ?></th><th title="Cartões amarelos"><i class="ix-ic t-amarelo"></i></th><th title="Cartões vermelhos"><i class="ix-ic t-vermelho"></i></th></tr></thead>
                <tbody><?php foreach ($js as $j): $s = $est[(int)$j['id']] ?? []; ?><tr>
                    <td class="ix-num-c"><?= h($j['numero'] ?: '–') ?></td>
                    <td><a href="interclasse.php?jogador=<?= (int)$j['id'] ?>"><?= h($j['nome']) ?></a><?= $j['capitao'] ? ' <em class="ix-cap" title="Capitão">C</em>' : '' ?><?= isset($sus[(int)$j['id']]) ? ' <em class="ix-sus" title="Suspenso no próximo jogo: ' . h($sus[(int)$j['id']]) . '">suspenso</em>' : '' ?></td>
                    <td><?= h($j['posicao'] ?: '') ?></td><td><?= (int)($s['gols'] ?? 0) ?></td><td><?= (int)($s['amarelos'] ?? 0) ?></td><td><?= (int)($s['vermelhos'] ?? 0) ?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </section>
        <section class="ix-bloco">
            <h2><i class="bi bi-calendar2-week"></i>Jogos</h2>
            <?php if (!$jogos_eq): ?><p class="ix-nada">Nenhum jogo marcado.</p><?php endif; ?>
            <div class="ix-jogos uma"><?php foreach ($jogos_eq as $g) echo $cartao_jogo($g); ?></div>
        </section>
    </div>

<?php else: /* ================= MODALIDADE ================= */
    $todos = ic_jogos(['modalidade' => $m_id]);
    $vivo = array_values(array_filter($todos, fn($g) => $g['status'] === 'andamento'));
    $prox = array_values(array_filter($todos, fn($g) => in_array($g['status'], ['agendado', 'adiado'], true)));
    $res = array_reverse(array_values(array_filter($todos, fn($g) => in_array($g['status'], ['encerrado', 'wo'], true))));
    $classif = ic_classificacao($m_id);
    $tem_grupos = (bool)array_filter($todos, fn($g) => $g['fase'] === 'grupos');
    $chave = ic_chave($m_id);
    $est = ic_estatisticas($m_id);
    $art = array_values(array_filter($est, fn($r) => $r['gols'] > 0)); usort($art, fn($a, $b) => [$b['gols'], $a['nome']] <=> [$a['gols'], $b['nome']]);
    $cart = array_values(array_filter($est, fn($r) => $r['amarelos'] + $r['vermelhos'] > 0)); usort($cart, fn($a, $b) => [$b['vermelhos'], $b['amarelos'], $a['nome']] <=> [$a['vermelhos'], $a['amarelos'], $b['nome']]);
    $sus = ic_suspensos($m_id);
    $equipes = ic_equipes($m_id);
    $n_gols = array_sum(array_map(fn($g) => (int)$g['placar_a'] + (int)$g['placar_b'], $res));
    $ponto = ($mod['placar'] ?? 'gols') === 'gols' ? 'gols' : 'pontos';
    $secoes = array_filter(['jogos' => 'Jogos', 'classificacao' => $classif && $tem_grupos ? 'Classificação' : '', 'chave' => $chave ? 'Chaveamento' : '', 'artilharia' => $art ? ($ponto === 'gols' ? 'Artilharia' : 'Pontuadores') : '', 'cartoes' => 'Cartões', 'equipes' => 'Equipes']); ?>

    <div class="ix-resumo">
        <div><b><?= count($equipes) ?></b><small>equipes</small></div>
        <div><b><?= count($res) ?><span>/<?= count($todos) ?></span></b><small>jogos realizados</small></div>
        <div><b><?= $n_gols ?></b><small><?= $ponto ?></small></div>
        <div><b><?= array_sum(array_column($est, 'amarelos')) + array_sum(array_column($est, 'vermelhos')) ?></b><small>cartões</small></div>
    </div>
    <nav class="ix-secoes" aria-label="Seções"><?php foreach ($secoes as $k => $v): ?><a href="#<?= $k ?>"><?= h($v) ?></a><?php endforeach; ?></nav>

    <section class="ix-bloco" id="jogos">
        <?php if ($vivo): ?><h2 class="ix-h-vivo"><span class="ix-vivo"><i></i>Ao vivo</span></h2><div class="ix-jogos"><?php foreach ($vivo as $g) echo $cartao_jogo($g); ?></div><?php endif; ?>
        <h2><i class="bi bi-calendar-event"></i>Próximos jogos</h2>
        <?php if (!$prox): ?><p class="ix-nada">Nenhum jogo marcado no momento.</p><?php else: ?><div class="ix-jogos"><?php foreach (array_slice($prox, 0, 12) as $g) echo $cartao_jogo($g); ?></div><?php endif; ?>
        <?php if ($res): ?><h2 class="mt"><i class="bi bi-check2-circle"></i>Resultados</h2><div class="ix-jogos"><?php foreach ($res as $g) echo $cartao_jogo($g); ?></div><?php endif; ?>
    </section>

    <?php if (isset($secoes['classificacao'])): ?>
    <section class="ix-bloco" id="classificacao">
        <h2><i class="bi bi-list-ol"></i>Classificação</h2>
        <div class="ix-grupos">
        <?php foreach ($classif as $gr => $linhas): ?>
            <div class="ix-tabela"><?php if (count($classif) > 1 || $gr !== 'Único'): ?><h3>Grupo <?= h($gr) ?></h3><?php endif; ?>
            <table class="ix-classif"><thead><tr><th>#</th><th class="eq">Equipe</th><th title="Pontos">P</th><th title="Jogos">J</th><th title="Vitórias">V</th><th title="Empates">E</th><th title="Derrotas">D</th><th title="Saldo">SG</th><th class="fm">Últimos</th></tr></thead>
            <tbody><?php foreach ($linhas as $i => $r): ?><tr>
                <td class="pos"><?= $i + 1 ?></td>
                <td class="eq"><a href="interclasse.php?equipe=<?= $r['id'] ?>"><?= $escudo($r['nome'], $r['cor'], 'mini') ?><?= h($r['nome']) ?></a></td>
                <td><b><?= $r['pts'] ?></b></td><td><?= $r['j'] ?></td><td><?= $r['v'] ?></td><td><?= $r['e'] ?></td><td><?= $r['d'] ?></td><td><?= $r['gp'] - $r['gc'] > 0 ? '+' : '' ?><?= $r['gp'] - $r['gc'] ?></td>
                <td class="fm"><?php foreach ($r['forma'] as $f): ?><i class="ix-f <?= $f ?>" title="<?= ['v' => 'Vitória', 'e' => 'Empate', 'd' => 'Derrota'][$f] ?>"></i><?php endforeach; ?></td>
            </tr><?php endforeach; ?></tbody></table></div>
        <?php endforeach; ?>
        </div>
        <p class="ix-nota">Critérios: pontos (vitória <?= (int)$mod['pts_vitoria'] ?>, empate <?= (int)$mod['pts_empate'] ?>), vitórias, saldo, <?= $ponto ?> marcados e menos cartões.</p>
    </section>
    <?php endif; ?>

    <?php if ($chave): ?>
    <section class="ix-bloco" id="chave">
        <h2><i class="bi bi-diagram-3"></i>Chaveamento</h2>
        <div class="ix-chave">
            <?php foreach ($chave as $fase => $jogos): ?>
            <div class="ix-chave-col<?= $fase === 'final' ? ' final' : '' ?>"><h3><?= h(IC_FASES[$fase]) ?></h3>
                <div class="ix-chave-jogos"><?php foreach ($jogos as $g): $v = ic_vencedor($g); ?>
                    <a class="ix-cj st-<?= h($g['status']) ?>" href="interclasse.php?jogo=<?= (int)$g['id'] ?>">
                        <?php foreach (['a', 'b'] as $l): ?><span class="<?= $v === $l ? 'venceu' : ($v && $v !== 'empate' ? 'perdeu' : '') ?><?= !$g['equipe_' . $l] ? ' indef' : '' ?>"><?= $escudo(ic_lado($g, $l), $g['cor_' . $l], 'mini') ?><b><?= h(ic_lado($g, $l)) ?></b><strong><?= $placar($g, $l) ?><?= $g['penaltis_' . $l] !== null ? '<small>(' . (int)$g['penaltis_' . $l] . ')</small>' : '' ?></strong></span><?php endforeach; ?>
                        <small class="ix-cj-q"><?= $g['status'] === 'andamento' ? '<em class="ix-vivo"><i></i>Ao vivo</em>' : h(in_array($g['status'], ['encerrado', 'wo'], true) ? 'Encerrado' : ic_quando($g['data_hora'])) ?></small>
                    </a>
                <?php endforeach; ?></div>
                <?php if ($fase === 'final' && ($fv = $jogos[0] ?? null) && ($w = ic_vencedor($fv)) && $w !== 'empate'): ?><div class="ix-campeao"><i class="bi bi-trophy-fill"></i><small>Campeão</small><b><?= h(ic_lado($fv, $w)) ?></b></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="ix-duas">
        <?php if ($art): ?>
        <section class="ix-bloco" id="artilharia">
            <h2><i class="bi bi-bullseye"></i><?= $ponto === 'gols' ? 'Artilharia' : 'Pontuadores' ?></h2>
            <ol class="ix-rank"><?php foreach (array_slice($art, 0, 10) as $i => $r): ?><li><span class="p"><?= $i + 1 ?>º</span><a href="interclasse.php?jogador=<?= (int)$r['id'] ?>"><b><?= h($r['nome']) ?></b><small><?= h($r['equipe']) ?></small></a><strong><?= (int)$r['gols'] ?></strong></li><?php endforeach; ?></ol>
        </section>
        <?php endif; ?>
        <section class="ix-bloco" id="cartoes">
            <h2><i class="bi bi-file-earmark-break"></i>Cartões</h2>
            <?php if ($sus): ?><div class="ix-suspensos"><b><i class="bi bi-slash-circle"></i> Suspensos no próximo jogo</b><?php foreach ($est as $r) if (isset($sus[(int)$r['id']])): ?><a href="interclasse.php?jogador=<?= (int)$r['id'] ?>"><?= h($r['nome']) ?> <small>· <?= h($r['equipe']) ?> · <?= h($sus[(int)$r['id']]) ?></small></a><?php endif; ?></div><?php endif; ?>
            <?php if (!$cart): ?><p class="ix-nada">Nenhum cartão até agora. Jogo limpo!</p><?php else: ?>
            <div class="ix-tabela"><table><thead><tr><th>Jogador</th><th>Equipe</th><th title="Amarelos"><i class="ix-ic t-amarelo"></i></th><th title="Vermelhos"><i class="ix-ic t-vermelho"></i></th></tr></thead>
                <tbody><?php foreach ($cart as $r): ?><tr><td><a href="interclasse.php?jogador=<?= (int)$r['id'] ?>"><?= h($r['nome']) ?></a></td><td><?= h($r['equipe']) ?></td><td><?= (int)$r['amarelos'] ?></td><td><?= (int)$r['vermelhos'] ?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </section>
    </div>

    <section class="ix-bloco" id="equipes">
        <h2><i class="bi bi-people"></i>Equipes</h2>
        <?php if (!$equipes): ?><p class="ix-nada">As equipes aparecem aqui assim que forem inscritas.</p><?php endif; ?>
        <div class="ix-equipes"><?php foreach ($equipes as $e): ?>
            <a href="interclasse.php?equipe=<?= (int)$e['id'] ?>" style="--c:<?= h($e['cor'] ?: '#13692a') ?>"><?= $escudo($e['nome'], $e['cor']) ?><span><b><?= h($e['nome']) ?></b><small><?= $e['grupo'] ? 'Grupo ' . h($e['grupo']) . ' · ' : '' ?><?= (int)$e['n_jogadores'] ?> jogadores</small></span><i class="bi bi-chevron-right"></i></a>
        <?php endforeach; ?></div>
    </section>
<?php endif; ?>
</div>
</main>
<?php require __DIR__ . '/includes/site_rodape.php'; ?>
