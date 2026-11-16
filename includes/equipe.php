<?php
/* Equipe do site: galeria de gestores e de funcionários (cadastro em Menu › Equipe no site) */

function equipe_lista(?string $grupo = null, bool $so_ativos = true): array
{
    try {
        $w = []; $p = [];
        if ($so_ativos) $w[] = 'ativo = 1';
        if ($grupo) { $w[] = 'grupo = ?'; $p[] = $grupo; }
        return q_todos('SELECT * FROM equipe_site' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY ordem, nome', $p);
    } catch (PDOException $e) { return []; }
}

/** Endereço da foto para usar no site (raiz) ou no painel ('../') */
function equipe_foto_src(array $e, string $raiz = ''): ?string
{
    return !empty($e['foto']) ? $raiz . 'foto_equipe.php?id=' . (int)$e['id'] . '&v=' . substr(md5((string)$e['foto']), 0, 8) : null;
}
