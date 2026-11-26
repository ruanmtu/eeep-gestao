<?php
/* ======================================================================
 *  v96: card do carrossel "Novo curso técnico: Inteligência Artificial",
 *  no mesmo padrão animado dos cards de destaque (fundo verde animado,
 *  título dourado). Editável em Site › Banners e alunos destaque.
 * ====================================================================== */

const NOVO_CURSO_ICONES = ['Administração' => 'briefcase-fill', 'Enfermagem' => 'heart-pulse-fill', 'Informática' => 'laptop-fill'];

function novo_curso_cfg(): array
{
    $c = json_decode((string)config_ler('site_novo_curso'), true);
    $padrao = ['ativo' => 1, 'selo' => 'Novo curso técnico', 'linha1' => 'Inteligência', 'linha2' => 'Artificial',
        'aviso' => 'Edital em breve', 'outros_tit' => 'Também ofertamos', 'outros' => ['Administração', 'Enfermagem', 'Informática'], 'link' => 'editais.php'];
    if (!is_array($c)) $c = [];
    $c += $padrao;
    $c['outros'] = array_values(array_filter(array_slice((array)$c['outros'], 0, 4), fn($x) => trim((string)$x) !== ''));
    return $c;
}

function novo_curso_slide(array $c, int $i, int $total): string
{
    $logo = $GLOBALS['ESCOLA']['logo_branca'] ?? 'assets/img/logo-branca.png';
    $link = (string)($c['link'] ?: 'editais.php');
    $h = '<div class="sg-bn sg-bn-ia" aria-roledescription="slide" aria-label="' . ($i + 1) . ' de ' . $total . ': ' . e($c['selo'] . ' em ' . $c['linha1'] . ' ' . $c['linha2']) . '">'
       . '<div class="dq2-fundo" aria-hidden="true"><i class="dq2-bolha b1"></i><i class="dq2-bolha b2"></i><i class="dq2-bolha b3"></i><span class="ia-grade"></span></div>'
       . '<div class="ia-texto">'
       . '<img class="ia-logo" src="' . e($logo) . '" alt="" width="1400" height="451">'
       . ($c['selo'] ? '<p class="ia-selo"><i class="bi bi-stars"></i>' . e($c['selo']) . '</p>' : '')
       . '<h3 class="ia-tit"><span>' . e($c['linha1']) . '</span><em>' . e($c['linha2']) . '</em></h3>'
       . ($c['aviso'] ? '<a class="ia-aviso" href="' . e($link) . '"><span class="ia-pulso" aria-hidden="true"></span>' . e($c['aviso']) . '<i class="bi bi-arrow-right"></i></a>' : '')
       . '</div>'
       . '<div class="ia-arte" aria-hidden="true">' . novo_curso_arte() . '</div>';
    if ($c['outros']) {
        $h .= '<div class="ia-outros"><small>' . e($c['outros_tit'] ?: 'Também ofertamos') . '</small><ul>';
        foreach ($c['outros'] as $k => $o) $h .= '<li style="--k:' . $k . '"><i class="bi bi-' . (NOVO_CURSO_ICONES[$o] ?? 'mortarboard-fill') . '"></i>' . e($o) . '</li>';
        $h .= '</ul></div>';
    }
    return $h . '</div>';
}

/** Desenho de uma rede neural (pontos ligados) em volta de um chip, animado só com CSS. */
function novo_curso_arte(): string
{
    $nos = [[16, 22], [10, 50], [16, 78], [84, 22], [90, 50], [84, 78], [50, 8], [50, 92], [30, 36], [70, 36], [30, 64], [70, 64]];
    $lig = [[0, 8], [1, 8], [1, 10], [2, 10], [3, 9], [4, 9], [4, 11], [5, 11], [6, 8], [6, 9], [7, 10], [7, 11], [8, 9], [10, 11], [8, 10], [9, 11]];
    $s = '<svg viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet">';
    foreach ($lig as $k => [$a, $b]) $s .= '<line class="ia-fio" style="--k:' . $k . '" x1="' . $nos[$a][0] . '" y1="' . $nos[$a][1] . '" x2="' . $nos[$b][0] . '" y2="' . $nos[$b][1] . '"/>';
    foreach ($nos as $k => [$x, $y]) $s .= '<circle class="ia-no" style="--k:' . $k . '" cx="' . $x . '" cy="' . $y . '" r="' . ($k >= 8 ? 2.6 : 2) . '"/>';
    $s .= '<rect class="ia-chip" x="36" y="36" width="28" height="28" rx="6"/>';
    foreach ([42, 50, 58] as $p) $s .= '<line class="ia-pino" x1="' . $p . '" y1="31" x2="' . $p . '" y2="36"/><line class="ia-pino" x1="' . $p . '" y1="64" x2="' . $p . '" y2="69"/><line class="ia-pino" x1="31" y1="' . $p . '" x2="36" y2="' . $p . '"/><line class="ia-pino" x1="64" y1="' . $p . '" x2="69" y2="' . $p . '"/>';
    return $s . '<text class="ia-chip-txt" x="50" y="54.5" text-anchor="middle">IA</text></svg>';
}
