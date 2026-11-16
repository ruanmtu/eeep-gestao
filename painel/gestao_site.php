<?php
/* Gestão escolar no site: os 4 cartões de "Quem conduz a escola" (diretor + 3 coordenadores) */
require __DIR__ . '/../includes/app.php';
exigir('site.publicar');

const GESTAO_VAGAS = 4;

/** Linhas atuais da gestão (grupo 'gestao' da equipe do site), na ordem */
$gestao_linhas = fn() => q_todos("SELECT * FROM equipe_site WHERE grupo = 'gestao' ORDER BY ordem, id");

/** Na primeira vez, copia os gestores que hoje aparecem no site (com as fotos) para o painel */
$materializar = function () use ($gestao_linhas, $GESTORES): void {
    if ($gestao_linhas()) return;
    foreach (array_slice($GESTORES, 0, GESTAO_VAGAS) as $k => $g) {
        q('INSERT INTO equipe_site (nome, funcao, grupo, texto, ordem, ativo, criado_em) VALUES (?, ?, ?, ?, ?, 1, ?)',
          [$g['nome'], $g['cargo'], 'gestao', $g['texto'] ?? null, $k + 1, date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        $orig = dirname(__DIR__) . '/' . ($g['foto'] ?? '');
        if (!empty($g['foto']) && is_file($orig)) {
            $nome = 'fotos/equipe/' . $id . '_' . bin2hex(random_bytes(5)) . '.jpg';
            pasta_dados('fotos/equipe');
            if (@copy($orig, PASTA_DADOS . '/' . $nome)) q('UPDATE equipe_site SET foto = ? WHERE id = ?', [$nome, $id]);
        }
    }
};

if (eh_post()) {
    csrf_verificar();
    $acao = $_POST['acao'] ?? '';
    $lote = !empty($_POST['lote']);
    try {
        $materializar();
        $id = (int)($_POST['id'] ?? 0);
        $atual = $id ? q_um("SELECT * FROM equipe_site WHERE id = ? AND grupo = 'gestao'", [$id]) : null;
        switch ($acao) {
            case 'foto_grupo':
                if (!empty($_POST['tirar'])) { site_img_apagar(config_ler('site_gestao_foto')); config_gravar('site_gestao_foto', null); flash('success', 'O site voltou a usar a foto padrão da equipe gestora.'); break; }
                if (!empty($_FILES['foto_grupo']['name'])) {
                    [$arq] = site_img_salvar($_FILES['foto_grupo'], 'gestao', 'g');
                    site_img_apagar(config_ler('site_gestao_foto'));
                    config_gravar('site_gestao_foto', $arq);
                }
                $leg = trim((string)($_POST['legenda'] ?? ''));
                config_gravar('site_gestao_legenda', $leg !== '' ? mb_substr($leg, 0, 160) : null);
                registrar_log('gestao_site', 'foto da equipe');
                if (!$lote) flash('success', 'Foto da equipe gestora atualizada no site.');
                break;
            case 'legenda':
                $leg = trim((string)($_POST['legenda'] ?? ''));
                config_gravar('site_gestao_legenda', $leg !== '' ? mb_substr($leg, 0, 160) : null);
                flash('success', 'Legenda salva.');
                break;
            case 'salvar':
                $nome = trim((string)($_POST['nome'] ?? '')); $funcao = trim((string)($_POST['funcao'] ?? '')); $texto = trim((string)($_POST['texto'] ?? ''));
                if (mb_strlen($nome) < 3 || mb_strlen($funcao) < 2) throw new RuntimeException('Preencha o nome e a função.');
                $dados = [mb_substr($nome, 0, 120), mb_substr($funcao, 0, 120), $texto !== '' ? mb_substr($texto, 0, 300) : null];
                if ($atual) q('UPDATE equipe_site SET nome = ?, funcao = ?, texto = ?, ativo = 1 WHERE id = ?', [...$dados, $id]);
                else {
                    $ordem = (int)q_valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM equipe_site WHERE grupo = 'gestao'");
                    q("INSERT INTO equipe_site (nome, funcao, texto, grupo, ordem, ativo, criado_em) VALUES (?, ?, ?, 'gestao', ?, 1, ?)", [...$dados, $ordem, date('Y-m-d H:i:s')]);
                    $id = (int)db()->lastInsertId(); $atual = null;
                }
                if (!empty($_FILES['foto']['name'])) {
                    $nova = foto_salvar($_FILES['foto'], 'equipe', $id, null);
                    if ($atual && $atual['foto'] && str_starts_with($atual['foto'], 'fotos/equipe/') && $atual['foto'] !== $nova) foto_apagar($atual['foto']);
                    q('UPDATE equipe_site SET foto = ? WHERE id = ?', [$nova, $id]);
                }
                registrar_log('gestao_site', $nome);
                if (!$lote) flash('success', $nome . ' salvo. Já aparece no site.');
                break;
            case 'mover':
                $ids = array_map('intval', array_column($gestao_linhas(), 'id'));
                $k = array_search($id, $ids, true); $d = (int)($_POST['dir'] ?? 0);
                if ($k !== false && isset($ids[$k + $d])) [$ids[$k], $ids[$k + $d]] = [$ids[$k + $d], $ids[$k]];
                foreach ($ids as $i => $x) q('UPDATE equipe_site SET ordem = ? WHERE id = ?', [$i + 1, $x]);
                break;
            case 'remover':
                if ($atual) { if ($atual['foto'] && str_starts_with($atual['foto'], 'fotos/equipe/')) foto_apagar($atual['foto']); q('DELETE FROM equipe_site WHERE id = ?', [$id]); flash('success', $atual['nome'] . ' saiu da gestão no site.'); }
                break;
        }
    } catch (RuntimeException $e) { flash('warning', $e->getMessage()); }
    redirecionar('gestao_site.php');
}

$linhas = [];
try { $linhas = $gestao_linhas(); } catch (PDOException $e) {}
$usando_padrao = !$linhas;
if ($usando_padrao) foreach (array_slice($GESTORES, 0, GESTAO_VAGAS) as $k => $g) $linhas[] = ['id' => 0, 'nome' => $g['nome'], 'funcao' => $g['cargo'], 'texto' => $g['texto'] ?? '', 'foto' => null, 'src_padrao' => is_file(dirname(__DIR__) . '/' . $g['foto']) ? RAIZ . $g['foto'] : null];

$titulo = 'Gestão escolar no site';
$subtitulo = 'Os 4 cartões de "Quem conduz a escola": o primeiro é o diretor (cartão verde).';
$menu = 'site';
require __DIR__ . '/../includes/layout_topo.php';
require __DIR__ . '/../includes/site_abas.php';
?>
<?php if ($usando_padrao): ?><div class="alert alert-info small d-flex gap-2"><i class="bi bi-info-circle-fill"></i><div>Estes são os dados que o site mostra hoje. Ao salvar qualquer cartão, os 4 passam a ser editados por aqui.</div></div><?php endif; ?>
<?php $gfoto = config_ler('site_gestao_foto'); $gleg = config_ler('site_gestao_legenda', ''); ?>
<form method="post" enctype="multipart/form-data" class="card gst-grupo mb-3" data-lote data-lote-acao="foto_grupo">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="foto_grupo">
    <label class="gst-grupo-foto" title="Trocar a foto da equipe">
        <img src="<?= $gfoto ? e(RAIZ . 'foto_site.php?g=1&v=' . substr(md5($gfoto), 0, 8)) : RAIZ . 'assets/img/gestao-equipe.jpg' ?>" alt="Foto da equipe gestora">
        <input type="file" name="foto_grupo" accept="image/*" hidden data-recorte="2:1" data-saida="1800" data-previa="label" data-recorte-titulo="Enquadrar a foto da equipe gestora">
        <em><i class="bi bi-camera"></i> Trocar foto</em>
    </label>
    <div class="gst-grupo-txt">
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-people-fill me-1 text-success"></i>Foto da equipe gestora</h2>
        <p class="small text-secondary mb-2">Aparece no site acima dos 4 cartões. Use uma foto na horizontal (ex.: 1600 × 800).</p>
        <input class="form-control form-control-sm mb-2" name="legenda" maxlength="160" value="<?= e((string)$gleg) ?>" placeholder="Legenda (opcional). Ex.: Equipe gestora 2026">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-light btn-sm" type="button" onclick="this.closest('form').querySelector('input[type=file]').click()"><i class="bi bi-crop me-1"></i>Alterar foto</button>
            <?php if ($gfoto): ?><button class="btn btn-light btn-sm" type="submit" name="tirar" value="1" onclick="return confirm('Voltar para a foto padrão?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Foto padrão</button><?php endif; ?>
        </div>
    </div>
</form>
<div class="gst-grade">
    <?php for ($k = 0; $k < max(GESTAO_VAGAS, count($linhas)); $k++): $g = $linhas[$k] ?? null; $src = $g ? ($g['src_padrao'] ?? equipe_foto_src($g, RAIZ)) : null; ?>
    <form method="post" enctype="multipart/form-data" class="card gst-card<?= $k === 0 ? ' diretor' : '' ?><?= $k >= GESTAO_VAGAS ? ' extra' : '' ?>" data-lote data-lote-acao="salvar">
        <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($g['id'] ?? 0) ?>">
        <div class="gst-topo">
            <span class="gst-pos"><?= $k === 0 ? '<i class="bi bi-star-fill"></i> Diretor(a)' : ($k < GESTAO_VAGAS ? 'Cartão ' . ($k + 1) : 'Não aparece (só os 4 primeiros)') ?></span>
            <?php if (!empty($g['id'])): ?>
            <span class="ms-auto d-flex gap-1">
                <button class="btn btn-sm btn-light" formaction="gestao_site.php" name="acao" value="mover" onclick="this.form.dir.value=-1" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Subir"><i class="bi bi-arrow-up"></i></button>
                <button class="btn btn-sm btn-light" name="acao" value="mover" onclick="this.form.dir.value=1" <?= $k >= count($linhas) - 1 ? 'disabled' : '' ?> aria-label="Descer"><i class="bi bi-arrow-down"></i></button>
                <button class="btn btn-sm btn-light" name="acao" value="remover" onclick="return confirm('Tirar esta pessoa da gestão no site?')" aria-label="Remover"><i class="bi bi-trash"></i></button>
            </span>
            <?php endif; ?>
            <input type="hidden" name="dir" value="0">
        </div>
        <div class="gst-corpo">
            <label class="gst-foto" title="Trocar a foto">
                <?php if ($src): ?><img src="<?= e($src) ?>" alt=""><?php else: ?><span><i class="bi bi-camera"></i>Foto</span><?php endif; ?>
                <input type="file" name="foto" accept="image/*" hidden data-recorte="4:5" data-saida="900" data-previa="label" data-recorte-titulo="Enquadrar a foto do gestor">
                <em><i class="bi bi-pencil"></i></em>
            </label>
            <div class="gst-campos">
                <input class="form-control fw-bold" name="nome" maxlength="120" required placeholder="Nome" value="<?= e((string)($g['nome'] ?? '')) ?>" aria-label="Nome">
                <input class="form-control form-control-sm mt-2" name="funcao" maxlength="120" required placeholder="Função (ex.: Coordenadora pedagógica)" value="<?= e((string)($g['funcao'] ?? '')) ?>" aria-label="Função">
                <textarea class="form-control form-control-sm mt-2" name="texto" rows="3" maxlength="300" placeholder="Texto curto sobre o que faz" aria-label="Texto"><?= e((string)($g['texto'] ?? '')) ?></textarea>
            </div>
        </div>
    </form>
    <?php endfor; ?>
</div>
<p class="small text-secondary mt-3"><i class="bi bi-lightbulb me-1"></i>Toque na foto para trocar e enquadrar. Faça todas as mudanças e salve uma vez só no botão que aparece embaixo. Para cadastrar professores e funcionários, use <a href="equipe.php">Equipe no site</a>.</p>
<?php require __DIR__ . '/../includes/layout_rodape.php'; ?>
