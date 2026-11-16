<?php
/* =====================================================================
 *  Agenda do professor: um caderno digital com uma página por dia.
 *  Cada usuário vê só as próprias anotações. O texto formatado passa por
 *  uma limpeza (só negrito, itálico, sublinhado, marca-texto, cores,
 *  fontes, listas e tarefas) antes de ir para o banco.
 * ===================================================================== */

const AGENDA_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'span', 'div', 'p', 'br', 'ul', 'ol', 'li', 'font', 'mark', 'h3'];
const AGENDA_ESTILOS = ['font-weight', 'font-style', 'text-decoration', 'text-decoration-line', 'color', 'background-color', 'font-family', 'font-size', 'text-align'];

/** Limpa o HTML do editor: só as marcações permitidas, sem scripts, links ou eventos */
function agenda_limpar_html(string $html): string
{
    $html = trim($html);
    if ($html === '' || !class_exists('DOMDocument')) return e(strip_tags($html));
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $raiz = $doc->getElementById('raiz');
    if (!$raiz) return '';
    $limpar = function (DOMNode $no) use (&$limpar, $doc) {
        foreach (iterator_to_array($no->childNodes) as $f) {
            if ($f instanceof DOMText) continue;
            if (!($f instanceof DOMElement)) { $no->removeChild($f); continue; }
            $tag = strtolower($f->tagName);
            if (!in_array($tag, AGENDA_TAGS, true)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'img', 'svg', 'math', 'video', 'audio', 'form', 'input', 'textarea', 'select', 'button', 'link', 'meta'], true)) { $no->removeChild($f); continue; }
                $limpar($f);                                          // tag desconhecida: fica só o conteúdo
                while ($f->firstChild) $no->insertBefore($f->firstChild, $f);
                $no->removeChild($f);
                continue;
            }
            foreach (iterator_to_array($f->attributes) as $a) {
                $n = strtolower($a->name);
                if ($n === 'style') {
                    $ok = [];
                    foreach (explode(';', $a->value) as $d) {
                        [$p, $v] = array_map('trim', explode(':', $d, 2) + [1 => '']);
                        $p = strtolower($p);
                        if (in_array($p, AGENDA_ESTILOS, true) && $v !== '' && !preg_match('/url\s*\(|expression|javascript|[<>"]/i', $v)) $ok[] = "$p: $v";
                    }
                    if ($ok) $f->setAttribute('style', implode('; ', $ok)); else $f->removeAttribute('style');
                } elseif ($n === 'class') {
                    $c = array_intersect(preg_split('/\s+/', $a->value), ['tarefa', 'feita']);
                    if ($c) $f->setAttribute('class', implode(' ', $c)); else $f->removeAttribute('class');
                } elseif ($tag === 'font' && in_array($n, ['face', 'color', 'size'], true)) {
                    if (preg_match('/[<>"(]|javascript/i', $a->value)) $f->removeAttribute($a->name);
                } else {
                    $f->removeAttribute($a->name);
                }
            }
            $limpar($f);
        }
    };
    $limpar($raiz);
    $out = '';
    foreach ($raiz->childNodes as $f) $out .= $doc->saveHTML($f);
    return mb_substr($out, 0, 500000);
}

/** Só o texto, para a busca e para a lista de anotações */
function agenda_texto(string $html): string
{
    $t = preg_replace('/<(br|\/?p|\/?div|\/?li|\/?h3)\b[^>]*>/i', "\n", $html);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{2,}/", "\n", $t)));
}

function agenda_buscar(int $uid, string $data): ?array
{
    try { return q_um('SELECT * FROM agenda_notas WHERE usuario_id = ? AND data = ?', [$uid, $data]) ?: null; }
    catch (PDOException $e) { return null; }
}

/** Salva (ou apaga, se ficar vazia) a página do dia */
function agenda_salvar(int $uid, string $data, string $html): array
{
    $limpo = agenda_limpar_html($html);
    $texto = agenda_texto($limpo);
    $agora = date('Y-m-d H:i:s');
    if ($texto === '') {
        q('DELETE FROM agenda_notas WHERE usuario_id = ? AND data = ?', [$uid, $data]);   // página em branco não ocupa espaço
        return ['ok' => true, 'vazio' => true, 'salvo_em' => date('H:i')];
    }
    if (q_valor('SELECT id FROM agenda_notas WHERE usuario_id = ? AND data = ?', [$uid, $data])) {
        q('UPDATE agenda_notas SET conteudo = ?, texto = ?, atualizado_em = ? WHERE usuario_id = ? AND data = ?', [$limpo, mb_substr($texto, 0, 60000), $agora, $uid, $data]);
    } else {
        q('INSERT INTO agenda_notas (usuario_id, data, conteudo, texto, atualizado_em) VALUES (?, ?, ?, ?, ?)', [$uid, $data, $limpo, mb_substr($texto, 0, 60000), $agora]);
    }
    return ['ok' => true, 'vazio' => false, 'salvo_em' => date('H:i')];
}

/** Dias do mês que têm anotação */
function agenda_dias_mes(int $uid, string $mes): array
{
    try {
        return array_column(q_todos('SELECT data FROM agenda_notas WHERE usuario_id = ? AND data BETWEEN ? AND ?', [$uid, "$mes-01", date('Y-m-t', strtotime("$mes-01"))]), 'data');
    } catch (PDOException $e) { return []; }
}

function agenda_lista(int $uid, string $busca = '', int $limite = 12): array
{
    try {
        if ($busca !== '') return q_todos('SELECT data, texto, atualizado_em FROM agenda_notas WHERE usuario_id = ? AND texto LIKE ? ORDER BY data DESC LIMIT ' . $limite, [$uid, '%' . $busca . '%']);
        return q_todos('SELECT data, texto, atualizado_em FROM agenda_notas WHERE usuario_id = ? ORDER BY data DESC LIMIT ' . $limite, [$uid]);
    } catch (PDOException $e) { return []; }
}
