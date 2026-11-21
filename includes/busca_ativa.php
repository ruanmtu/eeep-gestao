<?php
/* =====================================================================
 *  Busca ativa escolar
 *  Registro das providências tomadas pelo diretor de turma e pela gestão
 *  quando o aluno falta, some ou corre risco de abandonar a escola:
 *  ligação para a família, mensagem, ofício, visita, reunião, conversa
 *  com o aluno, Conselho Tutelar, encaminhamentos...
 * ===================================================================== */

/* acao => [rótulo, ícone] */
const ACOES_BUSCA = [
    'ligacao'        => ['Ligação para a família', 'telephone-outbound'],
    'whatsapp'       => ['Mensagem (WhatsApp/SMS)', 'chat-dots'],
    'oficio'         => ['Ofício / notificação escrita', 'envelope-paper'],
    'visita'         => ['Visita domiciliar', 'house-door'],
    'reuniao'        => ['Reunião com o responsável', 'people'],
    'conversa'       => ['Conversa com o aluno', 'person-raised-hand'],
    'conselho'       => ['Conselho Tutelar', 'shield-shaded'],
    'encaminhamento' => ['Encaminhamento (CREDE, CRAS, saúde...)', 'signpost-split'],
    'outro'          => ['Outra providência', 'three-dots'],
];
/* motivo => rótulo */
const MOTIVOS_BUSCA = [
    'faltas'        => 'Faltas consecutivas',
    'infrequencia'  => 'Infrequência (muitas faltas no período)',
    'abandono'      => 'Risco de abandono',
    'permanencia'   => 'Permanência / acompanhamento',
    'comportamento' => 'Comportamento ou rendimento',
    'outro'         => 'Outro motivo',
];
/* resultado => [rótulo, cor da etiqueta] */
const RESULTADOS_BUSCA = [
    'contato_feito'     => ['Família informada', 'verde'],
    'retorno_combinado' => ['Retorno combinado', 'azul'],
    'aluno_retornou'    => ['Aluno retornou', 'verde'],
    'nao_atendeu'       => ['Não atendeu / sem contato', 'ambar'],
    'sem_retorno'       => ['Família informada, aluno não voltou', 'vermelho'],
    'encaminhado'       => ['Caso encaminhado', 'roxo'],
    'outro'             => ['Outro', 'cinza'],
];

function busca_lista(string $where, array $p = [], int $limite = 300): array
{
    try {
        return q_todos('SELECT b.*, a.nome, a.foto, a.turma_id, t.serie, t.letra, c.nome AS curso, c.sigla FROM busca_ativa b JOIN alunos a ON a.id = b.aluno_id
                          LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                         WHERE ' . $where . ' ORDER BY b.data_acao DESC, b.id DESC LIMIT ' . $limite, $p);
    } catch (PDOException $e) { return []; }
}

/** Grava uma providência. Devolve [tipo do aviso, mensagem] */
function busca_salvar(int $aluno_id, array $d, ?array $arquivo = null): array
{
    $acao = isset(ACOES_BUSCA[$d['acao'] ?? '']) ? $d['acao'] : '';
    $res = isset(RESULTADOS_BUSCA[$d['resultado'] ?? '']) ? $d['resultado'] : '';
    $motivo = isset(MOTIVOS_BUSCA[$d['motivo'] ?? '']) ? $d['motivo'] : 'faltas';
    $data = (string)($d['data_acao'] ?? '');
    if (!$acao) return ['warning', 'Escolha a providência tomada.'];
    if (!$res) return ['warning', 'Informe o resultado do contato.'];
    if (!data_valida($data) || $data > date('Y-m-d')) return ['warning', 'Informe a data da providência (hoje ou antes).'];
    $hora = preg_match('/^\d{2}:\d{2}$/', (string)($d['hora'] ?? '')) ? $d['hora'] . ':00' : null;
    $ret = data_valida((string)($d['retorno_previsto'] ?? '')) ? $d['retorno_previsto'] : null;
    $fi = ($d['faltas_informadas'] ?? '') !== '' ? max(0, min(999, (int)$d['faltas_informadas'])) : null;
    $txt = fn($k, $n) => ($v = trim((string)($d[$k] ?? ''))) !== '' ? mb_substr($v, 0, $n) : null;
    /* v74: a providência só é registrada com o comprovante do contato (print, foto, áudio, ofício…) */
    try { [$comp, $comp_nome] = busca_comprovante_receber($arquivo ?? [], $aluno_id); }
    catch (RuntimeException $e) { return ['warning', $e->getMessage()]; }
    $u = usuario();
    q('INSERT INTO busca_ativa (aluno_id, data_acao, hora, acao, contato_nome, contato_parentesco, contato_telefone, motivo, faltas_informadas, resultado, descricao, documento, retorno_previsto, registrado_por, registrado_nome, criado_em, comprovante, comprovante_nome)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$aluno_id, $data, $hora, $acao, $txt('contato_nome', 150), $txt('contato_parentesco', 40), $txt('contato_telefone', 30), $motivo, $fi, $res,
       $txt('descricao', 4000), $txt('documento', 80), $ret, $u['id'] ?? null, $u['nome'] ?? null, date('Y-m-d H:i:s'), $comp, $comp_nome]);
    registrar_log('busca_ativa', ACOES_BUSCA[$acao][0] . ' · ' . RESULTADOS_BUSCA[$res][0], $aluno_id);
    /* se o aluno estava no alerta de faltas seguidas, o alerta é dado como tratado */
    foreach (alertas_sequencia(true) as $al) {
        if ((int)$al['aluno_id'] !== $aluno_id) continue;
        try {
            if (!q_valor('SELECT id FROM alertas_contato WHERE aluno_id = ? AND sequencia_fim = ?', [$aluno_id, $al['fim']]))
                q('INSERT INTO alertas_contato (aluno_id, sequencia_fim, dias, observacao, resolvido_por) VALUES (?, ?, ?, ?, ?)',
                  [$aluno_id, $al['fim'], count($al['dias']), 'Busca ativa: ' . ACOES_BUSCA[$acao][0], $u['id'] ?? null]);
        } catch (PDOException $e) {}
    }
    return ['success', 'Providência registrada na busca ativa do aluno.'];
}

/** Situação da busca ativa de um aluno: [rótulo, cor, texto] pela última providência */
function busca_situacao(array $registros): ?array
{
    if (!$registros) return null;
    $u = $registros[0];
    [$rot, $cor] = RESULTADOS_BUSCA[$u['resultado']] ?? ['—', 'cinza'];
    $txt = 'Última providência em ' . data_br($u['data_acao']) . ': ' . mb_strtolower(ACOES_BUSCA[$u['acao']][0] ?? $u['acao']);
    if ($u['retorno_previsto'] && $u['retorno_previsto'] >= date('Y-m-d')) $txt .= ' · retorno previsto para ' . data_br($u['retorno_previsto']);
    return [$rot, $cor, $txt];
}

/** Item da linha do tempo da busca ativa */
function busca_cartao(array $b, bool $mostrar_aluno = false): string
{
    [$ar, $ai] = ACOES_BUSCA[$b['acao']] ?? ['Providência', 'check2'];
    [$rr, $rc] = RESULTADOS_BUSCA[$b['resultado']] ?? ['—', 'cinza'];
    $h = '<li class="ba-item"><span class="ba-ic"><i class="bi bi-' . $ai . '"></i></span><div class="flex-grow-1 min-w-0">';
    if ($mostrar_aluno) $h .= '<a class="nome-link fw-semibold" href="aluno.php?id=' . (int)$b['aluno_id'] . '#busca">' . e(nome_proprio($b['nome'])) . '</a> <small class="text-secondary">' . e($b['serie'] . 'º ' . $b['letra'] . ' · ' . ($b['sigla'] ?: $b['curso'])) . '</small><br>';
    $h .= '<strong>' . e($ar) . '</strong> <span class="etiqueta ' . $rc . '">' . e($rr) . '</span>';
    $h .= '<div class="ba-meta">' . data_br($b['data_acao']) . ($b['hora'] ? ' às ' . substr($b['hora'], 0, 5) : '') . ' · ' . e(MOTIVOS_BUSCA[$b['motivo']] ?? $b['motivo'])
        . ($b['faltas_informadas'] !== null ? ' · ' . (int)$b['faltas_informadas'] . ' falta(s) informada(s)' : '') . '</div>';
    $quem = trim((string)$b['contato_nome'] . ($b['contato_parentesco'] ? ' (' . $b['contato_parentesco'] . ')' : '') . ($b['contato_telefone'] ? ' · ' . $b['contato_telefone'] : ''));
    if ($quem !== '') $h .= '<div class="ba-meta"><i class="bi bi-person me-1"></i>Falou com ' . e($quem) . '</div>';
    if ($b['descricao']) $h .= '<p class="ba-desc">' . nl2br(e($b['descricao'])) . '</p>';
    $extra = [];
    if (!empty($b['comprovante'])) $extra[] = '<a class="ba-comp" href="busca_comprovante.php?id=' . (int)$b['id'] . '" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1"></i>Comprovante' . (!empty($b['comprovante_nome']) ? ': ' . e(mb_strimwidth($b['comprovante_nome'], 0, 40, '…')) : '') . '</a>';
    if ($b['documento']) $extra[] = '<i class="bi bi-file-earmark-text me-1"></i>Documento ' . e($b['documento']);
    if ($b['retorno_previsto']) $extra[] = '<i class="bi bi-calendar-check me-1"></i>Retorno previsto: ' . data_br($b['retorno_previsto']);
    $extra[] = '<i class="bi bi-pen me-1"></i>' . e(primeiro_ultimo((string)$b['registrado_nome']));
    $h .= '<div class="ba-meta">' . implode(' · ', $extra) . '</div></div></li>';
    return $h;
}

/** Formulário de nova providência (ficha do aluno e tela da busca ativa) */
function busca_formulario(array $aluno, int $faltas_recentes = 0): string
{
    $op = fn(array $lista, ?string $sel = null) => implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . e(is_array($v) ? $v[0] : $v) . '</option>', array_keys($lista), $lista));
    $resp = $aluno['responsavel_nome'] ?? ($aluno['nome_mae'] ?? '');
    $par = !empty($aluno['responsavel_nome']) ? ($aluno['responsavel_parentesco'] ?? '') : (!empty($aluno['nome_mae']) ? 'Mãe' : '');
    return '<form method="post" class="row g-2 ba-form" enctype="multipart/form-data">' . csrf_campo() . '<input type="hidden" name="acao" value="busca_add"><input type="hidden" name="aluno_id" value="' . (int)$aluno['id'] . '">
        <div class="col-12"><label class="form-label obrigatorio">Providência tomada</label><div class="ba-acoes">'
        . implode('', array_map(fn($k, $v) => '<label class="ba-op"><input type="radio" name="acao_busca" value="' . $k . '" required' . ($k === 'ligacao' ? ' checked' : '') . '><span><i class="bi bi-' . $v[1] . '"></i>' . e($v[0]) . '</span></label>', array_keys(ACOES_BUSCA), ACOES_BUSCA))
        . '</div></div>
        <div class="col-sm-6"><label class="form-label" for="ba_motivo">Motivo</label><select class="form-select" id="ba_motivo" name="motivo">' . $op(MOTIVOS_BUSCA, 'faltas') . '</select></div>
        <div class="col-sm-3"><label class="form-label obrigatorio" for="ba_data">Data</label><input class="form-control" type="date" id="ba_data" name="data_acao" value="' . date('Y-m-d') . '" max="' . date('Y-m-d') . '" required></div>
        <div class="col-sm-3"><label class="form-label" for="ba_hora">Hora</label><input class="form-control" type="time" id="ba_hora" name="hora" value="' . date('H:i') . '"></div>
        <div class="col-sm-5"><label class="form-label" for="ba_cn">Falou com</label><input class="form-control" id="ba_cn" name="contato_nome" maxlength="150" value="' . e($resp) . '" placeholder="Nome do responsável"></div>
        <div class="col-sm-3"><label class="form-label" for="ba_cp">Parentesco</label><input class="form-control" id="ba_cp" name="contato_parentesco" maxlength="40" value="' . e($par) . '" list="parentescos_ba"></div>
        <div class="col-sm-4"><label class="form-label" for="ba_ct">Telefone usado</label><input class="form-control" id="ba_ct" name="contato_telefone" maxlength="30" value="' . e($aluno['responsavel_telefone'] ?? '') . '"></div>
        <div class="col-sm-6"><label class="form-label obrigatorio" for="ba_res">Resultado</label><select class="form-select" id="ba_res" name="resultado" required>' . $op(RESULTADOS_BUSCA, 'contato_feito') . '</select></div>
        <div class="col-sm-3"><label class="form-label" for="ba_fi">Faltas informadas</label><input class="form-control" type="number" min="0" max="999" id="ba_fi" name="faltas_informadas" value="' . ($faltas_recentes ?: '') . '"></div>
        <div class="col-sm-3"><label class="form-label" for="ba_rp">Retorno previsto</label><input class="form-control" type="date" id="ba_rp" name="retorno_previsto"></div>
        <div class="col-12"><label class="form-label" for="ba_desc">O que foi informado e combinado</label><textarea class="form-control" id="ba_desc" name="descricao" rows="3" maxlength="4000" placeholder="Ex.: Informei à mãe as 4 faltas da semana. Ela relatou que o aluno estava doente e se comprometeu a enviar o atestado e garantir o retorno na segunda."></textarea></div>
        <div class="col-12"><label class="form-label obrigatorio" for="ba_comp">Comprovante do contato</label>
            <label class="ba-anexo" for="ba_comp"><i class="bi bi-cloud-arrow-up"></i><span><b>Anexar comprovante</b><small>Print da conversa, foto da ligação, áudio, ofício ou termo assinado (PDF, imagem, áudio ou Word · até 25 MB)</small></span></label>
            <input class="visually-hidden ba-anexo-in" type="file" id="ba_comp" name="comprovante" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.mp3,.m4a,.ogg,.opus,image/*,audio/*,application/pdf"
                   onchange="const l=this.previousElementSibling,f=this.files[0];l.classList.toggle(\'ok\',!!f);l.querySelector(\'b\').textContent=f?f.name:\'Anexar comprovante\';l.querySelector(\'small\').textContent=f?(f.size/1048576).toFixed(1).replace(\'.\',\',\')+\' MB · toque para trocar\':\'\'">
            <div class="form-text">Sem o comprovante a providência não é registrada.</div></div>
        <div class="col-sm-6"><label class="form-label" for="ba_doc">Nº do ofício / protocolo</label><input class="form-control" id="ba_doc" name="documento" maxlength="80" placeholder="Se houver documento"></div>
        <div class="col-sm-6 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Registrar providência</button></div>
        <datalist id="parentescos_ba"><option>Mãe</option><option>Pai</option><option>Avó</option><option>Avô</option><option>Tia</option><option>Tio</option><option>Responsável legal</option><option>O próprio aluno</option></datalist>
    </form>';
}
