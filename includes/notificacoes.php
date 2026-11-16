<?php
/* =====================================================================
 *  Notificações do painel
 *  · avisos da direção/coordenação para todos ou para alguns perfis;
 *  · atualizações do sistema (administrador);
 *  · pedidos de liberação de saída (professor → coordenação) e respostas.
 *  Cada notificação vale para "todos", para uma lista de perfis
 *  (",professor,coordenacao,") ou para um usuário só.
 * ===================================================================== */

const TIPOS_NOTIFICACAO = [
    'aviso'       => ['Aviso', 'megaphone'],
    'sistema'     => ['Atualização do sistema', 'stars'],
    'autorizacao' => ['Pedido de liberação', 'box-arrow-right'],
    'resposta'    => ['Liberação respondida', 'patch-check'],
    'ocorrencia'  => ['Ocorrência registrada', 'shield-exclamation'],
    'lembrete'    => ['Lembrete da agenda', 'alarm'],
];

/** Cria uma notificação. $para: 'todos' | ['perfis' => [...]] | ['usuarios' => [ids]] */
function notificar($para, string $titulo, ?string $mensagem = null, string $tipo = 'aviso', ?string $link = null, bool $importante = false): int
{
    $u = usuario();
    $base = [mb_substr($titulo, 0, 150), $mensagem, $tipo, $link, $importante ? 1 : 0, $u['id'] ?? null, $u['nome'] ?? 'Sistema', date('Y-m-d H:i:s')];
    $sql = 'INSERT INTO notificacoes (titulo, mensagem, tipo, link, importante, autor_id, autor_nome, criado_em, destino, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    try {
        if ($para === 'todos' || !empty($para['perfis'])) {
            q($sql, array_merge($base, [$para === 'todos' ? 'todos' : ',' . implode(',', $para['perfis']) . ',', null]));
            if ($u) notificacao_marcar_lida((int)db()->lastInsertId());         // quem enviou não precisa ser avisado
            return 1;
        }
        $n = 0;
        foreach (array_unique(array_map('intval', $para['usuarios'] ?? [])) as $id) { q($sql, array_merge($base, ['usuario', $id])); $n++; }
        return $n;
    } catch (PDOException $e) { return 0; }
}

/** Usuários ativos que têm uma permissão (perfil + exceções individuais) */
function usuarios_com_permissao(string $perm): array
{
    $ids = [];
    foreach (q_todos('SELECT * FROM usuarios WHERE ativo = 1') as $u) if (in_array($perm, permissoes_usuario($u), true)) $ids[] = (int)$u['id'];
    return $ids;
}

/** Filtro SQL das notificações que o usuário logado pode ver */
function notif_filtro(): array
{
    $u = usuario();
    return ["(n.usuario_id = ? OR (n.usuario_id IS NULL AND (n.destino = 'todos' OR n.destino LIKE ?)))", [(int)$u['id'], '%,' . $u['perfil'] . ',%']];
}

function notificacoes_lista(int $limite = 50, bool $so_nao_lidas = false, string $tipo = ''): array
{
    [$w, $p] = notif_filtro();
    $p = array_merge([uid()], $p);
    if ($so_nao_lidas) $w .= ' AND l.lida_em IS NULL';
    if ($tipo !== '') { $w .= ' AND n.tipo = ?'; $p[] = $tipo; }
    try {
        return q_todos("SELECT n.*, l.lida_em FROM notificacoes n LEFT JOIN notificacao_lidas l ON l.notificacao_id = n.id AND l.usuario_id = ?
                         WHERE $w ORDER BY n.criado_em DESC, n.id DESC LIMIT " . (int)$limite, $p);
    } catch (PDOException $e) { return []; }
}

function notificacoes_nao_lidas(): int
{
    [$w, $p] = notif_filtro();
    try {
        return (int)q_valor("SELECT COUNT(*) FROM notificacoes n LEFT JOIN notificacao_lidas l ON l.notificacao_id = n.id AND l.usuario_id = ?
                              WHERE $w AND l.lida_em IS NULL", array_merge([uid()], $p));
    } catch (PDOException $e) { return 0; }
}

function notificacao_marcar_lida(int $id): void
{
    try {
        if (!q_valor('SELECT 1 FROM notificacao_lidas WHERE notificacao_id = ? AND usuario_id = ?', [$id, uid()]))
            q('INSERT INTO notificacao_lidas (notificacao_id, usuario_id, lida_em) VALUES (?, ?, ?)', [$id, uid(), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {}
}

function notificacoes_marcar_todas(): void
{
    foreach (notificacoes_lista(500, true) as $n) notificacao_marcar_lida((int)$n['id']);
}

/** Tempo relativo curto: "agora", "há 5 min", "ontem 14:20" */
function quando(string $dh): string
{
    $t = strtotime($dh); $d = time() - $t;
    if ($d < 60) return 'agora';
    if ($d < 3600) return 'há ' . floor($d / 60) . ' min';
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'hoje ' . date('H:i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'ontem ' . date('H:i', $t);
    return date('d/m H:i', $t);
}

/** Pedidos de liberação aguardando a coordenação */
function saidas_pendentes(): int
{
    try { return (int)q_valor("SELECT COUNT(*) FROM saidas WHERE status = 'pendente'"); } catch (PDOException $e) { return 0; }
}
