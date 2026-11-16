<?php
/* =====================================================================
 *  VERIFICAÇÃO DA INSTALAÇÃO
 *  Abra este arquivo no navegador (seusite/verificar.php) para conferir
 *  se o PHP e o banco de dados estão prontos. Não mostra senhas.
 *  Também cria as tabelas automaticamente (botão verde), usando
 *  includes/banco_escola.sql.
 *  >>> Apague este arquivo quando tudo estiver funcionando. <<<
 * ===================================================================== */
require __DIR__ . '/includes/carregar_config.php';

$itens = [];
$ok = fn(bool $c, string $t, string $dica = '') => $GLOBALS['itens'][] = [$c, $t, $dica];

$ok(version_compare(PHP_VERSION, '8.0.0', '>='), 'Versão do PHP: ' . PHP_VERSION, 'Use PHP 8.1 ou mais novo (hPanel › Avançado › Configuração do PHP).');
$ok(extension_loaded('pdo_mysql'), 'Extensão pdo_mysql', 'Ative a extensão pdo_mysql na configuração do PHP.');
$ok(extension_loaded('mbstring'), 'Extensão mbstring', 'Ative a extensão mbstring na configuração do PHP.');
$ok(is_writable(session_save_path() ?: sys_get_temp_dir()), 'Pasta de sessões com permissão de escrita');

$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ok(true, 'Conexão com o banco "' . DB_NAME . '" em ' . DB_HOST);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    $dica = str_contains($msg, 'Access denied') ? 'Usuário ou senha do banco incorretos, ou o usuário não foi ligado ao banco com todos os privilégios.'
          : (str_contains($msg, 'Unknown database') ? 'O nome do banco em config.php não existe. Copie o nome exato do painel (ex.: u123456789_escola).'
          : 'Confira DB_HOST, DB_NAME, DB_USER e DB_PASS em includes/config.php.');
    $ok(false, 'Conexão com o banco "' . DB_NAME . '" em ' . DB_HOST . ': ' . preg_replace('/\(using password.*?\)/', '', $msg), $dica);
}

/* Colunas que versões novas acrescentaram em tabelas que já existiam */
$alteracoes = [
    ['usuarios', 'cargo', 'ALTER TABLE usuarios ADD COLUMN cargo VARCHAR(80) NULL AFTER perfil'],
    ['usuarios', 'nome_exibicao', 'ALTER TABLE usuarios ADD COLUMN nome_exibicao VARCHAR(60) NULL AFTER nome'],
    ['usuarios', 'foto', 'ALTER TABLE usuarios ADD COLUMN foto VARCHAR(160) NULL'],
    ['alunos', 'foto', 'ALTER TABLE alunos ADD COLUMN foto VARCHAR(160) NULL'],
    ['ocorrencias', 'hora', 'ALTER TABLE ocorrencias ADD COLUMN hora TIME NULL AFTER data_ocorrencia'],
    ['ocorrencias', 'categoria', "ALTER TABLE ocorrencias ADD COLUMN categoria VARCHAR(40) NOT NULL DEFAULT 'outro' AFTER hora"],
    ['ocorrencias', 'local', 'ALTER TABLE ocorrencias ADD COLUMN local VARCHAR(60) NULL AFTER categoria'],
    ['ocorrencias', 'suspensao_inicio', 'ALTER TABLE ocorrencias ADD COLUMN suspensao_inicio DATE NULL AFTER local'],
    ['ocorrencias', 'suspensao_fim', 'ALTER TABLE ocorrencias ADD COLUMN suspensao_fim DATE NULL AFTER suspensao_inicio'],
    ['ocorrencias', 'ato_id', 'ALTER TABLE ocorrencias ADD COLUMN ato_id INT UNSIGNED NULL AFTER suspensao_fim, ADD UNIQUE KEY uk_oc_ato (ato_id)'],
    ['saidas', 'tipo', "ALTER TABLE saidas ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'saida' AFTER codigo, ADD KEY idx_saidas_tipo (tipo)"],
    ['saidas', 'titulo', 'ALTER TABLE saidas ADD COLUMN titulo VARCHAR(80) NULL AFTER tipo'],
    ['saidas', 'item', 'ALTER TABLE saidas ADD COLUMN item VARCHAR(60) NULL AFTER titulo'],
    ['saidas', 'local', 'ALTER TABLE saidas ADD COLUMN local VARCHAR(60) NULL AFTER item'],
    ['saidas', 'horario', 'ALTER TABLE saidas ADD COLUMN horario VARCHAR(60) NULL AFTER local'],
    ['saidas', 'validade_fim', 'ALTER TABLE saidas ADD COLUMN validade_fim DATE NULL AFTER horario'],
    ['provas', 'formato_externo', 'ALTER TABLE provas ADD COLUMN formato_externo TEXT NULL AFTER gabarito'],
    ['prova_resultados', 'partes', 'ALTER TABLE prova_resultados ADD COLUMN partes TEXT NULL AFTER nota'],
    ['saidas', 'status', "ALTER TABLE saidas ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'autorizada' AFTER validade_fim, ADD KEY idx_saidas_status (status)"],
    ['saidas', 'solicitado_por', 'ALTER TABLE saidas ADD COLUMN solicitado_por INT UNSIGNED NULL AFTER status'],
    ['saidas', 'solicitado_nome', 'ALTER TABLE saidas ADD COLUMN solicitado_nome VARCHAR(120) NULL AFTER solicitado_por'],
    ['saidas', 'solicitado_em', 'ALTER TABLE saidas ADD COLUMN solicitado_em DATETIME NULL AFTER solicitado_nome'],
    ['provas', 'hora_inicio', 'ALTER TABLE provas ADD COLUMN hora_inicio TIME NULL AFTER formato_externo'],
    ['provas', 'hora_fim', 'ALTER TABLE provas ADD COLUMN hora_fim TIME NULL AFTER hora_inicio'],
    ['provas', 'aplicador', 'ALTER TABLE provas ADD COLUMN aplicador VARCHAR(160) NULL AFTER hora_fim'],
    ['provas', 'modelo_folha', 'ALTER TABLE provas ADD COLUMN modelo_folha TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER aplicador'],
    ['turmas', 'diretor_turma_id', 'ALTER TABLE turmas ADD COLUMN diretor_turma_id INT UNSIGNED NULL AFTER turno, ADD KEY idx_turma_dt (diretor_turma_id)'],
    ['saidas', 'recusa_motivo', 'ALTER TABLE saidas ADD COLUMN recusa_motivo VARCHAR(255) NULL AFTER solicitado_em'],
    ['eventos', 'publico', 'ALTER TABLE eventos ADD COLUMN publico TINYINT(1) NOT NULL DEFAULT 1 AFTER tipo'],
    ['site_banners', 'imagem_larga', 'ALTER TABLE site_banners ADD COLUMN imagem_larga VARCHAR(255) NULL AFTER imagem'],
    ['reservas', 'retirada_em', 'ALTER TABLE reservas ADD COLUMN retirada_em DATETIME NULL, ADD COLUMN retirada_tipo VARCHAR(20) NULL, ADD COLUMN retirada_nome VARCHAR(120) NULL, ADD COLUMN retirada_obs VARCHAR(255) NULL, ADD COLUMN retirada_registro INT UNSIGNED NULL, ADD COLUMN devolvida_em DATETIME NULL'],
    ['site_informes', 'subtitulo', 'ALTER TABLE site_informes ADD COLUMN subtitulo VARCHAR(255) NULL AFTER titulo'],
    ['site_informes', 'imagem_foco', 'ALTER TABLE site_informes ADD COLUMN imagem_foco VARCHAR(20) NULL AFTER imagem'],
    ['site_informes', 'data_evento', 'ALTER TABLE site_informes ADD COLUMN data_evento DATE NULL AFTER data_publicacao'],
    ['alunos', 'obs_medica', 'ALTER TABLE alunos ADD COLUMN tem_obs_medica TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN obs_medica TEXT NULL'],
    ['equipe_site', 'biografia', 'ALTER TABLE equipe_site ADD COLUMN formacao VARCHAR(255) NULL, ADD COLUMN biografia TEXT NULL'],
];
/* Versão 59: nota conceitual; notas só pela planilha do Power BI (diretor) */
$migrar_v59 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v59'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao', 'professor'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'nota_conceitual']);
    foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'nota_conceitual.gerir']);
    $pdo->exec("DELETE FROM perfil_permissoes WHERE permissao IN ('notas', 'notas.excluir', 'importar') AND perfil <> 'diretor'");
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v59', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 26: publicar no site (galeria, informes e equipe) */
$migrar_v26 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v26'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao', 'secretaria'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'site.publicar']);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v26', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 20: assistente de IA para quem trabalha com ensino */
$migrar_v20 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v20'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao', 'secretaria', 'professor', 'bibliotecario'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'ia']);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v20', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 14: painel enxuto do professor; Multimeios e Apresentação só com o professor bibliotecário; notas voltam para o professor */
$migrar_v14 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v14'")->fetchColumn()) return;
    $pdo->exec("DELETE FROM perfil_permissoes WHERE perfil = 'professor' AND permissao IN ('busca_ativa', 'biblioteca', 'apresentacao', 'relatorios')");
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    if (in_array('professor', $pers, true)) $ins->execute(['professor', 'notas']);
    foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'notas.excluir']);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v14', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 13: excluir provas só com a gestão; relatórios da escola saem do professor (o DT vê os da turma em "Minha turma") */
$migrar_v13 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v13'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'provas.excluir']);
    $pdo->exec("DELETE FROM perfil_permissoes WHERE perfil = 'professor' AND permissao = 'relatorios'");
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v13', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 10: livros didáticos digitais */
$migrar_v10 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v10'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao', 'secretaria', 'professor'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'livros_didaticos']);
    foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'livros_didaticos.gerenciar']);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v10', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 9: busca ativa (registro das providências) */
$migrar_v9 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v9'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['diretor', 'coordenacao', 'secretaria', 'professor'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, 'busca_ativa']);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v9', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 8: coordenação confirma e imprime as liberações; notificações */
$migrar_v8 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v8'")->fetchColumn()) return;
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach (['saidas.autorizar', 'notificacoes.enviar'] as $perm) foreach (['diretor', 'coordenacao'] as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, $perm]);
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v8', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 7: tablet passou a ser o aparelho pessoal do aluno (não há mais "Tablet 01" da escola) */
$migrar_v7 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v7'")->fetchColumn()) return;
    $pdo->exec("UPDATE saidas SET item = NULL WHERE tipo = 'tablet' AND item REGEXP '^Tablet [0-9]+$'");
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v7', '" . date('Y-m-d H:i:s') . "')");
};
/* Versão 6: novas permissões nos perfis já personalizados e notas só pela gestão */
$migrar_permissoes_v6 = function (PDO $pdo): void {
    if ($pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v6'")->fetchColumn()) return;
    $novas = ['reservas' => ['diretor', 'coordenacao', 'secretaria', 'professor'], 'reservas.gerenciar' => ['diretor', 'coordenacao'],
              'horarios.todos' => ['diretor', 'coordenacao', 'secretaria'], 'horarios.editar' => ['diretor', 'coordenacao']];
    $pers = $pdo->query('SELECT chave FROM perfis WHERE personalizado = 1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil, permissao) VALUES (?, ?)');
    foreach ($novas as $perm => $perfis) foreach ($perfis as $pf) if (in_array($pf, $pers, true)) $ins->execute([$pf, $perm]);
    $pdo->exec("DELETE FROM perfil_permissoes WHERE perfil = 'professor' AND permissao = 'notas'");
    $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('migracao_v6', '" . date('Y-m-d H:i:s') . "')");
};
$pendentes = function (PDO $pdo) use ($alteracoes): array {
    $p = [];
    $tabelas = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    foreach ($alteracoes as [$t, $c, $sql]) {
        if (in_array($t, $tabelas, true) && !$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetchColumn()) $p[] = ["coluna $t.$c", $sql];
    }
    if (in_array('usuarios', $tabelas, true)) {
        $tipo = (string)($pdo->query("SHOW COLUMNS FROM usuarios LIKE 'perfil'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
        if (stripos($tipo, 'enum') === 0) $p[] = ['perfis personalizáveis (usuarios.perfil)', "ALTER TABLE usuarios MODIFY perfil VARCHAR(40) NOT NULL DEFAULT 'professor'"];
    }
    return $p;
};
/* Os antigos atos administrativos passam a fazer parte do Registro de ocorrências */
$migrar_atos = "INSERT IGNORE INTO ocorrencias (aluno_id, data_ocorrencia, categoria, tipo, gravidade, descricao, suspensao_inicio, suspensao_fim, registrado_por, criado_em, ato_id)
    SELECT aluno_id, data_ato,
           CASE tipo WHEN 'advertencia_verbal' THEN 'advertencia_verbal' WHEN 'advertencia_escrita' THEN 'advertencia_escrita'
                     WHEN 'suspensao' THEN 'suspensao' WHEN 'convocacao_responsavel' THEN 'convocacao_responsavel' ELSE 'outro' END,
           'disciplinar',
           CASE tipo WHEN 'suspensao' THEN 'grave' WHEN 'advertencia_escrita' THEN 'media' ELSE 'leve' END,
           CONCAT(CASE tipo WHEN 'termo_compromisso' THEN 'Termo de compromisso: ' WHEN 'declaracao' THEN 'Declaração: ' WHEN 'transferencia' THEN 'Transferência: ' ELSE '' END,
                  descricao, CASE WHEN numero_documento IS NULL THEN '' ELSE CONCAT(' (doc. ', numero_documento, ')') END),
           data_inicio, data_fim, registrado_por, criado_em, id
      FROM atos_administrativos";

/* Instalação automática: roda o banco_escola.sql guardado em includes/ (pasta bloqueada para o navegador) */
$arquivo_sql = __DIR__ . '/includes/banco_escola.sql';
$resultado_instalacao = null;
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'instalar' && is_file($arquivo_sql)) {
    $feitos = 0;
    try {
        foreach ($pendentes($pdo) as [, $sql]) { $pdo->exec($sql); $feitos++; }
        $sql = file_get_contents($arquivo_sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // tira comentários
        $comandos = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        foreach ($comandos as $cmd) { $pdo->exec($cmd); $feitos++; }
        $pdo->exec($migrar_atos); $feitos++;
        $migrar_permissoes_v6($pdo); $feitos++;
        $migrar_v7($pdo); $feitos++;
        $migrar_v8($pdo); $feitos++;
        $migrar_v9($pdo); $feitos++;
        $migrar_v10($pdo); $feitos++;
        $migrar_v13($pdo); $feitos++;
        $migrar_v14($pdo); $feitos++;
        $migrar_v20($pdo); $feitos++;
        $migrar_v26($pdo); $feitos++;
        $migrar_v59($pdo); $feitos++;
        $resultado_instalacao = [true, "Banco criado/atualizado com sucesso ($feitos comandos executados). Nenhum dado existente foi apagado."];
    } catch (PDOException $e) {
        $resultado_instalacao = [false, 'A instalação parou no comando ' . ($feitos + 1) . ': ' . $e->getMessage()];
    }
}

$pasta_dados = __DIR__ . '/dados';
if (!is_dir($pasta_dados)) @mkdir($pasta_dados, 0750, true);
$ok(is_dir($pasta_dados) && is_writable($pasta_dados), 'Pasta "dados" (fotos e arquivos) com permissão de escrita', 'No Gerenciador de Arquivos, dê permissão 755 para a pasta public_html/dados.');
$ok(extension_loaded('gd'), 'Extensão GD (ajusta as fotos)', 'Sem a GD as fotos são aceitas só em JPG e sem recorte. Ative "gd" na configuração do PHP.');

if ($pdo) {
    $esperadas = ['usuarios', 'cursos', 'turmas', 'disciplinas', 'atribuicoes', 'alunos', 'faltas', 'notas', 'ocorrencias', 'atos_administrativos', 'log_atividades',
                  'provas', 'prova_resultados', 'saidas', 'alertas_contato', 'avaliacoes', 'avaliacao_notas', 'livros', 'biblioteca_fichas', 'emprestimos', 'faltas_bimestre', 'importacoes',
                  'perfis', 'perfil_permissoes', 'usuario_permissoes', 'arquivos', 'configuracoes', 'eventos', 'recursos', 'reservas', 'horarios',
                  'prova_partes', 'notificacoes', 'notificacao_lidas', 'busca_ativa', 'livros_didaticos', 'agenda_notas', 'chamadas_aula', 'chamada_faltas', 'mapas_sala', 'aluno_observacoes', 'agenda_lembretes', 'ia_uso', 'equipe_site', 'site_albuns', 'site_fotos', 'site_informes', 'site_banners', 'site_informe_anexos', 'ia_conversas', 'site_arquivos', 'nota_conceitual', 'site_ambientes'];
    $existentes = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $faltando = array_values(array_diff($esperadas, $existentes));
    $ok(!$faltando, 'Tabelas do sistema (' . (count($esperadas) - count($faltando)) . ' de ' . count($esperadas) . ')',
        $faltando ? 'Faltam: ' . implode(', ', $faltando) . '. Clique no botão verde abaixo para criar tudo automaticamente.' : '');
    $pend = $pendentes($pdo);
    if ($pend) {
        foreach ($pend as [$nome]) $faltando[] = $nome;
        $ok(false, 'Atualizações de estrutura pendentes: ' . count($pend), implode(', ', array_column($pend, 0)) . '. Clique no botão verde abaixo para atualizar o banco.');
    }
    if (!$faltando) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn();
        $ok($n > 0, "Alunos cadastrados: $n", 'A importação parece incompleta. Importe banco_escola.sql de novo (não duplica dados).');
        $adm = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1")->fetchColumn();
        $ok($adm > 0, 'Usuário administrador ativo', 'Importe banco_escola.sql de novo para recriar o administrador.');
        $atos = (int)$pdo->query('SELECT COUNT(*) FROM atos_administrativos a WHERE NOT EXISTS (SELECT 1 FROM ocorrencias o WHERE o.ato_id = a.id)')->fetchColumn();
        if ($atos) { $faltando[] = 'atos'; $ok(false, "$atos ato(s) administrativo(s) para levar ao Registro de ocorrências", 'Clique no botão verde abaixo.'); }
        if (!$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v59'")->fetchColumn()) { $faltando[] = 'v59'; $ok(false, 'Permissões da versão 59 (nota conceitual; notas só pelo diretor)', 'Clique no botão verde abaixo.'); }
        if (!$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v6'")->fetchColumn()) { $faltando[] = 'v6'; $ok(false, 'Permissões da versão 6 (reservas, horários, notas só pela gestão)', 'Clique no botão verde abaixo.'); }
        if (!$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v9'")->fetchColumn()) { $faltando[] = 'v9'; $ok(false, 'Permissões da versão 9 (busca ativa)', 'Clique no botão verde abaixo.'); }
        if (!$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v8'")->fetchColumn()) { $faltando[] = 'v8'; $ok(false, 'Permissões da versão 8 (coordenação confirma liberações; notificações)', 'Clique no botão verde abaixo.'); }
        if (!$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'migracao_v7'")->fetchColumn()) { $faltando[] = 'v7'; $ok(false, 'Ajustes da versão 7 (autorização de tablet pessoal)', 'Clique no botão verde abaixo.'); }
        $cal = (int)$pdo->query("SELECT COUNT(*) FROM arquivos WHERE especial = 'calendario_sige'")->fetchColumn();
        $ok($cal > 0 && is_file($pasta_dados . '/arquivos/2026/calendario_sige_2026.pdf'), 'Calendário SIGE 2026 instalado', 'Envie também a pasta "dados" que veio no pacote, ou suba o PDF em Calendário SIGE.');
    }
}
$tudo = !array_filter($itens, fn($i) => !$i[0]);
?>
<!doctype html>
<html lang="pt-br"><head>
<link rel="icon" href="assets/img/favicon.png" type="image/png"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verificação da instalação</title>
<style>
body{font-family:system-ui,sans-serif;background:#f4f9f3;color:#152818;margin:0;padding:32px 16px}
.c{max-width:720px;margin:auto;background:#fff;border:1px solid #dbe8dc;border-radius:14px;padding:28px}
h1{margin:0 0 6px;font-size:24px;color:#13692a}li{list-style:none;padding:12px 0;border-bottom:1px solid #eef3ee;display:flex;gap:12px}
ul{padding:0;margin:20px 0}.s{font-size:18px;width:24px;flex-shrink:0}.d{color:#b42318;font-size:14px;margin-top:4px}
.r{padding:14px 16px;border-radius:10px;font-weight:600}.ok{background:#e1f3e2;color:#0c4a1d}.no{background:#fde8e6;color:#8a1c12}
</style></head><body><div class="c">
<h1>Verificação da instalação</h1>
<p>Confere o PHP e o banco de dados do sistema escolar.</p>
<ul>
<?php foreach ($itens as [$c, $t, $d]): ?>
    <li><span class="s"><?= $c ? '✅' : '❌' ?></span><div><?= htmlspecialchars($t) ?><?php if (!$c && $d): ?><div class="d"><?= htmlspecialchars($d) ?></div><?php endif; ?></div></li>
<?php endforeach; ?>
</ul>
<?php if ($resultado_instalacao): ?><p class="r <?= $resultado_instalacao[0] ? 'ok' : 'no' ?>"><?= htmlspecialchars($resultado_instalacao[1]) ?></p><?php endif; ?>
<?php if ($pdo && !empty($faltando) && is_file($arquivo_sql)): ?>
    <form method="post" style="margin:0 0 18px">
        <input type="hidden" name="acao" value="instalar">
        <button type="submit" style="font:600 16px system-ui;background:#13692a;color:#fff;border:0;border-radius:10px;padding:14px 22px;cursor:pointer;width:100%">
            Criar ou atualizar as tabelas agora
        </button>
        <p style="font-size:13px;color:#62766a;margin:8px 0 0">Cria as tabelas que faltam no banco "<?= htmlspecialchars(DB_NAME) ?>", os cursos, as turmas, as disciplinas, o usuário administrador e os alunos e faltas do sistema antigo. Não apaga nada que já exista.</p>
    </form>
<?php endif; ?>
<p class="r <?= $tudo ? 'ok' : 'no' ?>"><?= $tudo ? 'Tudo certo! Pode entrar em login.php. Depois, apague este arquivo (verificar.php).' : 'Corrija os itens marcados com ❌ e recarregue esta página.' ?></p>
</div></body></html>
