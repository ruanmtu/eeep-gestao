<?php
/* v77 · Interclasse: quem organiza alimenta aqui (modalidades, equipes, jogadores, jogos, placar, súmula e cartões).
 * Tudo aparece na página pública interclasse.php do site. */
require __DIR__ . '/../includes/app.php';
exigir('interclasse');

$agora = date('Y-m-d H:i:s');
$int_ou_null = fn($v) => ($v === '' || $v === null) ? null : (int)$v;
$txt = fn($k, $max = 120) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
$volta = fn(string $q = '') => redirecionar('interclasse.php' . ($q !== '' ? '?' . $q : ''));

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        switch ($acao) {
            /* ---------- Configuração ---------- */
            case 'config':
                config_gravar('ic_titulo', mb_substr(trim((string)($_POST['ic_titulo'] ?? '')), 0, 80));
                config_gravar('ic_subtitulo', mb_substr(trim((string)($_POST['ic_subtitulo'] ?? '')), 0, 200));
                config_gravar('ic_ativo', !empty($_POST['ic_ativo']) ? '1' : '0');
                flash('success', 'Configuração do interclasse salva.');
                $volta('aba=modalidades');

            /* ---------- Modalidades ---------- */
            case 'mod_salvar':
                $id = (int)($_POST['id'] ?? 0);
                $nome = $txt('nome', 80);
                if (!$nome) throw new RuntimeException('Dê um nome à modalidade (ex.: Futsal masculino).');
                $placar = array_key_exists($_POST['placar'] ?? '', IC_PLACAR) ? $_POST['placar'] : 'gols';
                $d = [$nome, $txt('icone', 30) ?: 'trophy', $placar, max(0, (int)($_POST['pts_vitoria'] ?? 3)), max(0, (int)($_POST['pts_empate'] ?? 1)), (int)($_POST['ordem'] ?? 0), !empty($_POST['ativo']) ? 1 : 0];
                if ($id) q('UPDATE ic_modalidades SET nome = ?, icone = ?, placar = ?, pts_vitoria = ?, pts_empate = ?, ordem = ?, ativo = ? WHERE id = ?', [...$d, $id]);
                else q('INSERT INTO ic_modalidades (nome, icone, placar, pts_vitoria, pts_empate, ordem, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)', $d);
                flash('success', 'Modalidade salva.');
                $volta('aba=modalidades');
            case 'mod_excluir':
                $id = (int)($_POST['id'] ?? 0);
                if (q_valor('SELECT COUNT(*) FROM ic_equipes WHERE modalidade_id = ?', [$id]) || q_valor('SELECT COUNT(*) FROM ic_jogos WHERE modalidade_id = ?', [$id]))
                    throw new RuntimeException('Esta modalidade já tem equipes ou jogos. Apague-os antes, ou desmarque "Mostrar no site".');
                q('DELETE FROM ic_modalidades WHERE id = ?', [$id]);
                flash('success', 'Modalidade apagada.');
                $volta('aba=modalidades');

            /* ---------- Equipes ---------- */
            case 'eq_salvar':
                $id = (int)($_POST['id'] ?? 0);
                $mod = (int)($_POST['modalidade_id'] ?? 0);
                $turma = $int_ou_null($_POST['turma_id'] ?? '');
                $nome = $txt('nome', 80);
                if (!$nome && $turma) { $t = q_um('SELECT t.*, c.sigla FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?', [$turma]); $nome = $t ? $t['serie'] . 'º ' . $t['letra'] . ' ' . $t['sigla'] : null; }
                if (!$nome || !ic_modalidade($mod)) throw new RuntimeException('Escolha a modalidade e a turma (ou escreva o nome da equipe).');
                $cor = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['cor'] ?? '')) ? $_POST['cor'] : null;
                $d = [$mod, $nome, $turma, $txt('grupo', 10) ? mb_strtoupper($txt('grupo', 10)) : null, $cor];
                if ($id) q('UPDATE ic_equipes SET modalidade_id = ?, nome = ?, turma_id = ?, grupo = ?, cor = ? WHERE id = ?', [...$d, $id]);
                else { q('INSERT INTO ic_equipes (modalidade_id, nome, turma_id, grupo, cor) VALUES (?, ?, ?, ?, ?)', $d); $id = (int)db()->lastInsertId(); }
                flash('success', 'Equipe salva. Agora inclua os jogadores.');
                $volta('equipe=' . $id);
            case 'eq_excluir':
                $id = (int)($_POST['id'] ?? 0);
                if (q_valor('SELECT COUNT(*) FROM ic_jogos WHERE equipe_a = ? OR equipe_b = ?', [$id, $id])) throw new RuntimeException('Esta equipe já está em jogos. Apague ou troque os jogos antes.');
                q('DELETE FROM ic_jogadores WHERE equipe_id = ?', [$id]);
                q('DELETE FROM ic_equipes WHERE id = ?', [$id]);
                flash('success', 'Equipe apagada.');
                $volta('aba=equipes');

            /* ---------- Jogadores ---------- */
            case 'jog_add':
                $eq = ic_equipe((int)($_POST['equipe_id'] ?? 0));
                if (!$eq) throw new RuntimeException('Equipe não encontrada.');
                $n = 0;
                foreach ((array)($_POST['alunos'] ?? []) as $aid) {
                    $a = q_um('SELECT id, nome FROM alunos WHERE id = ?', [(int)$aid]);
                    if (!$a || q_valor('SELECT COUNT(*) FROM ic_jogadores WHERE equipe_id = ? AND aluno_id = ?', [$eq['id'], $a['id']])) continue;
                    q('INSERT INTO ic_jogadores (equipe_id, aluno_id, nome) VALUES (?, ?, ?)', [$eq['id'], $a['id'], nome_proprio($a['nome'])]); $n++;
                }
                if ($nm = $txt('nome_manual', 120)) { q('INSERT INTO ic_jogadores (equipe_id, nome, numero, posicao) VALUES (?, ?, ?, ?)', [$eq['id'], nome_proprio($nm), $txt('numero', 4), $txt('posicao', 30)]); $n++; }
                flash($n ? 'success' : 'warning', $n ? "$n jogador(es) incluído(s). Agora coloque número e posição." : 'Nenhum jogador escolhido.');
                $volta('equipe=' . $eq['id']);
            case 'jog_salvar':
                $eq = (int)($_POST['equipe_id'] ?? 0);
                foreach ((array)($_POST['j'] ?? []) as $jid => $d) {
                    $nome = trim((string)($d['nome'] ?? ''));
                    q('UPDATE ic_jogadores SET nome = ?, numero = ?, posicao = ?, capitao = ? WHERE id = ? AND equipe_id = ?',
                      [mb_substr($nome ?: '—', 0, 120), mb_substr(trim((string)($d['numero'] ?? '')), 0, 4) ?: null, mb_substr(trim((string)($d['posicao'] ?? '')), 0, 30) ?: null,
                       (int)($_POST['capitao'] ?? 0) === (int)$jid ? 1 : 0, (int)$jid, $eq]);
                }
                flash('success', 'Jogadores salvos.');
                $volta('equipe=' . $eq);
            case 'jog_excluir':
                $j = q_um('SELECT * FROM ic_jogadores WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
                if ($j) {
                    if (q_valor('SELECT COUNT(*) FROM ic_eventos WHERE jogador_id = ?', [$j['id']])) throw new RuntimeException('Este jogador tem gol ou cartão em súmula. Apague esses lances antes.');
                    q('DELETE FROM ic_jogadores WHERE id = ?', [$j['id']]);
                    flash('success', 'Jogador retirado da equipe.');
                }
                $volta('equipe=' . (int)($j['equipe_id'] ?? 0));

            /* ---------- Jogos ---------- */
            case 'jogo_salvar':
                $id = (int)($_POST['id'] ?? 0);
                $mod = (int)($_POST['modalidade_id'] ?? 0);
                if (!ic_modalidade($mod)) throw new RuntimeException('Escolha a modalidade do jogo.');
                $fase = array_key_exists($_POST['fase'] ?? '', IC_FASES) ? $_POST['fase'] : 'grupos';
                $a = $int_ou_null($_POST['equipe_a'] ?? ''); $b = $int_ou_null($_POST['equipe_b'] ?? '');
                if ($a && $a === $b) throw new RuntimeException('Escolha duas equipes diferentes.');
                if ((!$a && !$txt('rotulo_a', 60)) || (!$b && !$txt('rotulo_b', 60))) throw new RuntimeException('Escolha as duas equipes. No mata-mata, se ainda não souber, escreva quem vai jogar (ex.: "1º do Grupo A").');
                $dh = trim((string)($_POST['data_hora'] ?? ''));
                $dh = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dh) ? str_replace('T', ' ', $dh) . ':00' : null;
                $d = [$mod, $fase, $txt('grupo', 10) ? mb_strtoupper($txt('grupo', 10)) : null, $int_ou_null($_POST['rodada'] ?? ''), $a, $b, $a ? null : $txt('rotulo_a', 60), $b ? null : $txt('rotulo_b', 60), $dh, $txt('local', 80), (int)($_POST['ordem'] ?? 0), $agora];
                if ($id) q('UPDATE ic_jogos SET modalidade_id = ?, fase = ?, grupo = ?, rodada = ?, equipe_a = ?, equipe_b = ?, rotulo_a = ?, rotulo_b = ?, data_hora = ?, local = ?, ordem = ?, atualizado_em = ? WHERE id = ?', [...$d, $id]);
                else { q('INSERT INTO ic_jogos (modalidade_id, fase, grupo, rodada, equipe_a, equipe_b, rotulo_a, rotulo_b, data_hora, local, ordem, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $d); $id = (int)db()->lastInsertId(); }
                flash('success', 'Jogo salvo.');
                $volta('jogo=' . $id);
            case 'jogo_excluir':
                $id = (int)($_POST['id'] ?? 0);
                q('DELETE FROM ic_eventos WHERE jogo_id = ?', [$id]);
                q('DELETE FROM ic_jogos WHERE id = ?', [$id]);
                flash('success', 'Jogo apagado.');
                $volta('aba=jogos');
            case 'gerar_grupos':
                $mod = (int)($_POST['modalidade_id'] ?? 0);
                $n = 0;
                $por_grupo = [];
                foreach (ic_equipes($mod) as $e) $por_grupo[trim((string)$e['grupo'])][] = (int)$e['id'];
                foreach ($por_grupo as $g => $ids) {
                    for ($i = 0; $i < count($ids); $i++) for ($k = $i + 1; $k < count($ids); $k++) {
                        $x = $ids[$i]; $y = $ids[$k];
                        if (q_valor("SELECT COUNT(*) FROM ic_jogos WHERE modalidade_id = ? AND fase = 'grupos' AND ((equipe_a = ? AND equipe_b = ?) OR (equipe_a = ? AND equipe_b = ?))", [$mod, $x, $y, $y, $x])) continue;
                        q("INSERT INTO ic_jogos (modalidade_id, fase, grupo, equipe_a, equipe_b, atualizado_em) VALUES (?, 'grupos', ?, ?, ?, ?)", [$mod, $g !== '' ? $g : null, $x, $y, $agora]); $n++;
                    }
                }
                flash($n ? 'success' : 'info', $n ? "$n jogo(s) da fase de grupos criados (todos contra todos em cada grupo). Agora coloque data, hora e local de cada um." : 'Nenhum jogo novo: os confrontos já existem ou faltam equipes.');
                $volta('aba=jogos&m=' . $mod);

            /* ---------- Resultado e súmula ---------- */
            case 'resultado':
                $g = ic_jogo((int)($_POST['id'] ?? 0));
                if (!$g) throw new RuntimeException('Jogo não encontrado.');
                $st = array_key_exists($_POST['status'] ?? '', IC_STATUS) ? $_POST['status'] : 'agendado';
                $arq = $g['sumula_arquivo']; $arq_nome = $g['sumula_nome'];
                if (!empty($_POST['sumula_remover'])) { $arq = null; $arq_nome = null; }
                if (!empty($_FILES['sumula']['name'])) [$arq, $arq_nome] = ic_sumula_receber($_FILES['sumula'], (int)$g['id']);
                q('UPDATE ic_jogos SET status = ?, placar_a = ?, placar_b = ?, penaltis_a = ?, penaltis_b = ?, arbitro = ?, mesario = ?, observacoes = ?,
                   sumula_arquivo = ?, sumula_nome = ?, sumula_publica = ?, atualizado_em = ? WHERE id = ?',
                  [$st, $int_ou_null($_POST['placar_a'] ?? ''), $int_ou_null($_POST['placar_b'] ?? ''), $int_ou_null($_POST['penaltis_a'] ?? ''), $int_ou_null($_POST['penaltis_b'] ?? ''),
                   $txt('arbitro'), $txt('mesario'), $txt('observacoes', 3000), $arq, $arq_nome, !empty($_POST['sumula_publica']) ? 1 : 0, $agora, $g['id']]);
                flash('success', 'Resultado e súmula salvos.');
                $volta('jogo=' . $g['id']);
            case 'evento_add':
                $g = ic_jogo((int)($_POST['jogo_id'] ?? 0));
                if (!$g || !$g['equipe_a'] || !$g['equipe_b']) throw new RuntimeException('Defina as duas equipes do jogo antes de lançar gols e cartões.');
                $tipo = array_key_exists($_POST['tipo'] ?? '', IC_EVENTOS) ? $_POST['tipo'] : '';
                $jog = $int_ou_null($_POST['jogador_id'] ?? '');
                $eq = null;
                if ($jog) $eq = (int)q_valor('SELECT equipe_id FROM ic_jogadores WHERE id = ?', [$jog]);
                if (!$eq) $eq = (int)($_POST['equipe_id'] ?? 0);
                if (!$tipo || !in_array($eq, [(int)$g['equipe_a'], (int)$g['equipe_b']], true)) throw new RuntimeException('Escolha o tipo do lance e o jogador (ou a equipe).');
                if (in_array($tipo, ['amarelo', 'vermelho'], true) && !$jog) throw new RuntimeException('Cartão precisa do jogador.');
                q('INSERT INTO ic_eventos (jogo_id, equipe_id, jogador_id, tipo, minuto, criado_em) VALUES (?, ?, ?, ?, ?, ?)', [$g['id'], $eq, $jog, $tipo, $txt('minuto', 8), $agora]);
                if (in_array($tipo, ['gol', 'gol_contra'], true) && $g['tipo_placar'] === 'gols') ic_recalcular_placar((int)$g['id']);
                if ($g['status'] === 'agendado') q("UPDATE ic_jogos SET status = 'andamento', atualizado_em = ? WHERE id = ?", [$agora, $g['id']]);
                flash('success', IC_EVENTOS[$tipo][0] . ' lançado na súmula.');
                $volta('jogo=' . $g['id'] . '#lances');
            case 'evento_excluir':
                $ev = q_um('SELECT ev.*, m.placar FROM ic_eventos ev JOIN ic_jogos g ON g.id = ev.jogo_id JOIN ic_modalidades m ON m.id = g.modalidade_id WHERE ev.id = ?', [(int)($_POST['id'] ?? 0)]);
                if ($ev) {
                    q('DELETE FROM ic_eventos WHERE id = ?', [$ev['id']]);
                    if (in_array($ev['tipo'], ['gol', 'gol_contra'], true) && $ev['placar'] === 'gols') ic_recalcular_placar((int)$ev['jogo_id']);
                    flash('success', 'Lance apagado da súmula.');
                }
                $volta('jogo=' . (int)($ev['jogo_id'] ?? 0) . '#lances');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        $volta((string)($_POST['voltar'] ?? ''));
    }
    $volta();
}

/* ---------------- Tela ---------------- */
$mods = ic_modalidades();
$jogo = isset($_GET['jogo']) ? ic_jogo((int)$_GET['jogo']) : null;
$equipe = isset($_GET['equipe']) ? ic_equipe((int)$_GET['equipe']) : null;
$aba = $jogo ? 'jogo' : ($equipe ? 'equipe' : (in_array($_GET['aba'] ?? '', ['jogos', 'equipes', 'modalidades'], true) ? $_GET['aba'] : ($mods ? 'jogos' : 'modalidades')));
$f_mod = (int)($_GET['m'] ?? 0);
$equipes_todas = ic_equipes();
$turmas = turmas_lista(true, ano_hoje());

$titulo = 'Interclasse';
$subtitulo = 'Jogos, placares, súmulas, cartões, equipes e jogadores. Tudo aparece na página do interclasse no site.';
$menu = 'interclasse';
require __DIR__ . '/../includes/layout_topo.php';

$sel_mod = function (string $nome, int $atual, bool $vazio = false) use ($mods) {
    $h = '<select class="form-select" name="' . $nome . '" required>' . ($vazio ? '<option value="">Escolha…</option>' : '');
    foreach ($mods as $m) $h .= '<option value="' . $m['id'] . '"' . ((int)$m['id'] === $atual ? ' selected' : '') . '>' . e($m['nome']) . '</option>';
    return $h . '</select>';
};
$placar_txt = fn($g) => $g['placar_a'] === null ? '–' : (int)$g['placar_a'] . ' × ' . (int)$g['placar_b'] . ($g['penaltis_a'] !== null ? ' <small>(' . (int)$g['penaltis_a'] . '–' . (int)$g['penaltis_b'] . ' pên.)</small>' : '');
?>
<div class="ic-topo">
    <div class="ic-topo-t"><i class="bi bi-trophy"></i><div><b><?= e(ic_titulo()) ?></b><span><?= ic_no_site() ? '<i class="bi bi-circle-fill text-success"></i> Visível no site' : '<i class="bi bi-eye-slash"></i> Ainda não aparece no site (ligue em Modalidades e configuração)' ?></span></div></div>
    <a class="btn btn-light" href="../interclasse.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a>
</div>

<ul class="nav nav-pills ic-abas mb-3">
    <li class="nav-item"><a class="nav-link<?= in_array($aba, ['jogos', 'jogo'], true) ? ' active' : '' ?>" href="?aba=jogos"><i class="bi bi-calendar2-week me-1"></i>Jogos e placares</a></li>
    <li class="nav-item"><a class="nav-link<?= in_array($aba, ['equipes', 'equipe'], true) ? ' active' : '' ?>" href="?aba=equipes"><i class="bi bi-people me-1"></i>Equipes e jogadores</a></li>
    <li class="nav-item"><a class="nav-link<?= $aba === 'modalidades' ? ' active' : '' ?>" href="?aba=modalidades"><i class="bi bi-sliders me-1"></i>Modalidades e configuração</a></li>
</ul>

<?php if ($aba === 'modalidades'): ?>
<div class="row g-3">
    <div class="col-lg-5">
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="config"><input type="hidden" name="voltar" value="aba=modalidades">
            <div class="card-header"><h2>Configuração</h2></div>
            <div class="card-body vstack gap-3">
                <div><label class="form-label" for="icT">Nome do evento</label><input class="form-control" id="icT" name="ic_titulo" maxlength="80" value="<?= e(config_ler('ic_titulo') ?: '') ?>" placeholder="<?= e('Interclasse ' . date('Y')) ?>"></div>
                <div><label class="form-label" for="icS">Frase de apresentação</label><input class="form-control" id="icS" name="ic_subtitulo" maxlength="200" value="<?= e(config_ler('ic_subtitulo') ?: '') ?>" placeholder="<?= e(ic_subtitulo()) ?>"></div>
                <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ic_ativo" value="1"<?= ic_no_site() ? ' checked' : '' ?>> <span class="form-check-label">Mostrar o interclasse no site (link no menu e na página inicial)</span></label>
                <button class="btn btn-primary align-self-start"><i class="bi bi-check2 me-1"></i>Salvar</button>
            </div>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h2>Modalidades</h2><span class="small text-secondary">Ex.: Futsal masculino, Futsal feminino, Vôlei misto, Queimada.</span></div>
            <div class="card-body vstack gap-2">
                <?php foreach ([...$mods, null] as $m): ?>
                <form method="post" class="ic-mod<?= $m ? '' : ' nova' ?>"><?= csrf_campo() ?><input type="hidden" name="acao" value="mod_salvar"><input type="hidden" name="id" value="<?= (int)($m['id'] ?? 0) ?>"><input type="hidden" name="voltar" value="aba=modalidades">
                    <?php if (!$m): ?><div class="ic-mod-t"><i class="bi bi-plus-circle"></i> Nova modalidade</div><?php endif; ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5"><label class="form-label small">Nome</label><input class="form-control" name="nome" maxlength="80" required value="<?= e($m['nome'] ?? '') ?>" placeholder="Futsal masculino"></div>
                        <div class="col-6 col-md-3"><label class="form-label small">Placar em</label><select class="form-select" name="placar"><?php foreach (IC_PLACAR as $k => $v): ?><option value="<?= $k ?>"<?= ($m['placar'] ?? 'gols') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-3 col-md-2"><label class="form-label small" title="Pontos por vitória">Vitória</label><input class="form-control" type="number" min="0" max="9" name="pts_vitoria" value="<?= (int)($m['pts_vitoria'] ?? 3) ?>"></div>
                        <div class="col-3 col-md-2"><label class="form-label small" title="Pontos por empate">Empate</label><input class="form-control" type="number" min="0" max="9" name="pts_empate" value="<?= (int)($m['pts_empate'] ?? 1) ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label small">Ícone</label><select class="form-select" name="icone"><?php foreach (['trophy' => 'Troféu', 'dribbble' => 'Bola', 'bullseye' => 'Alvo', 'lightning-charge' => 'Raio', 'controller' => 'Jogos', 'person-arms-up' => 'Atletismo'] as $k => $v): ?><option value="<?= $k ?>"<?= ($m['icone'] ?? 'trophy') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-6 col-md-2"><label class="form-label small">Ordem</label><input class="form-control" type="number" name="ordem" value="<?= (int)($m['ordem'] ?? count($mods) + 1) ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="ativo" value="1"<?= ($m['ativo'] ?? 1) ? ' checked' : '' ?>> <span class="form-check-label">Mostrar no site</span></label></div>
                        <div class="col-12 col-md-3 d-flex gap-2 justify-content-end">
                            <button class="btn btn-<?= $m ? 'light' : 'primary' ?>"><i class="bi bi-check2"></i> <?= $m ? 'Salvar' : 'Criar' ?></button>
                        </div>
                    </div>
                </form>
                <?php if ($m): ?><form method="post" class="text-end ic-mod-del" onsubmit="return confirm('Apagar a modalidade <?= e($m['nome']) ?>?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="mod_excluir"><input type="hidden" name="id" value="<?= $m['id'] ?>"><input type="hidden" name="voltar" value="aba=modalidades"><button class="btn btn-link btn-sm text-danger p-0">apagar <?= e($m['nome']) ?></button></form><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($aba === 'equipes'): ?>
<?php if (!$mods): ?><div class="alert alert-warning">Crie primeiro uma modalidade em <a href="?aba=modalidades">Modalidades e configuração</a>.</div><?php else: ?>
<div class="row g-3">
    <div class="col-lg-4">
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="eq_salvar"><input type="hidden" name="voltar" value="aba=equipes">
            <div class="card-header"><h2>Nova equipe</h2></div>
            <div class="card-body vstack gap-3">
                <div><label class="form-label">Modalidade</label><?= $sel_mod('modalidade_id', $f_mod ?: (int)$mods[0]['id']) ?></div>
                <div><label class="form-label">Turma</label><select class="form-select" name="turma_id"><option value="">— sem turma (equipe mista) —</option><?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Nome da equipe <small class="text-secondary">(opcional; se vazio, usa a turma)</small></label><input class="form-control" name="nome" maxlength="80" placeholder="Ex.: 3º A INF"></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Grupo</label><input class="form-control" name="grupo" maxlength="10" placeholder="A"></div>
                    <div class="col-6"><label class="form-label">Cor</label><input class="form-control form-control-color w-100" type="color" name="cor" value="#13692a"></div>
                </div>
                <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Criar equipe</button>
            </div>
        </form>
    </div>
    <div class="col-lg-8">
        <div class="ic-filtro mb-2"><a class="<?= !$f_mod ? 'ativo' : '' ?>" href="?aba=equipes">Todas</a><?php foreach ($mods as $m): ?><a class="<?= $f_mod === (int)$m['id'] ? 'ativo' : '' ?>" href="?aba=equipes&m=<?= $m['id'] ?>"><?= e($m['nome']) ?></a><?php endforeach; ?></div>
        <?php $lista = array_filter($equipes_todas, fn($e) => !$f_mod || (int)$e['modalidade_id'] === $f_mod); ?>
        <?php if (!$lista): ?><div class="vazio py-4"><i class="bi bi-people"></i>Nenhuma equipe cadastrada ainda.</div><?php endif; ?>
        <div class="ic-equipes">
            <?php foreach ($lista as $e): ?>
            <a class="ic-eq" href="?equipe=<?= $e['id'] ?>" style="--c:<?= e($e['cor'] ?: '#13692a') ?>">
                <span class="ic-eq-escudo"><?= e(ic_iniciais($e['nome'])) ?></span>
                <span><b><?= e($e['nome']) ?></b><small><?= e($e['modalidade']) ?><?= $e['grupo'] ? ' · Grupo ' . e($e['grupo']) : '' ?> · <?= (int)$e['n_jogadores'] ?> jogador(es)</small></span>
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($aba === 'equipe'):
    $jogadores = ic_jogadores((int)$equipe['id']);
    $ja = array_filter(array_map('intval', array_column($jogadores, 'aluno_id')));
    $alunos_turma = $equipe['turma_id'] ? q_todos("SELECT a.id, a.nome FROM alunos a WHERE a.id IN (SELECT xt.aluno_id FROM aluno_turmas xt WHERE xt.turma_id = ? AND xt.situacao IN ('ativo','concluido')) ORDER BY a.nome", [$equipe['turma_id']]) : [];
?>
<a class="ic-voltar" href="?aba=equipes&m=<?= $equipe['modalidade_id'] ?>"><i class="bi bi-arrow-left"></i> Equipes</a>
<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="jog_salvar"><input type="hidden" name="equipe_id" value="<?= $equipe['id'] ?>"><input type="hidden" name="voltar" value="equipe=<?= $equipe['id'] ?>">
            <div class="card-header"><h2><span class="ic-ponto" style="background:<?= e($equipe['cor'] ?: '#13692a') ?>"></span><?= e($equipe['nome']) ?></h2><span class="small text-secondary"><?= e($equipe['modalidade']) ?><?= $equipe['grupo'] ? ' · Grupo ' . e($equipe['grupo']) : '' ?> · <?= count($jogadores) ?> jogador(es)</span></div>
            <?php if (!$jogadores): ?><div class="vazio py-4"><i class="bi bi-person-plus"></i>Inclua os jogadores ao lado.</div><?php else: ?>
            <div class="table-responsive"><table class="table align-middle mb-0 ic-tab-jog">
                <thead><tr><th style="width:74px">Nº</th><th>Nome</th><th style="width:150px">Posição</th><th class="text-center" title="Capitão">C</th><th></th></tr></thead>
                <tbody><?php foreach ($jogadores as $j): ?><tr>
                    <td><input class="form-control form-control-sm" name="j[<?= $j['id'] ?>][numero]" maxlength="4" value="<?= e($j['numero']) ?>" inputmode="numeric"></td>
                    <td><input class="form-control form-control-sm" name="j[<?= $j['id'] ?>][nome]" maxlength="120" value="<?= e($j['nome']) ?>"></td>
                    <td><input class="form-control form-control-sm" name="j[<?= $j['id'] ?>][posicao]" maxlength="30" value="<?= e($j['posicao']) ?>" list="icPos"></td>
                    <td class="text-center"><input class="form-check-input" type="radio" name="capitao" value="<?= $j['id'] ?>"<?= $j['capitao'] ? ' checked' : '' ?> aria-label="Capitão"></td>
                    <td class="text-end"><button class="btn btn-sm btn-link text-danger" type="submit" form="del<?= $j['id'] ?>" title="Retirar da equipe"><i class="bi bi-x-lg"></i></button></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <datalist id="icPos"><?php foreach (['Goleiro', 'Fixo', 'Ala', 'Pivô', 'Levantador', 'Ponteiro', 'Central', 'Oposto', 'Líbero', 'Armador', 'Atacante', 'Defensor'] as $p): ?><option value="<?= $p ?>"><?php endforeach; ?></datalist>
            <div class="barra-salvar"><button class="btn btn-primary ms-auto"><i class="bi bi-check2 me-1"></i>Salvar jogadores</button></div>
            <?php endif; ?>
        </form>
        <?php foreach ($jogadores as $j): ?><form id="del<?= $j['id'] ?>" method="post" onsubmit="return confirm('Retirar <?= e($j['nome']) ?> da equipe?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="jog_excluir"><input type="hidden" name="id" value="<?= $j['id'] ?>"><input type="hidden" name="voltar" value="equipe=<?= $equipe['id'] ?>"></form><?php endforeach; ?>
    </div>
    <div class="col-lg-5 vstack gap-3">
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="jog_add"><input type="hidden" name="equipe_id" value="<?= $equipe['id'] ?>"><input type="hidden" name="voltar" value="equipe=<?= $equipe['id'] ?>">
            <div class="card-header"><h2>Incluir jogadores</h2></div>
            <div class="card-body">
                <?php if ($alunos_turma): ?>
                <label class="form-label">Alunos da turma <small class="text-secondary">(marque quem vai jogar)</small></label>
                <input class="form-control form-control-sm mb-2" type="search" placeholder="Procurar nome…" oninput="const q=this.value.toLowerCase();this.nextElementSibling.querySelectorAll('label').forEach(l=>l.hidden=!l.textContent.toLowerCase().includes(q))">
                <div class="ic-alunos"><?php foreach ($alunos_turma as $a): $tem = in_array((int)$a['id'], $ja, true); ?>
                    <label class="form-check<?= $tem ? ' text-secondary' : '' ?>"><input class="form-check-input" type="checkbox" name="alunos[]" value="<?= $a['id'] ?>"<?= $tem ? ' disabled checked' : '' ?>> <span class="form-check-label"><?= e(nome_proprio($a['nome'])) ?><?= $tem ? ' · já está' : '' ?></span></label>
                <?php endforeach; ?></div>
                <?php elseif ($equipe['turma_id']): ?><div class="small text-secondary mb-2">A turma desta equipe não tem alunos cadastrados.</div>
                <?php endif; ?>
                <div class="ic-manual">
                    <label class="form-label mt-2">Ou escreva o nome <small class="text-secondary">(aluno de outra turma, convidado…)</small></label>
                    <div class="row g-2"><div class="col-12"><input class="form-control" name="nome_manual" maxlength="120" placeholder="Nome do jogador"></div>
                    <div class="col-4"><input class="form-control" name="numero" maxlength="4" placeholder="Nº" inputmode="numeric"></div><div class="col-8"><input class="form-control" name="posicao" maxlength="30" placeholder="Posição" list="icPos"></div></div>
                </div>
                <button class="btn btn-primary w-100 mt-3"><i class="bi bi-person-plus me-1"></i>Incluir na equipe</button>
            </div>
        </form>
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="eq_salvar"><input type="hidden" name="id" value="<?= $equipe['id'] ?>"><input type="hidden" name="voltar" value="equipe=<?= $equipe['id'] ?>">
            <div class="card-header"><h2>Dados da equipe</h2></div>
            <div class="card-body vstack gap-2">
                <div><label class="form-label small">Modalidade</label><?= $sel_mod('modalidade_id', (int)$equipe['modalidade_id']) ?></div>
                <div><label class="form-label small">Turma</label><select class="form-select" name="turma_id"><option value="">— sem turma —</option><?php foreach ($turmas as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$equipe['turma_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= e(turma_nome($t)) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label small">Nome</label><input class="form-control" name="nome" maxlength="80" value="<?= e($equipe['nome']) ?>"></div>
                <div class="row g-2"><div class="col-6"><label class="form-label small">Grupo</label><input class="form-control" name="grupo" maxlength="10" value="<?= e($equipe['grupo']) ?>"></div><div class="col-6"><label class="form-label small">Cor</label><input class="form-control form-control-color w-100" type="color" name="cor" value="<?= e($equipe['cor'] ?: '#13692a') ?>"></div></div>
                <button class="btn btn-light"><i class="bi bi-check2 me-1"></i>Salvar equipe</button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm('Apagar a equipe <?= e($equipe['nome']) ?> e a lista de jogadores?')" class="text-end"><?= csrf_campo() ?><input type="hidden" name="acao" value="eq_excluir"><input type="hidden" name="id" value="<?= $equipe['id'] ?>"><input type="hidden" name="voltar" value="equipe=<?= $equipe['id'] ?>"><button class="btn btn-link text-danger btn-sm">Apagar equipe</button></form>
    </div>
</div>

<?php elseif ($aba === 'jogo'):
    $eventos = ic_eventos((int)$jogo['id']);
    $jog_a = $jogo['equipe_a'] ? ic_jogadores((int)$jogo['equipe_a']) : [];
    $jog_b = $jogo['equipe_b'] ? ic_jogadores((int)$jogo['equipe_b']) : [];
    $eq_mod = array_filter($equipes_todas, fn($e) => (int)$e['modalidade_id'] === (int)$jogo['modalidade_id']);
?>
<a class="ic-voltar" href="?aba=jogos&m=<?= $jogo['modalidade_id'] ?>"><i class="bi bi-arrow-left"></i> Jogos</a>
<div class="ic-placar-cab">
    <span class="ic-st <?= $jogo['status'] ?>"><i class="bi bi-<?= IC_STATUS[$jogo['status']][1] ?>"></i><?= IC_STATUS[$jogo['status']][0] ?></span>
    <small><?= e($jogo['modalidade']) ?> · <?= e(IC_FASES[$jogo['fase']] ?? $jogo['fase']) ?><?= $jogo['grupo'] ? ' · Grupo ' . e($jogo['grupo']) : '' ?> · <?= e(ic_quando($jogo['data_hora'])) ?><?= $jogo['local'] ? ' · ' . e($jogo['local']) : '' ?></small>
    <div class="ic-placar-l"><b style="--c:<?= e($jogo['cor_a'] ?: '#13692a') ?>"><?= e(ic_lado($jogo, 'a')) ?></b><strong><?= $placar_txt($jogo) ?></strong><b style="--c:<?= e($jogo['cor_b'] ?: '#13692a') ?>"><?= e(ic_lado($jogo, 'b')) ?></b></div>
</div>
<div class="row g-3">
    <div class="col-lg-7 vstack gap-3">
        <div class="card" id="lances">
            <div class="card-header"><h2>Súmula: gols e cartões</h2><span class="small text-secondary"><?= $jogo['tipo_placar'] === 'gols' ? 'Cada gol lançado já atualiza o placar.' : 'Lance cartões aqui; o placar (' . IC_PLACAR[$jogo['tipo_placar']] . ') é preenchido ao lado.' ?></span></div>
            <?php if ($jogo['equipe_a'] && $jogo['equipe_b']): ?>
            <form method="post" class="ic-lance"><?= csrf_campo() ?><input type="hidden" name="acao" value="evento_add"><input type="hidden" name="jogo_id" value="<?= $jogo['id'] ?>"><input type="hidden" name="voltar" value="jogo=<?= $jogo['id'] ?>">
                <div class="ic-tipos"><?php foreach (IC_EVENTOS as $k => [$rot, $ic]): if ($jogo['tipo_placar'] !== 'gols' && $k === 'gol_contra') continue; ?>
                    <label class="ic-tipo t-<?= $k ?>"><input type="radio" name="tipo" value="<?= $k ?>" required<?= $k === 'gol' ? ' checked' : '' ?>><span><i class="bi bi-<?= $ic ?>"></i><?= $k === 'gol' && $jogo['tipo_placar'] !== 'gols' ? 'Ponto' : $rot ?></span></label>
                <?php endforeach; ?></div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-7"><label class="form-label small">Jogador</label><select class="form-select" name="jogador_id">
                        <option value="">— sem jogador (só a equipe) —</option>
                        <?php foreach ([[$jogo['nome_a'], $jog_a], [$jogo['nome_b'], $jog_b]] as [$nome_eq, $js]): ?><optgroup label="<?= e($nome_eq) ?>"><?php foreach ($js as $j): ?><option value="<?= $j['id'] ?>"><?= $j['numero'] !== null && $j['numero'] !== '' ? e($j['numero']) . ' · ' : '' ?><?= e($j['nome']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                    </select></div>
                    <div class="col-7 col-md-3"><label class="form-label small">Equipe <small>(se sem jogador)</small></label><select class="form-select" name="equipe_id"><option value="<?= $jogo['equipe_a'] ?>"><?= e($jogo['nome_a']) ?></option><option value="<?= $jogo['equipe_b'] ?>"><?= e($jogo['nome_b']) ?></option></select></div>
                    <div class="col-5 col-md-2"><label class="form-label small">Minuto</label><input class="form-control" name="minuto" maxlength="8" placeholder="12'"></div>
                    <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Lançar na súmula</button></div>
                </div>
            </form>
            <?php else: ?><div class="alert alert-info m-3">Defina as duas equipes do jogo (abaixo) para lançar gols e cartões.</div><?php endif; ?>
            <?php if ($eventos): ?><ul class="ic-lances"><?php foreach (array_reverse($eventos) as $ev): $lado = (int)$ev['equipe_id'] === (int)$jogo['equipe_a'] ? 'a' : 'b'; ?>
                <li class="lado-<?= $lado ?>"><span class="ic-ev t-<?= $ev['tipo'] ?>"><i class="bi bi-<?= IC_EVENTOS[$ev['tipo']][1] ?>"></i></span>
                    <span><b><?= $ev['jogador'] ? e(($ev['numero'] ? $ev['numero'] . ' · ' : '') . $ev['jogador']) : e($ev['equipe']) ?></b><small><?= e($ev['tipo'] === 'gol' && $jogo['tipo_placar'] !== 'gols' ? 'Ponto' : IC_EVENTOS[$ev['tipo']][0]) ?> · <?= e($ev['equipe']) ?><?= $ev['minuto'] ? ' · ' . e($ev['minuto']) : '' ?></small></span>
                    <form method="post" class="ms-auto" onsubmit="return confirm('Apagar este lance?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="evento_excluir"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><input type="hidden" name="voltar" value="jogo=<?= $jogo['id'] ?>"><button class="btn btn-sm btn-link text-danger" title="Apagar"><i class="bi bi-trash3"></i></button></form>
                </li>
            <?php endforeach; ?></ul><?php else: ?><div class="vazio py-3 small">Nenhum gol ou cartão lançado.</div><?php endif; ?>
        </div>
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="jogo_salvar"><input type="hidden" name="id" value="<?= $jogo['id'] ?>"><input type="hidden" name="voltar" value="jogo=<?= $jogo['id'] ?>">
            <div class="card-header"><h2>Dados do jogo</h2></div>
            <div class="card-body"><?php $g = $jogo; include __DIR__ . '/../includes/interclasse_form_jogo.php'; ?></div>
            <div class="barra-salvar"><button class="btn btn-light ms-auto"><i class="bi bi-check2 me-1"></i>Salvar dados do jogo</button></div>
        </form>
    </div>
    <div class="col-lg-5 vstack gap-3">
        <form method="post" enctype="multipart/form-data" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="resultado"><input type="hidden" name="id" value="<?= $jogo['id'] ?>"><input type="hidden" name="voltar" value="jogo=<?= $jogo['id'] ?>">
            <div class="card-header"><h2>Placar e súmula</h2></div>
            <div class="card-body vstack gap-3">
                <div><label class="form-label">Situação</label><div class="ic-sts"><?php foreach (IC_STATUS as $k => [$rot, $ic]): ?><label class="ic-st-op"><input type="radio" name="status" value="<?= $k ?>"<?= $jogo['status'] === $k ? ' checked' : '' ?>><span class="ic-st <?= $k ?>"><i class="bi bi-<?= $ic ?>"></i><?= $rot ?></span></label><?php endforeach; ?></div></div>
                <div class="ic-placar-ed">
                    <div><small><?= e(ic_lado($jogo, 'a')) ?></small><input class="form-control" type="number" min="0" max="999" name="placar_a" value="<?= e($jogo['placar_a']) ?>" aria-label="Placar <?= e(ic_lado($jogo, 'a')) ?>"></div>
                    <span>×</span>
                    <div><small><?= e(ic_lado($jogo, 'b')) ?></small><input class="form-control" type="number" min="0" max="999" name="placar_b" value="<?= e($jogo['placar_b']) ?>" aria-label="Placar <?= e(ic_lado($jogo, 'b')) ?>"></div>
                </div>
                <details<?= $jogo['penaltis_a'] !== null ? ' open' : '' ?>><summary class="small">Pênaltis (desempate no mata-mata)</summary>
                    <div class="ic-placar-ed mt-2"><div><input class="form-control" type="number" min="0" max="99" name="penaltis_a" value="<?= e($jogo['penaltis_a']) ?>" aria-label="Pênaltis A"></div><span>×</span><div><input class="form-control" type="number" min="0" max="99" name="penaltis_b" value="<?= e($jogo['penaltis_b']) ?>" aria-label="Pênaltis B"></div></div>
                </details>
                <div class="row g-2"><div class="col-6"><label class="form-label small">Árbitro</label><input class="form-control" name="arbitro" maxlength="120" value="<?= e($jogo['arbitro']) ?>"></div><div class="col-6"><label class="form-label small">Mesário</label><input class="form-control" name="mesario" maxlength="120" value="<?= e($jogo['mesario']) ?>"></div></div>
                <div><label class="form-label small">Observações da súmula</label><textarea class="form-control" name="observacoes" rows="3" maxlength="3000" placeholder="Ocorrências, atrasos, W.O., relatório do árbitro…"><?= e($jogo['observacoes']) ?></textarea></div>
                <div>
                    <label class="form-label small">Súmula em papel (foto ou PDF)</label>
                    <?php if ($jogo['sumula_arquivo']): ?><div class="ic-sumula-arq"><i class="bi bi-file-earmark-check"></i><a href="../interclasse_sumula.php?jogo=<?= $jogo['id'] ?>" target="_blank" rel="noopener"><?= e($jogo['sumula_nome'] ?: 'súmula') ?></a><label class="ms-auto small"><input type="checkbox" name="sumula_remover" value="1"> remover</label></div><?php endif; ?>
                    <input class="form-control" type="file" name="sumula" accept="image/*,application/pdf">
                    <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="sumula_publica" value="1"<?= $jogo['sumula_publica'] ? ' checked' : '' ?>> <span class="form-check-label small">Mostrar a súmula em papel no site <span class="text-secondary">(a súmula digital, com gols e cartões, sempre aparece)</span></span></label>
                </div>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar placar e súmula</button>
            </div>
        </form>
        <a class="btn btn-light" href="../interclasse.php?jogo=<?= $jogo['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver este jogo no site</a>
        <form method="post" onsubmit="return confirm('Apagar este jogo e a súmula dele?')" class="text-end"><?= csrf_campo() ?><input type="hidden" name="acao" value="jogo_excluir"><input type="hidden" name="id" value="<?= $jogo['id'] ?>"><input type="hidden" name="voltar" value="jogo=<?= $jogo['id'] ?>"><button class="btn btn-link text-danger btn-sm">Apagar jogo</button></form>
    </div>
</div>

<?php else: /* jogos */
    if (!$mods): ?><div class="alert alert-warning">Para começar, crie as modalidades (ex.: Futsal masculino) em <a href="?aba=modalidades">Modalidades e configuração</a>, depois as equipes e os jogos.</div><?php else:
    $jogos = ic_jogos($f_mod ? ['modalidade' => $f_mod] : []);
    $ao_vivo = array_filter($jogos, fn($g) => $g['status'] === 'andamento');
    $proximos = array_filter($jogos, fn($g) => in_array($g['status'], ['agendado', 'adiado'], true));
    $feitos = array_reverse(array_filter($jogos, fn($g) => in_array($g['status'], ['encerrado', 'wo'], true)));
    $linha = function ($g) use ($placar_txt) { ?>
        <a class="ic-jogo-l st-<?= $g['status'] ?>" href="?jogo=<?= $g['id'] ?>">
            <span class="ic-jl-q"><b><?= e(ic_quando($g['data_hora'])) ?></b><small><?= e($g['modalidade']) ?> · <?= e($g['fase'] === 'grupos' ? ($g['grupo'] ? 'Grupo ' . $g['grupo'] : 'Grupos') : IC_FASES[$g['fase']]) ?><?= $g['local'] ? ' · ' . e($g['local']) : '' ?></small></span>
            <span class="ic-jl-x"><span class="t a"><?= e(ic_lado($g, 'a')) ?></span><strong><?= $placar_txt($g) ?></strong><span class="t b"><?= e(ic_lado($g, 'b')) ?></span></span>
            <span class="ic-st <?= $g['status'] ?>"><i class="bi bi-<?= IC_STATUS[$g['status']][1] ?>"></i><?= IC_STATUS[$g['status']][0] ?></span>
        </a>
    <?php }; ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="ic-filtro mb-2"><a class="<?= !$f_mod ? 'ativo' : '' ?>" href="?aba=jogos">Todas</a><?php foreach ($mods as $m): ?><a class="<?= $f_mod === (int)$m['id'] ? 'ativo' : '' ?>" href="?aba=jogos&m=<?= $m['id'] ?>"><?= e($m['nome']) ?></a><?php endforeach; ?></div>
        <?php if (!$jogos): ?><div class="vazio py-4"><i class="bi bi-calendar2-week"></i>Nenhum jogo cadastrado ainda. Crie ao lado, ou gere todos os jogos da fase de grupos de uma vez.</div><?php endif; ?>
        <?php if ($ao_vivo): ?><h3 class="ic-sub"><i class="bi bi-broadcast"></i>Ao vivo</h3><div class="ic-jogos-l"><?php foreach ($ao_vivo as $g) $linha($g); ?></div><?php endif; ?>
        <?php if ($proximos): ?><h3 class="ic-sub"><i class="bi bi-calendar-event"></i>Próximos jogos</h3><div class="ic-jogos-l"><?php foreach ($proximos as $g) $linha($g); ?></div><?php endif; ?>
        <?php if ($feitos): ?><h3 class="ic-sub"><i class="bi bi-check2-circle"></i>Resultados</h3><div class="ic-jogos-l"><?php foreach ($feitos as $g) $linha($g); ?></div><?php endif; ?>
    </div>
    <div class="col-xl-4 vstack gap-3">
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="jogo_salvar"><input type="hidden" name="voltar" value="aba=jogos">
            <div class="card-header"><h2>Novo jogo</h2></div>
            <div class="card-body"><?php $g = ['modalidade_id' => $f_mod ?: $mods[0]['id'], 'fase' => 'grupos']; include __DIR__ . '/../includes/interclasse_form_jogo.php'; ?>
                <button class="btn btn-primary w-100 mt-3"><i class="bi bi-plus-lg me-1"></i>Criar jogo</button></div>
        </form>
        <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="gerar_grupos"><input type="hidden" name="voltar" value="aba=jogos">
            <div class="card-header"><h2>Gerar fase de grupos</h2></div>
            <div class="card-body vstack gap-2"><span class="small text-secondary">Cria de uma vez os jogos de todos contra todos dentro de cada grupo (usa o grupo de cada equipe). Jogos que já existem não são repetidos.</span>
                <?= $sel_mod('modalidade_id', $f_mod ?: (int)$mods[0]['id']) ?>
                <button class="btn btn-light"><i class="bi bi-magic me-1"></i>Gerar jogos</button></div>
        </form>
    </div>
</div>
<?php endif; endif; ?>

<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
