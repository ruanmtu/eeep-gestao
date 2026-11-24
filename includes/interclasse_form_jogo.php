<?php
/* v77 · Campos do jogo (novo ou editar). Usa: $g (jogo ou padrão), $mods, $equipes_todas */
$gm = (int)($g['modalidade_id'] ?? 0);
$dh = !empty($g['data_hora']) ? date('Y-m-d\TH:i', strtotime($g['data_hora'])) : '';
$op_eq = function ($atual) use ($equipes_todas) {
    $h = '<option value="">— a definir —</option>';
    foreach ($equipes_todas as $e) $h .= '<option value="' . $e['id'] . '" data-mod="' . $e['modalidade_id'] . '"' . ((int)$atual === (int)$e['id'] ? ' selected' : '') . '>' . e($e['nome']) . ($e['grupo'] ? ' (Grupo ' . e($e['grupo']) . ')' : '') . '</option>';
    return $h;
};
?>
<div class="ic-fj vstack gap-2">
    <div class="row g-2">
        <div class="col-12"><label class="form-label small">Modalidade</label><select class="form-select ic-fj-mod" name="modalidade_id" required>
            <?php foreach ($mods as $m): ?><option value="<?= $m['id'] ?>"<?= (int)$m['id'] === $gm ? ' selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-7"><label class="form-label small">Fase</label><select class="form-select ic-fj-fase" name="fase"><?php foreach (IC_FASES as $k => $v): ?><option value="<?= $k ?>"<?= ($g['fase'] ?? 'grupos') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="col-5 ic-fj-grupo"><label class="form-label small">Grupo</label><input class="form-control" name="grupo" maxlength="10" value="<?= e($g['grupo'] ?? '') ?>" placeholder="A"></div>
    </div>
    <div class="row g-2">
        <div class="col-12 col-sm-6"><label class="form-label small">Equipe 1</label><select class="form-select ic-fj-eq" name="equipe_a"><?= $op_eq($g['equipe_a'] ?? 0) ?></select>
            <input class="form-control form-control-sm mt-1 ic-fj-rot" name="rotulo_a" maxlength="60" value="<?= e($g['rotulo_a'] ?? '') ?>" placeholder="Ainda não definida? Ex.: 1º do Grupo A"></div>
        <div class="col-12 col-sm-6"><label class="form-label small">Equipe 2</label><select class="form-select ic-fj-eq" name="equipe_b"><?= $op_eq($g['equipe_b'] ?? 0) ?></select>
            <input class="form-control form-control-sm mt-1 ic-fj-rot" name="rotulo_b" maxlength="60" value="<?= e($g['rotulo_b'] ?? '') ?>" placeholder="Ex.: Vencedor da semifinal 2"></div>
    </div>
    <div class="row g-2">
        <div class="col-12 col-sm-7"><label class="form-label small">Data e hora</label><input class="form-control" type="datetime-local" name="data_hora" value="<?= e($dh) ?>"></div>
        <div class="col-12 col-sm-5"><label class="form-label small">Local</label><input class="form-control" name="local" maxlength="80" value="<?= e($g['local'] ?? '') ?>" placeholder="Quadra" list="icLocais"></div>
        <datalist id="icLocais"><option value="Quadra"><option value="Quadra coberta"><option value="Campo"><option value="Pátio"></datalist>
        <div class="col-6"><label class="form-label small">Rodada <small class="text-secondary">(opcional)</small></label><input class="form-control" type="number" min="1" max="99" name="rodada" value="<?= e($g['rodada'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small" title="Posição do jogo no chaveamento (de cima para baixo)">Ordem na chave</label><input class="form-control" type="number" min="0" max="99" name="ordem" value="<?= (int)($g['ordem'] ?? 0) ?>"></div>
    </div>
</div>
<?php if (empty($GLOBALS['ic_fj_js'])): $GLOBALS['ic_fj_js'] = 1; ?>
<script>
document.querySelectorAll('.ic-fj').forEach(f => {
    const mod = f.querySelector('.ic-fj-mod'), fase = f.querySelector('.ic-fj-fase');
    const ajustar = () => {
        f.querySelectorAll('.ic-fj-eq').forEach(s => {
            s.querySelectorAll('option[data-mod]').forEach(o => { o.hidden = o.dataset.mod !== mod.value; if (o.hidden && o.selected) s.value = ''; });
            s.nextElementSibling.hidden = s.value !== '';
        });
        f.querySelector('.ic-fj-grupo').hidden = fase.value !== 'grupos';
    };
    f.addEventListener('change', ajustar); ajustar();
});
</script>
<?php endif; ?>
