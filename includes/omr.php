<?php
/* =====================================================================
 *  Leitura óptica de provas: desenho da folha de respostas e correção.
 *  As medidas estão em milímetros numa folha A4 (210 × 297).
 *  O MESMO desenho é usado para imprimir a folha e para ler a foto,
 *  por isso nada aqui deve ser alterado depois de imprimir folhas.
 * ===================================================================== */

const OMR_MAX_QUESTOES = 60;
const OMR_POR_COLUNA = 20;
const OMR_QR_PREFIXO = 'VVB1';
const OMR_MODELO_ATUAL = 4;      // modelo da folha usado nas provas novas (o modelo fica gravado na prova)   // conteúdo do QR: VVB1|P<prova>|A<aluno>

require_once __DIR__ . '/lib/qrcode.php';

/**
 * Desenho da folha de respostas (A4, medidas em mm).
 * A área de respostas é um cartão com um quadrado preto em cada ponta: são
 * esses 4 quadrados que a câmera procura. O quadradinho menor logo abaixo
 * do canto superior esquerdo indica o lado de cima. O QR code fica dentro
 * da área, na faixa da esquerda, para ser lido na mesma imagem.
 * $partes: [['nome' => 'Língua Portuguesa', 'n' => 5], ...] (bloco de provas)
 */
function omr_layout(int $nq, int $nalt, array $partes = [], int $modelo = OMR_MODELO_ATUAL): array
{
    if (!$partes) $partes = [['nome' => '', 'n' => $nq]];
    if ($modelo === 1) {                               // modelo 1 (até a versão 11): cartão largo, bolinhas grandes
        $AREA = [8, 96, 194, 156];                     // x, y, largura, altura do cartão de respostas
        $M = [[13.5, 101.5], [196.5, 101.5], [196.5, 246.5], [13.5, 246.5]]; $MT = 7; $OT = 4; $OD = 10;
        $COLX = [64, 108, 152];                        // início de cada coluna de questões
        $Y0 = 110; $Y1 = 238; $PASSO_MAX = 9.5; $BX = 11.4; $QR = [23, 106, 33];
        $dx = $nalt === 4 ? 8 : 6.8; $RMAX = $nalt === 4 ? 3.1 : 2.75; $RF = 0.41;
    } elseif ($modelo === 4) {                         // modelo 4: igual ao 3, com QR maior e bolinhas um pouco menores
        $AREA = [14, 87, 88, 196];
        $M = [[18.5, 91.5], [97.5, 91.5], [97.5, 278.5], [18.5, 278.5]]; $MT = 6; $OT = 3.5; $OD = 9;
        $COLX = [22, 59];
        $Y0 = 130; $Y1 = 271; $PASSO_MAX = 7.6; $BX = 9.2; $QR = [24, 94.5, 25.5];
        $dx = $nalt === 4 ? 6.2 : 5.2; $RMAX = $nalt === 4 ? 1.9 : 1.82; $RF = 0.35;
    } elseif ($modelo === 3) {                         // modelo 3: meia folha (cartão à esquerda, instruções à direita)
        $AREA = [14, 87, 88, 196];                    // margem de 14 mm dos lados
        $M = [[18.5, 91.5], [97.5, 91.5], [97.5, 278.5], [18.5, 278.5]]; $MT = 6; $OT = 3.5; $OD = 9;
        $COLX = [22, 59];
        $Y0 = 127.5; $Y1 = 271; $PASSO_MAX = 7.6; $BX = 9.2; $QR = [25, 95, 22];
        $dx = $nalt === 4 ? 6.2 : 5.2; $RMAX = $nalt === 4 ? 2.05 : 1.95; $RF = 0.37;
    } else {                                           // modelo 2: cartão centralizado, bolinhas menores
        $AREA = [8, 89.5, 194, 127];
        $M = [[12.5, 94], [197.5, 94], [197.5, 212], [12.5, 212]]; $MT = 6; $OT = 3.5; $OD = 9;
        $COLX = [57, 104, 151];
        $Y0 = 113.5; $Y1 = 205; $PASSO_MAX = 8; $BX = 9.6; $QR = [20, 98.5, 26];
        $dx = $nalt === 4 ? 6.3 : 5.4; $RMAX = $nalt === 4 ? 2.15 : 2.0; $RF = 0.36;
    }
    // monta a sequência: cabeçalho de cada disciplina + questões (com "continuação" ao trocar de coluna)
    $multi = count($partes) > 1;
    $seq = [];
    foreach ($partes as $pi => $pt) {
        if ($multi) $seq[] = ['h', $pi];
        for ($k = 0; $k < (int)$pt['n']; $k++) $seq[] = ['q', $pi];
    }
    // menor número de linhas que cabe nas colunas: as colunas ficam equilibradas e a folha bem ocupada
    $NC = count($COLX); $UC = $NC - 1;
    $linhas = max(8, (int)ceil(count($seq) / $NC));
    while (true) {
        $col = 0; $lin = 0; $pos = []; $cab = []; $cabe = true; $ultima = null;
        foreach ($seq as $idx => [$t, $pi]) {
            if ($t === 'h' && $lin > 0) {
                $tam = (int)$partes[$pi]['n'] + 1;                                  // disciplina inteira cabe na próxima coluna? então começa lá
                if ($lin >= $linhas - 1 || ($lin + $tam > $linhas && $tam <= $linhas && $col < $UC)) { $col++; $lin = 0; }
            }
            if ($lin >= $linhas) { $col++; $lin = 0; }
            if ($col > $UC) { $cabe = false; break; }
            if ($t === 'q' && $lin === 0 && $multi && $ultima === $pi && ($seq[$idx - 1][0] ?? '') === 'q') {
                $cab[] = [$col, $lin, $pi, true]; $lin++;                           // "(continuação)"
                if ($lin >= $linhas) { $col++; $lin = 0; }
            }
            if ($t === 'h') $cab[] = [$col, $lin, $pi, false]; else $pos[] = [$col, $lin, $pi];
            $ultima = $pi; $lin++;
        }
        if ($cabe || $linhas >= 45) break;
        $linhas++;
    }
    $passo = round(min($PASSO_MAX, ($Y1 - $Y0) / ($linhas - 1)), 3);
    $Y0 = round($Y0 + (($Y1 - $Y0) - ($linhas - 1) * $passo) / 2, 2);     // centraliza na altura do cartão
    $r = round(min($RMAX, $passo * $RF), 2);
    $L = [
        'modelo'      => $modelo,
        'pagina'      => [210, 297],
        'area'        => $AREA,
        'marcador'    => $MT,
        'marcadores'  => $M,
        'orientacao'  => [$M[0][0], $M[0][1] + $OD], 'orientacao_tam' => $OT,
        'anti'        => [[$M[1][0], $M[1][1] + $OD], [$M[2][0], $M[2][1] - $OD], [$M[3][0], $M[3][1] - $OD]],
        'qr'          => $QR,
        'raio'        => $r, 'raio_leitura' => round($r * 0.66, 2), 'raio_papel' => round(min($dx, $passo) / 2, 2),
        'passo'       => $passo, 'colunas_x' => $COLX,
        'cabecalhos'  => [], 'questoes' => [], 'branco' => [], 'nalt' => $nalt,
    ];
    foreach ($cab as [$c, $l, $pi, $cont]) $L['cabecalhos'][] = ['x' => $COLX[$c], 'y' => round($Y0 + $l * $passo, 2), 'parte' => $pi, 'cont' => $cont];
    foreach ($pos as $q => [$c, $l, $pi]) {
        $y = round($Y0 + $l * $passo, 2);
        $alts = [];
        for ($a = 0; $a < $nalt; $a++) $alts[] = [round($COLX[$c] + $BX + $a * $dx, 2), $y];
        $L['questoes'][] = ['n' => $q + 1, 'x' => $COLX[$c], 'y' => $y, 'parte' => $pi, 'alts' => $alts];
        if (count($L['branco']) < 48) for ($a = 0; $a < $nalt - 1; $a++) $L['branco'][] = [round($COLX[$c] + $BX + ($a + 0.5) * $dx, 2), $y];
    }
    return $L;
}

/** Layout de uma prova cadastrada (com as disciplinas do bloco) */
function omr_layout_prova(array $prova): array
{
    return omr_layout((int)$prova['num_questoes'], (int)$prova['num_alternativas'], array_map(fn($p) => ['nome' => $p['nome'], 'n' => $p['n']], $prova['partes'] ?? []), omr_modelo($prova));
}

/** Modelo da folha gravado na prova (provas antigas continuam no modelo 1, com que foram impressas) */
function omr_modelo(array $prova): int
{
    return in_array((int)($prova['modelo_folha'] ?? 1), [1, 2, 3, 4], true) ? (int)($prova['modelo_folha'] ?? 1) : 1;
}

/** Texto gravado no QR code da folha */
function omr_qr_texto(int $prova_id, ?int $aluno_id): string
{
    return OMR_QR_PREFIXO . '|P' . $prova_id . ($aluno_id ? '|A' . $aluno_id : '');
}

/** QR code em SVG (retângulos), dentro de um quadrado de $lado mm */
function qr_svg(string $texto, float $x, float $y, float $lado): string
{
    $qr = QRCode::getMinimumQRCode($texto, QR_ERROR_CORRECT_LEVEL_M);
    $n = $qr->getModuleCount();
    $m = $lado / $n;
    $d = '';
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if (!$qr->isDark($r, $c)) continue;
            $ini = $c;
            while ($c + 1 < $n && $qr->isDark($r, $c + 1)) $c++;      // junta módulos vizinhos da mesma linha
            $d .= 'M' . round($x + $ini * $m, 3) . ' ' . round($y + $r * $m, 3) . 'h' . round(($c - $ini + 1) * $m, 3) . 'v' . round($m, 3) . 'h-' . round(($c - $ini + 1) * $m, 3) . 'z';
        }
    }
    return '<path d="' . $d . '" fill="#000" shape-rendering="crispEdges"/>';
}

function letras_alternativas(int $nalt): array { return array_slice(['A', 'B', 'C', 'D', 'E'], 0, $nalt); }

/** Corrige: devolve [acertos, nota] a partir do gabarito ('*' = anulada, vale para todos) */
function omr_corrigir(array $prova, string $respostas): array
{
    $g = (string)$prova['gabarito'];
    $n = (int)$prova['num_questoes'];
    $certo = fn($i) => ($c = $g[$i] ?? '') === '*' || ($c !== '' && ($respostas[$i] ?? '') === $c);
    $acertos = 0;
    for ($i = 0; $i < $n; $i++) if ($certo($i)) $acertos++;
    $nota = $n ? round($acertos / $n * (float)$prova['valor'], 2) : 0;
    $partes = [];
    foreach ($prova['partes'] ?? [] as $pt) {
        $a = 0;
        for ($i = $pt['inicio']; $i < $pt['fim']; $i++) if ($certo($i)) $a++;
        $partes[] = ['nome' => $pt['nome'], 'acertos' => $a, 'total' => $pt['n'], 'nota' => $pt['n'] ? round($a / $pt['n'] * $pt['valor'], 2) : 0, 'valor' => $pt['valor']];
    }
    return [$acertos, $nota, $partes];
}

/* Cores das disciplinas na folha e nas telas (uma por parte do bloco) */
const CORES_PARTES = ['#13692a', '#1d5fb0', '#6b3fa0', '#b5560f', '#0f7c84', '#a3245c'];

/** Disciplinas da prova (bloco). Prova antiga, sem partes: uma parte só. */
function prova_partes_ler(array $p): array
{
    $rows = q_todos('SELECT pp.*, d.nome AS disciplina FROM prova_partes pp LEFT JOIN disciplinas d ON d.id = pp.disciplina_id WHERE pp.prova_id = ? ORDER BY pp.ordem, pp.id', [(int)$p['id']]);
    if (!$rows) $rows = [['nome' => $p['disciplina'], 'disciplina_id' => $p['disciplina_id'], 'num_questoes' => $p['num_questoes'], 'valor' => $p['valor']]];
    $ini = 0; $out = [];
    foreach ($rows as $i => $r) {
        $n = (int)$r['num_questoes'];
        $out[] = ['nome' => $r['nome'], 'disciplina_id' => $r['disciplina_id'] ? (int)$r['disciplina_id'] : null, 'n' => $n, 'valor' => (float)$r['valor'],
                  'inicio' => $ini, 'fim' => $ini + $n, 'cor' => CORES_PARTES[$i % count(CORES_PARTES)]];
        $ini += $n;
    }
    return $out;
}

function prova_buscar(int $id): ?array
{
    $p = q_um('SELECT p.*, d.nome AS disciplina, d.curso_id, u.nome AS autor
                 FROM provas p JOIN disciplinas d ON d.id = p.disciplina_id
                 LEFT JOIN usuarios u ON u.id = p.criado_por WHERE p.id = ?', [$id]);
    if (!$p) return null;
    $p['partes'] = prova_partes_ler($p);
    $p['bloco'] = count($p['partes']) > 1;
    if ($p['bloco']) $p['disciplina'] = implode(' · ', array_column($p['partes'], 'nome'));
    return $p;
}

/** Professor só mexe nas provas que criou; gestão mexe em todas */
function pode_prova(array $p): bool
{
    return !restrito_a_atribuicoes() || (int)$p['criado_por'] === uid();
}

function prova_nome(array $p): string
{
    return $p['titulo'] . ' · ' . $p['disciplina'] . ' · ' . $p['serie'] . 'º ano';
}

/** Quebra um texto em linhas para o SVG. **negrito** vira <tspan>. Devolve as linhas já em SVG. */
function svg_linhas(string $txt, float $larg, float $fs): array
{
    $pal = [];
    $neg = false; $fim_espaco = true;
    foreach (preg_split('/(\*\*)/', $txt, -1, PREG_SPLIT_DELIM_CAPTURE) as $parte) {
        if ($parte === '**') { $neg = !$neg; continue; }
        if ($parte === '') continue;
        $inicio_espaco = preg_match('/^\s/', $parte) || $fim_espaco;
        $fim_espaco = (bool)preg_match('/\s$/', $parte);
        foreach (preg_split('/\s+/', trim($parte)) as $i => $w) {
            if ($w === '') continue;
            $cola = $pal && $i === 0 && !$inicio_espaco;          // pontuação logo depois do negrito
            $pal[] = [$w, $neg, $cola];
        }
    }
    $linhas = []; $atual = []; $w = 0;
    foreach ($pal as [$p, $b, $cola]) {
        $lp = mb_strlen($p) * $fs * ($b ? 0.6 : 0.54);
        if ($atual && !$cola && $w + $lp > $larg) { $linhas[] = $atual; $atual = []; $w = 0; }
        $atual[] = [$p, $b, $cola && $atual]; $w += $lp + $fs * 0.3;
    }
    if ($atual) $linhas[] = $atual;
    return array_map(function ($l) {
        $h = '';
        foreach ($l as $i => [$p, $b, $cola]) $h .= ($i && !$cola ? ' ' : '') . ($b ? '<tspan font-weight="700">' . e($p) . '</tspan>' : e($p));
        return $h;
    }, $linhas);
}

/** Linha da duração nas instruções (horário cadastrado na prova) */
function omr_duracao(array $p): string
{
    $f = fn($h) => str_replace(':', 'h', substr((string)$h, 0, 5));
    if (!empty($p['hora_inicio']) && !empty($p['hora_fim'])) return 'A duração da prova é das **' . $f($p['hora_inicio']) . '** às **' . $f($p['hora_fim']) . '**.';
    return 'A duração da prova é das ______ às ______.';
}

/** Sigla do curso para a linha "Turma" (A-ADM, B-DES.SISTEMAS...) */
function curso_sigla(string $curso): string
{
    $mapa = ['administra' => 'ADM', 'desenvolvimento' => 'DES.SISTEMAS', 'enfermagem' => 'ENF', 'informática' => 'INFO', 'informatica' => 'INFO'];
    $c = mb_strtolower($curso);
    foreach ($mapa as $k => $v) if (str_contains($c, $k)) return $v;
    return mb_strtoupper(mb_substr($curso, 0, 4));
}

/** Desenha a folha de respostas no modelo gravado na prova */
function omr_svg(array $prova, ?array $aluno = null, array $turmas = []): string
{
    return match (omr_modelo($prova)) { 1 => omr_svg_v1($prova, $aluno, $turmas), 2 => omr_svg_v2($prova, $aluno, $turmas), default => omr_svg_v3($prova, $aluno, $turmas) };   // 3 e 4 usam o mesmo desenho (o 4 com QR maior)
}

/** Modelo 1 (até a versão 11). Mantido para reimprimir provas que já tinham folhas impressas. */
function omr_svg_v1(array $prova, ?array $aluno = null, array $turmas = []): string
{
    $n = (int)$prova['num_questoes'];
    $partes = $prova['partes'] ?? [['nome' => $prova['disciplina'], 'n' => $n, 'inicio' => 0, 'fim' => $n]];
    $L = omr_layout_prova($prova + ['partes' => $partes]);
    $letras = letras_alternativas((int)$prova['num_alternativas']);
    $rect = fn($x, $y, $w, $h, $r, $fill, $extra = '') => '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" rx="' . $r . '" fill="' . $fill . '"' . $extra . '/>';
    $caixa = fn($x, $y, $w, $h, $r = 1.8) => $rect($x, $y, $w, $h, $r, '#fff', ' stroke="#000" stroke-width="0.35"');
    $txt = fn($x, $y, $fs, $c, $extra = '') => '<text x="' . round($x, 2) . '" y="' . round($y, 2) . '" font-size="' . round($fs, 2) . '"' . $extra . '>' . $c . '</text>';
    $cabe = fn(string $t, float $larg, float $fs, float $f = 0.58) => min($fs, $larg / max(1, mb_strlen($t) * $f));
    $img = function ($arq, $x, $y, $h) {
        $c = __DIR__ . '/../assets/img/' . $arq;
        if (!is_file($c) || !($d = @getimagesize($c))) return '';
        return '<image href="' . RAIZ . 'assets/img/' . $arq . '" x="' . $x . '" y="' . $y . '" height="' . $h . '" width="' . round($h * $d[0] / $d[1], 2) . '"/>';
    };

    $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297" width="210mm" height="297mm" font-family="Plus Jakarta Sans, Arial, sans-serif" fill="#000">';
    $s .= '<rect width="210" height="297" fill="#fff"/>';

    /* ---------- Cabeçalho ---------- */
    $s .= $img('logo-doc-2026.png', 8, 8.5, 20);
    $bandeira = '';
    foreach (['bandeira-ceara.png', 'bandeira-ceara.jpg'] as $b) if (is_file(__DIR__ . '/../assets/img/' . $b)) { $bandeira = $b; break; }
    $s .= $img('logo-ceara.png', 175, 7, 23.5);
    if ($bandeira) $s .= $img($bandeira, 151, 12.5, 14);
    $cx = $bandeira ? 115 : 126;
    $s .= $txt($cx, 17.8, 5.6, 'FOLHA DE RESPOSTAS', ' font-weight="800" text-anchor="middle"');
    $s .= $txt($cx, 24, 3, 'LEITURA ÓPTICA · CORREÇÃO AUTOMÁTICA', ' font-weight="700" text-anchor="middle" letter-spacing="0.3"');

    // faixa preta com o título
    $s .= $rect(8, 32, 194, 17, 2.4, '#000');
    $tit = $prova['titulo'];
    $s .= $txt(12.5, 40.6, $cabe($tit, 140, 6.4, 0.62), e($tit), ' font-weight="800" fill="#fff"');
    $sub = implode(' · ', array_column($partes, 'nome')) . ' · ' . $n . ' questões';
    $s .= $txt(12.5, 46, $cabe($sub, 140, 3.3, 0.55), e($sub), ' fill="#fff"');
    $s .= $txt(197.5, 40.3, 3.7, e($prova['serie'] . 'º ano · ' . $prova['bimestre'] . 'º bim.'), ' font-weight="800" fill="#fff" text-anchor="end"');
    $s .= $txt(197.5, 46, 3, 'Avaliação nº ' . (int)$prova['id'], ' fill="#fff" text-anchor="end"');

    /* ---------- Identificação do participante ---------- */
    $s .= $txt(8.5, 55.3, 3.3, 'IDENTIFICAÇÃO DO PARTICIPANTE', ' font-weight="800" letter-spacing="0.3"');
    $campo = function ($x, $y, $w, $rot, $val = '', $ph = '') use ($caixa, $txt, $cabe) {
        $h = $caixa($x, $y, $w, 11.5) . $txt($x + 2.5, $y + 3.9, 2.5, e($rot), ' font-weight="700"');
        if ($val !== '') $h .= $txt($x + 2.5, $y + 9.6, $cabe($val, $w - 5, 4.6), e($val), ' font-weight="800"');
        elseif ($ph !== '') $h .= $txt($x + $w - 2.5, $y + 9.4, 3.6, $ph, ' text-anchor="end" fill="#555"');
        return $h;
    };
    $s .= $campo(8, 57.5, 154, 'NOME COMPLETO', $aluno ? nome_proprio($aluno['nome']) : '');
    $s .= $campo(164, 57.5, 38, 'Nº');
    $s .= $caixa(8, 70.5, 194, 11.5) . $txt(10.5, 74.4, 2.5, 'TURMA', ' font-weight="700"');
    if ($turmas) {
        $larg = 180 / count($turmas);
        foreach (array_values($turmas) as $i => $t) {
            $x = 24 + $i * $larg; $sel = $aluno && (int)$aluno['turma_id'] === (int)$t['id'];
            $s .= $rect($x, 74, 5, 5, 0.8, $sel ? '#000' : '#fff', ' stroke="#000" stroke-width="0.35"');
            if ($sel) $s .= '<path d="M' . ($x + 1.2) . ' 76.6 l1.3 1.3 l2.3 -2.6" fill="none" stroke="#fff" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round"/>';
            $rot = $prova['serie'] . 'º ' . $t['letra'] . ' · ' . curso_sigla($t['curso']);
            $s .= $txt($x + 6.8, 78.2, $cabe($rot, $larg - 9, 3.7, 0.56), e($rot), $sel ? ' font-weight="800"' : ' font-weight="600"');
        }
    }
    $data = $prova['data_aplicacao'] ? date('d/m/Y', strtotime($prova['data_aplicacao'])) : '';
    $s .= $campo(8, 83.5, 154, 'PROFESSOR(ES) APLICADOR(ES)', trim((string)($prova['aplicador'] ?? '')));
    $s .= $campo(164, 83.5, 38, 'DATA', $data, $data ? '' : '___/___/_____');

    /* ---------- Cartão de respostas ---------- */
    [$ax, $ay, $aw, $ah] = $L['area'];
    $s .= $caixa($ax, $ay, $aw, $ah, 3);
    $m = $L['marcador'];
    foreach ($L['marcadores'] as [$x, $y]) $s .= '<rect x="' . ($x - $m / 2) . '" y="' . ($y - $m / 2) . '" width="' . $m . '" height="' . $m . '"/>';
    $o = $L['orientacao_tam'];
    $s .= '<rect x="' . ($L['orientacao'][0] - $o / 2) . '" y="' . ($L['orientacao'][1] - $o / 2) . '" width="' . $o . '" height="' . $o . '"/>';

    // faixa da esquerda
    [$qx, $qy, $ql] = $L['qr'];
    $mx = $qx + $ql / 2;
    $s .= qr_svg(omr_qr_texto((int)$prova['id'], $aluno ? (int)$aluno['id'] : null), $qx, $qy, $ql);
    $y = $qy + $ql + 4.6;
    if ($aluno) {
        foreach (array_slice(svg_linhas('**' . nome_proprio($aluno['nome']) . '**', 40, 3.3), 0, 2) as $l) { $s .= $txt($mx, $y, 3.3, $l, ' text-anchor="middle"'); $y += 3.9; }
        $s .= $txt($mx, $y + 0.2, 2.9, e(turma_nome($aluno)), ' text-anchor="middle"');
    } else {
        $s .= $txt($mx, $y, 3, 'Folha sem nome:', ' text-anchor="middle" font-weight="700"') . $txt($mx, $y + 3.8, 2.8, 'o professor escolhe', ' text-anchor="middle"') . $txt($mx, $y + 7.3, 2.8, 'o aluno na correção', ' text-anchor="middle"');
    }
    $s .= $rect(19, 160, 41, 6.5, 1.6, '#000') . $txt(39.5, 164.6, 3.2, 'COMO MARCAR', ' text-anchor="middle" font-weight="800" fill="#fff" letter-spacing="0.2"');
    $bola = fn($cx, $cy, $extra = '') => '<circle cx="' . $cx . '" cy="' . $cy . '" r="2.7" fill="#fff" stroke="#000" stroke-width="0.35"/>' . $extra;
    $s .= '<circle cx="23" cy="172.5" r="2.7"/>' . $txt(27.5, 173.8, 3.6, 'Certo', ' font-weight="800"');
    $s .= $bola(23, 180.5, '<path d="M21.5 179 l3 3 M24.5 179 l-3 3" stroke="#000" stroke-width="0.6"/>')
        . $bola(30, 180.5, '<path d="M28.4 180.6 l1.1 1.2 l2 -2.4" fill="none" stroke="#000" stroke-width="0.6"/>')
        . $bola(37, 180.5, '<path d="M34.3 180.5 a2.7 2.7 0 0 0 5.4 0 z"/>') . $txt(41.5, 181.8, 3.6, 'Errado', ' font-weight="800"');
    $s .= $txt(19.5, 188.3, 2.6, 'Caneta preta · preencha toda') . $txt(19.5, 191.6, 2.6, 'a bolinha · uma por questão');

    $s .= $rect(19, 196, 41, 6.5, 1.6, '#000') . $txt(39.5, 200.6, 3.2, 'NOTA', ' text-anchor="middle" font-weight="800" fill="#fff" letter-spacing="0.2"');
    if (count($partes) === 1) {
        $s .= $caixa(19, 204.5, 41, 33) . $txt(39.5, 235.5, 2.3, 'uso do professor', ' text-anchor="middle" fill="#555"');
    } else {
        $y = 204.5;
        $ph = min(11, 34 / count($partes));
        foreach ($partes as $pt) {
            $s .= $txt(19.5, $y + $ph / 2 + 0.9, $cabe($pt['nome'], 22, 2.8, 0.56), e($pt['nome']), ' font-weight="700"');
            $s .= $caixa(43, $y, 17, $ph - 1.4, 1.2);
            $y += $ph;
        }
    }

    // questões
    foreach ($L['cabecalhos'] as $h) {
        $pt = $partes[$h['parte']];
        $s .= $rect($h['x'], $h['y'] - 2.6, 43, 5.2, 1.4, '#000');
        $rot = mb_strimwidth($pt['nome'], 0, $h['cont'] ? 18 : 22, '…') . ($h['cont'] ? ' (cont.)' : '');
        $s .= $txt($h['x'] + 1.8, $h['y'] + 1.05, 2.9, e($rot), ' font-weight="800" fill="#fff"');
        if (!$h['cont']) $s .= $txt($h['x'] + 41.2, $h['y'] + 1.05, 2.6, ($pt['inicio'] + 1) . ($pt['n'] > 1 ? '–' . $pt['fim'] : ''), ' fill="#fff" font-weight="700" text-anchor="end"');
    }
    $r = $L['raio'];
    foreach ($L['questoes'] as $q) {
        $s .= $txt($q['x'] + 7.4, $q['y'] + 1.25, 3.5, $q['n'], ' font-weight="800" text-anchor="end"');
        foreach ($q['alts'] as $a => [$x, $y]) {
            $s .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff" stroke="#000" stroke-width="0.3"/>';
            $s .= $txt($x, $y + $r * 0.38, round($r * 0.98, 2), $letras[$a], ' text-anchor="middle" font-weight="600" fill="#6b6b6b"');
        }
    }

    /* ---------- Instruções ---------- */
    $disc = mb_strtoupper(implode(', ', array_column($partes, 'nome')));
    $itens = [
        'Preencha os dados do participante corretamente: nome completo, turma, professor aplicador e data.',
        "Este caderno possui $n questões, numeradas de **1 a $n**, correspondentes a **$disc**.",
        omr_duracao($prova),
        'Leia atentamente as questões antes de responder.',
        'Marque as respostas na prova e somente depois passe para a FOLHA DE RESPOSTAS, no campo correspondente a cada questão.',
        'Em cada questão há somente **uma** resposta certa.',
        'A FOLHA DE RESPOSTAS é o único documento usado na correção. Não amasse, não dobre nem rasure. Marque só com caneta esferográfica de **tinta preta**; não é permitido lápis, lapiseira ou borracha.',
        'Não use corretivo nem tente apagar uma alternativa depois de marcada.',
        'É proibido o empréstimo de materiais como borracha, lápis ou caneta.',
        'Não será permitida a saída de alunos durante a prova.',
        'O campo **NOTA** deve ficar em branco: é de uso exclusivo do professor responsável pela correção.',
        'A prova será recolhida quando **TODOS** os alunos concluírem.',
    ];
    $s .= $caixa(8, 254.5, 194, 34.5, 3);
    $s .= $rect(8, 254.5, 30, 6, 1.6, '#000') . $txt(23, 258.8, 3, 'INSTRUÇÕES', ' text-anchor="middle" font-weight="800" fill="#fff" letter-spacing="0.2"');
    for ($fs = 2.6; $fs >= 1.8; $fs -= 0.05) {
        $lh = $fs * 1.3; $blocos = [];
        foreach ($itens as $it) $blocos[] = svg_linhas($it, 89, $fs);
        $max = (int)floor((287.6 - 264.2) / $lh) + 1;
        $col = 0; $lin = 0; $ok = true;
        foreach ($blocos as $b) { if ($lin + count($b) > $max) { $col++; $lin = 0; } $lin += count($b); if ($col > 1) { $ok = false; break; } }
        if ($ok) break;
    }
    $col = 0; $y = 264.2; $lin = 0;
    foreach ($blocos as $b) {
        if ($lin + count($b) > $max && $col === 0) { $col = 1; $y = 264.2; $lin = 0; }
        $x = $col ? 106 : 11;
        foreach ($b as $i => $l) {
            if ($i === 0) $s .= '<circle cx="' . ($x + 0.7) . '" cy="' . round($y - $fs * 0.33, 2) . '" r="0.5"/>';
            $s .= $txt($x + 2.4, $y, $fs, $l);
            $y += $lh; $lin++;
        }
    }

    $s .= $txt(105, 292.6, 2.2, 'Avaliação nº ' . (int)$prova['id'] . ' · não escreva sobre os quadrados pretos nem sobre o QR code · ' . e(impresso_por()), ' text-anchor="middle" fill="#444"');
    return $s . '</svg>';
}

/** Modelo 2: cabeçalho com as logomarcas, título em caixa branca, cartão centralizado com bolinhas menores e instruções maiores. */
function omr_svg_v2(array $prova, ?array $aluno = null, array $turmas = []): string
{
    $n = (int)$prova['num_questoes'];
    $partes = $prova['partes'] ?? [['nome' => $prova['disciplina'], 'n' => $n, 'inicio' => 0, 'fim' => $n]];
    $L = omr_layout_prova($prova + ['partes' => $partes]);
    $letras = letras_alternativas((int)$prova['num_alternativas']);
    $rect = fn($x, $y, $w, $h, $r, $fill, $extra = '') => '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" rx="' . $r . '" fill="' . $fill . '"' . $extra . '/>';
    $caixa = fn($x, $y, $w, $h, $r = 1.6, $sw = 0.35) => $rect($x, $y, $w, $h, $r, '#fff', ' stroke="#000" stroke-width="' . $sw . '"');
    $txt = fn($x, $y, $fs, $c, $extra = '') => '<text x="' . round($x, 2) . '" y="' . round($y, 2) . '" font-size="' . round($fs, 2) . '"' . $extra . '>' . $c . '</text>';
    $cabe = fn(string $t, float $larg, float $fs, float $f = 0.58) => min($fs, $larg / max(1, mb_strlen($t) * $f));
    $img = function ($arq, $x, $y, $h, $alinhar_dir = false) {
        $c = __DIR__ . '/../assets/img/' . $arq;
        if (!is_file($c) || !($d = @getimagesize($c))) return '';
        $w = round($h * $d[0] / $d[1], 2);
        return '<image href="' . RAIZ . 'assets/img/' . $arq . '" x="' . round($alinhar_dir ? $x - $w : $x, 2) . '" y="' . $y . '" height="' . $h . '" width="' . $w . '"/>';
    };
    [$ax, $ay, $aw, $ah] = $L['area'];
    $X0 = $ax; $X1 = $ax + $aw;                                      // campos alinhados com o cartão

    $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297" width="210mm" height="297mm" font-family="Plus Jakarta Sans, Arial, sans-serif" fill="#000">';
    $s .= '<rect width="210" height="297" fill="#fff"/>';

    /* ---------- Cabeçalho: escola · título · Governo do Ceará ---------- */
    $s .= $img('logo-doc-2026.png', 8.5, 9.5, 12);
    $s .= is_file(__DIR__ . '/../assets/img/logo-ceara-seduc.png') ? $img('logo-ceara-seduc.png', 201.5, 9.5, 12, true) : $img('logo-ceara.png', 201.5, 7, 17, true);
    $s .= $txt(105, 17, 6, 'FOLHA DE RESPOSTAS', ' font-weight="700" text-anchor="middle" letter-spacing="0.25"');
    $s .= $txt(105, 22.6, 2.9, 'EEEP VENCESLAU VIEIRA BATISTA', ' font-weight="500" text-anchor="middle" letter-spacing="1.1"');

    /* título da prova em caixa branca */
    $s .= $caixa(8, 29.5, 194, 16.5, 2.6, 0.6);
    $tit = $prova['titulo'];
    $s .= $txt(13, 38.4, $cabe($tit, 138, 6.2, 0.6), e($tit), ' font-weight="700"');
    $sub = implode(' · ', array_column($partes, 'nome')) . ' · ' . $n . ' questões';
    $s .= $txt(13, 43.3, $cabe($sub, 138, 3.3, 0.55), e($sub));
    $s .= $txt(197, 38.1, 3.8, e($prova['serie'] . 'º ano · ' . $prova['bimestre'] . 'º bim.'), ' font-weight="600" text-anchor="end"');
    $s .= $txt(197, 43.3, 3, 'Avaliação nº ' . (int)$prova['id'], ' text-anchor="end"');

    /* ---------- Identificação do participante ---------- */
    $s .= $txt($X0 + 0.5, 52.6, 3.1, 'IDENTIFICAÇÃO DO PARTICIPANTE', ' font-weight="700" letter-spacing="0.6"');
    $campo = function ($x, $y, $w, $rot, $val = '', $ph = '', $centro = false) use ($caixa, $txt, $cabe) {
        $h = $caixa($x, $y, $w, 9.8) . $txt($x + 2.2, $y + 3.3, 2.2, e($rot), ' font-weight="600"');
        if ($val !== '') $h .= $centro ? $txt($x + $w / 2 + 2, $y + 8.1, 4.8, e($val), ' font-weight="800" text-anchor="middle"')
                                       : $txt($x + 2.2, $y + 8.1, $cabe($val, $w - 4.4, 4.2), e($val), ' font-weight="700"');
        elseif ($ph !== '') $h .= $txt($x + $w - 2.2, $y + 8, 3.3, $ph, ' text-anchor="end" fill="#555"');
        return $h;
    };
    $wn = 35; $wc = $aw - $wn - 2;
    $num = $aluno && !empty($aluno['numero']) ? str_pad((string)$aluno['numero'], 2, '0', STR_PAD_LEFT) : '';
    $s .= $campo($X0, 55, $wc, 'NOME COMPLETO', $aluno ? nome_proprio($aluno['nome']) : '');
    $s .= $campo($X1 - $wn, 55, $wn, 'Nº', $num, '', true);
    $s .= $caixa($X0, 66.5, $aw, 9.8) . $txt($X0 + 2.2, 69.8, 2.2, 'TURMA', ' font-weight="600"');
    if ($turmas) {
        $x0 = $X0 + 15; $larg = ($X1 - 2 - $x0) / count($turmas);
        foreach (array_values($turmas) as $i => $t) {
            $x = $x0 + $i * $larg; $sel = $aluno && (int)$aluno['turma_id'] === (int)$t['id'];
            $s .= $rect($x, 69.2, 4.4, 4.4, 0.7, $sel ? '#000' : '#fff', ' stroke="#000" stroke-width="0.35"');
            if ($sel) $s .= '<path d="M' . ($x + 1) . ' 71.5 l1.2 1.2 l2.1 -2.4" fill="none" stroke="#fff" stroke-width="0.75" stroke-linecap="round" stroke-linejoin="round"/>';
            $rot = $prova['serie'] . 'º ' . $t['letra'] . ' · ' . curso_sigla($t['curso']);
            $s .= $txt($x + 6, 72.9, $cabe($rot, $larg - 8, 3.3, 0.56), e($rot), $sel ? ' font-weight="800"' : '');
        }
    }
    $data = $prova['data_aplicacao'] ? date('d/m/Y', strtotime($prova['data_aplicacao'])) : '';
    $s .= $campo($X0, 78, $wc, 'PROFESSOR(ES) APLICADOR(ES)', trim((string)($prova['aplicador'] ?? '')));
    $s .= $campo($X1 - $wn, 78, $wn, 'DATA', $data, $data ? '' : '___/___/_____');

    /* ---------- Cartão de respostas ---------- */
    $s .= $caixa($ax, $ay, $aw, $ah, 3, 0.45);
    $m = $L['marcador'];
    foreach ($L['marcadores'] as [$x, $y]) $s .= '<rect x="' . ($x - $m / 2) . '" y="' . ($y - $m / 2) . '" width="' . $m . '" height="' . $m . '"/>';
    $o = $L['orientacao_tam'];
    $s .= '<rect x="' . ($L['orientacao'][0] - $o / 2) . '" y="' . ($L['orientacao'][1] - $o / 2) . '" width="' . $o . '" height="' . $o . '"/>';

    // faixa da esquerda: QR, aluno, como marcar e nota
    [$qx, $qy, $ql] = $L['qr'];
    $mx = $qx + $ql / 2; $lx = $mx - 15.5; $lw = 31;
    $s .= qr_svg(omr_qr_texto((int)$prova['id'], $aluno ? (int)$aluno['id'] : null), $qx, $qy, $ql);
    $y = $qy + $ql + 4;
    if ($aluno) {
        foreach (array_slice(svg_linhas(nome_proprio($aluno['nome']), 33, 3), 0, 2) as $l) { $s .= $txt($mx, $y, 3, $l, ' text-anchor="middle"'); $y += 3.5; }
        $s .= $txt($mx, $y, 2.5, e(turma_nome($aluno)), ' text-anchor="middle"');
    } else {
        $s .= $txt($mx, $y, 2.8, 'Folha sem nome:', ' text-anchor="middle" font-weight="700"') . $txt($mx, $y + 3.4, 2.5, 'o professor escolhe', ' text-anchor="middle"') . $txt($mx, $y + 6.5, 2.5, 'o aluno na correção', ' text-anchor="middle"');
    }
    $s .= $rect($lx, 141, $lw, 5.4, 1.4, '#000') . $txt($mx, 144.9, 2.9, 'COMO MARCAR', ' text-anchor="middle" font-weight="700" fill="#fff" letter-spacing="0.3"');
    $rb = 2.1;
    $bola = fn($cx, $cy, $extra = '') => '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $rb . '" fill="#fff" stroke="#000" stroke-width="0.35"/>' . $extra;
    $s .= '<circle cx="' . ($lx + 3.5) . '" cy="151.5" r="' . $rb . '"/>' . $txt($lx + 7.5, 152.6, 3.2, 'Certo', ' font-weight="600"');
    $c1 = $lx + 3.5; $c2 = $lx + 9; $c3 = $lx + 14.5;
    $s .= $bola($c1, 158.5, '<path d="M' . ($c1 - 1.1) . ' 157.4 l2.2 2.2 M' . ($c1 + 1.1) . ' 157.4 l-2.2 2.2" stroke="#000" stroke-width="0.5"/>')
        . $bola($c2, 158.5, '<path d="M' . ($c2 - 1.2) . ' 158.6 l0.9 1 l1.6 -1.9" fill="none" stroke="#000" stroke-width="0.5"/>')
        . $bola($c3, 158.5, '<path d="M' . ($c3 - $rb) . ' 158.5 a' . $rb . ' ' . $rb . ' 0 0 0 ' . (2 * $rb) . ' 0 z"/>')
        . $txt($lx + 18.5, 159.6, 3.2, 'Errado', ' font-weight="600"');
    $s .= $txt($lx, 165.3, 2.2, 'Caneta preta · preencha toda') . $txt($lx, 168.2, 2.2, 'a bolinha · uma por questão');

    $s .= $rect($lx, 172, $lw, 5.4, 1.4, '#000') . $txt($mx, 175.9, 2.9, 'NOTA', ' text-anchor="middle" font-weight="700" fill="#fff" letter-spacing="0.3"');
    if (count($partes) === 1) {
        $s .= $caixa($lx, 179.5, $lw, 26) . $txt($mx, 203.6, 2.1, 'uso do professor', ' text-anchor="middle" fill="#555"');
    } else {
        $y = 179.5; $ph = min(9.5, 27 / count($partes));
        foreach ($partes as $pt) {
            $s .= $txt($lx, $y + $ph / 2 + 0.6, $cabe($pt['nome'], 16, 2.5, 0.56), e($pt['nome']));
            $s .= $caixa($lx + 17, $y, $lw - 17, $ph - 1.2, 1.1);
            $y += $ph;
        }
    }

    // questões
    $pw = 38;
    foreach ($L['cabecalhos'] as $h) {
        $pt = $partes[$h['parte']];
        $s .= $rect($h['x'], $h['y'] - 2.3, $pw, 4.6, 1.2, '#000');
        $rot = mb_strimwidth($pt['nome'], 0, $h['cont'] ? 19 : 22, '…') . ($h['cont'] ? ' (cont.)' : '');
        $s .= $txt($h['x'] + 1.6, $h['y'] + 0.95, 2.55, e($rot), ' fill="#fff"');
        if (!$h['cont']) $s .= $txt($h['x'] + $pw - 1.4, $h['y'] + 0.95, 2.3, ($pt['inicio'] + 1) . ($pt['n'] > 1 ? '–' . $pt['fim'] : ''), ' fill="#fff" text-anchor="end"');
    }
    $r = $L['raio'];
    foreach ($L['questoes'] as $q) {
        $s .= $txt($q['x'] + 6.6, $q['y'] + 1.1, 3.1, $q['n'], ' font-weight="600" text-anchor="end"');
        foreach ($q['alts'] as $a => [$x, $y]) {
            $s .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff" stroke="#000" stroke-width="0.28"/>';
            $s .= $txt($x, $y + $r * 0.4, round($r * 1.0, 2), $letras[$a], ' text-anchor="middle" fill="#6b6b6b"');
        }
    }

    /* ---------- Instruções (letra maior) ---------- */
    $disc = mb_strtoupper(implode(', ', array_column($partes, 'nome')));
    $itens = [
        'Preencha os dados do participante corretamente: nome completo, turma, professor aplicador e data.',
        "Este caderno possui $n questões, numeradas de **1 a $n**, correspondentes a **$disc**.",
        omr_duracao($prova),
        'Leia atentamente as questões antes de responder.',
        'Marque as respostas na prova e somente depois passe para a FOLHA DE RESPOSTAS, no campo correspondente a cada questão.',
        'Em cada questão há somente **uma** resposta certa.',
        'A FOLHA DE RESPOSTAS é o único documento usado na correção. Não amasse, não dobre nem rasure. Marque só com caneta esferográfica de **tinta preta**; não é permitido lápis, lapiseira ou borracha.',
        'Não use corretivo nem tente apagar uma alternativa depois de marcada.',
        'É proibido o empréstimo de materiais como borracha, lápis ou caneta.',
        'Não será permitida a saída de alunos durante a prova.',
        'O campo **NOTA** deve ficar em branco: é de uso exclusivo do professor responsável pela correção.',
        'A prova será recolhida quando **TODOS** os alunos concluírem.',
    ];
    $IY = 221; $IH = 68; $T0 = $IY + 11.5; $T1 = $IY + $IH - 3;
    $s .= $caixa(8, $IY, 194, $IH, 3, 0.45);
    $s .= $rect(8, $IY, 38, 7.2, 2, '#000') . $txt(27, $IY + 5, 3.5, 'INSTRUÇÕES', ' text-anchor="middle" font-weight="700" fill="#fff" letter-spacing="0.5"');
    for ($fs = 3.5; $fs >= 2.5; $fs -= 0.05) {
        $lh = $fs * 1.32; $blocos = [];
        foreach ($itens as $it) $blocos[] = svg_linhas($it, 88, $fs);
        $max = (int)floor(($T1 - $T0) / $lh) + 1;
        $col = 0; $lin = 0; $ok = true;
        foreach ($blocos as $b) { if ($lin + count($b) > $max) { $col++; $lin = 0; } $lin += count($b) + 0.25; if ($col > 1) { $ok = false; break; } }
        if ($ok) break;
    }
    $col = 0; $y = $T0; $lin = 0;
    foreach ($blocos as $b) {
        if ($lin + count($b) > $max && $col === 0) { $col = 1; $y = $T0; $lin = 0; }
        $x = $col ? 107 : 12;
        foreach ($b as $i => $l) {
            if ($i === 0) $s .= '<circle cx="' . ($x + 0.8) . '" cy="' . round($y - $fs * 0.33, 2) . '" r="0.6"/>';
            $s .= $txt($x + 2.8, $y, $fs, $l);
            $y += $lh; $lin++;
        }
        $y += $lh * 0.25; $lin += 0.25;
    }

    $s .= $txt(105, 293.6, 2.2, 'Avaliação nº ' . (int)$prova['id'] . ' · não escreva sobre os quadrados pretos nem sobre o QR code · ' . e(impresso_por()), ' text-anchor="middle" fill="#444"');
    return $s . '</svg>';
}

/** Modelo 3: margens de 14 mm; depois da identificação a folha se divide ao meio: cartão de respostas à esquerda (é só ele que a câmera enquadra) e, à direita, nota e instruções. */
function omr_svg_v3(array $prova, ?array $aluno = null, array $turmas = []): string
{
    $n = (int)$prova['num_questoes'];
    $partes = $prova['partes'] ?? [['nome' => $prova['disciplina'], 'n' => $n, 'inicio' => 0, 'fim' => $n]];
    $L = omr_layout_prova($prova + ['partes' => $partes]);
    $letras = letras_alternativas((int)$prova['num_alternativas']);
    $rect = fn($x, $y, $w, $h, $r, $fill, $extra = '') => '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" rx="' . $r . '" fill="' . $fill . '"' . $extra . '/>';
    $caixa = fn($x, $y, $w, $h, $r = 1.4, $sw = 0.3) => $rect($x, $y, $w, $h, $r, '#fff', ' stroke="#000" stroke-width="' . $sw . '"');
    $txt = fn($x, $y, $fs, $c, $extra = '') => '<text x="' . round($x, 2) . '" y="' . round($y, 2) . '" font-size="' . round($fs, 2) . '"' . $extra . '>' . $c . '</text>';
    $cabe = fn(string $t, float $larg, float $fs, float $f = 0.58) => min($fs, $larg / max(1, mb_strlen($t) * $f));
    $img = function ($arq, $x, $y, $h, $dir = false) {
        $c = __DIR__ . '/../assets/img/' . $arq;
        if (!is_file($c) || !($d = @getimagesize($c))) return '';
        $w = round($h * $d[0] / $d[1], 2);
        return '<image href="' . RAIZ . 'assets/img/' . $arq . '" x="' . round($dir ? $x - $w : $x, 2) . '" y="' . $y . '" height="' . $h . '" width="' . $w . '"/>';
    };
    $X0 = 14; $X1 = 196; $W = $X1 - $X0;                             // área útil com margem
    [$ax, $ay, $aw, $ah] = $L['area'];

    $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297" width="210mm" height="297mm" font-family="Plus Jakarta Sans, Arial, sans-serif" fill="#000">';
    $s .= '<rect width="210" height="297" fill="#fff"/>';

    /* ---------- Cabeçalho ---------- */
    $s .= $img('logo-doc-2026.png', $X0, 12, 10.5);
    $s .= is_file(__DIR__ . '/../assets/img/logo-ceara-seduc.png') ? $img('logo-ceara-seduc.png', $X1, 12, 10.5, true) : $img('logo-ceara.png', $X1, 10, 15, true);
    $s .= $txt(105, 18.6, 6.4, 'Folha de Respostas', ' font-family="Bricolage Grotesque, Plus Jakarta Sans, Arial, sans-serif" font-weight="800" text-anchor="middle" letter-spacing="-0.12"');
    $s .= $txt(105, 24.4, 2.5, 'EEEP VENCESLAU VIEIRA BATISTA', ' font-weight="600" text-anchor="middle" letter-spacing="0.45"');

    /* título */
    $s .= $caixa($X0, 28, $W, 13.5, 2.2, 0.5);
    $tit = $prova['titulo'];
    $s .= $txt($X0 + 4, 35, $cabe($tit, 128, 5, 0.6), e($tit), ' font-weight="700"');
    $sub = implode(' · ', array_column($partes, 'nome')) . ' · ' . $n . ' questões';
    $s .= $txt($X0 + 4, 39.3, $cabe($sub, 128, 2.8, 0.55), e($sub));
    $s .= $txt($X1 - 4, 34.8, 3.2, e($prova['serie'] . 'º ano · ' . $prova['bimestre'] . 'º bimestre'), ' font-weight="600" text-anchor="end"');
    $s .= $txt($X1 - 4, 39.3, 2.6, str_pad((string)(int)$prova['id'], 4, '0', STR_PAD_LEFT), ' text-anchor="end" font-weight="400" fill="#8a8a8a" letter-spacing="0.3"');

    /* ---------- Identificação ---------- */
    $s .= $txt($X0 + 0.3, 47.6, 2.6, 'IDENTIFICAÇÃO DO PARTICIPANTE', ' font-weight="700" letter-spacing="0.4"');
    $campo = function ($x, $y, $w, $rot, $val = '', $ph = '', $centro = false) use ($caixa, $txt, $cabe) {
        $h = $caixa($x, $y, $w, 8.6) . $txt($x + 2, $y + 2.9, 1.9, e($rot), ' font-weight="600"');
        if ($val !== '') $h .= $centro ? $txt($x + $w / 2 + 2, $y + 7.1, 4, e($val), ' font-weight="800" text-anchor="middle"')
                                       : $txt($x + 2, $y + 7.1, $cabe($val, $w - 4, 3.6), e($val), ' font-weight="700"');
        elseif ($ph !== '') $h .= $txt($x + $w - 2, $y + 7, 2.9, $ph, ' text-anchor="end" fill="#555"');
        return $h;
    };
    $wn = 32; $wc = $W - $wn - 2;
    $num = $aluno && !empty($aluno['numero']) ? str_pad((string)$aluno['numero'], 2, '0', STR_PAD_LEFT) : '';
    $s .= $campo($X0, 49.8, $wc, 'NOME COMPLETO', $aluno ? nome_proprio($aluno['nome']) : '');
    $s .= $campo($X1 - $wn, 49.8, $wn, 'Nº', $num, '', true);
    $s .= $caixa($X0, 59.8, $W, 8.6) . $txt($X0 + 2, 62.7, 1.9, 'TURMA', ' font-weight="600"');
    if ($turmas) {
        $x0 = $X0 + 13; $larg = ($X1 - 2 - $x0) / count($turmas);
        foreach (array_values($turmas) as $i => $t) {
            $x = $x0 + $i * $larg; $sel = $aluno && (int)$aluno['turma_id'] === (int)$t['id'];
            $s .= $rect($x, 62.1, 3.8, 3.8, 0.6, $sel ? '#000' : '#fff', ' stroke="#000" stroke-width="0.3"');
            if ($sel) $s .= '<path d="M' . ($x + 0.9) . ' 64.1 l1 1 l1.8 -2.1" fill="none" stroke="#fff" stroke-width="0.65" stroke-linecap="round" stroke-linejoin="round"/>';
            $rot = $prova['serie'] . 'º ' . $t['letra'] . ' · ' . curso_sigla($t['curso']);
            $s .= $txt($x + 5.2, 65.2, $cabe($rot, $larg - 7, 2.8, 0.56), e($rot), $sel ? ' font-weight="800"' : '');
        }
    }
    $data = $prova['data_aplicacao'] ? date('d/m/Y', strtotime($prova['data_aplicacao'])) : '';
    $s .= $campo($X0, 69.8, $wc, 'PROFESSOR(ES) APLICADOR(ES)', trim((string)($prova['aplicador'] ?? '')));
    $s .= $campo($X1 - $wn, 69.8, $wn, 'DATA', $data, $data ? '' : '___/___/_____');

    /* ---------- Metade esquerda: cartão de respostas ---------- */
    $s .= $caixa($ax, $ay, $aw, $ah, 2.6, 0.4);
    $m = $L['marcador'];
    foreach ($L['marcadores'] as [$x, $y]) $s .= '<rect x="' . ($x - $m / 2) . '" y="' . ($y - $m / 2) . '" width="' . $m . '" height="' . $m . '"/>';
    $o = $L['orientacao_tam'];
    $s .= '<rect x="' . ($L['orientacao'][0] - $o / 2) . '" y="' . ($L['orientacao'][1] - $o / 2) . '" width="' . $o . '" height="' . $o . '"/>';
    [$qx, $qy, $ql] = $L['qr'];
    $s .= qr_svg(omr_qr_texto((int)$prova['id'], $aluno ? (int)$aluno['id'] : null), $qx, $qy, $ql);
    $tx = $qx + $ql + 3.5; $tw = $ax + $aw - 8 - $tx;
    if ($aluno) {
        $y = $qy + 3.6;
        foreach (array_slice(svg_linhas('**' . nome_proprio($aluno['nome']) . '**', $tw, 2.7), 0, 2) as $l) { $s .= $txt($tx, $y, 2.7, $l); $y += 3.2; }
        $s .= $txt($tx, $y, 2.2, e(turma_nome($aluno)) . ($num ? ' · nº ' . $num : ''));
        $fim_nome = $y;
    } else {
        $s .= $txt($tx, $qy + 3.6, 2.5, 'Folha sem nome:', ' font-weight="700"') . $txt($tx, $qy + 6.6, 2.2, 'o professor escolhe o aluno');
        $fim_nome = $qy + 6.6;
    }
    $cy = max($qy + 16, $fim_nome + 7.4);                      // nome em duas linhas não encosta no "COMO MARCAR"
    $s .= $txt($tx, $cy - 3.4, 2, 'COMO MARCAR', ' font-weight="800" letter-spacing="0.25"');
    $rb = 1.7;
    $bola = fn($cx, $cy, $extra = '') => '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $rb . '" fill="#fff" stroke="#000" stroke-width="0.3"/>' . $extra;
    $c0 = $tx + 1.8; $s .= '<circle cx="' . $c0 . '" cy="' . $cy . '" r="' . $rb . '"/>' . $txt($c0 + 2.8, $cy + 0.9, 2.4, 'Certo', ' font-weight="600"');
    $c1 = $tx + 15.5; $c2 = $c1 + 4.4; $c3 = $c2 + 4.4;
    $s .= $bola($c1, $cy, '<path d="M' . ($c1 - 0.9) . ' ' . ($cy - 0.9) . ' l1.8 1.8 M' . ($c1 + 0.9) . ' ' . ($cy - 0.9) . ' l-1.8 1.8" stroke="#000" stroke-width="0.4"/>')
        . $bola($c2, $cy, '<path d="M' . ($c2 - 1) . ' ' . ($cy + 0.1) . ' l0.7 0.8 l1.3 -1.6" fill="none" stroke="#000" stroke-width="0.4"/>')
        . $bola($c3, $cy, '<path d="M' . ($c3 - $rb) . ' ' . $cy . ' a' . $rb . ' ' . $rb . ' 0 0 0 ' . (2 * $rb) . ' 0 z"/>')
        . $txt($c3 + 2.7, $cy + 0.9, 2.4, 'Errado', ' font-weight="600"');
    $yl = round($qy + $ql + 3.3, 2);
    $s .= '<line x1="' . ($ax + 4) . '" y1="' . $yl . '" x2="' . ($ax + $aw - 4) . '" y2="' . $yl . '" stroke="#000" stroke-width="0.18" stroke-dasharray="0.8 0.8"/>';

    $pw = 33;
    foreach ($L['cabecalhos'] as $h) {
        $pt = $partes[$h['parte']];
        $s .= $rect($h['x'], $h['y'] - 2, $pw, 4, 1, '#000');
        $rot = mb_strimwidth($pt['nome'], 0, $h['cont'] ? 16 : 20, '…') . ($h['cont'] ? ' (cont.)' : '');
        $s .= $txt($h['x'] + 1.4, $h['y'] + 0.8, 2.2, e($rot), ' fill="#fff"');
        if (!$h['cont']) $s .= $txt($h['x'] + $pw - 1.3, $h['y'] + 0.8, 2.1, ($pt['inicio'] + 1) . ($pt['n'] > 1 ? '–' . $pt['fim'] : ''), ' fill="#fff" text-anchor="end"');
    }
    $r = $L['raio'];
    $fq = min(2.7, $L['passo'] * 0.45);
    foreach ($L['questoes'] as $q) {
        $s .= $txt($q['x'] + 6.2, $q['y'] + $fq * 0.36, $fq, $q['n'], ' font-weight="600" text-anchor="end"');
        foreach ($q['alts'] as $a => [$x, $y]) {
            $s .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff" stroke="#000" stroke-width="0.26"/>';
            $s .= $txt($x, $y + $r * 0.4, round($r * 1.0, 2), $letras[$a], ' text-anchor="middle" fill="#6b6b6b"');
        }
    }

    /* ---------- Metade direita: nota e instruções ---------- */
    $RX = $ax + $aw + 4; $RW = $X1 - $RX;
    $np = count($partes);
    $rh = min(12.5, max(9.5, 84 / $np));                       // altura de cada linha da nota (a nota é escrita à caneta)
    $nh = 8.5 + $np * $rh + 1.5;
    $s .= $caixa($RX, $ay, $RW, $nh, 2.6, 0.4);
    $s .= $rect($RX, $ay, 20, 5.6, 1.8, '#000') . $txt($RX + 10, $ay + 3.95, 2.7, 'NOTA', ' text-anchor="middle" font-weight="700" fill="#fff" letter-spacing="0.4"');
    $s .= $txt($RX + $RW - 3, $ay + 3.95, 1.9, 'Uso exclusivo do professor', ' text-anchor="end" fill="#555"');
    $y = $ay + 8.5; $bw = 30; $bh = $rh - 2.2;
    foreach ($partes as $i => $pt) {
        if ($i) $s .= '<line x1="' . ($RX + 3.5) . '" y1="' . round($y - 1.1, 2) . '" x2="' . ($RX + $RW - 3.5) . '" y2="' . round($y - 1.1, 2) . '" stroke="#000" stroke-width="0.12" stroke-dasharray="0.6 0.6"/>';
        $s .= $txt($RX + 3.5, $y + $bh / 2 + 1.2, $cabe($pt['nome'], $RW - $bw - 11, 3.4, 0.56), e($pt['nome']), ' font-weight="700"');
        $s .= $caixa($RX + $RW - $bw - 3.5, $y, $bw, $bh, 1.4, 0.35);
        $y += $rh;
    }

    $disc = mb_strtoupper(implode(', ', array_column($partes, 'nome')));
    $itens = [
        'Preencha os dados do participante corretamente: nome completo, turma, professor aplicador e data.',
        "Este caderno possui $n questões, numeradas de **1 a $n**, correspondentes a **$disc**.",
        omr_duracao($prova),
        'Leia atentamente as questões antes de responder.',
        'Marque as respostas na prova e somente depois passe para a FOLHA DE RESPOSTAS, no campo correspondente a cada questão.',
        'Em cada questão há somente **uma** resposta certa.',
        'A correção é feita **pelo sistema**. O **QR code**, os **quadrados pretos** e a **área de respostas** não podem, de forma alguma, ser rasurados, riscados ou dobrados: isso impede a identificação e a leitura da folha.',
        'A FOLHA DE RESPOSTAS é o único documento usado na correção. Não amasse, não dobre nem rasure. Marque só com caneta esferográfica de **tinta preta**; não é permitido lápis, lapiseira ou borracha.',
        'Não use corretivo nem tente apagar uma alternativa depois de marcada.',
        'É proibido o empréstimo de materiais como borracha, lápis ou caneta.',
        'Não será permitida a saída de alunos durante a prova.',
        'O campo **NOTA** deve ficar em branco: é de uso exclusivo do professor responsável pela correção.',
        'A prova será recolhida quando **TODOS** os alunos concluírem.',
    ];
    $IY = $ay + $nh + 3; $IH = $ay + $ah - $IY;
    $T0 = $IY + 10; $T1 = $IY + $IH - 3;
    $s .= $caixa($RX, $IY, $RW, $IH, 2.6, 0.4);
    $s .= $rect($RX, $IY, 31, 6, 1.8, '#000') . $txt($RX + 15.5, $IY + 4.2, 2.9, 'INSTRUÇÕES', ' text-anchor="middle" font-weight="700" fill="#fff" letter-spacing="0.4"');
    $lw = $RW - 12;
    for ($fs = 3.05; $fs >= 2.2; $fs -= 0.05) {
        $lh = $fs * 1.32; $tot = 0; $blocos = [];
        foreach ($itens as $it) { $blocos[] = $b = svg_linhas($it, $lw, $fs); $tot += count($b) * $lh + $lh * 0.35; }
        if ($tot <= $T1 - $T0 + $lh) break;
    }
    /* sobra espaço embaixo? distribui entre os itens para as instruções irem até o fim do quadro */
    $sobra = max(0, ($T1 - $T0 + $lh) - $tot);
    $extra = count($blocos) > 1 ? min($lh * 1.4, $sobra / (count($blocos) - 1)) : 0;
    $y = $T0;
    foreach ($blocos as $b) {
        foreach ($b as $i => $l) {
            if ($i === 0) $s .= '<circle cx="' . ($RX + 4) . '" cy="' . round($y - $fs * 0.33, 2) . '" r="0.55"/>';
            $s .= $txt($RX + 6.3, $y, $fs, $l);
            $y += $lh;
        }
        $y += $lh * 0.35 + $extra;
    }

    $s .= $txt(105, 288.6, 1.9, 'Não escreva sobre os quadrados pretos nem sobre o QR code · ' . e(impresso_por()), ' text-anchor="middle" fill="#444"');
    return $s . '</svg>';
}
