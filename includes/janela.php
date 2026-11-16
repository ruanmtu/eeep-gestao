<?php
/* Formulários em janela: a página mostra só um botão ("Registrar ocorrência", "Novo empréstimo"…)
   e o formulário abre numa janela por cima ao clicar. */

/** Botão que abre a janela */
function janela_botao(string $id, string $rotulo, string $icone = 'plus-lg', string $classe = 'btn btn-primary'): string
{
    return '<button type="button" class="' . e($classe) . ' janela-bt" data-bs-toggle="modal" data-bs-target="#' . e($id) . '"><i class="bi bi-' . e($icone) . ' me-1"></i>' . e($rotulo) . '</button>';
}

/** Começo da janela. $abrir = já abre ao carregar a página (ex.: editando um item, ou vindo de um atalho) */
function janela_abre(string $id, string $titulo, string $icone = 'plus-lg', bool $abrir = false, string $tamanho = 'modal-lg'): string
{
    return '<div class="modal fade janela" id="' . e($id) . '" tabindex="-1" aria-labelledby="' . e($id) . 'T" aria-hidden="true"' . ($abrir ? ' data-abrir="1"' : '') . '>'
         . '<div class="modal-dialog ' . e($tamanho) . ' modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">'
         . '<div class="modal-header"><span class="janela-ic"><i class="bi bi-' . e($icone) . '"></i></span><h5 class="modal-title" id="' . e($id) . 'T">' . e($titulo) . '</h5>'
         . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body">';
}

function janela_fecha(): string { return '</div></div></div></div>'; }
