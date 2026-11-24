<?php
/* ======================================================================
 *  v77 — Interclasse
 *  Área do site com os jogos do interclasse (placar, próximos jogos,
 *  classificação, chaveamento, artilharia, cartões, equipes e súmulas).
 *  Quem alimenta: permissão "interclasse". O perfil "Interclasse" só
 *  enxerga essa área do painel.
 * ====================================================================== */

const IC_FASES = [
    'grupos'   => 'Fase de grupos',
    'oitavas'  => 'Oitavas de final',
    'quartas'  => 'Quartas de final',
    'semi'     => 'Semifinal',
    'terceiro' => 'Disputa de 3º lugar',
    'final'    => 'Final',
];
const IC_STATUS = [
    'agendado'  => ['Agendado', 'calendar-event'],
    'andamento' => ['Ao vivo', 'broadcast'],
    'encerrado' => ['Encerrado', 'check2-circle'],
    'wo'        => ['W.O.', 'flag'],
    'adiado'    => ['Adiado', 'clock-history'],
];
const IC_EVENTOS = [
    'gol'        => ['Gol', 'record-circle'],
    'gol_contra' => ['Gol contra', 'record-circle'],
    'amarelo'    => ['Cartão amarelo', 'square-fill'],
    'vermelho'   => ['Cartão vermelho', 'square-fill'],
];
const IC_PLACAR = ['gols' => 'Gols', 'sets' => 'Sets', 'pontos' => 'Pontos'];
const IC_EXT_SUMULA = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];

function ic_migrar(): void
{
    if (config_ler('migracao_v77')) return;
    try {
        $pdo = db();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS ic_modalidades (id $id, nome VARCHAR(80) NOT NULL, icone VARCHAR(30) DEFAULT 'trophy',
                    placar VARCHAR(10) NOT NULL DEFAULT 'gols', pts_vitoria SMALLINT NOT NULL DEFAULT 3, pts_empate SMALLINT NOT NULL DEFAULT 1,
                    ordem SMALLINT NOT NULL DEFAULT 0, ativo TINYINT NOT NULL DEFAULT 1)$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ic_equipes (id $id, modalidade_id $int NOT NULL, nome VARCHAR(80) NOT NULL, turma_id $int NULL,
                    grupo VARCHAR(10) NULL, cor VARCHAR(9) NULL)$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ic_jogadores (id $id, equipe_id $int NOT NULL, aluno_id $int NULL, nome VARCHAR(120) NOT NULL,
                    numero VARCHAR(4) NULL, posicao VARCHAR(30) NULL, capitao TINYINT NOT NULL DEFAULT 0)$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ic_jogos (id $id, modalidade_id $int NOT NULL, fase VARCHAR(12) NOT NULL DEFAULT 'grupos', grupo VARCHAR(10) NULL,
                    rodada SMALLINT NULL, equipe_a $int NULL, equipe_b $int NULL, rotulo_a VARCHAR(60) NULL, rotulo_b VARCHAR(60) NULL,
                    data_hora DATETIME NULL, local VARCHAR(80) NULL, status VARCHAR(12) NOT NULL DEFAULT 'agendado',
                    placar_a SMALLINT NULL, placar_b SMALLINT NULL, penaltis_a SMALLINT NULL, penaltis_b SMALLINT NULL,
                    arbitro VARCHAR(120) NULL, mesario VARCHAR(120) NULL, observacoes TEXT NULL,
                    sumula_arquivo VARCHAR(255) NULL, sumula_nome VARCHAR(160) NULL, sumula_publica TINYINT NOT NULL DEFAULT 0,
                    ordem SMALLINT NOT NULL DEFAULT 0, atualizado_em DATETIME NULL)$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ic_eventos (id $id, jogo_id $int NOT NULL, equipe_id $int NOT NULL, jogador_id $int NULL,
                    tipo VARCHAR(12) NOT NULL, minuto VARCHAR(8) NULL, criado_em DATETIME NULL)$fim");
        foreach (['CREATE INDEX idx_ic_eq_mod ON ic_equipes (modalidade_id)', 'CREATE INDEX idx_ic_jog_eq ON ic_jogadores (equipe_id)',
                  'CREATE INDEX idx_ic_jogo_mod ON ic_jogos (modalidade_id)', 'CREATE INDEX idx_ic_ev_jogo ON ic_eventos (jogo_id)'] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        /* Perfil "Interclasse": só alimenta a área do interclasse */
        try {
            if (!q_valor("SELECT COUNT(*) FROM perfis WHERE chave = 'interclasse'"))
                q("INSERT INTO perfis (chave, nome, descricao, cor, sistema, personalizado, ordem) VALUES ('interclasse', 'Interclasse', ?, 'ambar', 0, 1, 70)",
                  ['Só alimenta a área do interclasse no site (jogos, placares, súmulas, cartões, equipes). Não vê a gestão da escola.']);
            if (!q_valor("SELECT COUNT(*) FROM perfil_permissoes WHERE perfil = 'interclasse' AND permissao = 'interclasse'"))
                q("INSERT INTO perfil_permissoes (perfil, permissao) VALUES ('interclasse', 'interclasse')");
            // perfis personalizados da gestão/desenvolvimento também podem alimentar
            foreach (q_todos("SELECT chave FROM perfis WHERE personalizado = 1 AND chave IN ('dev', 'diretor', 'coordenacao')") as $p)
                if (!q_valor('SELECT COUNT(*) FROM perfil_permissoes WHERE perfil = ? AND permissao = ?', [$p['chave'], 'interclasse']))
                    q('INSERT INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)', [$p['chave'], 'interclasse']);
        } catch (Throwable $e) {}
        config_gravar('migracao_v77', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* tenta de novo no próximo acesso */ }
}

/** Usuário que só tem a permissão do interclasse: vê apenas essa área do painel */
function so_interclasse(): bool
{
    $u = usuario();
    if (!$u || ($u['perfil'] ?? '') === 'admin') return false;
    $p = permissoes_usuario($u);
    return in_array('interclasse', $p, true) && !array_diff($p, ['interclasse']);
}

/* ---------------- Configuração ---------------- */
function ic_titulo(): string { return trim((string)config_ler('ic_titulo')) ?: 'Interclasse ' . date('Y'); }
function ic_subtitulo(): string { return trim((string)config_ler('ic_subtitulo')) ?: 'Jogos, placares, classificação e chaveamento do interclasse da escola.'; }
function ic_no_site(): bool { return config_ler('ic_ativo') === '1'; }

/* ---------------- Consultas ---------------- */
function ic_modalidades(bool $so_ativas = false): array
{
    try { return q_todos('SELECT * FROM ic_modalidades' . ($so_ativas ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome'); }
    catch (PDOException $e) { return []; }
}
function ic_modalidade(int $id): ?array { try { return q_um('SELECT * FROM ic_modalidades WHERE id = ?', [$id]) ?: null; } catch (PDOException $e) { return null; } }

function ic_equipes(?int $modalidade = null): array
{
    try {
        return q_todos('SELECT e.*, m.nome AS modalidade, (SELECT COUNT(*) FROM ic_jogadores j WHERE j.equipe_id = e.id) AS n_jogadores
                        FROM ic_equipes e JOIN ic_modalidades m ON m.id = e.modalidade_id'
                     . ($modalidade ? ' WHERE e.modalidade_id = ?' : '') . ' ORDER BY m.ordem, m.nome, e.grupo, e.nome', $modalidade ? [$modalidade] : []);
    } catch (PDOException $e) { return []; }
}
function ic_equipe(int $id): ?array { try { return q_um('SELECT e.*, m.nome AS modalidade, m.placar FROM ic_equipes e JOIN ic_modalidades m ON m.id = e.modalidade_id WHERE e.id = ?', [$id]) ?: null; } catch (PDOException $e) { return null; } }

function ic_jogadores(int $equipe): array
{
    try { return q_todos('SELECT * FROM ic_jogadores WHERE equipe_id = ? ORDER BY capitao DESC, CASE WHEN numero IS NULL OR numero = \'\' THEN 1 ELSE 0 END, CAST(numero AS UNSIGNED), nome', [$equipe]); }
    catch (PDOException $e) {
        // SQLite (testes) não tem UNSIGNED
        return q_todos('SELECT * FROM ic_jogadores WHERE equipe_id = ? ORDER BY capitao DESC, nome', [$equipe]);
    }
}

const IC_SQL_JOGO = 'SELECT g.*, m.nome AS modalidade, m.placar AS tipo_placar, m.icone,
                       ea.nome AS nome_a, ea.cor AS cor_a, eb.nome AS nome_b, eb.cor AS cor_b
                     FROM ic_jogos g JOIN ic_modalidades m ON m.id = g.modalidade_id
                     LEFT JOIN ic_equipes ea ON ea.id = g.equipe_a LEFT JOIN ic_equipes eb ON eb.id = g.equipe_b';

function ic_jogos(array $filtro = [], string $ordem = 'g.data_hora IS NULL, g.data_hora, g.ordem, g.id', int $limite = 500): array
{
    $w = []; $p = [];
    if (!empty($filtro['modalidade'])) { $w[] = 'g.modalidade_id = ?'; $p[] = (int)$filtro['modalidade']; }
    if (!empty($filtro['status']))     { $w[] = 'g.status IN (' . implode(',', array_fill(0, count((array)$filtro['status']), '?')) . ')'; array_push($p, ...(array)$filtro['status']); }
    if (!empty($filtro['fase']))       { $w[] = 'g.fase = ?'; $p[] = $filtro['fase']; }
    if (!empty($filtro['equipe']))     { $w[] = '(g.equipe_a = ? OR g.equipe_b = ?)'; $p[] = (int)$filtro['equipe']; $p[] = (int)$filtro['equipe']; }
    if (!empty($filtro['so_ativas']))  { $w[] = 'm.ativo = 1'; }
    try { return q_todos(IC_SQL_JOGO . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $ordem LIMIT " . (int)$limite, $p); }
    catch (PDOException $e) { return []; }
}
function ic_jogo(int $id): ?array { try { return q_um(IC_SQL_JOGO . ' WHERE g.id = ?', [$id]) ?: null; } catch (PDOException $e) { return null; } }

function ic_eventos(int $jogo): array
{
    try {
        $r = q_todos('SELECT ev.*, j.nome AS jogador, j.numero, e.nome AS equipe FROM ic_eventos ev
                        LEFT JOIN ic_jogadores j ON j.id = ev.jogador_id JOIN ic_equipes e ON e.id = ev.equipe_id
                        WHERE ev.jogo_id = ? ORDER BY ev.id', [$jogo]);
        // em ordem de minuto (os sem minuto ficam na ordem em que foram lançados)
        $m = fn($e) => preg_match('/\d+/', (string)$e['minuto'], $x) ? (int)$x[0] : null;
        usort($r, fn($a, $b) => ($m($a) === null || $m($b) === null) ? $a['id'] <=> $b['id'] : [$m($a), $a['id']] <=> [$m($b), $b['id']]);
        return $r;
    } catch (PDOException $e) { return []; }
}

/** Nome do lado A/B (equipe ou o texto provisório, ex.: "1º do Grupo A") */
function ic_lado(array $g, string $l): string { return (string)($g['nome_' . $l] ?: ($g['rotulo_' . $l] ?: 'A definir')); }

/** Quem venceu: 'a', 'b', 'empate' ou null (jogo sem resultado) */
function ic_vencedor(array $g): ?string
{
    if (!in_array($g['status'], ['encerrado', 'wo'], true) || $g['placar_a'] === null || $g['placar_b'] === null) return null;
    if ((int)$g['placar_a'] !== (int)$g['placar_b']) return (int)$g['placar_a'] > (int)$g['placar_b'] ? 'a' : 'b';
    if ($g['penaltis_a'] !== null && $g['penaltis_b'] !== null && (int)$g['penaltis_a'] !== (int)$g['penaltis_b'])
        return (int)$g['penaltis_a'] > (int)$g['penaltis_b'] ? 'a' : 'b';
    return 'empate';
}

/** Classificação da fase de grupos: [grupo => [linhas]] */
function ic_classificacao(int $modalidade): array
{
    $m = ic_modalidade($modalidade);
    if (!$m) return [];
    $tab = [];
    foreach (ic_equipes($modalidade) as $e) {
        $g = trim((string)$e['grupo']) ?: 'Único';
        $tab[$g][$e['id']] = ['id' => (int)$e['id'], 'nome' => $e['nome'], 'cor' => $e['cor'], 'j' => 0, 'v' => 0, 'e' => 0, 'd' => 0, 'gp' => 0, 'gc' => 0, 'pts' => 0, 'ca' => 0, 'cv' => 0, 'forma' => []];
    }
    $idx = [];
    foreach ($tab as $g => $l) foreach ($l as $id => $_) $idx[$id] = $g;
    foreach (ic_jogos(['modalidade' => $modalidade, 'fase' => 'grupos', 'status' => ['encerrado', 'wo']], 'g.data_hora, g.id') as $j) {
        $a = (int)$j['equipe_a']; $b = (int)$j['equipe_b'];
        if (!isset($idx[$a], $idx[$b]) || $j['placar_a'] === null) continue;
        $pa = (int)$j['placar_a']; $pb = (int)$j['placar_b'];
        foreach ([[$a, $pa, $pb], [$b, $pb, $pa]] as [$t, $pro, $con]) {
            $r = &$tab[$idx[$t]][$t];
            $r['j']++; $r['gp'] += $pro; $r['gc'] += $con;
            if ($pro > $con) { $r['v']++; $r['pts'] += (int)$m['pts_vitoria']; $r['forma'][] = 'v'; }
            elseif ($pro === $con) { $r['e']++; $r['pts'] += (int)$m['pts_empate']; $r['forma'][] = 'e'; }
            else { $r['d']++; $r['forma'][] = 'd'; }
            unset($r);
        }
    }
    // cartões (critério de desempate: disciplina)
    try {
        foreach (q_todos("SELECT ev.equipe_id, SUM(CASE WHEN ev.tipo = 'amarelo' THEN 1 ELSE 0 END) AS ca, SUM(CASE WHEN ev.tipo = 'vermelho' THEN 1 ELSE 0 END) AS cv
                          FROM ic_eventos ev JOIN ic_jogos g ON g.id = ev.jogo_id WHERE g.modalidade_id = ? AND g.fase = 'grupos' GROUP BY ev.equipe_id", [$modalidade]) as $c) {
            $t = (int)$c['equipe_id'];
            if (isset($idx[$t])) { $tab[$idx[$t]][$t]['ca'] = (int)$c['ca']; $tab[$idx[$t]][$t]['cv'] = (int)$c['cv']; }
        }
    } catch (PDOException $e) {}
    ksort($tab, SORT_NATURAL);
    foreach ($tab as &$l) {
        $l = array_values($l);
        usort($l, fn($x, $y) => [$y['pts'], $y['v'], $y['gp'] - $y['gc'], $y['gp'], $x['cv'], $x['ca'], $x['nome']] <=> [$x['pts'], $x['v'], $x['gp'] - $x['gc'], $x['gp'], $y['cv'], $y['ca'], $y['nome']]);
        foreach ($l as &$r) $r['forma'] = array_slice($r['forma'], -5);
        unset($r);
    }
    unset($l);
    return $tab;
}

/** Chaveamento (mata-mata): [fase => [jogos]] na ordem das fases */
function ic_chave(int $modalidade): array
{
    $r = [];
    foreach (ic_jogos(['modalidade' => $modalidade], 'g.ordem, g.data_hora, g.id') as $j) if ($j['fase'] !== 'grupos') $r[$j['fase']][] = $j;
    $ord = [];
    foreach (array_keys(IC_FASES) as $f) if (isset($r[$f])) $ord[$f] = $r[$f];
    return $ord;
}

/** Artilharia e cartões por jogador */
function ic_estatisticas(int $modalidade): array
{
    try {
        return q_todos("SELECT j.id, j.nome, j.numero, e.nome AS equipe, e.id AS equipe_id,
                               SUM(CASE WHEN ev.tipo = 'gol' THEN 1 ELSE 0 END) AS gols,
                               SUM(CASE WHEN ev.tipo = 'amarelo' THEN 1 ELSE 0 END) AS amarelos,
                               SUM(CASE WHEN ev.tipo = 'vermelho' THEN 1 ELSE 0 END) AS vermelhos
                        FROM ic_eventos ev JOIN ic_jogadores j ON j.id = ev.jogador_id JOIN ic_equipes e ON e.id = j.equipe_id
                        JOIN ic_jogos g ON g.id = ev.jogo_id WHERE g.modalidade_id = ?
                        GROUP BY j.id, j.nome, j.numero, e.nome, e.id", [$modalidade]);
    } catch (PDOException $e) { return []; }
}

/** Jogador suspenso no próximo jogo: cartão vermelho, ou 3º amarelo, sem jogo da equipe depois */
function ic_suspensos(int $modalidade): array
{
    $sus = [];
    try {
        $ev = q_todos("SELECT ev.jogador_id, ev.tipo, ev.equipe_id, g.id AS jogo, g.data_hora FROM ic_eventos ev JOIN ic_jogos g ON g.id = ev.jogo_id
                       WHERE g.modalidade_id = ? AND ev.jogador_id IS NOT NULL AND ev.tipo IN ('amarelo', 'vermelho') ORDER BY g.data_hora, g.id, ev.id", [$modalidade]);
    } catch (PDOException $e) { return []; }
    $am = []; $gatilho = [];
    foreach ($ev as $e) {
        $j = (int)$e['jogador_id'];
        if ($e['tipo'] === 'vermelho') $gatilho[$j] = [$e, 'cartão vermelho'];
        else { $am[$j] = ($am[$j] ?? 0) + 1; if ($am[$j] % 3 === 0) $gatilho[$j] = [$e, '3 cartões amarelos']; }
    }
    foreach ($gatilho as $j => [$e, $motivo]) {
        // a equipe já jogou (encerrado) depois do jogo do cartão? então já cumpriu
        $depois = q_valor("SELECT COUNT(*) FROM ic_jogos WHERE (equipe_a = ? OR equipe_b = ?) AND status IN ('encerrado', 'wo') AND id <> ?
                           AND (data_hora > ? OR (data_hora = ? AND id > ?))", [$e['equipe_id'], $e['equipe_id'], $e['jogo'], (string)$e['data_hora'], (string)$e['data_hora'], $e['jogo']]);
        if (!$depois) $sus[$j] = $motivo;
    }
    return $sus;
}

/** Recalcula o placar pelos gols lançados na súmula (gol contra conta para o adversário) */
function ic_recalcular_placar(int $jogo): void
{
    $g = q_um('SELECT equipe_a, equipe_b FROM ic_jogos WHERE id = ?', [$jogo]);
    if (!$g) return;
    $n = fn(int $eq, string $tipo) => (int)q_valor('SELECT COUNT(*) FROM ic_eventos WHERE jogo_id = ? AND equipe_id = ? AND tipo = ?', [$jogo, $eq, $tipo]);
    $a = (int)$g['equipe_a']; $b = (int)$g['equipe_b'];
    $pa = $n($a, 'gol') + $n($b, 'gol_contra');
    $pb = $n($b, 'gol') + $n($a, 'gol_contra');
    q('UPDATE ic_jogos SET placar_a = ?, placar_b = ?, atualizado_em = ? WHERE id = ?', [$pa, $pb, date('Y-m-d H:i:s'), $jogo]);
}

/** Recebe a súmula digitalizada (foto ou PDF) */
function ic_sumula_receber(array $f, int $jogo): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(erro_upload((int)($f['error'] ?? 4)));
    $ext = arquivo_extensao((string)$f['name']);
    if (!isset(IC_EXT_SUMULA[$ext])) throw new RuntimeException('Envie a súmula em PDF ou imagem (JPG, PNG).');
    if ((int)$f['size'] > 20 * 1048576) throw new RuntimeException('A súmula passa de 20 MB.');
    $pasta = pasta_dados('interclasse/' . date('Y'));
    $nome = 'sumula_' . $jogo . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$pasta/$nome")) throw new RuntimeException('Não foi possível salvar a súmula no servidor.');
    return ['interclasse/' . date('Y') . '/' . $nome, mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string)$f['name'])), 0, 150)];
}

/** Letras do escudo: "1º B DS" vira "1B"; outros nomes, as 2 primeiras letras */
function ic_iniciais(string $nome): string
{
    if (preg_match('/^(\d)\s*[º°ª]?\s*(?:ano\s+)?([A-Za-z])\b/iu', trim($nome), $m)) return $m[1] . mb_strtoupper($m[2]);
    $p = preg_split('/\s+/u', trim($nome));
    return mb_strtoupper(count($p) > 1 ? mb_substr($p[0], 0, 1) . mb_substr($p[1], 0, 1) : mb_substr($nome, 0, 2));
}

/** Data curta do jogo: "Ter 25/11 · 09:30" */
function ic_quando(?string $dt): string
{
    if (!$dt) return 'Data a definir';
    $t = strtotime($dt);
    $dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $hoje = date('Y-m-d'); $d = date('Y-m-d', $t);
    $rot = $d === $hoje ? 'Hoje' : ($d === date('Y-m-d', strtotime('+1 day')) ? 'Amanhã' : $dias[(int)date('w', $t)] . ' ' . date('d/m', $t));
    return $rot . (date('H:i', $t) !== '00:00' ? ' · ' . date('H:i', $t) : '');
}
