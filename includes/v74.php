<?php
/* v74 · coordenador de curso, comprovante da busca ativa, visibilidade das provas,
 *       anotações no livro didático e autorização interna. Funções e migração do banco. */

/** Cria colunas e tabelas novas (uma vez só; funciona no MySQL da Hostinger e no SQLite dos testes) */
function v74_migrar(): void
{
    if (config_ler('migracao_v74')) return;
    try {
        $pdo = db();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $tem_col = function (string $t, string $c) use ($pdo, $mysql): bool {
            if ($mysql) return (bool)q_valor('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
            foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll() as $r) if ($r['name'] === $c) return true;
            return false;
        };
        $int = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $auto = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $texto_longo = $mysql ? 'MEDIUMTEXT' : 'TEXT';

        if (!$tem_col('cursos', 'coordenador_id')) $pdo->exec("ALTER TABLE cursos ADD COLUMN coordenador_id $int NULL");
        if (!$tem_col('busca_ativa', 'comprovante')) $pdo->exec('ALTER TABLE busca_ativa ADD COLUMN comprovante VARCHAR(200) NULL');
        if (!$tem_col('busca_ativa', 'comprovante_nome')) $pdo->exec('ALTER TABLE busca_ativa ADD COLUMN comprovante_nome VARCHAR(200) NULL');
        if (!$tem_col('provas', 'visibilidade')) $pdo->exec("ALTER TABLE provas ADD COLUMN visibilidade VARCHAR(12) NOT NULL DEFAULT 'todos'");
        $pdo->exec("CREATE TABLE IF NOT EXISTS prova_acesso (prova_id $int NOT NULL, usuario_id $int NOT NULL, PRIMARY KEY (prova_id, usuario_id))$fim");
        $pdo->exec("CREATE TABLE IF NOT EXISTS livro_anotacoes (id $auto, usuario_id $int NOT NULL, livro_id $int NOT NULL, pagina $int NOT NULL,
                    dados $texto_longo NOT NULL, atualizado_em DATETIME NULL, UNIQUE (usuario_id, livro_id, pagina))$fim");
        config_gravar('migracao_v74', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* sem permissão para alterar o banco agora: tenta de novo no próximo acesso */ }
}

/* ---------------------------------------------------------------------
 *  Coordenador de curso
 * --------------------------------------------------------------------- */

/** IDs dos cursos que o usuário coordena */
function cursos_coordenados(?int $usuario = null): array
{
    static $cache = [];
    $usuario ??= uid();
    if (!$usuario) return [];
    if (!isset($cache[$usuario])) {
        try { $cache[$usuario] = array_map('intval', array_column(q_todos('SELECT id FROM cursos WHERE coordenador_id = ?', [$usuario]), 'id')); }
        catch (PDOException $e) { $cache[$usuario] = []; }
    }
    return $cache[$usuario];
}

function eh_coordenador_curso(): bool { return (bool)cursos_coordenados(); }

/** Turmas em que o usuário pode emitir a liberação de saída: as que dirige + as do(s) curso(s) que coordena */
function saida_turmas_permitidas(): array
{
    $ids = array_map('intval', array_column(turmas_dirigidas(null, ano_hoje()), 'id'));   // saída é sempre de hoje
    if ($cs = cursos_coordenados())
        $ids = array_merge($ids, array_map('intval', array_column(q_todos('SELECT id FROM turmas WHERE curso_id IN (' . implode(',', $cs) . ') AND ano_letivo = ?', [ano_hoje()]), 'id')));
    return array_values(array_unique($ids));
}

/** Saída da escola: só o(a) diretor(a) da turma do aluno ou o(a) coordenador(a) do curso dele (o administrador do sistema também) */
function pode_emitir_saida(?array $aluno = null): bool
{
    if ((usuario()['perfil'] ?? '') === 'admin') return true;
    if ($aluno === null) return (bool)saida_turmas_permitidas();
    return in_array((int)($aluno['turma_id'] ?? 0), saida_turmas_permitidas(), true);
}

/* ---------------------------------------------------------------------
 *  Visibilidade das provas (gabaritos)
 * --------------------------------------------------------------------- */
const VISIBILIDADE_PROVA = [
    'todos'       => ['Todos os professores', 'globe2', 'Qualquer pessoa com acesso à correção de provas vê, edita e corrige.'],
    'selecionados' => ['Professores escolhidos', 'people', 'Só você e as pessoas que você escolher.'],
    'privado'     => ['Só eu', 'lock', 'Só você (e a gestão).'],
];

function prova_acesso_lista(int $prova_id): array
{
    try { return array_map('intval', array_column(q_todos('SELECT usuario_id FROM prova_acesso WHERE prova_id = ?', [$prova_id]), 'usuario_id')); }
    catch (PDOException $e) { return []; }
}

function prova_acesso_gravar(int $prova_id, string $vis, array $usuarios): void
{
    $vis = isset(VISIBILIDADE_PROVA[$vis]) ? $vis : 'todos';
    try {
        q('UPDATE provas SET visibilidade = ? WHERE id = ?', [$vis, $prova_id]);
        q('DELETE FROM prova_acesso WHERE prova_id = ?', [$prova_id]);
        if ($vis === 'selecionados') foreach (array_unique(array_map('intval', $usuarios)) as $u) if ($u && $u !== uid()) q('INSERT INTO prova_acesso (prova_id, usuario_id) VALUES (?, ?)', [$prova_id, $u]);
    } catch (PDOException $e) {}
}

/** Pessoas que podem ser escolhidas para ver uma prova (quem tem acesso à correção de provas) */
function prova_pessoas_escolha(): array
{
    $l = [];
    foreach (q_todos("SELECT id, nome, foto, perfil FROM usuarios WHERE ativo = 1 AND perfil <> 'admin' ORDER BY nome") as $u)
        if ((int)$u['id'] !== uid() && in_array('provas', permissoes_usuario($u), true)) $l[] = $u;
    return $l;
}

/** Filtro SQL das provas que o usuário vê (alias p) */
function provas_filtro_visiveis(): array
{
    if (!restrito_a_atribuicoes()) return ['1 = 1', []];
    return ["(p.criado_por = ? OR COALESCE(p.visibilidade, 'todos') = 'todos' OR (p.visibilidade = 'selecionados' AND EXISTS (SELECT 1 FROM prova_acesso pa WHERE pa.prova_id = p.id AND pa.usuario_id = ?)))", [uid(), uid()]];
}

/* ---------------------------------------------------------------------
 *  Comprovante da busca ativa
 * --------------------------------------------------------------------- */
const EXT_COMPROVANTE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
                         'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                         'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg'];

/** Recebe o arquivo que comprova a busca ativa (print da conversa, foto do registro, áudio, ofício…) */
function busca_comprovante_receber(array $f, int $aluno_id): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(($f['error'] ?? 4) == UPLOAD_ERR_NO_FILE
        ? 'Anexe o comprovante do contato com a família (print da conversa, foto, áudio ou documento).' : erro_upload((int)$f['error']));
    $ext = arquivo_extensao((string)$f['name']);
    if (!isset(EXT_COMPROVANTE[$ext])) throw new RuntimeException('Tipo de arquivo não aceito (.' . e($ext) . '). Envie PDF, imagem, áudio ou documento do Word.');
    if ((int)$f['size'] > 25 * 1048576) throw new RuntimeException('O comprovante passa de 25 MB.');
    $pasta = pasta_dados('busca_ativa/' . date('Y'));
    $nome = $aluno_id . '_' . date('md') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$pasta/$nome")) throw new RuntimeException('Não foi possível salvar o comprovante no servidor.');
    $original = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string)$f['name'])), 0, 200);
    return ['busca_ativa/' . date('Y') . '/' . $nome, $original];
}

/** Campos de "Quem pode ver este gabarito" (criação e edição da prova) */
function prova_visibilidade_campos(string $vis = 'todos', array $selecionados = []): string
{
    $vis = isset(VISIBILIDADE_PROVA[$vis]) ? $vis : 'todos';
    $h = '<div class="pv"><div class="pv-ops" role="radiogroup" aria-label="Quem pode ver">';
    foreach (VISIBILIDADE_PROVA as $k => [$rot, $ic, $desc])
        $h .= '<label class="pv-op"><input type="radio" name="visibilidade" value="' . $k . '"' . ($k === $vis ? ' checked' : '') . '><span><i class="bi bi-' . $ic . '"></i><b>' . e($rot) . '</b><small>' . e($desc) . '</small></span></label>';
    $h .= '</div><div class="pv-pessoas"' . ($vis === 'selecionados' ? '' : ' hidden') . '>'
        . '<input type="search" class="form-control form-control-sm pv-busca" placeholder="Procurar professor…" aria-label="Procurar professor">'
        . '<div class="pv-lista">';
    foreach (prova_pessoas_escolha() as $u)
        $h .= '<label class="pv-p" data-n="' . e(norm($u['nome'])) . '"><input type="checkbox" name="acesso[]" value="' . (int)$u['id'] . '"' . (in_array((int)$u['id'], $selecionados, true) ? ' checked' : '') . '>'
            . avatar($u, 'usuario', 'avatar-xs') . '<span>' . e(primeiro_ultimo($u['nome'])) . '</span><i class="bi bi-check-lg"></i></label>';
    $h .= '</div><small class="pv-conta"></small></div></div>';
    return $h;
}

/** Script dos campos de visibilidade (mostra a lista de pessoas e conta as escolhidas) */
function prova_visibilidade_script(): string
{
    return '<script>
document.querySelectorAll(".pv").forEach(pv => {
  const box = pv.querySelector(".pv-pessoas"), conta = pv.querySelector(".pv-conta"), busca = pv.querySelector(".pv-busca");
  const n = () => { const k = pv.querySelectorAll(".pv-p input:checked").length; conta.textContent = k ? k + " pessoa(s) escolhida(s), além de você" : "Marque quem vai ver, editar e corrigir com você."; };
  pv.querySelectorAll("[name=visibilidade]").forEach(r => r.addEventListener("change", () => { box.hidden = r.value !== "selecionados" || !r.checked; if (!box.hidden) busca.focus(); }));
  pv.querySelectorAll(".pv-p input").forEach(c => c.addEventListener("change", n));
  busca.addEventListener("input", () => { const q = busca.value.trim().toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, ""); pv.querySelectorAll(".pv-p").forEach(l => l.hidden = q && !l.dataset.n.includes(q)); });
  pv.closest("form").addEventListener("submit", e => { const s = pv.querySelector("[name=visibilidade]:checked"); if (s && s.value === "selecionados" && !pv.querySelector(".pv-p input:checked")) { e.preventDefault(); box.hidden = false; conta.textContent = "Escolha pelo menos uma pessoa (ou marque Só eu)."; conta.classList.add("text-danger"); } });
  n();
});
</script>';
}
