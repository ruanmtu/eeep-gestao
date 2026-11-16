<?php
/* v66 · Conferência da planilha da biblioteca antes de importar (acervo ou leitores).
   Usa $bb_conf = ['imp' => sessão, 'itens' => análise, 'cont' => contagens] e $aba. Nada é gravado aqui. */
$ac = $aba === 'acervo';
$imp = $bb_conf['imp']; $itens = $bb_conf['itens']; $cont = $bb_conf['cont'];
$entram = count($itens) - $cont['erro'];
$rot_st = ['novo' => ['Novo', 'verde', 'plus-circle-fill'], 'nova' => ['Nova ficha', 'verde', 'plus-circle-fill'], 'atualiza' => ['Atualiza', 'azul', 'arrow-repeat'],
           'repetido' => ['Repetido', 'cinza', 'files'], 'erro' => ['Não entra', 'vermelho', 'x-circle-fill']];
$mostrar = array_slice($itens, 0, 2000);
?>
<section class="bc" aria-label="Conferência da planilha">
    <div class="bc-topo">
        <span class="bc-ic"><i class="bi bi-<?= $ac ? 'journal-check' : 'person-check' ?>"></i></span>
        <div class="bc-tx">
            <small>Importar <?= $ac ? 'acervo' : 'leitores' ?> · conferência</small>
            <h2>Confira antes de importar</h2>
            <p><i class="bi bi-file-earmark-spreadsheet me-1"></i><b><?= e($imp['arquivo']) ?></b> · <?= count($itens) ?> linha(s) lida(s). Nada foi gravado ainda.</p>
        </div>
        <ol class="bc-passos" aria-label="Etapas">
            <li class="feito"><span><i class="bi bi-check-lg"></i></span>Enviar</li>
            <li class="ativo"><span>2</span>Conferir</li>
            <li><span>3</span>Importar</li>
        </ol>
    </div>

    <div class="bc-nums" role="group" aria-label="Filtrar linhas">
        <button type="button" class="ativo" data-f="todos"><b><?= count($itens) ?></b><span>Todas as linhas</span></button>
        <button type="button" class="verde" data-f="novo"<?= $cont['novo'] ? '' : ' disabled' ?>><b><?= $cont['novo'] ?></b><span><?= $ac ? 'Livros novos' : 'Fichas novas' ?></span></button>
        <button type="button" class="azul" data-f="atualiza"<?= $cont['atualiza'] ? '' : ' disabled' ?>><b><?= $cont['atualiza'] ?></b><span>Já existem: atualizam</span></button>
        <button type="button" class="ambar" data-f="aviso"<?= $cont['aviso'] ? '' : ' disabled' ?>><b><?= $cont['aviso'] ?></b><span>Com aviso</span></button>
        <?php if ($cont['repetido']): ?><button type="button" class="cinza" data-f="repetido"><b><?= $cont['repetido'] ?></b><span>Repetidas na planilha</span></button><?php endif; ?>
        <button type="button" class="vermelho" data-f="erro"<?= $cont['erro'] ? '' : ' disabled' ?>><b><?= $cont['erro'] ?></b><span>Não vão entrar</span></button>
    </div>

    <div class="card bc-tab">
        <div class="card-header">
            <h2><i class="bi bi-list-check me-1"></i>Linha por linha</h2>
            <input type="search" class="form-control form-control-sm ms-auto" id="bcBusca" placeholder="<?= $ac ? 'Procurar título, autor ou ISBN…' : 'Procurar aluno ou turma…' ?>" style="max-width:280px" aria-label="Procurar">
        </div>
        <div class="table-responsive">
        <table class="table tabela bc-t mb-0">
            <thead><tr>
                <th style="width:54px">Linha</th><th style="width:120px">O que acontece</th>
                <?php if ($ac): ?><th>Título e autor</th><th>Editora · ano</th><th>ISBN</th><th>Categoria · local</th><th class="text-end">Exemplares</th>
                <?php else: ?><th>Aluno na planilha</th><th>Aluno encontrado</th><th>Ficha</th><th>Situação</th><th>Observação</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($mostrar as $it): [$rt, $rc, $ri] = $rot_st[$it['status']];
                $f = ($it['status'] === 'nova' ? 'novo' : $it['status']) . ($it['avisos'] && $it['status'] !== 'erro' ? ' aviso' : '');
                $busca = $ac ? $it['dados']['titulo'] . ' ' . $it['dados']['autor'] . ' ' . $it['dados']['isbn'] : $it['nome'] . ' ' . $it['turma_txt'] . ' ' . ($it['aluno']['nome'] ?? ''); ?>
                <tr class="bc-<?= $it['status'] ?>" data-f="<?= $f ?>" data-b="<?= e(mb_strtolower($busca)) ?>">
                    <td class="text-secondary small"><?= $it['linha'] ?></td>
                    <td><span class="etiqueta <?= $rc ?>"><i class="bi bi-<?= $ri ?>"></i><?= $rt ?></span></td>
                    <?php if ($ac): $d = $it['dados']; ?>
                    <td class="bc-livro"><b><?= $d['titulo'] !== '' ? e($d['titulo']) : '<em>sem título</em>' ?></b><small><?= $d['autor'] !== '' ? e($d['autor']) : '<em>sem autor</em>' ?></small>
                        <?php if ($it['motivo']): ?><span class="bc-msg <?= $it['status'] === 'erro' ? 'erro' : '' ?>"><i class="bi bi-<?= $it['status'] === 'erro' ? 'x-octagon' : 'info-circle' ?>"></i><?= e($it['motivo']) ?></span><?php endif; ?>
                        <?php foreach ($it['avisos'] as $av): ?><span class="bc-msg"><i class="bi bi-exclamation-triangle"></i><?= e($av) ?></span><?php endforeach; ?></td>
                    <td class="small"><?= e($d['editora'] ?: '—') ?><?= $d['ano'] ? ' · ' . $d['ano'] : '' ?></td>
                    <td class="small text-nowrap"><?php if ($d['isbn']): $ok = isbn_valido($d['isbn']); ?><span class="bc-isbn <?= $ok ? 'ok' : 'ruim' ?>" title="<?= $ok ? 'ISBN válido' : 'Confira: o dígito verificador não bate' ?>"><i class="bi bi-<?= $ok ? 'patch-check-fill' : 'exclamation-circle-fill' ?>"></i><?= e($d['isbn']) ?></span><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                    <td class="small"><?= e($d['categoria'] ?: '—') ?><?= $d['localizacao'] ? '<br><span class="text-secondary">' . e($d['localizacao']) . '</span>' : '' ?></td>
                    <td class="text-end text-nowrap"><?php if ($it['status'] === 'atualiza' && $it['ja']['exemplares'] !== max($d['exemplares'], $it['ja']['emp'])): ?><span class="bc-de"><?= $it['ja']['exemplares'] ?></span><i class="bi bi-arrow-right mx-1 text-secondary"></i><?php endif; ?><b><?= $it['status'] === 'atualiza' ? max($d['exemplares'], $it['ja']['emp']) : $d['exemplares'] ?></b></td>
                    <?php else: ?>
                    <td><b><?= e($it['nome'] ?: '—') ?></b><?= $it['turma_txt'] !== '' ? '<small class="d-block text-secondary">' . e($it['turma_txt']) . '</small>' : '' ?>
                        <?php if ($it['motivo']): ?><span class="bc-msg <?= $it['status'] === 'erro' ? 'erro' : '' ?>"><i class="bi bi-<?= $it['status'] === 'erro' ? 'x-octagon' : 'info-circle' ?>"></i><?= e($it['motivo']) ?></span><?php endif; ?>
                        <?php foreach ($it['avisos'] as $av): ?><span class="bc-msg"><i class="bi bi-exclamation-triangle"></i><?= e($av) ?></span><?php endforeach; ?></td>
                    <td><?php if ($it['aluno']): ?><span class="bc-achou"><i class="bi bi-person-check-fill"></i><?= e(nome_proprio($it['aluno']['nome'])) ?></span><small class="d-block text-secondary"><?= e($it['aluno']['turma']) ?></small><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                    <td class="text-nowrap"><?= $it['status'] === 'erro' ? '—' : ($it['ficha'] ? '<b>' . e($it['ficha']) . '</b>' : '<span class="text-secondary small">nº automático</span>') ?></td>
                    <td><?= $it['status'] === 'erro' ? '—' : ($it['bloq'] ? '<span class="etiqueta vermelho">Bloqueada</span>' : '<span class="etiqueta">Liberada</span>') ?></td>
                    <td class="small"><?= e($it['obs'] ?: '—') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="vazio py-4" id="bcVazio" hidden><i class="bi bi-search"></i>Nenhuma linha com esse filtro.</div>
        <?php if (count($itens) > count($mostrar)): ?><p class="small text-secondary px-3 py-2 mb-0">Mostrando as primeiras <?= count($mostrar) ?> linhas. Todas as <?= count($itens) ?> serão importadas.</p><?php endif; ?>
    </div>

    <div class="bc-barra">
        <div class="bc-resumo">
            <?php if ($entram): ?><i class="bi bi-check2-circle"></i><span><b><?= $entram ?></b> linha(s) vão entrar<?= $cont['erro'] ? ' · <b class="txt-ruim">' . $cont['erro'] . '</b> ficam de fora' : '' ?></span>
            <?php else: ?><i class="bi bi-x-octagon text-danger"></i><span>Nenhuma linha pode ser importada. Corrija a planilha e envie de novo.</span><?php endif; ?>
        </div>
        <form method="post" class="d-flex gap-2 ms-auto">
            <?= csrf_campo() ?><input type="hidden" name="token" value="<?= e($imp['token']) ?>">
            <button class="btn btn-light" name="acao" value="importar_cancelar" type="submit"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
            <button class="btn btn-primary" name="acao" value="importar_confirmar" type="submit"<?= $entram ? '' : ' disabled' ?>><i class="bi bi-cloud-check me-1"></i>Confirmar e importar<?= $entram ? ' ' . $entram : '' ?></button>
        </form>
    </div>
</section>
<script>
(function () {
  const linhas = [...document.querySelectorAll('.bc-t tbody tr')], vazio = document.getElementById('bcVazio'), busca = document.getElementById('bcBusca');
  let filtro = 'todos';
  function aplicar() {
    const q = (busca.value || '').trim().toLowerCase(); let n = 0;
    linhas.forEach(tr => { const ok = (filtro === 'todos' || tr.dataset.f.split(' ').includes(filtro)) && (!q || tr.dataset.b.includes(q)); tr.hidden = !ok; if (ok) n++; });
    vazio.hidden = n > 0;
  }
  document.querySelectorAll('.bc-nums button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.bc-nums button').forEach(x => x.classList.toggle('ativo', x === b)); filtro = b.dataset.f; aplicar();
  }));
  busca.addEventListener('input', aplicar);
})();
</script>
