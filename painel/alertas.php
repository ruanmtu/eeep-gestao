<?php
/* Alerta de faltas: alunos com faltas seguidas e registro do contato com a família.
 * Gestão vê a escola toda; o(a) diretor(a) de turma vê só as turmas dele(a). */
require __DIR__ . '/../includes/app.php';
exigir_login();

$gestao = eh_gestao();
$dt_turmas = turmas_dirigidas();
if (!$gestao && !$dt_turmas) { flash('warning', 'O alerta de faltas é da gestão e dos diretores de turma.'); redirecionar('index.php'); }
$ids_dt = array_map('intval', array_column($dt_turmas, 'id'));

/* registrar contato com a família (o alerta sai da lista e vira ocorrência pedagógica + busca ativa) */
if (eh_post() && ($_POST['acao'] ?? '') === 'tratar_alerta') {
    csrf_verificar();
    $aluno = (int)($_POST['aluno_id'] ?? 0);
    $fim = (string)($_POST['fim'] ?? '');
    $dias = (int)($_POST['dias'] ?? 0);
    $obs = trim((string)($_POST['observacao'] ?? ''));
    $al = $aluno ? aluno_buscar($aluno) : null;
    $pode = $al && (pode('alertas.tratar') || in_array((int)$al['turma_id'], $ids_dt, true));
    if ($pode && data_valida($fim)) {
        if (!q_valor('SELECT id FROM alertas_contato WHERE aluno_id = ? AND sequencia_fim = ?', [$aluno, $fim]))
            q('INSERT INTO alertas_contato (aluno_id, sequencia_fim, dias, observacao, resolvido_por) VALUES (?, ?, ?, ?, ?)', [$aluno, $fim, $dias, $obs ?: null, uid()]);
        try {
            $u0 = usuario();
            q('INSERT INTO busca_ativa (aluno_id, data_acao, hora, acao, motivo, faltas_informadas, resultado, descricao, registrado_por, registrado_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [$aluno, date('Y-m-d'), date('H:i:s'), 'ligacao', 'faltas', $dias, 'contato_feito', $obs ?: "Família informada sobre $dias faltas seguidas.", uid(), $u0['nome'], date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {}
        q('INSERT INTO ocorrencias (aluno_id, data_ocorrencia, tipo, gravidade, descricao, providencias, responsavel_notificado, registrado_por) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
          [$aluno, date('Y-m-d'), 'pedagogica', 'media', "Alerta de $dias faltas seguidas (até " . data_br($fim) . ').', $obs ?: 'Contato com o responsável realizado.', uid()]);
        registrar_log('alerta_tratado', "$dias faltas seguidas até " . data_br($fim), $aluno);
        flash('success', 'Contato registrado. O alerta saiu da lista e foi anotado na ficha do aluno.');
    } else flash('warning', 'Não foi possível registrar o contato deste aluno.');
    redirecionar('alertas.php');
}

/* alertas visíveis para quem está vendo */
$alertas = alertas_sequencia(true);
if (!$gestao) {
    $ids_al = $ids_dt ? array_map('intval', array_column(q_todos('SELECT id FROM alunos WHERE turma_id IN (' . implode(',', $ids_dt) . ")"), 'id')) : [];
    $alertas = array_values(array_filter($alertas, fn($a) => in_array((int)$a['aluno_id'], $ids_al, true)));
}
usort($alertas, fn($a, $b) => count($b['dias']) <=> count($a['dias']));
$turmas_af = [];
foreach ($alertas as $a) $turmas_af[turma_nome($a)] = ($turmas_af[turma_nome($a)] ?? 0) + 1;
ksort($turmas_af);
$maior = $alertas ? max(array_map(fn($a) => count($a['dias']), $alertas)) : 0;
$ativos = count(array_filter($alertas, fn($a) => !empty($a['ativa'])));

/* contatos já registrados (histórico) */
$hist = [];
try {
    $w = $gestao ? '1 = 1' : ($ids_dt ? 'a.turma_id IN (' . implode(',', $ids_dt) . ')' : '0 = 1');
    $hist = q_todos("SELECT c.*, a.nome, t.serie, t.letra, cu.sigla, u.nome AS por FROM alertas_contato c JOIN alunos a ON a.id = c.aluno_id
                      LEFT JOIN turmas t ON t.id = a.turma_id LEFT JOIN cursos cu ON cu.id = t.curso_id LEFT JOIN usuarios u ON u.id = c.resolvido_por
                      WHERE $w ORDER BY c.id DESC LIMIT 30");
} catch (PDOException $e) {}
$contatos_mes = count(array_filter($hist, fn($h) => substr((string)($h['criado_em'] ?? $h['resolvido_em'] ?? ''), 0, 7) === date('Y-m')));

$titulo = 'Alerta de faltas';
$subtitulo = $gestao ? 'Alunos com ' . FALTAS_SEGUIDAS_ALERTA . ' ou mais faltas seguidas na escola' : 'Alunos da sua turma com ' . FALTAS_SEGUIDAS_ALERTA . ' ou mais faltas seguidas';
$menu = 'alertas';
require __DIR__ . '/../includes/layout_topo.php';
?>
<section class="af-hero mb-3">
    <span class="af-deco" aria-hidden="true"><i></i></span>
    <span class="af-ic"><i class="bi bi-exclamation-triangle-fill"></i></span>
    <div class="af-tx">
        <span class="af-sobre"><span class="pulso"></span><?= $gestao ? 'Escola toda' : 'Minha turma · ' . e(implode(', ', array_map('turma_nome', $dt_turmas))) ?></span>
        <h2><?= count($alertas) ?> aluno<?= count($alertas) === 1 ? '' : 's' ?> com <?= FALTAS_SEGUIDAS_ALERTA ?> ou mais faltas seguidas</h2>
        <p>Entre em contato com os responsáveis e registre a conversa: o aluno sai da lista e o contato fica na ficha dele.</p>
    </div>
    <div class="af-nums">
        <span><b><?= $maior ?></b><small>maior sequência</small></span>
        <span><b><?= $ativos ?></b><small>faltaram no último dia</small></span>
        <span><b><?= count($turmas_af) ?></b><small>turma<?= count($turmas_af) === 1 ? '' : 's' ?> com alerta</small></span>
    </div>
</section>

<?php if (!$alertas): ?>
<div class="card af-ok mb-4"><div class="card-body"><span><i class="bi bi-check2-circle"></i></span><div><strong>Nenhum aluno com <?= FALTAS_SEGUIDAS_ALERTA ?> faltas seguidas</strong><small>Os alertas aparecem aqui assim que a coleta de faltas indicar faltas seguidas.</small></div></div></div>
<?php else: ?>
<div class="af-ferr mb-3">
    <div class="busca-rapida"><i class="bi bi-search"></i><input class="form-control" id="afBusca" placeholder="Procurar aluno…" aria-label="Procurar aluno"></div>
    <?php if (count($turmas_af) > 1): ?><div class="af-turmas" role="group" aria-label="Filtrar por turma"><button type="button" class="ativo" data-t="">Todas <b><?= count($alertas) ?></b></button><?php foreach ($turmas_af as $tn => $n): ?><button type="button" data-t="<?= e($tn) ?>"><?= e($tn) ?> <b><?= $n ?></b></button><?php endforeach; ?></div><?php endif; ?>
</div>

<section class="alerta-sequencia af-lista mb-4" id="alertas" aria-label="Alunos com faltas seguidas">
    <ul>
        <?php foreach ($alertas as $a):
            $tel = $a['responsavel_telefone'] ?: $a['telefone'];
            $wpp = link_whatsapp($tel, 'Olá! Aqui é da ' . $ESCOLA['nome'] . '. Gostaríamos de conversar sobre a frequência de ' . nome_proprio($a['nome']) . '.'); ?>
            <li data-t="<?= e(turma_nome($a)) ?>" data-b="<?= e(norm($a['nome'])) ?>">
                <span class="selo-sequencia"><i class="bi bi-exclamation-triangle-fill"></i><?= count($a['dias']) ?> seguidas</span>
                <span class="al-ini" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(nome_proprio($a['nome']), 0, 1) . mb_substr(strrchr(' ' . trim($a['nome']), ' '), 1, 1))) ?></span>
                <div class="flex-grow-1 al-quem" style="min-width:200px">
                    <a class="nome-link" href="aluno.php?id=<?= $a['aluno_id'] ?>#frequencia"><?= e(nome_proprio($a['nome'])) ?></a>
                    <div class="small text-secondary"><?= e(turma_nome($a)) ?><?= $a['ativa'] ? ' · <strong class="text-danger">faltou também no último dia letivo</strong>' : '' ?><?= $a['responsavel_nome'] ?? '' ? ' · resp.: ' . e($a['responsavel_nome']) : '' ?></div>
                </div>
                <div class="dias"><?php foreach ($a['dias'] as $d): ?><span class="dia"><?= e(dias_curtos([$d])) ?></span><?php endforeach; ?></div>
                <div class="d-flex gap-2 alerta-acoes">
                    <?php if ($wpp): ?><a class="btn btn-sm btn-light" href="<?= e($wpp) ?>" target="_blank" rel="noopener" title="WhatsApp do responsável"><i class="bi bi-whatsapp text-success"></i><span class="d-none d-xl-inline ms-1"><?= e($tel) ?></span></a>
                    <?php else: ?><a class="btn btn-sm btn-light" href="aluno_form.php?id=<?= $a['aluno_id'] ?>" title="Cadastrar telefone do responsável"><i class="bi bi-telephone-plus"></i></a><?php endif; ?>
                    <button class="btn btn-sm btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#modalAlerta"
                            data-aluno="<?= $a['aluno_id'] ?>" data-nome="<?= e(nome_proprio($a['nome'])) ?>" data-fim="<?= $a['fim'] ?>" data-dias="<?= count($a['dias']) ?>">Registrar contato</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="vazio py-4" id="afVazio" hidden><i class="bi bi-search"></i>Ninguém com esse filtro.</div>
</section>
<?php endif; ?>

<section class="card af-hist">
    <div class="card-header"><h2><i class="bi bi-telephone-inbound me-1"></i>Contatos já registrados</h2><span class="etiqueta"><?= count($hist) ?></span><small class="ms-auto text-secondary">últimos 30</small></div>
    <?php if ($hist): ?><ul class="af-hist-l">
        <?php foreach ($hist as $h): ?>
        <li><span class="af-hist-ic"><i class="bi bi-check2"></i></span>
            <div class="min-w-0"><a class="nome-link" href="aluno.php?id=<?= (int)$h['aluno_id'] ?>#ocorrencias"><?= e(nome_proprio($h['nome'])) ?></a><small><?= e(($h['serie'] ? $h['serie'] . 'º ' . $h['letra'] . ' ' . $h['sigla'] : '')) ?> · <?= (int)$h['dias'] ?> faltas até <?= e(data_br($h['sequencia_fim'])) ?></small>
                <?php if ($h['observacao']): ?><p><?= e($h['observacao']) ?></p><?php endif; ?></div>
            <small class="af-hist-por"><?= e(primeiro_ultimo((string)($h['por'] ?? '—'))) ?></small></li>
        <?php endforeach; ?></ul>
    <?php else: ?><div class="vazio py-4"><i class="bi bi-telephone"></i>Nenhum contato registrado ainda.</div><?php endif; ?>
</section>

<!-- Modal: registrar contato -->
<div class="modal fade" id="modalAlerta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content ux-modal" method="post">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="tratar_alerta">
        <input type="hidden" name="aluno_id" id="alAluno"><input type="hidden" name="fim" id="alFim"><input type="hidden" name="dias" id="alDias">
        <div class="ux-topo perigo"><span class="ux-ic"><i class="bi bi-telephone-outbound"></i></span><div><small>Registrar contato com a família</small><h5 id="alNome">—</h5><span><b id="alDiasTxt"></b> faltas seguidas</span></div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <label class="form-label" for="alObs">O que foi conversado</label>
            <textarea class="form-control" id="alObs" name="observacao" rows="3" placeholder="Ex.: mãe informou que o aluno está doente; trará atestado na segunda."></textarea>
            <div class="form-text">Fica registrado na ficha do aluno (ocorrência pedagógica e busca ativa).</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" type="submit"><i class="bi bi-check2 me-1"></i>Salvar contato</button></div>
    </form></div>
</div>
<?php
$script_pagina = '<script>
(function () {
  const m = document.getElementById("modalAlerta");
  if (m) m.addEventListener("show.bs.modal", e => { const b = e.relatedTarget; alAluno.value = b.dataset.aluno; alFim.value = b.dataset.fim; alDias.value = b.dataset.dias; alNome.textContent = b.dataset.nome; alDiasTxt.textContent = b.dataset.dias; });
  const busca = document.getElementById("afBusca"); let turma = "";
  const norm = s => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const filtra = () => { const q = norm((busca && busca.value || "").trim()); let n = 0;
    document.querySelectorAll(".af-lista li").forEach(li => { const ok = (!turma || li.dataset.t === turma) && (!q || li.dataset.b.includes(q)); li.hidden = !ok; if (ok) n++; });
    const v = document.getElementById("afVazio"); if (v) v.hidden = n > 0; };
  if (busca) busca.addEventListener("input", filtra);
  document.querySelectorAll(".af-turmas button").forEach(b => b.addEventListener("click", () => { document.querySelectorAll(".af-turmas button").forEach(x => x.classList.toggle("ativo", x === b)); turma = b.dataset.t; filtra(); }));
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
