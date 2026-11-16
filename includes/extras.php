<?php
/* =====================================================================
 *  Funções novas da versão 20: observações sobre o aluno, lembretes da
 *  agenda, mapa de turma e assistente de IA (Gemini).
 * ===================================================================== */

/* ---------------------------------------------------------------------
 *  Observações sobre o aluno (sem peso disciplinar)
 * --------------------------------------------------------------------- */
const TIPOS_OBSERVACAO = [
    'positiva' => ['Positiva', 'hand-thumbs-up', 'verde'],
    'atencao'  => ['Ponto de atenção', 'eye', 'ambar'],
    'neutra'   => ['Anotação', 'chat-left-text', 'cinza'],
];

function observacoes_aluno(int $aluno_id, int $limite = 100): array
{
    try {
        return q_todos('SELECT o.*, u.foto AS usuario_foto FROM aluno_observacoes o LEFT JOIN usuarios u ON u.id = o.usuario_id
                         WHERE o.aluno_id = ? ORDER BY o.criado_em DESC, o.id DESC LIMIT ' . $limite, [$aluno_id]);
    } catch (PDOException $e) { return []; }
}

function observacao_salvar(array $aluno, string $tipo, string $texto): array
{
    $texto = trim($texto);
    if (!isset(TIPOS_OBSERVACAO[$tipo])) $tipo = 'neutra';
    if (mb_strlen($texto) < 3) return ['warning', 'Escreva a observação.'];
    $u = usuario();
    q('INSERT INTO aluno_observacoes (aluno_id, tipo, texto, usuario_id, usuario_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
      [(int)$aluno['id'], $tipo, mb_substr($texto, 0, 600), uid(), $u['nome'] ?? null, date('Y-m-d H:i:s')]);
    registrar_log('observacao', TIPOS_OBSERVACAO[$tipo][0], (int)$aluno['id']);
    /* ponto de atenção: avisa o(a) diretor(a) de turma */
    if ($tipo === 'atencao' && !empty($aluno['turma_id'])) {
        $dt = (int)q_valor('SELECT diretor_turma_id FROM turmas WHERE id = ?', [$aluno['turma_id']]);
        if ($dt && $dt !== uid()) notificar(['usuarios' => [$dt]], 'Ponto de atenção · ' . primeiro_ultimo(nome_proprio($aluno['nome'])),
            mb_strimwidth($texto, 0, 180, '…') . ' (por ' . primeiro_ultimo((string)($u['nome'] ?? '')) . ')', 'aviso', 'aluno.php?id=' . (int)$aluno['id'] . '#observacoes');
    }
    return ['success', 'Observação registrada.'];
}

/* ---------------------------------------------------------------------
 *  Lembretes da agenda: avisam no sino na data e hora marcadas
 * --------------------------------------------------------------------- */
function lembretes_lista(int $uid, bool $so_pendentes = true, int $limite = 30): array
{
    try {
        return q_todos('SELECT * FROM agenda_lembretes WHERE usuario_id = ?' . ($so_pendentes ? ' AND feito = 0' : '') . ' ORDER BY quando LIMIT ' . $limite, [$uid]);
    } catch (PDOException $e) { return []; }
}

/** Cria a notificação dos lembretes que já venceram (chamado no "sino" a cada 20 s) */
function lembretes_disparar(int $uid): void
{
    try {
        foreach (q_todos('SELECT * FROM agenda_lembretes WHERE usuario_id = ? AND avisado = 0 AND feito = 0 AND quando <= ? ORDER BY quando LIMIT 5', [$uid, date('Y-m-d H:i:s')]) as $l) {
            q('UPDATE agenda_lembretes SET avisado = 1 WHERE id = ?', [$l['id']]);
            notificar(['usuarios' => [$uid]], 'Lembrete: ' . mb_strimwidth($l['texto'], 0, 120, '…'), 'Marcado para ' . date('d/m \à\s H:i', strtotime($l['quando'])) . '.', 'lembrete',
                      'agenda.php?data=' . substr($l['quando'], 0, 10), true);
        }
    } catch (PDOException $e) {}
}

/* ---------------------------------------------------------------------
 *  Mapa de turma (cada professor monta as carteiras como estão na sala)
 * --------------------------------------------------------------------- */
function mapa_sala_ler(int $turma_id): array
{
    $m = null;
    try { $m = q_um('SELECT * FROM mapas_sala WHERE turma_id = ?', [$turma_id]); } catch (PDOException $e) {}
    $j = json_decode((string)($m['lugares'] ?? ''), true) ?: [];
    if (($j['v'] ?? 0) == 2) {
        $cad = array_values(array_filter((array)($j['cadeiras'] ?? []), fn($k) => preg_match('/^\d+-\d+$/', (string)$k)));
        $alu = array_filter((array)($j['alunos'] ?? []), fn($v, $k) => in_array($k, $cad, true), ARRAY_FILTER_USE_BOTH);
        $mesa = in_array($j['mesa'] ?? '', ['esquerda', 'centro', 'direita'], true) ? $j['mesa'] : 'centro';
    } else {
        /* formato antigo (v20 inicial): grade fixa de filas x carteiras */
        $cad = []; $alu = []; $mesa = 'centro';
        if ($m) {
            for ($f = 0; $f < (int)$m['filas']; $f++) for ($c = 0; $c < (int)$m['colunas']; $c++) $cad[] = "$c-$f";
            foreach ($j as $k => $id) if (preg_match('/^(\d+)-(\d+)$/', (string)$k, $x)) $alu[$x[2] . '-' . $x[1]] = (int)$id;
        }
    }
    return ['cadeiras' => $cad, 'alunos' => array_map('intval', $alu), 'mesa' => $mesa,
            'em' => $m['atualizado_em'] ?? null, 'por' => $m['atualizado_por'] ?? null];
}

function pode_editar_mapa(int $turma_id): bool
{
    /* só a gestão e o(a) diretor(a) da turma montam o mapa; os demais professores só veem */
    return pode_painel_turma($turma_id);
}

/* ---------------------------------------------------------------------
 *  Assistente de IA (Google Gemini)
 *  A chave fica em includes/config.php: define('IA_CHAVE', 'AIza...');
 *  (ou em Configurações › Assistente de IA, só para o administrador)
 * --------------------------------------------------------------------- */
const IA_RECUSA = 'Sou treinado apenas para fins educacionais.';

function ia_config(): array
{
    $chave = defined('IA_CHAVE') && IA_CHAVE ? IA_CHAVE : (string)config_ler('ia_chave', '');
    return [
        'chave'  => ia_limpar_chave($chave),
        'modelo' => defined('IA_MODELO') && IA_MODELO ? IA_MODELO : ((string)config_ler('ia_modelo', '') ?: 'gemini-flash-lite-latest'),
        'cota'   => (int)(defined('IA_COTA_DIA') ? IA_COTA_DIA : ((int)config_ler('ia_cota', 0) ?: 15)),
        'escola' => (int)(defined('IA_COTA_ESCOLA') ? IA_COTA_ESCOLA : ((int)config_ler('ia_cota_escola', 0) ?: 400)),
    ];
}

/** Tira espaços, quebras de linha, aspas e caracteres invisíveis que vêm junto quando se copia a chave */
function ia_limpar_chave(string $c): string
{
    $c = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}\x{00A0}"\'`]+/u', '', $c);
    if (preg_match('/AIza[0-9A-Za-z_\-]{20,}/', $c, $m)) return $m[0];       // achou a chave no meio do texto colado
    return trim($c, " ;,()");
}

function ia_ativa(): bool { return ia_config()['chave'] !== ''; }

/** Uso de hoje: [meus, escola] (só conta o que foi para o Gemini) */
function ia_uso_hoje(): array
{
    try {
        $ini = date('Y-m-d') . ' 00:00:00';
        return [(int)q_valor("SELECT COUNT(*) FROM ia_uso WHERE usuario_id = ? AND criado_em >= ? AND situacao IN ('ok', 'recusada', 'erro')", [uid(), $ini]),
                (int)q_valor("SELECT COUNT(*) FROM ia_uso WHERE criado_em >= ? AND situacao IN ('ok', 'recusada', 'erro')", [$ini])];
    } catch (PDOException $e) { return [0, 0]; }
}

/** Pode perguntar agora? Cota própria; acabou, usa a reserva da escola enquanto ela tiver folga */
function ia_pode_perguntar(): array
{
    $c = ia_config(); [$meu, $esc] = ia_uso_hoje();
    if ($esc >= $c['escola']) return [false, 'A cota diária da escola acabou. O assistente volta amanhã.', $meu, $c['cota']];
    if ($meu < $c['cota']) return [true, '', $meu, $c['cota']];
    if ($esc < $c['escola'] * 0.75 && $meu < $c['cota'] * 2) return [true, 'Sua cota de hoje acabou; usando a reserva da escola.', $meu, $c['cota']];
    return [false, 'Você usou as ' . $c['cota'] . ' perguntas de hoje. A cota volta amanhã.', $meu, $c['cota']];
}

/** Bloqueia dados pessoais de alunos (nome completo, CPF, telefone) antes de enviar para fora */
function ia_tem_dado_pessoal(string $t): ?string
{
    if (preg_match('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', $t)) return 'CPF';
    if (preg_match('/\(?\b\d{2}\)?\s?9?\d{4}[-\s]?\d{4}\b/', $t)) return 'telefone';
    $tl = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', $t)) . ' ';
    try {
        foreach (q_todos("SELECT nome FROM alunos WHERE situacao = 'ativo'") as $a) {
            $p = preg_split('/\s+/', mb_strtolower(trim($a['nome'])));
            if (count($p) < 2) continue;
            if (str_contains($tl, ' ' . implode(' ', $p) . ' ') || str_contains($tl, ' ' . $p[0] . ' ' . end($p) . ' ')) return 'nome de aluno';
        }
    } catch (PDOException $e) {}
    return null;
}

function ia_instrucao(): string
{
    $u = usuario(); global $ESCOLA;
    return "Você é a Sofia, a corujinha assistente pedagógica da " . ($ESCOLA['nome_oficial'] ?? 'escola') . ", em " . ($ESCOLA['cidade'] ?? 'Ceará') . ", uma escola estadual de educação profissional "
         . "(ensino médio integrado aos cursos técnicos de Informática, Desenvolvimento de Sistemas, Enfermagem e Administração).\n"
         . "Quem está falando com você: " . ($u['nome'] ?? 'um profissional da escola') . " (" . cargo_usuario($u) . ").\n\n"
         . "ESCOPO PERMITIDO (só isto): planejamento de aulas e sequências didáticas; criação de questões, provas, simulados e gabaritos; explicação de conteúdos "
         . "das disciplinas da base comum e dos cursos técnicos; metodologias ativas; BNCC e currículo do Ceará; avaliação e recuperação; atividades e projetos; "
         . "redação de documentos e comunicados escolares (aviso aos pais, ofício, ata, relato de ocorrência); orientação educacional e convivência escolar; "
         . "dúvidas sobre o uso do sistema escolar.\n"
         . "Se o pedido estiver FORA desse escopo (assuntos pessoais, entretenimento, política partidária, receitas, apostas, código fora do contexto de aula, "
         . "qualquer coisa não educacional) responda EXATAMENTE e somente: \"" . IA_RECUSA . "\"\n\n"
         . "Regras: responda em português do Brasil, de forma clara e organizada (use títulos curtos e listas quando ajudar). Questões de múltipla escolha: "
         . "alternativas A a E (ou A a D se pedirem), uma só correta, e o gabarito no final. Nunca peça nem use dados pessoais de alunos. "
         . "Se não souber, diga que não sabe. Lembre que o professor deve revisar o material antes de usar com a turma.";
}

/** Envia ao Gemini. $historico = [['papel' => 'usuario'|'ia', 'texto' => ...], ...] */
function ia_perguntar(string $pergunta, array $historico = []): array
{
    $c = ia_config();
    if ($c['chave'] === '') return ['ok' => false, 'situacao' => 'sem_chave', 'texto' => 'O assistente já está pronto, mas ainda falta a chave da API do Google. Assim que a gestão colar a chave, ele começa a responder.'];
    $conteudos = [];
    foreach (array_slice($historico, -8) as $h) {
        $t = mb_substr(trim((string)($h['texto'] ?? '')), 0, 4000);
        if ($t === '') continue;
        $conteudos[] = ['role' => ($h['papel'] ?? '') === 'ia' ? 'model' : 'user', 'parts' => [['text' => $t]]];
    }
    $conteudos[] = ['role' => 'user', 'parts' => [['text' => $pergunta]]];
    $seg = array_map(fn($cat) => ['category' => $cat, 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                     ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT']);
    $corpo = json_encode(['systemInstruction' => ['parts' => [['text' => ia_instrucao()]]], 'contents' => $conteudos,
                          'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 3000], 'safetySettings' => $seg], JSON_UNESCAPED_UNICODE);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($c['modelo']) . ':generateContent';
    if (!function_exists('curl_init')) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'O servidor não tem a extensão cURL do PHP ativa.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['chave']]]);
    $res = curl_exec($ch); $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'Não consegui falar com o Google agora (' . $err . '). Tente de novo em instantes.'];
    $j = json_decode($res, true);
    if ($cod === 429) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'O limite gratuito do Google foi atingido por agora. Tente de novo mais tarde.'];
    $msg = (string)($j['error']['message'] ?? 'erro');
    if ($cod >= 400 && (str_contains($msg, 'API key') || str_contains($msg, 'API_KEY'))) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'O Google não aceitou a chave (' . $cod . '). Confira se copiou a chave inteira pelo botão de copiar do AI Studio e se ela não foi apagada lá. Detalhe: ' . mb_strimwidth($msg, 0, 140, '…')];
    if ($cod === 404) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'A chave funcionou, mas o modelo "' . $c['modelo'] . '" não existe para ela. Troque o modelo para gemini-2.5-flash-lite (ou gemini-2.5-flash) na tela do assistente.'];
    if ($cod >= 400) return ['ok' => false, 'situacao' => 'erro', 'texto' => 'O Google recusou o pedido (' . $cod . '): ' . mb_strimwidth($msg, 0, 160, '…')];
    if (!empty($j['promptFeedback']['blockReason'])) return ['ok' => true, 'situacao' => 'recusada', 'texto' => IA_RECUSA];
    $partes = $j['candidates'][0]['content']['parts'] ?? [];
    $texto = trim(implode('', array_map(fn($p) => $p['text'] ?? '', $partes)));
    if ($texto === '') return ['ok' => true, 'situacao' => 'recusada', 'texto' => IA_RECUSA];
    $recusa = str_contains(mb_strtolower($texto), mb_strtolower(rtrim(IA_RECUSA, '.')));
    return ['ok' => true, 'situacao' => $recusa ? 'recusada' : 'ok', 'texto' => $recusa ? IA_RECUSA : $texto];
}

const IA_NOME = 'Sofia';

/** Chat da Sofia (fica dentro da saudação do painel inicial) */
function cartao_assistente_ia(): string
{
    if (!pode('ia')) return '';
    [, , $meu, $cota] = ia_pode_perguntar();
    $ativa = ia_ativa();
    $sugestoes = pode('provas')
        ? [['ui-checks', 'Gere 5 questões de múltipla escolha sobre frações para o 1º ano, com gabarito'], ['journal-text', 'Monte um plano de aula de 50 minutos sobre ética no trabalho'], ['arrow-repeat', 'Sugira uma atividade de recuperação de interpretação de texto']]
        : [['megaphone', 'Escreva um comunicado aos pais sobre a reunião bimestral'], ['graph-up-arrow', 'Como posso melhorar a frequência dos alunos?'], ['file-text', 'Modelo de ata de reunião pedagógica']];
    $foto = RAIZ . 'assets/img/sofia.svg';
    ob_start(); ?>
    <div class="ia-chat" id="assistente" data-ativa="<?= $ativa ? 1 : 0 ?>" data-foto="<?= e($foto) ?>">
        <div class="ia-cab">
            <span class="ia-foto"><img src="<?= e($foto) ?>" alt=""><i class="<?= $ativa ? 'on' : '' ?>"></i></span>
            <div class="min-w-0"><strong><?= IA_NOME ?> <span class="ia-tag">IA</span></strong>
                <small><?= $ativa ? 'assistente pedagógica · online' : 'assistente pedagógica · aguardando a chave' ?></small></div>
            <span class="ia-cota ms-auto" title="Perguntas que você usou hoje"><i class="bi bi-lightning-charge-fill"></i><b id="iaUso"><?= $meu ?></b>/<?= $cota ?> hoje</span>
            <button class="ia-limpar" type="button" id="iaLimpar" title="Nova conversa" aria-label="Nova conversa"><i class="bi bi-arrow-counterclockwise"></i></button>
        </div>
        <div class="ia-conversa" id="iaConversa" aria-live="polite">
            <div class="ia-msg ia"><img class="ia-av" src="<?= e($foto) ?>" alt=""><div class="ia-balao">
                Oi, <?= e(nome_exibicao(usuario())) ?>! Eu sou a <b><?= IA_NOME ?></b>, a corujinha pedagógica da escola. 🦉 Posso montar <b>planos de aula</b>, criar <b>questões com gabarito</b>, explicar conteúdos e escrever <b>comunicados</b>. Por onde a gente começa?
                <?php if (!$ativa): ?><div class="ia-aviso"><i class="bi bi-key"></i> Ainda estou sem a chave da API. Assim que a gestão configurar, começo a responder aqui mesmo.</div><?php endif; ?>
            </div></div>
        </div>
        <div class="ia-sugestoes" id="iaSugestoes">
            <?php foreach ($sugestoes as [$ic, $sg]): ?><button type="button" data-sugestao="<?= e($sg) ?>"><i class="bi bi-<?= $ic ?>"></i><?= e($sg) ?></button><?php endforeach; ?>
        </div>
        <form class="ia-form" id="iaForm" autocomplete="off">
            <textarea id="iaPergunta" rows="1" maxlength="1500" placeholder="Pergunte para a <?= IA_NOME ?> sobre aulas, provas, conteúdos ou documentos…" aria-label="Pergunta para a <?= IA_NOME ?>"></textarea>
            <button class="ia-enviar" type="submit" aria-label="Enviar"><i class="bi bi-send-fill"></i></button>
        </form>
        <p class="ia-rodape"><i class="bi bi-shield-check"></i> Só respondo sobre educação · não escreva nome completo, CPF ou telefone de alunos · revise o material antes de usar.</p>
    </div>
    <?php return ob_get_clean();
}
