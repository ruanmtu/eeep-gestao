<?php
/* =====================================================================
 *  Núcleo do sistema: conexão, sessão, segurança, permissões e ajudantes.
 *  Todas as páginas começam com: require __DIR__ . '/../includes/app.php';
 * ===================================================================== */

require_once __DIR__ . '/carregar_config.php';
require_once __DIR__ . '/escola.php';

if (EXIBIR_ERROS) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        error_log('[sistema-escola] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Erro</title>'
           . '<div style="font-family:sans-serif;max-width:520px;margin:15vh auto;padding:24px;border:1px solid #cfe6d1;border-radius:12px">'
           . '<h2 style="color:#13692a;margin-top:0">Não foi possível concluir esta ação</h2>'
           . (($e instanceof PDOException && in_array($e->getCode(), ['42S02', '42S22'], true))
               ? '<p><strong>O banco de dados está incompleto:</strong> uma tabela ou coluna do sistema não foi encontrada. '
                 . 'Importe o arquivo <code>banco_escola.sql</code> no mesmo banco informado em <code>includes/config.php</code> '
                 . 'e abra <code>verificar.php</code> para conferir.</p>'
               : '<p>Ocorreu um erro interno. Tente novamente; se persistir, avise a coordenação do sistema.</p>'
                 . '<p style="color:#62766a;font-size:13px">Código para o suporte: ' . htmlspecialchars(get_class($e) . ' ' . $e->getCode()) . '</p>')
           . '<p><a href="javascript:history.back()">Voltar</a></p></div>';
    });
}

/* Caminho relativo até a raiz do site (as páginas do painel ficam em /painel) */
define('RAIZ', basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'painel' ? '../' : '');

/* ------------------------------------------------------------------ */
/*  Sessão                                                             */
/* ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ESCOLA_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------------------ */
/*  Banco de dados                                                     */
/* ------------------------------------------------------------------ */
const SISTEMA_VERSAO = '89';

/* ------------------------------------------------------------------ */
/*  Ano letivo (v75)                                                   */
/*  O sistema começa em 2026; a lista cresce sozinha a cada ano e      */
/*  sempre mostra também o ano seguinte (para preparar as turmas).     */
/*  Cada página tem o próprio campo de ano (ano_campo()) e lembra o    */
/*  ano escolhido só para ela; sem escolha, abre no ano atual.        */
/* ------------------------------------------------------------------ */
const ANO_INICIAL = 2026;
function ano_hoje(): int { return max(ANO_INICIAL, (int)date('Y')); }
function anos_letivos(): array { return range(ano_hoje() + 1, ANO_INICIAL); }
(function () {
    $lista = anos_letivos();
    $pag = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $ativa = session_status() === PHP_SESSION_ACTIVE;
    if (isset($_GET['ano_letivo']) && $ativa) {
        $a = (int)$_GET['ano_letivo'];
        if (in_array($a, $lista, true)) { if ($a === ano_hoje()) unset($_SESSION['ano_pag'][$pag]); else $_SESSION['ano_pag'][$pag] = $a; }
    }
    $a = $ativa ? (int)($_SESSION['ano_pag'][$pag] ?? 0) : 0;
    define('ANO_VER', in_array($a, $lista, true) ? $a : ano_hoje());
})();
function vendo_outro_ano(): bool { return ANO_VER !== ano_hoje(); }
/** Primeiro e último dia do ano escolhido (para filtrar registros que têm data) */
function ano_inicio(): string { return ANO_VER . '-01-01'; }
function ano_fim(): string { return ANO_VER . '-12-31'; }
/** Data/mês que as telas abrem por padrão: hoje no ano atual; o fim do ano escolhido nos anos anteriores */
function data_padrao(): string { return vendo_outro_ano() ? min(ano_fim(), date('Y-m-d', strtotime(ANO_VER . '-12-15'))) : date('Y-m-d'); }
function mes_padrao(): string { return substr(data_padrao(), 0, 7); }
/** Link da página atual trocando só o ano */
/** Campo de ano letivo que cada página coloca no próprio conteúdo */
function ano_campo(string $rotulo = 'Ano letivo'): string
{
    $q = $_GET; unset($q['ano_letivo'], $q['ano']);
    $ocultos = '';
    foreach ($q as $k => $v) if (is_scalar($v)) $ocultos .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    $op = '';
    foreach (anos_letivos() as $a) $op .= '<option value="' . $a . '"' . ($a === ANO_VER ? ' selected' : '') . '>' . $a . ($a === ano_hoje() ? ' (atual)' : ($a > ano_hoje() ? ' (próximo)' : '')) . '</option>';
    return '<form method="get" class="ano-campo' . (vendo_outro_ano() ? ' outro' : '') . '">' . $ocultos
         . '<label><i class="bi bi-calendar3"></i><span>' . e($rotulo) . '</span>'
         . '<select name="ano_letivo" onchange="this.form.submit()" aria-label="' . e($rotulo) . '">' . $op . '</select></label>'
         . (vendo_outro_ano() ? '<a href="' . e(url_ano(ano_hoje())) . '">voltar para ' . ano_hoje() . '</a>' : '')
         . '<noscript><button class="btn btn-sm btn-light">Ver</button></noscript></form>';
}
function url_ano(int $a): string
{
    $q = $_GET; unset($q['ano_letivo'], $q['ano']); $q['ano_letivo'] = $a;
    return strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $opcoes = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Usado apenas em testes locais (SQLite). No servidor, sempre MySQL.
    if ($sqlite = getenv('ESCOLA_SQLITE')) {
        $pdo = new PDO('sqlite:' . $sqlite, null, null, $opcoes);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->sqliteCreateFunction('MONTH', fn($d) => $d ? (int)substr($d, 5, 2) : null, 1);
        $pdo->sqliteCreateFunction('YEAR', fn($d) => $d ? (int)substr($d, 0, 4) : null, 1);
        $pdo->sqliteCreateFunction('DAY', fn($d) => $d ? (int)substr($d, 8, 2) : null, 1);
        $pdo->sqliteCreateFunction('DAYOFWEEK', fn($d) => $d ? (int)date('w', strtotime($d)) + 1 : null, 1);
        $pdo->sqliteCreateFunction('CONCAT', fn(...$a) => implode('', $a));
        $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('FIELD', function ($v, ...$l) { $i = array_search($v, $l); return $i === false ? 0 : $i + 1; });
        return $pdo;
    }

    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opcoes);
        $pdo->exec("SET time_zone = '-03:00'");
        $pdo->exec('SET @ano_ver = ' . (int)ANO_VER);   // v75: ano letivo escolhido, usado nas consultas
    } catch (PDOException $e) {
        error_log('[sistema-escola] Falha na conexão: ' . $e->getMessage());
        http_response_code(503);
        exit('<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:520px;margin:15vh auto">'
           . '<h2 style="color:#13692a">Sistema temporariamente indisponível</h2>'
           . '<p>Não foi possível conectar ao banco de dados. Confira os dados em <code>includes/config.php</code>.</p></div>');
    }
    return $pdo;
}

/** Executa uma consulta preparada e devolve o statement */
function q(string $sql, array $params = []): PDOStatement
{
    // v75: @ano_ver = ano letivo escolhido (variável da conexão no MySQL; no SQLite de testes vira o número)
    if (str_contains($sql, '@ano_ver') && db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') $sql = str_replace('@ano_ver', (string)ANO_VER, $sql);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q_todos(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function q_um(string $sql, array $p = []) { return q($sql, $p)->fetch(); }
function q_valor(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

/* ------------------------------------------------------------------ */
/*  Saída segura, mensagens e redirecionamento                         */
/* ------------------------------------------------------------------ */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function redirecionar(string $url): void { header('Location: ' . $url); exit; }

function flash(string $tipo, string $msg): void { $_SESSION['flash'][] = [$tipo, $msg]; }

function flash_exibir(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        [$tipo, $msg] = $f; $html_ok = !empty($f[2]);   // [2] = mensagem já montada em HTML seguro
        $icone = ['success' => 'check-circle-fill', 'danger' => 'exclamation-octagon-fill', 'warning' => 'exclamation-triangle-fill'][$tipo] ?? 'info-circle-fill';
        $html .= '<div class="alert alert-' . e($tipo) . ' alert-dismissible fade show d-flex gap-2 align-items-start" role="alert">'
              . '<i class="bi bi-' . $icone . '"></i><div>' . ($html_ok ? $msg : e($msg)) . '</div>'
              . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/* ------------------------------------------------------------------ */
/*  Proteção contra envio de formulário forjado (CSRF)                 */
/* ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_campo(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_verificar(): void
{
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Sessão expirada ou formulário inválido. Volte, recarregue a página e tente de novo.');
    }
}
function eh_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

/* ------------------------------------------------------------------ */
/*  Usuários, perfis e permissões                                      */
/* ------------------------------------------------------------------ */
/* Perfis padrão (os nomes e as permissões ficam no banco e podem ser ajustados) */
const PERFIS = [
    'admin'       => 'Administrador',
    'diretor'     => 'Diretor(a)',
    'coordenacao' => 'Coordenação',
    'secretaria'  => 'Secretaria',
    'professor'   => 'Professor(a)',
    'bibliotecario' => 'Professor(a) bibliotecário(a)',
    'financeiro'  => 'Assistente financeiro',
];

/* Catálogo de permissões: grupo => [chave => [rótulo, explicação]] */
const PERMISSOES_CATALOGO = [
    'Alunos' => [
        'alunos.ver'            => ['Ver alunos e fichas', 'Lista de alunos, ficha completa e boletim'],
        'alunos.editar'         => ['Inserir e editar alunos', 'Ficha do aluno: nome, turma, foto, responsável e observação médica'],
        'alunos.excluir'        => ['Excluir alunos', 'Apaga o aluno e todo o histórico'],
    ],
    'Diário de classe' => [
        'frequencia'            => ['Coleta de faltas', 'Lançar as faltas do dia e imprimir o relatório de quem faltou'],
        'frequencia.justificar' => ['Justificar e remover faltas', ''],
        'saidas'                => ['Autorizações (pedir)', 'Pedir liberação de saída e outras autorizações; o pedido vai para a coordenação'],
        'saidas.autorizar'      => ['Confirmar e imprimir autorizações', 'Recebe os pedidos dos professores, confirma e imprime o cupom'],
        'saidas.excluir'        => ['Cancelar autorizações', ''],
        'nota_conceitual'       => ['Nota conceitual', 'Avaliar os alunos nos 3 aspectos (pedagógico, disciplinar e atitudinal) nas turmas e disciplinas atribuídas'],
        'nota_conceitual.gerir' => ['Gerenciar a nota conceitual', 'Ver o andamento de todas as turmas e definir os critérios'],
        'notas'                 => ['Ver notas', 'Consultar as notas das turmas (só leitura). As notas entram só pela planilha do Power BI'],
        'notas.excluir'         => ['Excluir notas importadas', ''],
        'provas'                => ['Correção de provas', 'Gabaritos e leitura óptica'],
        'provas.excluir'        => ['Apagar provas e notas corrigidas', ''],
        'turmas.todas'          => ['Todas as turmas', 'Sem isto, a pessoa só vê as turmas e disciplinas atribuídas a ela'],
        'horarios.todos'        => ['Horário de todos os professores', 'Sem isto, a pessoa vê só o próprio horário'],
        'horarios.editar'       => ['Montar os horários', 'Cadastrar e alterar a grade de aulas'],
    ],
    'Escola' => [
        'livros_didaticos'      => ['Livros didáticos digitais', 'Ler e baixar os livros em PDF (manual do professor)'],
        'livros_didaticos.gerenciar' => ['Enviar livros didáticos', 'Subir, editar e apagar os PDFs dos livros'],
        'biblioteca'            => ['Centro de Multimeios', 'Acervo, fichas e empréstimos'],
        'biblioteca.excluir'    => ['Tirar livros do acervo', ''],
        'arquivos.enviar'       => ['Enviar arquivos na Central', 'Subir, editar e apagar arquivos (todos podem ver e baixar)'],
        'calendario.editar'     => ['Atualizar o Calendário SIGE', 'Trocar o PDF do calendário (todos podem ver)'],
        'reservas'              => ['Reservar ambientes e equipamentos', 'Auditório, laboratórios, projetores...'],
        'reservas.agenda'       => ['Agenda de agendamentos e retiradas', 'Ver a agenda de todos os agendamentos e registrar a retirada e a devolução de equipamentos (Centro de Multimeios)'],
        'reservas.gerenciar'    => ['Gerenciar reservas', 'Cancelar reservas de outras pessoas e cadastrar ambientes e equipamentos'],
    ],
    'Acompanhamento' => [
        'ocorrencias'           => ['Registrar ocorrências', 'Advertências, retiradas de sala, suspensões...'],
        'ocorrencias.todas'     => ['Ver todas as ocorrências', 'Números e registros de todos os alunos. Sem ela, a pessoa vê só as ocorrências que registrou'],
        'ocorrencias.excluir'   => ['Apagar ocorrências', ''],
        'alertas.tratar'        => ['Tratar alertas de faltas', 'Registrar contato com a família'],
        'busca_ativa'           => ['Busca ativa', 'Registrar ligações, ofícios, visitas e demais providências na ficha do aluno'],
        'relatorios'            => ['Relatórios da escola', 'O diretor de turma vê os da turma dele em "Minha turma"'],
        'apresentacao'          => ['Apresentação de resultados', 'Slides e PDF para reuniões'],
    ],
    'Comunicação' => [
        'ia'                    => ['Usar o assistente de IA', 'Assistente pedagógico no painel inicial (cota diária por pessoa)'],
        'site.publicar'         => ['Publicar no site', 'Álbuns de fotos e informes da página da escola'],
        'ia.config'             => ['Configurar o assistente de IA', 'Chave da API do Google, cotas e relatório de uso (só o administrador, por padrão)'],
        'notificacoes.enviar'   => ['Enviar notificações', 'Mandar avisos para todos ou para grupos (professores, coordenação...)'],
    ],
    'Administração' => [
        'importar'              => ['Importar planilha', 'Alimentar o sistema com a planilha do Power BI'],
        'config'                => ['Cursos, turmas e calendário letivo', 'Inclui as datas dos bimestres'],
        'usuarios'              => ['Usuários e permissões', 'Criar usuários e ajustar o que cada um acessa'],
    ],
];

/* Permissões de fábrica de cada perfil (valem até o administrador personalizar o perfil) */
const PERMISSOES = [
    'alunos.ver'            => ['diretor', 'coordenacao', 'secretaria', 'professor', 'financeiro', 'bibliotecario'],
    'alunos.editar'         => ['diretor', 'coordenacao', 'secretaria'],
    'alunos.excluir'        => [],
    'frequencia'            => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'frequencia.justificar' => ['diretor', 'coordenacao', 'secretaria'],
    'saidas'                => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'saidas.autorizar'      => ['diretor', 'coordenacao'],
    'saidas.excluir'        => ['diretor', 'coordenacao'],
    'notificacoes.enviar'   => ['diretor', 'coordenacao'],
    'notas'                 => ['diretor', 'coordenacao', 'secretaria', 'professor'],
    'nota_conceitual'       => ['diretor', 'coordenacao', 'professor'],
    'nota_conceitual.gerir' => ['diretor', 'coordenacao'],
    'provas'                => ['diretor', 'coordenacao', 'professor', 'bibliotecario'],
    'provas.excluir'        => ['diretor', 'coordenacao'],
    'turmas.todas'          => ['diretor', 'coordenacao', 'secretaria', 'financeiro'],
    'horarios.todos'        => ['diretor', 'coordenacao', 'secretaria'],
    'horarios.editar'       => ['diretor', 'coordenacao'],
    'reservas'              => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'reservas.agenda'       => ['diretor', 'coordenacao', 'bibliotecario'],
    'reservas.gerenciar'    => ['diretor', 'coordenacao'],
    'livros_didaticos'      => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'livros_didaticos.gerenciar' => ['diretor', 'coordenacao'],
    'biblioteca'            => ['diretor', 'coordenacao', 'secretaria', 'bibliotecario'],
    'biblioteca.excluir'    => ['diretor', 'coordenacao', 'secretaria'],
    'arquivos.enviar'       => ['diretor', 'coordenacao', 'secretaria'],
    'calendario.editar'     => ['diretor', 'coordenacao', 'secretaria'],
    'ocorrencias'           => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'ocorrencias.todas'     => ['diretor', 'coordenacao', 'secretaria'],
    'ocorrencias.excluir'   => ['diretor', 'coordenacao'],
    'alertas.tratar'        => ['diretor', 'coordenacao', 'secretaria'],
    'busca_ativa'           => ['diretor', 'coordenacao', 'secretaria'],
    'relatorios'            => ['diretor', 'coordenacao', 'secretaria', 'financeiro'],
    'apresentacao'          => ['diretor', 'coordenacao', 'secretaria', 'financeiro', 'bibliotecario'],
    'importar'              => ['diretor'],
    'config'                => ['diretor', 'coordenacao'],
    'usuarios'              => [],
    'notas.excluir'         => ['diretor'],
    'ia'                    => ['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'],
    'ia.config'             => [],
    'site.publicar'         => ['diretor', 'coordenacao', 'secretaria'],
];

/** Cor de cada curso (mesma em todo o sistema): Administração azul, Desenvolvimento de Sistemas cinza, Enfermagem verde, Informática vermelho */
function cor_curso(?string $nome): string
{
    $n = mb_strtolower((string)$nome);
    if (str_contains($n, 'administra')) return '#1d5fb0';
    if (str_contains($n, 'desenvolv') || str_contains($n, 'sistemas')) return '#5b6670';
    if (str_contains($n, 'enferm')) return '#13692a';
    if (str_contains($n, 'inform')) return '#c0261b';
    return '#0f8a8a';
}

/** Todas as chaves de permissão */
function permissoes_todas(): array
{
    $t = [];
    foreach (PERMISSOES_CATALOGO as $g) foreach ($g as $k => $_) $t[] = $k;
    return $t;
}

/** Perfis cadastrados [chave => linha]; cai nos padrões se a tabela ainda não existir */
function perfis_lista(): array
{
    static $p = null;
    if ($p !== null) return $p;
    try {
        $p = [];
        foreach (q_todos('SELECT * FROM perfis ORDER BY ordem, nome') as $r) $p[$r['chave']] = $r;
    } catch (PDOException $e) { $p = []; }
    if (!$p) foreach (PERFIS as $k => $n) $p[$k] = ['chave' => $k, 'nome' => $n, 'descricao' => '', 'cor' => '', 'sistema' => 1, 'personalizado' => 0, 'ordem' => 0];
    return $p;
}

function perfil_nome(?string $chave): string { return perfis_lista()[$chave ?? '']['nome'] ?? (PERFIS[$chave ?? ''] ?? (string)$chave); }

/** Permissões de um perfil (as gravadas, se personalizado; senão as de fábrica) */
function permissoes_perfil(string $perfil): array
{
    if ($perfil === 'admin') return permissoes_todas();
    $info = perfis_lista()[$perfil] ?? null;
    if ($info && (int)$info['personalizado']) {
        try { return array_column(q_todos('SELECT permissao FROM perfil_permissoes WHERE perfil = ?', [$perfil]), 'permissao'); }
        catch (PDOException $e) {}
    }
    return array_keys(array_filter(PERMISSOES, fn($perfis) => in_array($perfil, $perfis, true)));
}

/** Exceções individuais válidas hoje: [permissao => 'conceder'|'remover'] */
function excecoes_usuario(int $usuario_id): array
{
    try {
        $r = [];
        foreach (q_todos('SELECT permissao, efeito FROM usuario_permissoes WHERE usuario_id = ? AND (validade IS NULL OR validade >= ?)', [$usuario_id, date('Y-m-d')]) as $l) $r[$l['permissao']] = $l['efeito'];
        return $r;
    } catch (PDOException $e) { return []; }
}

/** Permissões efetivas de um usuário (perfil + exceções) */
function permissoes_usuario(array $u): array
{
    if (($u['perfil'] ?? '') === 'admin') return permissoes_todas();
    /* v64: o acesso vem só do perfil (Usuários › Perfis e permissões). Ajustes individuais antigos ficam guardados, mas não valem mais. */
    return permissoes_perfil((string)$u['perfil']);
}

function usuario(): ?array { return $_SESSION['usuario'] ?? null; }

/** Função que aparece nas assinaturas (ex.: "Diretor geral", "Professor de Informática") */
function cargo_usuario(?array $u = null): string
{
    $u = $u ?? usuario();
    return trim((string)($u['cargo'] ?? '')) ?: perfil_nome($u['perfil'] ?? '');
}

/** Linha "Impresso por" usada em todos os documentos */
function impresso_por(): string
{
    $u = usuario();
    return 'Impresso por ' . ($u['nome'] ?? '—') . ' (' . cargo_usuario($u) . ') em ' . date('d/m/Y \à\s H:i');
}

/** Cabeçalho de impressão padrão (aparece só no papel/PDF) */
function cabecalho_impressao(string $titulo, string $sub = ''): string
{
    global $ESCOLA;
    return '<div class="so-impressao"><div class="cab-impressao"><img src="' . RAIZ . 'assets/img/logo-doc-2026.png" alt="">'
         . '<div><strong style="font-size:15px">' . e($titulo) . '</strong>' . ($sub ? '<br><span>' . e($sub) . '</span>' : '') . '</div>'
         . '<div class="dir">' . e($ESCOLA['nome_oficial']) . '<br>' . e(impresso_por()) . '</div></div></div>';
}
function uid(): ?int { return isset($_SESSION['usuario']) ? (int)$_SESSION['usuario']['id'] : null; }

function pode(string $perm): bool
{
    static $cache = [];
    $u = usuario();
    if (!$u) return false;
    if ($perm === '') return true;                      // só precisa estar logado
    $cache[$u['id']] ??= array_flip(permissoes_usuario($u));
    return isset($cache[$u['id']][$perm]);
}

/** Sem "Todas as turmas", a pessoa só trabalha com as turmas atribuídas a ela */
function restrito_a_atribuicoes(): bool { return !pode('turmas.todas'); }

/** Nome que aparece no sistema (nome de exibição ou primeiro + último nome) */
function nome_exibicao(?array $u = null): string
{
    $u = $u ?? usuario();
    if (trim((string)($u['nome_exibicao'] ?? '')) !== '') return trim($u['nome_exibicao']);
    return primeiro_ultimo((string)($u['nome'] ?? ''));
}

function primeiro_ultimo(string $nome): string
{
    $p = preg_split('/\s+/', trim($nome));
    $p = array_values(array_filter($p, fn($x) => $x !== ''));
    if (count($p) <= 1) return $p[0] ?? '';
    return $p[0] . ' ' . end($p);
}

function exigir_login(): void
{
    if (!usuario()) {
        // guarda a página pedida para voltar a ela depois de entrar
        if (RAIZ === '../' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $pg = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
            if (preg_match('/^[a-z0-9_]+\.php$/', $pg) && $pg !== 'index.php') $_SESSION['voltar'] = 'painel/' . $pg . ($qs !== '' && preg_match('/^[\w=&%.\-]{1,200}$/', $qs) ? '?' . $qs : '');
        }
        flash('warning', 'Entre com seu e-mail e senha para acessar o sistema.');
        redirecionar(RAIZ . 'login.php');
    }
    // expira após 2h sem uso
    if (time() - ($_SESSION['ultimo_uso'] ?? time()) > 7200) {
        session_unset();
        flash('warning', 'Sua sessão expirou por inatividade. Entre novamente.');
        redirecionar(RAIZ . 'login.php');
    }
    $_SESSION['ultimo_uso'] = time();

    // confere se o usuário continua ativo
    $u = q_um('SELECT * FROM usuarios WHERE id = ?', [uid()]);
    if ($u) unset($u['senha_hash']);
    if (!$u || !$u['ativo']) {
        session_unset();
        flash('danger', 'Seu acesso foi desativado. Procure a coordenação.');
        redirecionar(RAIZ . 'login.php');
    }
    $_SESSION['usuario'] = $u;
    if ($u['trocar_senha'] && basename($_SERVER['SCRIPT_NAME']) !== 'perfil.php') {
        redirecionar('perfil.php');   // primeiro acesso: a tela explica o que fazer
    }
}

function exigir(string $perm): void
{
    exigir_login();
    if (!pode($perm)) {
        http_response_code(403);
        flash('danger', 'Seu perfil não tem acesso a essa área.');
        redirecionar('index.php');
    }
}

function registrar_log(string $acao, ?string $detalhe = null, ?int $aluno_id = null): void
{
    try {
        q('INSERT INTO log_atividades (usuario_id, aluno_id, acao, detalhe, ip) VALUES (?, ?, ?, ?, ?)',
          [uid(), $aluno_id, $acao, $detalhe ? mb_substr($detalhe, 0, 255) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { /* o log nunca deve impedir a operação */ }
}

/* ------------------------------------------------------------------ */
/*  Dados de apoio                                                     */
/* ------------------------------------------------------------------ */
const SITUACOES = ['ativo' => 'Ativo', 'transferido' => 'Transferido', 'desistente' => 'Desistente', 'concluido' => 'Concluído'];

const TIPOS_OCORRENCIA = [
    'disciplinar' => 'Disciplinar', 'pedagogica' => 'Pedagógica', 'saude' => 'Saúde',
    'elogio' => 'Elogio', 'outro' => 'Outro',
];
const GRAVIDADES = ['leve' => 'Leve', 'media' => 'Média', 'grave' => 'Grave'];

const TIPOS_ATO = [
    'advertencia_verbal'     => 'Advertência verbal',
    'advertencia_escrita'    => 'Advertência escrita',
    'suspensao'              => 'Suspensão',
    'convocacao_responsavel' => 'Convocação do responsável',
    'termo_compromisso'      => 'Termo de compromisso',
    'declaracao'             => 'Declaração',
    'transferencia'          => 'Transferência',
    'outro'                  => 'Outro',
];

const DIAS_SEMANA = [1 => 'Domingo', 2 => 'Segunda', 3 => 'Terça', 4 => 'Quarta', 5 => 'Quinta', 6 => 'Sexta', 7 => 'Sábado'];
const MESES = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const MESES_CURTO = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

function cursos_lista(bool $so_ativos = true): array
{
    return q_todos('SELECT * FROM cursos' . ($so_ativos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome');
}

/** Turmas do ano letivo atual, com o nome do curso */
function turmas_lista(bool $so_ativas = true, ?int $ano = null): array
{
    return q_todos(
        'SELECT t.*, c.nome AS curso, c.sigla
           FROM turmas t JOIN cursos c ON c.id = t.curso_id
          WHERE t.ano_letivo = ?' . ($so_ativas ? ' AND t.ativo = 1' : '') . '
          ORDER BY t.serie, t.letra, c.nome',
        [$ano ?? ANO_VER]
    );
}

function turma_nome(?array $t, bool $com_curso = true): string
{
    if (!$t || empty($t['serie'])) return 'Sem turma';
    $n = $t['serie'] . 'º Ano ' . $t['letra'];
    return $com_curso && !empty($t['curso']) ? $n . ' · ' . $t['curso'] : $n;
}

/** Classificação de faltas: ok, atencao ou critico */
function nivel_faltas(int $n): string
{
    return $n >= FALTAS_CRITICO ? 'critico' : ($n >= FALTAS_ATENCAO ? 'atencao' : 'ok');
}
function badge_faltas(int $n): string
{
    $cls = ['ok' => 'faltas-ok', 'atencao' => 'faltas-atencao', 'critico' => 'faltas-critico'][nivel_faltas($n)];
    return '<span class="badge-faltas ' . $cls . '">' . $n . '</span>';
}

function data_br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function data_hora_br(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function dia_semana(string $d): string { return DIAS_SEMANA[(int)date('w', strtotime($d)) + 1]; }

/** Valida data no formato AAAA-MM-DD */
function data_valida(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$a, $m, $dia] = array_map('intval', explode('-', $d));
    return checkdate($m, $dia, $a);
}

function iniciais(string $nome): string
{
    $p = preg_split('/\s+/', trim($nome));
    $i = mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : '');
    return mb_strtoupper($i);
}

function nome_proprio(string $nome): string
{
    $nome = mb_convert_case(mb_strtolower(trim($nome)), MB_CASE_TITLE, 'UTF-8');
    return preg_replace_callback('/\b(Da|De|Do|Das|Dos|E)\b/u', fn($m) => mb_strtolower($m[1]), $nome);
}

/** Envia uma tabela como arquivo CSV (abre no Excel) */
function enviar_csv(string $arquivo, array $cabecalho, array $linhas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // acentos corretos no Excel
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $l) fputcsv($out, $l, ';');
    fclose($out);
    exit;
}

require_once __DIR__ . '/servicos.php';
require_once __DIR__ . '/omr.php';
require_once __DIR__ . '/frequencia.php';
require_once __DIR__ . '/avaliacoes.php';
require_once __DIR__ . '/biblioteca.php';
require_once __DIR__ . '/importacao.php';
require_once __DIR__ . '/arquivos.php';
require_once __DIR__ . '/ocorrencias.php';
require_once __DIR__ . '/autorizacoes.php';
require_once __DIR__ . '/reservas.php';
require_once __DIR__ . '/notificacoes.php';
require_once __DIR__ . '/busca_ativa.php';
require_once __DIR__ . '/diretor_turma.php';
require_once __DIR__ . '/agenda.php';
require_once __DIR__ . '/chamada_aula.php';
require_once __DIR__ . '/extras.php';
require_once __DIR__ . '/equipe.php';
require_once __DIR__ . '/site_conteudo.php';
site_aplicar_contato($ESCOLA);
require_once __DIR__ . '/janela.php';
require_once __DIR__ . '/nota_conceitual.php';
nc_migrar_auto();
require_once __DIR__ . '/v74.php';
v74_migrar();
require_once __DIR__ . '/v75.php'; v75_migrar(); aluno_turmas_talvez();
require_once __DIR__ . '/interclasse.php'; ic_migrar();
require_once __DIR__ . '/v79.php'; v79_migrar();
require_once __DIR__ . '/google_login.php';
require __DIR__ . '/resultados.php';
require_once __DIR__ . '/livros_didaticos.php';

function num($v, int $casas = 1): string { return number_format((float)$v, $casas, ',', '.'); }
