<?php
/* ======================================================================
 *  v87 — Card "Venha ser EEEP!" do carrossel, no mesmo modelo animado do
 *  card de alunos destaque (logo, título dourado, fundo animado).
 *  Tudo editável em Site › Banners e alunos destaque.
 * ====================================================================== */

const VENHA_ICONES = ['journal-bookmark-fill', 'clock-fill', 'briefcase-fill'];

function venha_cfg(): array
{
    $c = json_decode((string)config_ler('site_venha'), true);
    $padrao = ['ativo' => 1, 'linha1' => 'Venha ser', 'linha2' => 'EEEP!', 'selo' => 'Seleção ' . (date('Y') + 1),
        'sub' => 'Saia do ensino médio com uma profissão.',
        'itens' => [['t' => 'Ensino médio + técnico', 'd' => 'Dois diplomas em 3 anos'], ['t' => 'Tempo integral', 'd' => 'Aulas, laboratórios e projetos'], ['t' => 'Estágio no 3º ano', 'd' => 'Experiência no mercado de trabalho']],
        'botao' => 'Conheça os cursos', 'link' => '#cursos'];
    if (!is_array($c)) { $c = $padrao; config_gravar('site_venha', json_encode($c, JSON_UNESCAPED_UNICODE)); }
    $c += $padrao;
    $c['itens'] = array_values(array_filter(array_slice((array)$c['itens'], 0, 3), fn($x) => trim((string)($x['t'] ?? '')) !== ''));
    return $c;
}

/** Foto do card. Na primeira vez, grava a foto dos estudantes em frente à escola. */
function venha_foto(): string
{
    $f = (string)config_ler('site_venha_foto');
    if ($f === '' && !config_ler('site_venha_semeado')) {
        config_gravar('site_venha_semeado', '1');
        try {
            $bin = base64_decode((string)(require __DIR__ . '/venha_foto_inicial.php'), true);
            if ($bin) {
                $pasta = pasta_dados('site/banners'); $nome = 'venha_' . bin2hex(random_bytes(6));
                if (@file_put_contents("$pasta/$nome.jpg", $bin) && @copy("$pasta/$nome.jpg", "$pasta/{$nome}_p.jpg")) {
                    $f = "site/banners/$nome.jpg"; config_gravar('site_venha_foto', $f);
                }
            }
        } catch (Throwable $e) {}
    }
    return $f;
}

/** É o banner de imagem antigo do "Venha ser EEEP"? (sai do carrossel quando o card novo está ligado) */
function venha_eh_antigo(array $b): bool
{
    return stripos((string)($b['titulo'] ?? ''), 'venha ser') === 0 || str_contains((string)($b['src'] ?? ''), 'venha-ser');
}

function venha_slide(array $c, int $i, int $total, array $cursos, array $icones): string
{
    $f = venha_foto();
    $logo = $GLOBALS['ESCOLA']['logo_branca'] ?? 'assets/img/logo-branca.png';
    $link = (string)($c['link'] ?: '#cursos');
    $h = '<div class="sg-bn sg-bn-dq2 sg-bn-vs" aria-roledescription="slide" aria-label="' . ($i + 1) . ' de ' . $total . ': ' . e($c['linha1'] . ' ' . $c['linha2']) . '">'
       . '<div class="dq2-fundo" aria-hidden="true"><i class="dq2-bolha b1"></i><i class="dq2-bolha b2"></i><i class="dq2-bolha b3"></i><span class="dq2-raios"></span>'
       . str_repeat('<b class="dq2-confete"></b>', 14) . '</div>'
       . '<div class="dq2-intro"><img class="dq2-logo" src="' . e($logo) . '" alt="" width="1400" height="451">'
       . '<div class="dq2-txtbox"><h3 class="dq2-tit"><span>' . e($c['linha1']) . '</span><em>' . e($c['linha2']) . '</em></h3>'
       . ($c['selo'] ? '<p class="dq2-per"><i class="bi bi-stars"></i>' . e($c['selo']) . '</p>' : '')
       . ($c['sub'] ? '<p class="vs-sub">' . e($c['sub']) . '</p>' : '') . '</div></div>'
       . '<div class="vs-corpo"><ul class="vs-itens">';
    foreach ($c['itens'] as $k => $x)
        $h .= '<li style="--k:' . $k . '"><span class="vs-ic"><i class="bi bi-' . VENHA_ICONES[$k] . '"></i></span><span class="vs-it"><b>' . e($x['t']) . '</b>' . (!empty($x['d']) ? '<small>' . e($x['d']) . '</small>' : '') . '</span></li>';
    $h .= '</ul><div class="vs-lado"><p class="vs-cursos-tit"><b>' . count($cursos) . '</b> cursos técnicos</p><ul class="vs-cursos">';
    foreach ($cursos as $k => $cu)
        $h .= '<li style="--k:' . $k . '"><i class="bi bi-' . e($icones[$cu['nome']] ?? 'mortarboard') . '"></i><span>' . e($cu['nome']) . '</span></li>';
    $h .= '</ul><a class="vs-bt" href="' . e($link) . '"' . (str_starts_with($link, 'http') ? ' target="_blank" rel="noopener"' : '') . '><span>' . e($c['botao'] ?: 'Conheça os cursos') . '</span><i class="bi bi-arrow-right"></i></a></div></div>'
       . '<figure class="vs-foto">' . ($f ? '<img src="' . e(site_img_url('vs', 1, $f)) . '" alt="Estudantes da escola"' . ($i > 1 ? ' loading="lazy"' : '') . '>' : '')
       . '<span class="vs-foto-selo"><b>' . count($cursos) . '</b><small>cursos<br>técnicos</small></span>'
       . '<span class="vs-foto-tag"><i class="bi bi-heart-fill"></i>Somos EEEP</span></figure></div>';
    return $h;
}
