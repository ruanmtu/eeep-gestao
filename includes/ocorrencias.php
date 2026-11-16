<?php
/* =====================================================================
 *  Registro de ocorrências: controle dos atos infracionais do estudante
 *  (advertência verbal, retirada de sala, suspensão, fora do mapa de
 *  sala, saída sem permissão, desrespeito...). Substitui as antigas
 *  "Ocorrências" e "Atos administrativos".
 * ===================================================================== */

/* chave => [rótulo, gravidade sugerida, ícone] */
const CATEGORIAS_OCORRENCIA = [
    'advertencia_verbal'      => ['Advertência verbal', 'leve', 'chat-left-text'],
    'advertencia_escrita'     => ['Advertência escrita', 'media', 'file-earmark-text'],
    'retirada_sala'           => ['Retirada de sala de aula', 'media', 'door-open'],
    'suspensao'               => ['Suspensão', 'grave', 'slash-circle'],
    'comportamento'           => ['Comportamento inadequado', 'leve', 'emoji-frown'],
    'fora_mapa'               => ['Fora do mapa de turma', 'leve', 'grid-3x3-gap'],
    'saida_sem_permissao'     => ['Saiu da sala sem permissão', 'media', 'box-arrow-right'],
    'desrespeito'             => ['Desrespeito a professor ou servidor', 'grave', 'person-x'],
    'celular'                 => ['Uso indevido do celular', 'leve', 'phone'],
    'atraso'                  => ['Atraso / chegada após o horário', 'leve', 'clock-history'],
    'fardamento'              => ['Sem fardamento', 'leve', 'person-badge'],
    'sem_atividade'           => ['Sem material ou atividade', 'leve', 'journal-x'],
    'agressao'                => ['Briga, agressão ou bullying', 'grave', 'exclamation-octagon'],
    'patrimonio'              => ['Dano ao patrimônio', 'grave', 'hammer'],
    'convocacao_responsavel'  => ['Convocação do responsável', 'media', 'telephone-outbound'],
    'outro'                   => ['Outra ocorrência', 'leve', 'three-dots'],
];

const LOCAIS_OCORRENCIA = ['Sala de aula', 'Laboratório', 'Pátio', 'Corredor', 'Refeitório', 'Quadra', 'Banheiro', 'Biblioteca / multimeios', 'Portaria', 'Transporte escolar', 'Fora da escola'];

function categoria_ocorrencia(array $o): array
{
    $c = CATEGORIAS_OCORRENCIA[$o['categoria'] ?? 'outro'] ?? CATEGORIAS_OCORRENCIA['outro'];
    /* registros antigos (antes do Registro de ocorrências) */
    if (($o['categoria'] ?? 'outro') === 'outro' && isset($o['tipo']) && $o['tipo'] !== 'disciplinar' && isset(TIPOS_OCORRENCIA[$o['tipo']])) $c[0] = 'Ocorrência ' . mb_strtolower(TIPOS_OCORRENCIA[$o['tipo']]);
    return $c;
}

function cor_ocorrencia(array $o): string
{
    if (($o['tipo'] ?? '') === 'elogio') return '';
    return ['grave' => 'vermelho', 'media' => 'ambar', 'leve' => 'cinza'][$o['gravidade']] ?? 'cinza';
}

/** Grava o registro para um ou vários alunos. Devolve [tipo_flash, mensagem, ids] */
function ocorrencia_salvar(array $p): array
{
    $alunos = array_values(array_unique(array_filter(array_map('intval', (array)($p['alunos'] ?? [$p['aluno_id'] ?? 0])))));
    $cat = (string)($p['categoria'] ?? '');
    $data = (string)($p['data_ocorrencia'] ?? '');
    $hora = preg_match('/^\d{2}:\d{2}$/', (string)($p['hora'] ?? '')) ? $p['hora'] . ':00' : null;
    $grav = isset(GRAVIDADES[$p['gravidade'] ?? '']) ? $p['gravidade'] : (CATEGORIAS_OCORRENCIA[$cat][1] ?? 'leve');
    $desc = trim((string)($p['descricao'] ?? ''));
    $local = trim((string)($p['local'] ?? ''));
    $ini = (string)($p['suspensao_inicio'] ?? '');
    $fim = (string)($p['suspensao_fim'] ?? '');
    if (!$alunos) return ['warning', 'Escolha pelo menos um aluno.', []];
    if (!isset(CATEGORIAS_OCORRENCIA[$cat])) return ['warning', 'Escolha o que aconteceu.', []];
    if (!data_valida($data) || $data > date('Y-m-d')) return ['warning', 'Informe uma data válida (não futura).', []];
    if ($desc === '') $desc = CATEGORIAS_OCORRENCIA[$cat][0] . '.';
    if ($cat === 'suspensao') {
        if (!data_valida($ini) || !data_valida($fim) || $fim < $ini) return ['warning', 'Na suspensão, informe o primeiro e o último dia (o fim não pode ser antes do início).', []];
    } else { $ini = $fim = ''; }
    $ids = [];
    foreach ($alunos as $aid) {
        if (!aluno_buscar($aid)) continue;
        q('INSERT INTO ocorrencias (aluno_id, data_ocorrencia, hora, categoria, local, suspensao_inicio, suspensao_fim, tipo, gravidade, descricao, providencias, responsavel_notificado, registrado_por)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$aid, $data, $hora, $cat, $local !== '' ? mb_substr($local, 0, 60) : null, $ini ?: null, $fim ?: null, 'disciplinar', $grav, $desc,
           trim((string)($p['providencias'] ?? '')) ?: null, !empty($p['responsavel_notificado']) ? 1 : 0, uid()]);
        $ids[] = (int)db()->lastInsertId();
        registrar_log('ocorrencia', CATEGORIAS_OCORRENCIA[$cat][0] . ' em ' . data_br($data), $aid);
    }
    if (!$ids) return ['warning', 'Aluno não encontrado.', []];
    ocorrencia_notificar($ids);
    return ['success', CATEGORIAS_OCORRENCIA[$cat][0] . ' registrada' . (count($ids) > 1 ? ' para ' . count($ids) . ' alunos' : '') . '.', $ids];
}

/**
 * Avisa a coordenação e o(a) diretor(a) de turma de cada aluno logo depois do registro.
 * Uma notificação por turma (ex.: a mesma retirada de sala para 3 alunos da mesma turma).
 */
function ocorrencia_notificar(array $ids): void
{
    if (!$ids) return;
    try {
        $lista = ocorrencias_lista('o.id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 100);
        $por_turma = [];
        foreach ($lista as $o) $por_turma[(int)($o['turma_id'] ?? 0)][] = $o;
        $autor = usuario();
        $coord = array_map('intval', array_column(q_todos("SELECT id FROM usuarios WHERE ativo = 1 AND perfil = 'coordenacao'"), 'id'));
        foreach ($por_turma as $tid => $os) {
            $o = $os[0];
            $cat = categoria_ocorrencia($o)[0];
            $nomes = array_map(fn($x) => primeiro_ultimo(nome_proprio($x['nome'])), $os);
            $turma = $o['serie'] ? $o['serie'] . 'º ' . $o['letra'] . ($o['sigla'] ?? '' ? ' ' . $o['sigla'] : '') : 'sem turma';
            $titulo = count($os) > 1 ? "$cat · " . count($os) . " alunos do $turma" : "$cat · " . $nomes[0];
            $msg = (count($os) > 1 ? implode(', ', $nomes) . '. ' : "$turma. ")
                 . ($o['local'] ? $o['local'] . ($o['hora'] ? ', ' . substr($o['hora'], 0, 5) : '') . '. ' : ($o['hora'] ? substr($o['hora'], 0, 5) . '. ' : ''))
                 . 'Registrado por ' . primeiro_ultimo((string)($autor['nome'] ?? 'Sistema')) . '. '
                 . mb_strimwidth((string)$o['descricao'], 0, 160, '…');
            $link = count($os) > 1 ? 'index.php#ocorrencias-hoje' : 'aluno.php?id=' . (int)$o['aluno_id'] . '#ocorrencias';
            $grave = in_array('grave', array_column($os, 'gravidade'), true);
            /* diretor(a) da turma: aviso próprio, que abre a ocorrência no painel da turma dele */
            $dt = $tid ? (int)q_valor('SELECT diretor_turma_id FROM turmas WHERE id = ?', [$tid]) : 0;
            if ($dt && $dt !== uid()) {
                $quem = primeiro_ultimo((string)($autor['nome'] ?? 'Sistema'));
                notificar(['usuarios' => [$dt]],
                    'Ocorrência na sua turma · ' . (count($os) > 1 ? count($os) . ' alunos do ' . $turma : $nomes[0]),
                    'Prof. ' . $quem . ' registrou "' . $cat . '"' . (count($os) > 1 ? ' para ' . implode(', ', $nomes) : '') . ($o['hora'] ? ' às ' . substr($o['hora'], 0, 5) : '') . '. '
                    . mb_strimwidth((string)$o['descricao'], 0, 140, '…') . ' Clique para ver mais.',
                    'ocorrencia', 'minha_turma.php?turma=' . $tid . '&aba=ocorrencias' . (count($os) === 1 ? '#oc-' . (int)$o['id'] : ''), $grave);
            }
            /* coordenação (todos do perfil), sem repetir o DT e sem avisar quem registrou */
            $dest = array_values(array_diff(array_unique($coord), [uid(), $dt]));
            if ($dest) notificar(['usuarios' => $dest], $titulo, $msg, 'ocorrencia', $link, $grave);
        }
    } catch (PDOException $e) { /* o registro já foi gravado; o aviso é complementar */ }
}

/** Ocorrências de hoje que o usuário acompanha: a gestão vê todas; o diretor de turma, as das suas turmas; o professor, as que registrou */
function ocorrencias_hoje(): array
{
    $hoje = date('Y-m-d');
    try {
        if (pode('turmas.todas')) return ocorrencias_lista('o.data_ocorrencia = ?', [$hoje], 100);
        return ocorrencias_lista('o.data_ocorrencia = ? AND (t.diretor_turma_id = ? OR o.registrado_por = ?)', [$hoje, uid(), uid()], 100);
    } catch (PDOException $e) { return []; }
}

/** Formulário de registro. Com $aluno, o aluno já vem escolhido. */
function form_ocorrencia(?array $aluno = null, string $acao = '', array $pre = []): string
{
    static $n = 0; $n++;
    $f = 'oc' . $n;
    ob_start(); ?>
    <form method="post" action="<?= e($acao) ?>" class="form-ocorrencia" id="<?= $f ?>" autocomplete="off">
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="ocorrencia_add">
        <?php if ($aluno): ?>
            <input type="hidden" name="alunos[]" value="<?= (int)$aluno['id'] ?>">
        <?php else: ?>
            <label class="form-label obrigatorio" for="<?= $f ?>_busca">Aluno(s)</label>
            <div class="coleta-busca"><i class="bi bi-person"></i>
                <input type="search" class="form-control oc-busca" id="<?= $f ?>_busca" placeholder="Digite o nome · pode escolher mais de um" role="combobox" aria-expanded="false">
                <div class="busca-resultados oc-lista" hidden></div></div>
            <div class="oc-escolhidos mt-2"></div>
        <?php endif; ?>

        <label class="form-label obrigatorio mt-3">O que aconteceu</label>
        <div class="oc-categorias">
            <?php foreach (CATEGORIAS_OCORRENCIA as $k => [$rot, $grav, $ic]): ?>
                <label class="oc-cat grav-<?= $grav ?>"><input type="radio" name="categoria" value="<?= $k ?>" data-grav="<?= $grav ?>" required<?= ($pre['categoria'] ?? '') === $k ? ' checked' : '' ?>>
                    <i class="bi bi-<?= $ic ?>"></i><span><?= e($rot) ?></span></label>
            <?php endforeach; ?>
        </div>

        <div class="row g-2 mt-1">
            <div class="col-7"><label class="form-label obrigatorio" for="<?= $f ?>_data">Data</label>
                <input type="date" class="form-control" id="<?= $f ?>_data" name="data_ocorrencia" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
            <div class="col-5"><label class="form-label" for="<?= $f ?>_hora">Hora</label>
                <input type="time" class="form-control" id="<?= $f ?>_hora" name="hora" value="<?= date('H:i') ?>"></div>
            <div class="col-7"><label class="form-label" for="<?= $f ?>_local">Local</label>
                <input class="form-control" id="<?= $f ?>_local" name="local" list="<?= $f ?>_locais" value="Sala de aula" maxlength="60">
                <datalist id="<?= $f ?>_locais"><?php foreach (LOCAIS_OCORRENCIA as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist></div>
            <div class="col-5"><label class="form-label" for="<?= $f ?>_grav">Gravidade</label>
                <select class="form-select oc-grav" id="<?= $f ?>_grav" name="gravidade"><?php $gpre = isset($pre['categoria']) ? (CATEGORIAS_OCORRENCIA[$pre['categoria']][1] ?? '') : ''; foreach (GRAVIDADES as $k => $v): ?><option value="<?= $k ?>"<?= $gpre === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 oc-suspensao" hidden><div class="row g-2">
                <div class="col-6"><label class="form-label obrigatorio" for="<?= $f ?>_si">Suspenso de</label><input type="date" class="form-control" id="<?= $f ?>_si" name="suspensao_inicio"></div>
                <div class="col-6"><label class="form-label obrigatorio" for="<?= $f ?>_sf">até</label><input type="date" class="form-control" id="<?= $f ?>_sf" name="suspensao_fim"></div></div></div>
            <div class="col-12"><label class="form-label" for="<?= $f ?>_desc">Descrição</label>
                <textarea class="form-control" id="<?= $f ?>_desc" name="descricao" rows="2" placeholder="Ex.: durante a aula de Matemática, saiu da sala sem autorização e voltou após 20 minutos."><?= e($pre['descricao'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label" for="<?= $f ?>_prov">Providência tomada</label>
                <input class="form-control" id="<?= $f ?>_prov" name="providencias" placeholder="Ex.: conversa com o aluno; encaminhado à coordenação"></div>
        </div>
        <div class="d-flex flex-wrap gap-3 align-items-center mt-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="responsavel_notificado" value="1" id="<?= $f ?>_not"><label class="form-check-label" for="<?= $f ?>_not">Responsável foi avisado</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="imprimir" value="1" id="<?= $f ?>_imp"><label class="form-check-label" for="<?= $f ?>_imp">Imprimir termo</label></div>
            <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-shield-plus me-1"></i>Registrar</button>
        </div>
    </form>
    <script>
    (function () {
      const f = document.getElementById(<?= json_encode($f) ?>);
      f.querySelectorAll('input[name=categoria]').forEach(r => r.addEventListener('change', () => {
        f.querySelector('.oc-grav').value = r.dataset.grav;
        const sus = f.querySelector('.oc-suspensao'); sus.hidden = r.value !== 'suspensao';
        sus.querySelectorAll('input').forEach(i => i.required = r.value === 'suspensao');
      }));
      const busca = f.querySelector('.oc-busca');
      if (!busca) return;
      const lista = f.querySelector('.oc-lista'), esc = f.querySelector('.oc-escolhidos');
      const add = a => {
        if (esc.querySelector(`input[value="${a.id}"]`)) return;
        const c = document.createElement('span'); c.className = 'chip-aluno';
        c.innerHTML = `<input type="hidden" name="alunos[]" value="${a.id}">${a.foto ? `<img class="avatar avatar-xs" src="${a.foto}" alt="">` : ''}${window.esc(a.nome)} <small>${window.esc(a.turma)}</small><button type="button" aria-label="Tirar">×</button>`;
        c.querySelector('button').onclick = () => c.remove();
        esc.appendChild(c);
      };
      const iniciar = () => window.comboBusca({ input: busca, lista, url: q => 'api.php?acao=buscar&q=' + encodeURIComponent(q), vazio: 'Nenhum aluno ativo encontrado.',
        item: a => `${a.foto ? `<img class="avatar avatar-xs" src="${a.foto}" alt="">` : '<i class="bi bi-person"></i>'}<span>${window.esc(a.nome)}</span><span class="turma">${window.esc(a.turma)}</span>`,
        escolher: a => { add(a); busca.value = ''; busca.focus(); } });
      window.comboBusca ? iniciar() : document.addEventListener('DOMContentLoaded', iniciar);
      f.addEventListener('submit', e => { if (!esc.querySelector('input')) { e.preventDefault(); busca.focus(); busca.classList.add('is-invalid'); } });
    })();
    </script>
    <?php return ob_get_clean();
}

/** Cartão de um registro (lista geral e ficha do aluno) */
function ocorrencia_cartao(array $o, bool $mostrar_aluno = true, string $volta = ''): string
{
    [$rot, , $ic] = categoria_ocorrencia($o);
    ob_start(); ?>
    <article class="registro registro-oc grav-<?= e($o['gravidade']) ?>">
        <div class="topo-registro">
            <span class="oc-icone"><i class="bi bi-<?= $ic ?>"></i></span>
            <div class="flex-grow-1 min-w-0">
                <strong><?= e($rot) ?></strong>
                <?php if ($mostrar_aluno): ?> · <a class="nome-link" href="aluno.php?id=<?= $o['aluno_id'] ?>#ocorrencias"><?= e(nome_proprio($o['nome'])) ?></a>
                    <span class="etiqueta contorno"><?= e(turma_nome($o)) ?></span><?php endif; ?>
                <div class="small text-secondary"><?= data_br($o['data_ocorrencia']) ?><?= !empty($o['hora']) ? ' às ' . substr($o['hora'], 0, 5) : '' ?><?= $o['local'] ? ' · ' . e($o['local']) : '' ?> · por <?= e($o['por'] ?? '—') ?></div>
            </div>
            <span class="etiqueta <?= cor_ocorrencia($o) ?>"><?= e(GRAVIDADES[$o['gravidade']] ?? '') ?></span>
            <a class="btn btn-sm btn-light" href="ocorrencia_termo.php?id=<?= $o['id'] ?>" target="_blank" title="Imprimir termo"><i class="bi bi-printer"></i></a>
            <?php if (pode('ocorrencias.excluir')): ?>
                <form method="post" action="ocorrencias.php" data-confirmar="Apagar este registro?" data-botao="Apagar">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="remover"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="volta" value="<?= e($volta) ?>">
                    <button class="btn btn-sm btn-light" type="submit" aria-label="Apagar"><i class="bi bi-trash"></i></button>
                </form>
            <?php endif; ?>
        </div>
        <p><?= e($o['descricao']) ?></p>
        <?php if ($o['suspensao_inicio']): ?><p class="mt-1"><strong>Suspensão:</strong> de <?= data_br($o['suspensao_inicio']) ?> a <?= data_br($o['suspensao_fim']) ?></p><?php endif; ?>
        <?php if ($o['providencias']): ?><p class="mt-1"><strong>Providência:</strong> <?= e($o['providencias']) ?></p><?php endif; ?>
        <div class="rodape-registro"><?= $o['responsavel_notificado'] ? '<i class="bi bi-check2-circle text-success"></i> Responsável avisado' : '<i class="bi bi-dash-circle"></i> Responsável ainda não avisado' ?></div>
    </article>
    <?php return ob_get_clean();
}

/** Consulta padrão de ocorrências com aluno, turma e autor */
function ocorrencias_lista(string $where, array $p, int $limite = 300): array
{
    return q_todos('SELECT o.*, a.nome, a.foto, a.matricula, a.responsavel_telefone, a.telefone, a.turma_id, t.serie, t.letra, c.nome AS curso, c.sigla, u.nome AS por, u.foto AS por_foto
                      FROM ocorrencias o JOIN alunos a ON a.id = o.aluno_id
                      LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
                      LEFT JOIN usuarios u ON u.id = o.registrado_por
                     WHERE ' . $where . ' ORDER BY o.data_ocorrencia DESC, o.hora DESC, o.id DESC LIMIT ' . $limite, $p);
}

/** Cartão "Ocorrências registradas hoje" do painel inicial (também devolvido sozinho para atualizar ao vivo) */
function cartao_ocorrencias_hoje(): string
{
    $lista = ocorrencias_hoje();
    $graus = array_count_values(array_column($lista, 'gravidade'));
    $alunos = count(array_unique(array_column($lista, 'aluno_id')));
    $escopo = pode('turmas.todas') ? 'toda a escola' : 'suas turmas e seus registros';
    ob_start(); ?>
    <div class="card cartao-oc-hoje" id="ocorrencias-hoje" data-total="<?= count($lista) ?>" data-escola="<?= pode('turmas.todas') ? 1 : 0 ?>">
        <div class="card-header">
            <div><h2><i class="bi bi-shield-exclamation me-1"></i>Ocorrências registradas hoje</h2>
                <small class="text-secondary"><?= count($lista) ? count($lista) . ' registro(s) · ' . $alunos . ' aluno(s) · ' : '' ?><?= e($escopo) ?></small></div>
            <div class="acoes">
                <?php foreach (['grave' => 'vermelho', 'media' => 'ambar', 'leve' => 'cinza'] as $g => $cor) if (!empty($graus[$g])): ?>
                    <span class="etiqueta <?= $cor ?>"><?= $graus[$g] ?> <?= mb_strtolower(GRAVIDADES[$g]) ?></span>
                <?php endif; ?>
                <a class="btn btn-sm btn-light" href="ocorrencias.php?de=<?= date('Y-m-d') ?>&amp;ate=<?= date('Y-m-d') ?>" title="Ver todas"><i class="bi bi-list-ul oc-ic"></i><span class="oc-tx">Ver todas</span></a>
                <a class="btn btn-sm btn-danger" href="ocorrencias.php#nova" title="Registrar ocorrência"><i class="bi bi-plus-lg me-1"></i><span class="oc-tx">Registrar</span></a>
            </div>
        </div>
        <?php if ($lista): ?>
        <ul class="oc-hoje-lista">
            <?php foreach ($lista as $o): [$rot, , $ic] = categoria_ocorrencia($o); $cor = cor_ocorrencia($o); $nova = strtotime((string)$o['criado_em']) > time() - 900; ?>
            <li class="grav-<?= e($o['gravidade']) ?><?= $nova ? ' nova' : '' ?>">
                <span class="oc-hora"><b><?= $o['hora'] ? substr($o['hora'], 0, 5) : date('H:i', strtotime((string)$o['criado_em'])) ?></b><?= $nova ? '<em>agora</em>' : '' ?></span>
                <?= avatar(['id' => $o['aluno_id'], 'nome' => $o['nome'], 'foto' => $o['foto']], 'aluno', 'avatar-sm') ?>
                <div class="flex-grow-1 min-w-0">
                    <a class="nome-link fw-semibold" href="aluno.php?id=<?= (int)$o['aluno_id'] ?>#ocorrencias"><?= e(nome_proprio($o['nome'])) ?></a>
                    <small class="text-secondary"> · <?= e($o['serie'] ? $o['serie'] . 'º ' . $o['letra'] . ' ' . ($o['sigla'] ?: $o['curso']) : 'Sem turma') ?></small>
                    <div class="oc-hoje-cat"><span class="etiqueta <?= $cor ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= e($rot) ?></span>
                        <?php if ($o['local']): ?><small class="text-secondary"><i class="bi bi-geo-alt"></i> <?= e($o['local']) ?></small><?php endif; ?></div>
                    <?php if (trim((string)$o['descricao']) !== '' && $o['descricao'] !== $rot . '.'): ?><p class="oc-hoje-desc"><?= e(mb_strimwidth((string)$o['descricao'], 0, 180, '…')) ?></p><?php endif; ?>
                </div>
                <span class="oc-por" title="Registrado por"><?= avatar(['id' => $o['registrado_por'], 'nome' => $o['por'] ?? '?', 'foto' => $o['por_foto'] ?? null], 'usuario', 'avatar-sm') ?><small><?= e(primeiro_ultimo((string)($o['por'] ?? '—'))) ?></small></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="vazio py-4"><i class="bi bi-emoji-smile"></i><strong>Nenhuma ocorrência hoje</strong>Quando um professor registrar, ela aparece aqui na hora.</div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/** Quem vê o painel completo de ocorrências (números e registros de todos). Os demais veem só o que registraram. */
function ocorrencias_ve_tudo(): bool
{
    return pode('ocorrencias.todas') || pode('ocorrencias.excluir') || pode('alertas.tratar');
}
