<?php
/* Rodapé padrão: o MESMO no site, na tela de login e em todas as páginas do painel.
 * $raiz = caminho até a raiz do site ('' no site, '../' dentro de painel/). */
function rodape_padrao(string $raiz = ''): string
{
    $ESCOLA = $GLOBALS['ESCOLA']; $ATALHOS = $GLOBALS['ATALHOS'] ?? [];
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $insta = $ESCOLA['instagram'] ? 'https://instagram.com/' . rawurlencode($ESCOLA['instagram']) : '';
    $mapa = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ESCOLA['mapa']);
    $site = $raiz . 'index.php';
    ob_start(); ?>
<footer class="rodape">
    <div class="rodape-onda" aria-hidden="true"></div>
    <div class="largura rodape-grade">
        <div class="rodape-marca">
            <img src="<?= $esc($raiz . ($ESCOLA['logo_branca'] ?? 'assets/img/logo-branca.png')) ?>" alt="<?= $esc($ESCOLA['nome']) ?>" width="1400" height="451" loading="lazy">
            <p><?= $esc($ESCOLA['slogan']) ?>. Rede estadual de Escolas de Educação Profissional do Ceará.</p>
        </div>
        <nav class="rodape-col" aria-label="Navegação do rodapé">
            <h3>Navegação</h3>
            <?php foreach ([['house', 'Início', '#inicio'], ['building', 'A escola', '#escola'], ['mortarboard', 'Cursos', '#cursos'], ['clock-history', 'História', '#historia']] as [$ic, $rot, $ancora]): ?>
                <a href="<?= $site . $ancora ?>"><span class="rd-i"><i class="bi bi-<?= $ic ?>"></i></span><?= $rot ?></a>
            <?php endforeach; ?>
        </nav>
        <nav class="rodape-col" aria-label="Acesso rápido">
            <h3>Acesso rápido</h3>
            <?php foreach ([['box-arrow-in-right', 'Login', 'login.php'], ['ui-checks-grid', 'Corrigir avaliações', 'painel/provas.php'], ['shield-exclamation', 'Registro de ocorrências', 'painel/ocorrencias.php'], ['journal-bookmark', 'Livros didáticos', 'painel/livros_didaticos.php']] as [$ic, $rot, $url]): ?>
                <a href="<?= $esc($raiz . $url) ?>"><span class="rd-i"><i class="bi bi-<?= $ic ?>"></i></span><?= $rot ?></a>
            <?php endforeach; ?>
        </nav>
        <nav class="rodape-col rodape-fale" aria-label="Fale com a escola">
            <h3>Fale com a escola</h3>
            <a href="<?= $esc($mapa) ?>" target="_blank" rel="noopener" title="<?= $esc($ESCOLA['endereco'] . ' · ' . $ESCOLA['cidade']) ?>"><span class="rd-i"><i class="bi bi-geo-alt"></i></span>Localização</a>
            <?php if ($ESCOLA['email']): ?><a href="mailto:<?= $esc($ESCOLA['email']) ?>" title="<?= $esc($ESCOLA['email']) ?>"><span class="rd-i"><i class="bi bi-envelope"></i></span>E-mail</a><?php endif; ?>
            <?php if ($ESCOLA['telefone']): ?><a href="tel:<?= $esc(preg_replace('/\D/', '', $ESCOLA['telefone'])) ?>" title="<?= $esc($ESCOLA['telefone']) ?>"><span class="rd-i"><i class="bi bi-telephone"></i></span>Contato</a><?php elseif ($insta): ?><a href="<?= $esc($insta) ?>" target="_blank" rel="noopener" title="Instagram @<?= $esc($ESCOLA['instagram']) ?>"><span class="rd-i"><i class="bi bi-instagram"></i></span>Contato</a><?php endif; ?>
            <div class="rodape-atend"><span class="rd-i"><i class="bi bi-clock"></i></span><div><small>Atendimento ao público</small><span><?= $esc($ESCOLA['atendimento'] ?? 'Segunda a sexta') ?></span></div></div>
        </nav>
    </div>
    <div class="rodape-base">
        <div class="largura rodape-base-linha">
            <span>© <?= date('Y') ?> EEEP Venceslau Vieira Batista | Todos os direitos reservados.</span>
            <span class="rodape-dev">Desenvolvido por <a href="https://www.instagram.com/ruanmtu_/" target="_blank" rel="noopener">Ruan Uchoa</a></span>
        </div>
    </div>
</footer>
    <?php return ob_get_clean();
}
