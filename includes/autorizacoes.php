<?php
/* =====================================================================
 *  Autorizações: saída da escola, porte de tablet pessoal (o aluno traz
 *  o próprio tablet para estudar no horário do almoço; o cupom funciona
 *  como identificação quando um professor pergunta) e outras. Todas ficam na tabela
 *  "saidas", com o tipo, o código de autenticação e a assinatura digital
 *  de quem emitiu.
 * ===================================================================== */

/* tipo => [rótulo, ícone, título do cupom] */
const TIPOS_AUTORIZACAO = [
    'saida'  => ['Saída da escola', 'box-arrow-right', 'AUTORIZAÇÃO DE SAÍDA'],
    'tablet' => ['Porte de tablet', 'tablet', 'AUTORIZAÇÃO DE PORTE DE TABLET'],
    'outra'  => ['Autorização interna', 'door-open', 'AUTORIZAÇÃO INTERNA'],   // v74: aluno sai da sala (laboratório, biblioteca…), emitida por qualquer professor
];
const MOTIVOS_SAIDA = ['Mal-estar / doença', 'Consulta médica ou odontológica', 'Problema familiar', 'Solicitação do responsável', 'Compromisso documentado', 'Outro'];
const FINALIDADES_TABLET = ['Estudo e revisão de conteúdo', 'Pesquisa para trabalho escolar', 'Leitura (livros digitais)', 'Preparação para o ENEM', 'Atividade do curso técnico', 'Outra atividade de estudo'];
const TERMO_TABLET = 'O(a) estudante está autorizado(a) a portar seu tablet pessoal na escola somente para estudo, no local e horário indicados. Fora disso, o aparelho deve ficar desligado e guardado. A escola não se responsabiliza por perda, furto ou dano. Apresente este cupom sempre que solicitado.';

function tipo_autorizacao(array $s): array { return TIPOS_AUTORIZACAO[$s['tipo'] ?? 'saida'] ?? TIPOS_AUTORIZACAO['saida']; }

/** Nome curto da autorização para listas */
function autorizacao_nome(array $s): string
{
    $t = $s['tipo'] ?? 'saida';
    if ($t === 'tablet') return 'Porte de tablet pessoal';
    if ($t === 'outra') return $s['titulo'] ?: 'Autorização';
    return 'Saída da escola';
}

/** A autorização vale hoje? */
function autorizacao_vigente(array $s, ?string $dia = null): bool
{
    $dia ??= date('Y-m-d');
    $ini = substr($s['data_hora'], 0, 10);
    return $dia >= $ini && $dia <= ($s['validade_fim'] ?: $ini);
}

function vigencia_texto(array $s): string
{
    $ini = substr($s['data_hora'], 0, 10);
    if (!$s['validade_fim'] || $s['validade_fim'] === $ini) return 'Válida em ' . data_br($ini);
    return 'Válida de ' . data_br($ini) . ' até ' . data_br($s['validade_fim']);
}

/** Lista de autorizações com aluno e turma */
function autorizacoes_lista(string $where, array $p, int $limite = 300): array
{
    if (!str_contains($where, 's.status')) $where = "($where) AND s.status = 'autorizada'";   // pedidos pendentes/recusados ficam de fora
    try {
        return q_todos('SELECT s.*, a.nome, a.foto, t.serie, t.letra, c.nome AS curso FROM saidas s JOIN alunos a ON a.id = s.aluno_id
                          LEFT JOIN turmas t ON t.id = COALESCE((SELECT xa.turma_id FROM aluno_turmas xa WHERE xa.aluno_id = a.id AND xa.ano_letivo = @ano_ver), a.turma_id) LEFT JOIN cursos c ON c.id = t.curso_id
                         WHERE ' . $where . ' ORDER BY s.data_hora DESC, s.id DESC LIMIT ' . $limite, $p);
    } catch (PDOException $e) { return []; }
}

const STATUS_AUTORIZACAO = [
    'pendente'   => ['Aguardando coordenação', 'ambar', 'hourglass-split'],
    'autorizada' => ['Autorizada', 'verde', 'patch-check-fill'],
    'recusada'   => ['Recusada', 'vermelho', 'x-octagon'],
];
