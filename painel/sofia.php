<?php
/* Sofia: página própria da assistente de IA, com histórico de conversas de cada pessoa */
require __DIR__ . '/../includes/app.php';
exigir('ia');

const SOFIA_MAX_MSG = 60;   // mensagens guardadas por conversa (pares pergunta/resposta)

/** Conversa da própria pessoa (nunca de outra) */
function sofia_conversa(int $id): ?array
{
    if ($id <= 0) return null;
    $c = q_um('SELECT * FROM ia_conversas WHERE id = ? AND usuario_id = ?', [$id, uid()]);
    if (!$c) return null;
    $m = json_decode((string)$c['mensagens'], true);
    $c['mensagens'] = is_array($m) ? $m : [];
    return $c;
}

if (eh_post()) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = function (array $d, int $st = 200) { http_response_code($st); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) $resp(['ok' => false, 'texto' => 'A página expirou. Recarregue e tente de novo.'], 400);
    $_SESSION['ultimo_uso'] = time();
    $acao = (string)($_POST['acao'] ?? '');
    $cid = (int)($_POST['conversa'] ?? 0);
    try {
        switch ($acao) {
            case 'listar':
                $lista = q_todos('SELECT id, titulo, atualizado_em FROM ia_conversas WHERE usuario_id = ? ORDER BY atualizado_em DESC, id DESC LIMIT 60', [uid()]);
                $resp(['ok' => true, 'conversas' => array_map(fn($c) => ['id' => (int)$c['id'], 'titulo' => $c['titulo'], 'em' => $c['atualizado_em']], $lista)]);

            case 'abrir':
                $c = sofia_conversa($cid);
                if (!$c) $resp(['ok' => false, 'texto' => 'Conversa não encontrada.'], 404);
                $resp(['ok' => true, 'id' => (int)$c['id'], 'titulo' => $c['titulo'], 'mensagens' => $c['mensagens']]);

            case 'apagar':
                q('DELETE FROM ia_conversas WHERE id = ? AND usuario_id = ?', [$cid, uid()]);
                $resp(['ok' => true]);

            case 'perguntar':
                $perg = trim(mb_substr((string)($_POST['pergunta'] ?? ''), 0, 1500));
                $refazer = !empty($_POST['refazer']);
                $c = $cid ? sofia_conversa($cid) : null;
                if ($cid && !$c) $resp(['ok' => false, 'texto' => 'Conversa não encontrada. Comece uma nova.'], 404);
                $msgs = $c['mensagens'] ?? [];
                if ($refazer) {
                    // refaz a última resposta: tira o último par e pergunta de novo
                    $n = count($msgs);
                    if ($n < 2 || ($msgs[$n - 1]['papel'] ?? '') !== 'ia') $resp(['ok' => false, 'texto' => 'Não há resposta para refazer.']);
                    $perg = (string)$msgs[$n - 2]['texto'];
                    $msgs = array_slice($msgs, 0, -2);
                }
                if (mb_strlen($perg) < 3) $resp(['ok' => false, 'texto' => 'Escreva a sua pergunta.']);
                $agora = date('Y-m-d H:i:s');
                if ($dp = ia_tem_dado_pessoal($perg)) {
                    try { q('INSERT INTO ia_uso (usuario_id, pergunta, situacao, criado_em) VALUES (?, ?, ?, ?)', [uid(), '[removido: ' . $dp . ']', 'bloqueada', $agora]); } catch (PDOException $e) {}
                    $resp(['ok' => true, 'situacao' => 'bloqueada', 'texto' => 'Por segurança, não envio dados pessoais de alunos para a IA (encontrei ' . $dp . ' na pergunta). Troque por "o aluno" ou "a turma" e pergunte de novo.']);
                }
                [$pode, $aviso, $meu, $cota] = ia_pode_perguntar();
                if (!$pode) $resp(['ok' => false, 'situacao' => 'cota', 'texto' => $aviso, 'uso' => $meu, 'cota' => $cota]);
                @set_time_limit(90);
                $r = ia_perguntar($perg, $msgs);
                try { q('INSERT INTO ia_uso (usuario_id, pergunta, resposta, situacao, criado_em) VALUES (?, ?, ?, ?, ?)', [uid(), $perg, mb_substr($r['texto'], 0, 60000), $r['situacao'], $agora]); } catch (PDOException $e) {}
                $saida = $r + ['aviso' => $aviso, 'uso' => ia_uso_hoje()[0], 'cota' => $cota];
                if (($r['situacao'] ?? '') === 'ok') {
                    // só respostas válidas entram no histórico
                    $msgs[] = ['papel' => 'usuario', 'texto' => $perg, 'em' => $agora];
                    $msgs[] = ['papel' => 'ia', 'texto' => mb_substr($r['texto'], 0, 30000), 'em' => $agora];
                    $msgs = array_slice($msgs, -SOFIA_MAX_MSG);
                    $json = json_encode($msgs, JSON_UNESCAPED_UNICODE);
                    if ($c) q('UPDATE ia_conversas SET mensagens = ?, atualizado_em = ? WHERE id = ? AND usuario_id = ?', [$json, $agora, $c['id'], uid()]);
                    else {
                        $tit = preg_replace('/\s+/', ' ', $perg);
                        $tit = mb_strlen($tit) > 70 ? rtrim(mb_substr($tit, 0, 68)) . '…' : $tit;
                        q('INSERT INTO ia_conversas (usuario_id, titulo, mensagens, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?)', [uid(), $tit, $json, $agora, $agora]);
                        $c = ['id' => (int)db()->lastInsertId(), 'titulo' => $tit];
                    }
                    $saida['conversa'] = (int)$c['id']; $saida['titulo'] = $c['titulo'];
                }
                $resp($saida);
        }
    } catch (PDOException $e) {
        $resp(['ok' => false, 'texto' => 'O histórico de conversas ainda não foi criado no banco. Peça à gestão para rodar a atualização do banco (verificar.php).'], 500);
    }
    $resp(['ok' => false, 'texto' => 'Ação desconhecida.'], 400);
}

[, $aviso_cota, $meu, $cota] = ia_pode_perguntar();
$ativa = ia_ativa();
$foto = RAIZ . 'assets/img/sofia.svg';
$abrir = (int)($_GET['c'] ?? 0);
$atalhos = [
    ['list-check', 'Questões', 'com gabarito', 'Gere 5 questões de múltipla escolha sobre [tema] para o [série] ano, com alternativas A a E e gabarito no final.'],
    ['journal-text', 'Plano de aula', 'por tema e tempo', 'Monte um plano de aula de [50] minutos sobre [tema] para o [série] ano, com objetivos, metodologia, atividade e avaliação.'],
    ['envelope-paper', 'Comunicado', 'para pais e alunos', 'Escreva um comunicado aos pais e responsáveis sobre [assunto], em linguagem simples e cordial.'],
    ['arrow-repeat', 'Recuperação', 'atividade de reforço', 'Sugira uma atividade de recuperação sobre [conteúdo] para alunos com dificuldade, com passo a passo e critérios de correção.'],
];

$titulo = 'Sofia';
$menu = 'sofia';
$sem_titulo = true;
$classe_area = 'sf-area';
require __DIR__ . '/../includes/layout_topo.php';
?>
<div class="sf" id="sofia" data-foto="<?= e($foto) ?>" data-abrir="<?= $abrir ?>" data-nome="<?= e(nome_exibicao(usuario())) ?>">
    <aside class="sf-hist" id="sfHist" aria-label="Conversas">
        <div class="sf-hist-topo">
            <button class="sf-nova" type="button" id="sfNova"><i class="bi bi-plus-lg"></i>Nova conversa</button>
            <button class="sf-fechar" type="button" id="sfFecharHist" aria-label="Fechar conversas"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="sf-lista" id="sfLista"><p class="sf-vazio">Carregando…</p></div>
        <div class="sf-cota">
            <span><b id="sfUso"><?= $meu ?></b> de <b><?= $cota ?></b> perguntas hoje</span>
            <div class="sf-cota-barra"><i id="sfUsoBarra" style="width:<?= $cota ? min(100, round($meu * 100 / $cota)) : 0 ?>%"></i></div>
            <?php if ($aviso_cota): ?><small><?= e($aviso_cota) ?></small><?php endif; ?>
        </div>
    </aside>
    <div class="sf-fundo" id="sfFundo" hidden></div>

    <section class="sf-chat">
        <header class="sf-cab">
            <button class="sf-btn-hist" type="button" id="sfAbrirHist" aria-label="Ver conversas"><i class="bi bi-layout-sidebar"></i></button>
            <span class="sf-foto"><img src="<?= e($foto) ?>" alt=""></span>
            <div class="min-w-0">
                <strong>Sofia</strong>
                <small><i class="<?= $ativa ? 'on' : '' ?>"></i><span class="text-truncate"><?= $ativa ? 'assistente pedagógica · online' : 'assistente pedagógica · aguardando a chave' ?></span></small>
            </div>
            <div class="sf-cab-acoes">
                <button type="button" id="sfCopiarTudo" disabled><i class="bi bi-clipboard"></i><span>Copiar</span></button>
                <button type="button" id="sfImprimir" disabled><i class="bi bi-printer"></i><span>Imprimir</span></button>
            </div>
        </header>

        <div class="sf-conversa" id="sfConversa" aria-live="polite"></div>

        <div class="sf-inicio" id="sfInicio">
            <?php foreach ($atalhos as [$ic, $t, $s, $modelo]): ?>
            <button type="button" data-modelo="<?= e($modelo) ?>"><i class="bi bi-<?= $ic ?>"></i><b><?= $t ?></b><small><?= $s ?></small></button>
            <?php endforeach; ?>
        </div>

        <form class="sf-form" id="sfForm" autocomplete="off">
            <textarea id="sfPergunta" rows="1" maxlength="1500" placeholder="Pergunte para a Sofia sobre aulas, provas, conteúdos ou documentos…" aria-label="Pergunta para a Sofia"></textarea>
            <button class="sf-enviar" type="submit" aria-label="Enviar"><i class="bi bi-send-fill"></i></button>
        </form>
        <p class="sf-nota"><i class="bi bi-shield-check"></i>Só respondo sobre educação · não escreva nome completo, CPF ou telefone de alunos · revise o material antes de usar.</p>
    </section>
</div>
<?php if (!$ativa): ?><template id="sfSemChave"><div class="ia-aviso"><i class="bi bi-key"></i> Ainda estou sem a chave da API. Assim que a gestão configurar, começo a responder aqui.</div></template><?php endif; ?>
<script src="<?= RAIZ ?>assets/js/sofia.js?v=1" defer></script>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
