<?php
/* ======================================================================
 *  Interclasse — v79
 *  O interclasse agora tem um site próprio. Aqui fica só o link para ele
 *  (Painel › Site › "Links externos"), usado no menu do site.
 *  As tabelas ic_* da v77 continuam no banco, sem uso (nada foi apagado).
 * ====================================================================== */

/** Endereço do site do interclasse (vazio = o link não aparece no site) */
function ic_url(): string
{
    $u = trim((string)config_ler('interclasse_url'));
    return preg_match('#^https?://#i', $u) ? $u : '';
}

/** Compatibilidade: o perfil restrito do interclasse não existe mais */
function so_interclasse(): bool { return false; }

function ic_migrar(): void
{
    if (config_ler('migracao_v79_ic')) return;
    try {
        // tira a permissão e o perfil "Interclasse" (o perfil só sai se ninguém estiver usando)
        q("DELETE FROM perfil_permissoes WHERE permissao = 'interclasse'");
        q("DELETE FROM usuario_permissoes WHERE permissao = 'interclasse'");
        if (!q_valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'interclasse'")) q("DELETE FROM perfis WHERE chave = 'interclasse'");
        config_gravar('ic_ativo', '0');
        config_gravar('migracao_v79_ic', date('Y-m-d H:i:s'));
    } catch (Throwable $e) {}
}
