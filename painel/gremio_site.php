<?php
/* v79 · Grêmio estudantil no site: foto, nome, curso e função de cada membro */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

$gid = (int)($_GET['editar'] ?? $_POST['id'] ?? 0);
$atual = null;
try { $atual = $gid ? q_um('SELECT * FROM site_gremio WHERE id = ?', [$gid]) : null; } catch (PDOException $e) {}
$cursos = []; try { $cursos = q_todos('SELECT nome FROM cursos WHERE ativo = 1 ORDER BY nome'); } catch (PDOException $e) {}
const GREMIO_FUNCOES = ['Presidente', 'Vice-presidente', 'Secretário(a) geral', '1º Secretário(a)', 'Tesoureiro(a)', '1º Tesoureiro(a)', 'Diretor(a) de comunicação', 'Diretor(a) de esportes', 'Diretor(a) de cultura', 'Diretor(a) social', 'Suplente'];

if (eh_post()) {
    csrf_verificar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        switch ($acao) {
            case 'salvar':
                $nome = nome_proprio(trim((string)($_POST['nome'] ?? '')));
                $funcao = trim((string)($_POST['funcao'] ?? ''));
                if (mb_strlen($nome) < 3 || $funcao === '') throw new RuntimeException('Preencha o nome e a função no grêmio.');
                $curso = trim(implode(' · ', array_filter([trim((string)($_POST['curso'] ?? '')), trim((string)($_POST['serie'] ?? ''))])));
                $d = [mb_substr($nome, 0, 120), mb_substr($funcao, 0, 80), $curso !== '' ? mb_substr($curso, 0, 120) : null, (int)($_POST['ordem'] ?? 100)];
                if ($atual) { q('UPDATE site_gremio SET nome = ?, funcao = ?, curso = ?, ordem = ? WHERE id = ?', [...$d, $gid]); $id = $gid; }
                else { q('INSERT INTO site_gremio (nome, funcao, curso, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)', [...$d, date('Y-m-d H:i:s')]); $id = (int)db()->lastInsertId(); }
                if (!empty($_FILES['foto']['name'])) {
                    $nova = foto_salvar($_FILES['foto'], 'gremio', $id, null);
                    if ($atual && $atual['foto'] && $atual['foto'] !== $nova) gremio_foto_apagar($atual['foto']);
                    q('UPDATE site_gremio SET foto = ? WHERE id = ?', [$nova, $id]);
                }
                flash('success', $nome . ' salvo(a) no grêmio.');
                break;
            case 'tirar_foto':
                if ($atual) { gremio_foto_apagar($atual['foto']); q('UPDATE site_gremio SET foto = NULL WHERE id = ?', [$gid]); flash('success', 'Foto removida.'); }
                redirecionar('gremio_site.php?editar=' . $gid);
            case 'ativo':
                if ($atual) q('UPDATE site_gremio SET ativo = 1 - ativo WHERE id = ?', [$gid]);
                break;
            case 'apagar':
                if ($atual) { gremio_foto_apagar($atual['foto']); q('DELETE FROM site_gremio WHERE id = ?', [$gid]); flash('success', $atual['nome'] . ' saiu do grêmio no site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); redirecionar('gremio_site.php' . ($atual ? '?editar=' . $gid : '')); }
    redirecionar('gremio_site.php');
}

$lista = gremio_lista(false);
$titulo = 'Grêmio estudantil';
$subtitulo = 'Quem faz parte do grêmio: aparece no site com foto, nome, curso e função.';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
$v = $atual ?? [];
[$v_curso, $v_serie] = array_pad(explode(' · ', (string)($v['curso'] ?? ''), 2), 2, '');
?>
<div class="row g-3">
    <div class="col-lg-4">
        <form method="post" enctype="multipart/form-data" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
            <div class="card-header"><h2><?= $atual ? 'Editar membro' : 'Novo membro' ?></h2><?php if ($atual): ?><a class="small" href="gremio_site.php">+ novo</a><?php endif; ?></div>
            <div class="card-body vstack gap-3">
                <label class="gr-prev" for="grFoto">
                    <?php if (!empty($v['foto'])): ?><img id="grPrev" src="<?= e(gremio_foto_src($v, '../')) ?>" alt=""><?php else: ?><img id="grPrev" alt="" hidden><span id="grPrevIc"><i class="bi bi-camera"></i>Escolher foto</span><?php endif; ?>
                </label>
                <input class="visually-hidden" id="grFoto" type="file" name="foto" accept="image/*"><small class="text-secondary text-center d-block" style="margin-top:-8px">Toque no quadro para escolher a foto (em pé, de preferência)</small>
                <div><label class="form-label" for="grN">Nome do estudante</label><input class="form-control" id="grN" name="nome" maxlength="120" required value="<?= e($v['nome'] ?? '') ?>"></div>
                <div><label class="form-label" for="grF">Função no grêmio</label><input class="form-control" id="grF" name="funcao" maxlength="80" required list="grFuncoes" value="<?= e($v['funcao'] ?? '') ?>" placeholder="Presidente"><datalist id="grFuncoes"><?php foreach (GREMIO_FUNCOES as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist></div>
                <div class="row g-2">
                    <div class="col-7"><label class="form-label" for="grC">Curso</label><input class="form-control" id="grC" name="curso" maxlength="80" list="grCursos" value="<?= e($v_curso) ?>" placeholder="Informática"><datalist id="grCursos"><?php foreach ($cursos as $c): ?><option value="<?= e($c['nome']) ?>"><?php endforeach; ?></datalist></div>
                    <div class="col-5"><label class="form-label" for="grS">Série</label><select class="form-select" id="grS" name="serie"><option value="">—</option><?php foreach (['1º ano', '2º ano', '3º ano'] as $s): ?><option<?= $v_serie === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
                </div>
                <div><label class="form-label" for="grO">Ordem <small class="text-secondary">(menor aparece primeiro)</small></label><input class="form-control" id="grO" type="number" name="ordem" value="<?= (int)($v['ordem'] ?? (count($lista) + 1)) ?>"></div>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $atual ? 'Salvar' : 'Incluir no grêmio' ?></button>
            </div>
        </form>
        <?php if (!empty($v['foto'])): ?><form method="post" class="text-center mt-2"><?= csrf_campo() ?><input type="hidden" name="acao" value="tirar_foto"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-link btn-sm text-danger">tirar a foto</button></form><?php endif; ?>
    </div>
    <div class="col-lg-8">
        <?php if (!$lista): ?><div class="vazio py-5"><i class="bi bi-people"></i>Nenhum membro cadastrado. A seção do grêmio só aparece no site quando houver alguém.</div><?php endif; ?>
        <div class="gr-grade">
            <?php foreach ($lista as $g): $src = gremio_foto_src($g, '../'); ?>
            <div class="gr-card<?= $g['ativo'] ? '' : ' off' ?><?= (int)$g['id'] === $gid ? ' atual' : '' ?>">
                <div class="gr-foto"><?php if ($src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(iniciais($g['nome'])) ?></span><?php endif; ?></div>
                <b><?= e($g['nome']) ?></b><span class="gr-fn"><?= e($g['funcao']) ?></span><small><?= e($g['curso'] ?: '') ?><?= $g['ativo'] ? '' : ' · oculto' ?></small>
                <div class="gr-acoes">
                    <a class="btn btn-sm btn-light" href="?editar=<?= $g['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $g['ativo'] ? 'Ocultar do site' : 'Mostrar no site' ?>"><i class="bi bi-eye<?= $g['ativo'] ? '-slash' : '' ?>"></i></button></form>
                    <form method="post" onsubmit="return confirm('Tirar <?= e($g['nome']) ?> do grêmio no site?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-light text-danger" title="Apagar"><i class="bi bi-trash3"></i></button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
document.getElementById('grFoto').addEventListener('change', e => {
    const f = e.target.files[0], img = document.getElementById('grPrev'), ic = document.getElementById('grPrevIc');
    if (!f) return; img.src = URL.createObjectURL(f); img.hidden = false; if (ic) ic.hidden = true;
});
</script>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
