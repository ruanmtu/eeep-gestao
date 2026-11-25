<?php
/* Grêmio estudantil no site: foto, nome, função, curso e série de cada membro.
 * v85: igual à galeria da equipe — lista de cartões e "Adicionar estudante" numa janela; foto com enquadramento e lixeira. */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$cursos = []; try { $cursos = q_todos('SELECT nome FROM cursos WHERE ativo = 1 ORDER BY nome'); } catch (PDOException $e) {}
const GREMIO_FUNCOES = ['Presidente', 'Vice-presidente', 'Secretário(a) geral', '1º Secretário(a)', 'Tesoureiro(a)', '1º Tesoureiro(a)', 'Diretor(a) de comunicação', 'Diretor(a) de esportes', 'Diretor(a) de cultura', 'Diretor(a) social', 'Diretor(a) de meio ambiente', 'Suplente'];
const GREMIO_SERIES = ['1º ano', '2º ano', '3º ano'];

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $atual = null;
    try { $atual = $id ? q_um('SELECT * FROM site_gremio WHERE id = ?', [$id]) : null; } catch (PDOException $e) {}
    try {
        switch ($acao) {
            case 'salvar':
                $nome = nome_proprio(trim((string)($_POST['nome'] ?? '')));
                $funcao = trim((string)($_POST['funcao'] ?? ''));
                if (mb_strlen($nome) < 3 || $funcao === '') throw new RuntimeException('Preencha o nome e a função no grêmio.');
                $curso = trim(implode(' · ', array_filter([trim((string)($_POST['curso'] ?? '')), trim((string)($_POST['serie'] ?? ''))])));
                $d = [mb_substr($nome, 0, 120), mb_substr($funcao, 0, 80), $curso !== '' ? mb_substr($curso, 0, 120) : null];
                if ($atual) { q('UPDATE site_gremio SET nome = ?, funcao = ?, curso = ? WHERE id = ?', [...$d, $id]); }
                else {
                    $ordem = (int)q_valor('SELECT COALESCE(MAX(ordem), 0) + 10 FROM site_gremio');   // novo entra no fim
                    q('INSERT INTO site_gremio (nome, funcao, curso, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)', [...$d, $ordem, date('Y-m-d H:i:s')]);
                    $id = (int)db()->lastInsertId();
                }
                if (!empty($_FILES['foto']['name'])) {
                    $nova = foto_salvar($_FILES['foto'], 'gremio', $id, null);
                    if ($atual && $atual['foto'] && $atual['foto'] !== $nova) gremio_foto_apagar($atual['foto']);
                    q('UPDATE site_gremio SET foto = ? WHERE id = ?', [$nova, $id]);
                }
                registrar_log('gremio_site', $nome . ' · ' . $funcao);
                flash('success', ($atual ? 'Dados atualizados: ' : 'Adicionado(a) ao grêmio: ') . $nome . '.');
                break;
            case 'tirar_foto':
                if ($atual) { gremio_foto_apagar($atual['foto']); q('UPDATE site_gremio SET foto = NULL WHERE id = ?', [$id]); flash('success', 'Foto de ' . $atual['nome'] . ' removida.'); }
                break;
            case 'ativo':
                if ($atual) { q('UPDATE site_gremio SET ativo = 1 - ativo WHERE id = ?', [$id]); flash('success', $atual['ativo'] ? $atual['nome'] . ' saiu do site (continua cadastrado).' : $atual['nome'] . ' voltou a aparecer no site.'); }
                break;
            case 'mover':
                if ($atual) {
                    $ids = array_map('intval', array_column(gremio_lista(false), 'id'));
                    $i = array_search($id, $ids, true); $j = $i + ((int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                    if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $k => $x) q('UPDATE site_gremio SET ordem = ? WHERE id = ?', [($k + 1) * 10, $x]); }
                }
                break;
            case 'excluir':
                if ($atual) { gremio_foto_apagar($atual['foto']); q('DELETE FROM site_gremio WHERE id = ?', [$id]); flash('success', $atual['nome'] . ' foi removido(a) do grêmio no site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    redirecionar('gremio_site.php');
}

$lista = gremio_lista(false);
$no_site = count(array_filter($lista, fn($g) => $g['ativo']));
$sem_foto = count(array_filter($lista, fn($g) => !$g['foto'] && $g['ativo']));
$titulo = 'Grêmio estudantil';
$subtitulo = 'Quem faz parte do grêmio: aparece no site com foto, nome, função e turma.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
$st_acoes = '<button class="btn btn-primary st-nav-add" type="button" data-bs-toggle="modal" data-bs-target="#modalGr"><i class="bi bi-person-plus"></i><span>Adicionar estudante</span></button>';
require __DIR__ . '/../includes/site_abas.php';
?>
<div class="eq-topo">
    <div class="cf-resumo"><span><b><?= $no_site ?></b> no site</span><span class="<?= $sem_foto ? 'ambar' : '' ?>"><b><?= $sem_foto ?></b> sem foto</span></div>
    <span class="small text-secondary ms-auto"><i class="bi bi-info-circle me-1"></i>Use as setas para mudar a ordem em que aparecem no site.</span>
</div>

<?php if ($lista): ?>
<div class="eq-grade gr-adm">
    <?php foreach ($lista as $g): $src = gremio_foto_src($g, '../');
        [$g_curso, $g_serie] = array_pad(explode(' · ', (string)($g['curso'] ?? ''), 2), 2, ''); ?>
    <article class="eq-card<?= $g['ativo'] ? '' : ' fora' ?>">
        <div class="eq-foto"><?php if ($src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(iniciais($g['nome'])) ?></span><em><i class="bi bi-camera"></i>sem foto</em><?php endif; ?>
            <?php if (!$g['ativo']): ?><b class="eq-fora">fora do site</b><?php endif; ?></div>
        <div class="eq-info"><strong><?= e($g['nome']) ?></strong><span class="gr-adm-fn"><?= e($g['funcao']) ?></span><?php if ($g['curso']): ?><small><?= e($g['curso']) ?></small><?php endif; ?></div>
        <div class="eq-acoes">
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $g['id'] ?>"><input type="hidden" name="dir" value="-1"><button type="submit" title="Mover para antes" aria-label="Mover para antes"><i class="bi bi-arrow-left"></i></button></form>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="<?= $g['id'] ?>"><input type="hidden" name="dir" value="1"><button type="submit" title="Mover para depois" aria-label="Mover para depois"><i class="bi bi-arrow-right"></i></button></form>
            <button type="button" title="Editar" aria-label="Editar" data-bs-toggle="modal" data-bs-target="#modalGr" data-dados="<?= e(json_encode(['id' => (int)$g['id'], 'nome' => $g['nome'], 'funcao' => $g['funcao'], 'curso' => $g_curso, 'serie' => $g_serie, 'foto' => $src], JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-pencil"></i></button>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button type="submit" title="<?= $g['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>" aria-label="<?= $g['ativo'] ? 'Tirar do site' : 'Mostrar no site' ?>"><i class="bi bi-<?= $g['ativo'] ? 'eye' : 'eye-slash' ?>"></i></button></form>
            <form method="post" data-confirmar="Remover <?= e($g['nome']) ?> do grêmio no site?" data-botao="Remover"><?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button type="submit" class="perigo" title="Remover" aria-label="Remover"><i class="bi bi-trash"></i></button></form>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="vazio"><i class="bi bi-flag"></i><strong>Ninguém no grêmio ainda</strong>Clique em "Adicionar estudante" para começar. A seção do grêmio só aparece no site quando houver alguém.</div></div>
<?php endif; ?>

<form method="post" id="grTirarFoto" hidden><?= csrf_campo() ?><input type="hidden" name="acao" value="tirar_foto"><input type="hidden" name="id" id="grTirarId"></form>
<div class="modal fade" id="modalGr" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><form method="post" enctype="multipart/form-data" class="modal-content">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" id="grId">
    <div class="modal-header"><h5 class="modal-title" id="grTit">Adicionar estudante</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body">
        <div class="gr-form">
            <div class="gr-form-foto">
                <label class="gr-prev2" for="grFoto"><img id="grPrev" alt="" hidden><span id="grPrevIc"><i class="bi bi-camera"></i>Escolher foto</span></label>
                <button class="gr-prev-lixo" type="submit" form="grTirarFoto" id="grLixo" title="Remover a foto" aria-label="Remover a foto" hidden><i class="bi bi-trash3"></i></button>
                <input class="visually-hidden" type="file" id="grFoto" name="foto" accept="image/*" data-recorte="4:5" data-saida="900" data-recorte-titulo="Enquadrar a foto do estudante">
                <small class="text-secondary">Toque na foto para escolher. Depois você ajusta o enquadramento.</small>
            </div>
            <div class="row g-3 flex-grow-1">
                <div class="col-12"><label class="form-label obrigatorio" for="grNome">Nome do estudante</label><input class="form-control" id="grNome" name="nome" maxlength="120" required></div>
                <div class="col-12"><label class="form-label obrigatorio" for="grFuncao">Função no grêmio</label><input class="form-control" id="grFuncao" name="funcao" maxlength="80" required list="grFuncoes" placeholder="Ex.: Presidente, Tesoureiro(a), Diretor(a) de cultura">
                    <datalist id="grFuncoes"><?php foreach (GREMIO_FUNCOES as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-7"><label class="form-label" for="grCurso">Curso</label><input class="form-control" id="grCurso" name="curso" maxlength="80" list="grCursos" placeholder="Informática">
                    <datalist id="grCursos"><?php foreach ($cursos as $c): ?><option value="<?= e($c['nome']) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-5"><label class="form-label" for="grSerie">Série</label><select class="form-select" id="grSerie" name="serie"><option value="">—</option><?php foreach (GREMIO_SERIES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvar</button></div>
</form></div></div>

<?php
$script_pagina = '<script>
(function () {
  const m = document.getElementById("modalGr"), prev = document.getElementById("grPrev"), ic = document.getElementById("grPrevIc"), lixo = document.getElementById("grLixo");
  m.addEventListener("show.bs.modal", e => {
    const d = e.relatedTarget && e.relatedTarget.dataset.dados ? JSON.parse(e.relatedTarget.dataset.dados) : { id: "", nome: "", funcao: "", curso: "", serie: "", foto: null };
    grId.value = d.id; grNome.value = d.nome; grFuncao.value = d.funcao; grCurso.value = d.curso || ""; grSerie.value = d.serie || ""; grFoto.value = "";
    grTit.textContent = d.id ? "Editar " + d.nome : "Adicionar estudante";
    grTirarId.value = d.id;
    if (d.foto) { prev.src = d.foto; prev.hidden = false; ic.hidden = true; } else { prev.hidden = true; ic.hidden = false; }
    lixo.hidden = !(d.id && d.foto);
  });
  grFoto.addEventListener("change", () => { const f = grFoto.files[0]; if (!f) return; prev.src = URL.createObjectURL(f); prev.hidden = false; ic.hidden = true; lixo.hidden = true; });
})();
</script>';
require __DIR__ . '/../includes/layout_rodape.php';
