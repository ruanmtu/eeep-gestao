<?php
/* Equipe no site: quem aparece na galeria de gestores e na galeria de funcionários da página da escola */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$grupos = $GRUPOS_EQUIPE;
$filtro = isset($grupos[$_GET['grupo'] ?? '']) ? $_GET['grupo'] : '';
$volta = 'equipe.php' . ($filtro ? '?grupo=' . $filtro : '');
$apagar_foto = function (?string $rel) { if ($rel && str_starts_with($rel, 'fotos/equipe/')) foto_apagar($rel); };   // foto vinda do usuário não é apagada

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $atual = $id ? q_um('SELECT * FROM equipe_site WHERE id = ?', [$id]) : null;
    try {
        switch ($acao) {
            case 'salvar':
                $nome = trim((string)($_POST['nome'] ?? '')); $funcao = trim((string)($_POST['funcao'] ?? ''));
                $grupo = isset($grupos[$_POST['grupo'] ?? '']) ? $_POST['grupo'] : 'professores';
                $texto = trim((string)($_POST['texto'] ?? '')); $ordem = (int)($_POST['ordem'] ?? 100);
                if (mb_strlen($nome) < 3 || mb_strlen($funcao) < 2) { flash('warning', 'Preencha o nome e a função.'); break; }
                if ($atual) q('UPDATE equipe_site SET nome = ?, funcao = ?, grupo = ?, texto = ?, ordem = ? WHERE id = ?', [mb_substr($nome, 0, 120), mb_substr($funcao, 0, 120), $grupo, $texto ? mb_substr($texto, 0, 300) : null, $ordem, $id]);
                else { q('INSERT INTO equipe_site (nome, funcao, grupo, texto, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, ?, 1, ?)', [mb_substr($nome, 0, 120), mb_substr($funcao, 0, 120), $grupo, $texto ? mb_substr($texto, 0, 300) : null, $ordem, date('Y-m-d H:i:s')]); $id = (int)db()->lastInsertId(); }
                if (!empty($_FILES['foto']['name'])) {
                    $nova = foto_salvar($_FILES['foto'], 'equipe', $id, null);
                    if ($atual && $atual['foto'] && $atual['foto'] !== $nova) $apagar_foto($atual['foto']);
                    q('UPDATE equipe_site SET foto = ? WHERE id = ?', [$nova, $id]);
                }
                $formacao = trim((string)($_POST['formacao'] ?? '')); $bio = trim((string)($_POST['biografia'] ?? ''));
                try { q('UPDATE equipe_site SET formacao = ?, biografia = ? WHERE id = ?', [$formacao !== '' ? mb_substr($formacao, 0, 255) : null, $bio !== '' ? mb_substr($bio, 0, 5000) : null, $id]); }
                catch (PDOException $e) { flash('warning', 'A biografia não foi salva: rode a atualização do banco (atualizacao_banco_v59.sql).'); }
                registrar_log('equipe_site', $nome . ' · ' . $grupos[$grupo]);
                flash('success', ($atual ? 'Dados atualizados: ' : 'Adicionado(a) à equipe do site: ') . $nome . '.');
                break;
            case 'tirar_foto':
                if ($atual) { $apagar_foto($atual['foto']); q('UPDATE equipe_site SET foto = NULL WHERE id = ?', [$id]); flash('success', 'Foto removida.'); }
                break;
            case 'ativo':
                if ($atual) { q('UPDATE equipe_site SET ativo = 1 - ativo WHERE id = ?', [$id]); flash('success', $atual['ativo'] ? $atual['nome'] . ' saiu do site (continua cadastrado).' : $atual['nome'] . ' voltou a aparecer no site.'); }
                break;
            case 'mover':
                if ($atual) {
                    $lista = equipe_lista($atual['grupo'], false); $ids = array_map('intval', array_column($lista, 'id'));
                    $i = array_search($id, $ids, true); $j = $i + ((int)$_POST['dir'] < 0 ? -1 : 1);
                    if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $k => $x) q('UPDATE equipe_site SET ordem = ? WHERE id = ?', [($k + 1) * 10, $x]); }
                }
                break;
            case 'excluir':
                if ($atual) { $apagar_foto($atual['foto']); q('DELETE FROM equipe_site WHERE id = ?', [$id]); flash('success', $atual['nome'] . ' foi removido(a) da equipe do site.'); }
                break;
            case 'importar':
                /* traz quem já tem acesso ao sistema, com a foto do perfil */
                $mapa = ['diretor' => 'gestao', 'coordenacao' => 'gestao', 'secretaria' => 'secretaria', 'bibliotecario' => 'multimeios', 'professor' => 'professores'];
                $ja = array_flip(array_map(fn($n) => mb_strtolower(trim($n)), array_column(equipe_lista(null, false), 'nome')));
                $n = 0;
                foreach (q_todos("SELECT * FROM usuarios WHERE ativo = 1 AND perfil <> 'admin' ORDER BY nome") as $u) {
                    if (isset($ja[mb_strtolower(trim($u['nome']))])) continue;
                    $g = $mapa[$u['perfil']] ?? 'professores';
                    q('INSERT INTO equipe_site (nome, funcao, grupo, foto, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, 100, 1, ?)',
                      [nome_proprio($u['nome']), cargo_usuario($u), $g, $u['foto'] ?: null, date('Y-m-d H:i:s')]);
                    $n++;
                }
                flash('success', $n ? "$n pessoa(s) trazida(s) do sistema. Confira as funções e as fotos." : 'Todos os usuários do sistema já estão na equipe do site.');
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    redirecionar($volta);
}

$todos = equipe_lista(null, false);
$contagem = array_count_values(array_column($todos, 'grupo'));
$lista = $filtro ? array_values(array_filter($todos, fn($e) => $e['grupo'] === $filtro)) : $todos;
usort($lista, fn($a, $b) => array_search($a['grupo'], array_keys($grupos)) <=> array_search($b['grupo'], array_keys($grupos)) ?: $a['ordem'] <=> $b['ordem'] ?: strcmp($a['nome'], $b['nome']));
$sem_foto = count(array_filter($todos, fn($e) => !$e['foto'] && $e['ativo']));
$icones = ['gestao' => 'star', 'professores' => 'easel2', 'secretaria' => 'folder2-open', 'multimeios' => 'pc-display', 'apoio' => 'tools'];

$titulo = 'Equipe no site';
$subtitulo = 'Quem aparece na página da escola: galeria de gestores e galeria de funcionários, com foto, nome e função.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="eq-topo">
    <div class="cf-resumo"><span><b><?= count(array_filter($todos, fn($e) => $e['ativo'])) ?></b> no site</span><span class="<?= $sem_foto ? 'ambar' : '' ?>"><b><?= $sem_foto ?></b> sem foto</span></div>
    <a class="btn btn-light btn-sm ms-auto" href="<?= RAIZ ?>index.php#equipe" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver no site</a>
    <form method="post" data-confirmar="Trazer para a equipe do site todas as pessoas com acesso ao sistema (com a foto do perfil)? Quem já está na lista não é repetido." data-botao="Trazer">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="importar"><button class="btn btn-light btn-sm" type="submit"><i class="bi bi-people me-1"></i>Trazer usuários do sistema</button></form>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#modalEq" data-novo="1"><i class="bi bi-person-plus me-1"></i>Adicionar pessoa</button>
</div>

<nav class="eq-grupos" aria-label="Grupos">
    <a class="<?= $filtro === '' ? 'ativo' : '' ?>" href="equipe.php"><i class="bi bi-grid"></i>Todos<b><?= count($todos) ?></b></a>
    <?php foreach ($grupos as $k => $g): ?><a class="<?= $filtro === $k ? 'ativo' : '' ?>" href="?grupo=<?= $k ?>"><i class="bi bi-<?= $icones[$k] ?>"></i><?= e($g) ?><b><?= $contagem[$k] ?? 0 ?></b></a><?php endforeach; ?>
</nav>
<p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i>O grupo <b>Gestão</b> aparece na galeria de gestores (com a frase de cada um). Todos aparecem na galeria <b>Nossa equipe</b>, separados pelos grupos. Use as setas para mudar a ordem.</p>

<?php if ($lista): ?>
<div class="eq-grade">
    <?php $grupo_ant = null; foreach ($lista as $e): $src = equipe_foto_src($e, RAIZ);
        if (!$filtro && $e['grupo'] !== $grupo_ant): $grupo_ant = $e['grupo']; ?><h3 class="eq-sep"><i class="bi bi-<?= $icones[$e['grupo']] ?? 'people' ?>"></i><?= e($grupos[$e['grupo']] ?? $e['grupo']) ?></h3><?php endif; ?>
    <article class="eq-card<?= $e['ativo'] ? '' : ' fora' ?>">
        <div class="eq-foto"><?php if ($src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(iniciais($e['nome'])) ?></span><em><i class="bi bi-camera"></i>sem foto</em><?php endif; ?>
            <?php if (!$e['ativo']): ?><b class="eq-fora">fora do site</b><?php endif; ?></div>
        <div class="eq-info"><strong><?= e($e['nome']) ?></strong><small><?= e($e['funcao']) ?></small></div>
        <div class="eq-acoes">
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $e['id'] ?>"><input type="hidden" name="dir" value="-1"><button type="submit" title="Mover para antes" aria-label="Mover para antes"><i class="bi bi-arrow-left"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $e['id'] ?>"><input type="hidden" name="dir" value="1"><button type="submit" title="Mover para depois" aria-label="Mover para depois"><i class="bi bi-arrow-right"></i></button></form>
            <button type="button" title="Editar" aria-label="Editar" data-bs-toggle="modal" data-bs-target="#modalEq" data-dados="<?= e(json_encode(['id' => (int)$e['id'], 'nome' => $e['nome'], 'funcao' => $e['funcao'], 'grupo' => $e['grupo'], 'texto' => (string)$e['texto'], 'ordem' => (int)$e['ordem'], 'foto' => $src, 'formacao' => (string)($e['formacao'] ?? ''), 'biografia' => (string)($e['biografia'] ?? '')], JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-pencil"></i></button>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button type="submit" title="<?= $e['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>" aria-label="<?= $e['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>"><i class="bi bi-<?= $e['ativo'] ? 'eye' : 'eye-slash' ?>"></i></button></form>
            <form method="post" data-confirmar="Remover <?= e($e['nome']) ?> da equipe do site?" data-botao="Remover"><?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button type="submit" class="perigo" title="Remover" aria-label="Remover"><i class="bi bi-trash"></i></button></form>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="vazio"><i class="bi bi-person-badge"></i><strong>Ninguém <?= $filtro ? 'neste grupo' : 'na equipe do site' ?> ainda</strong>Clique em "Adicionar pessoa" ou em "Trazer usuários do sistema" para começar.</div></div>
<?php endif; ?>

<div class="modal fade" id="modalEq" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><form method="post" enctype="multipart/form-data" class="modal-content">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" id="eqId">
    <div class="modal-header"><h5 class="modal-title" id="eqTit">Adicionar pessoa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <div class="eq-form-foto">
            <label class="eq-prev" for="eqFoto"><img id="eqPrev" alt="" hidden><span id="eqPrevIc"><i class="bi bi-camera"></i>Escolher foto</span></label>
            <div class="small text-secondary">Foto de rosto (JPG ou PNG). Depois de escolher, você ajusta o enquadramento.
                <input class="form-control form-control-sm mt-2" type="file" id="eqFoto" name="foto" data-recorte="4:5" data-saida="900" data-recorte-titulo="Enquadrar a foto da pessoa" accept="image/*"></div>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-12"><label class="form-label obrigatorio" for="eqNome">Nome</label><input class="form-control" id="eqNome" name="nome" maxlength="120" required></div>
            <div class="col-md-7"><label class="form-label obrigatorio" for="eqFuncao">Função na escola</label><input class="form-control" id="eqFuncao" name="funcao" maxlength="120" required placeholder="Ex.: Professora de Química, Merendeira, Porteiro" list="eqFuncoes">
                <datalist id="eqFuncoes"><?php foreach (['Diretor geral', 'Coordenador pedagógico', 'Coordenador de estágio', 'Coordenador administrativo-financeiro', 'Secretário escolar', 'Professor(a)', 'Professor(a) do Centro de Multimeios', 'Merendeira', 'Auxiliar de serviços gerais', 'Porteiro', 'Vigia'] as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-5"><label class="form-label" for="eqGrupo">Grupo</label><select class="form-select" id="eqGrupo" name="grupo"><?php foreach ($grupos as $k => $g): ?><option value="<?= $k ?>"><?= e($g) ?></option><?php endforeach; ?></select></div>
            <div class="col-12" id="eqTextoBox"><label class="form-label" for="eqTexto">Frase na galeria de gestores <span class="text-secondary fw-normal">· opcional</span></label><textarea class="form-control" id="eqTexto" name="texto" rows="2" maxlength="300" placeholder="O que essa pessoa faz pela escola"></textarea></div>
            <div class="col-12"><label class="form-label" for="eqForm">Formação <span class="text-secondary fw-normal">· aparece na página da pessoa no site</span></label><input class="form-control" id="eqForm" name="formacao" maxlength="255" placeholder="Ex.: Licenciatura em Química (UECE) · Especialização em Ensino de Ciências"></div>
            <div class="col-12"><label class="form-label" for="eqBio">Biografia <span class="text-secondary fw-normal">· um texto curto sobre a pessoa</span></label><textarea class="form-control" id="eqBio" name="biografia" rows="5" maxlength="5000" placeholder="Onde se formou, há quanto tempo está na escola, o que gosta de ensinar, projetos que coordena…"></textarea></div>
            <div class="col-5"><label class="form-label" for="eqOrdem">Ordem</label><input class="form-control" type="number" id="eqOrdem" name="ordem" value="100"></div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvar</button></div>
</form></div></div>

<?php
$script_pagina = '<script>
(function () {
  const m = document.getElementById("modalEq"), prev = document.getElementById("eqPrev"), ic = document.getElementById("eqPrevIc"), g = document.getElementById("eqGrupo"), tb = document.getElementById("eqTextoBox");
  const textoVis = () => tb.style.display = g.value === "gestao" ? "" : "none";
  g.addEventListener("change", textoVis);
  m.addEventListener("show.bs.modal", e => {
    const d = e.relatedTarget && e.relatedTarget.dataset.dados ? JSON.parse(e.relatedTarget.dataset.dados) : { id: "", nome: "", funcao: "", grupo: ' . json_encode($filtro ?: 'professores') . ', texto: "", ordem: 100, foto: null, formacao: "", biografia: "" };
    eqId.value = d.id; eqNome.value = d.nome; eqFuncao.value = d.funcao; g.value = d.grupo; eqTexto.value = d.texto; eqOrdem.value = d.ordem; eqFoto.value = ""; eqForm.value = d.formacao || ""; eqBio.value = d.biografia || "";
    eqTit.textContent = d.id ? "Editar " + d.nome : "Adicionar pessoa";
    if (d.foto) { prev.src = d.foto; prev.hidden = false; ic.hidden = true; } else { prev.hidden = true; ic.hidden = false; }
    textoVis();
  });
  eqFoto.addEventListener("change", () => { const f = eqFoto.files[0]; if (!f) return; prev.src = URL.createObjectURL(f); prev.hidden = false; ic.hidden = true; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
