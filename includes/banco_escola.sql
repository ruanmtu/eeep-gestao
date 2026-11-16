-- =====================================================================
--  SISTEMA ESCOLAR EEEP  ·  Banco de dados completo
--  Compatível com MySQL 5.7+ / MariaDB 10.3+ (HostEngine / cPanel)
--
--  COMO USAR: crie o banco no painel da hospedagem, abra o phpMyAdmin,
--  selecione o banco e importe este arquivo (aba "Importar").
--  Pode ser importado mais de uma vez sem duplicar dados.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Usuários do sistema (gestão, coordenação, secretaria, professores)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome           VARCHAR(120) NOT NULL,
  nome_exibicao  VARCHAR(60) NULL,                       -- como aparece no sistema (ex.: Prof. Juan)
  email          VARCHAR(160) NOT NULL,
  senha_hash     VARCHAR(255) NOT NULL,
  perfil         VARCHAR(40) NOT NULL DEFAULT 'professor',   -- chave da tabela perfis
  cargo          VARCHAR(80) NULL,                       -- função que sai na assinatura (ex.: Diretor geral)
  foto           VARCHAR(160) NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  trocar_senha   TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acesso  DATETIME NULL,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Cursos técnicos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cursos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(80) NOT NULL,
  sigla      VARCHAR(10) NOT NULL,
  descricao  TEXT NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cursos_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Turmas (curso + série + letra + ano letivo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS turmas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_id    INT UNSIGNED NOT NULL,
  serie       TINYINT UNSIGNED NOT NULL,           -- 1, 2 ou 3
  letra       CHAR(1) NOT NULL,
  ano_letivo  SMALLINT UNSIGNED NOT NULL,
  turno       VARCHAR(20) NOT NULL DEFAULT 'Integral',
  diretor_turma_id INT UNSIGNED NULL,              -- professor(a) diretor(a) de turma (recebe os avisos da turma)
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_turma (curso_id, serie, letra, ano_letivo),
  KEY idx_turma_dt (diretor_turma_id),
  CONSTRAINT fk_turmas_curso FOREIGN KEY (curso_id) REFERENCES cursos (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Disciplinas (base comum e técnicas)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS disciplinas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome           VARCHAR(100) NOT NULL,
  tipo           ENUM('base_comum','tecnica') NOT NULL DEFAULT 'base_comum',
  curso_id       INT UNSIGNED NULL,                  -- só para disciplinas técnicas
  carga_horaria  SMALLINT UNSIGNED NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_disciplina (nome, curso_id),
  CONSTRAINT fk_disc_curso FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Atribuições: qual professor leciona qual disciplina em qual turma
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS atribuicoes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  turma_id       INT UNSIGNED NOT NULL,
  disciplina_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_atribuicao (usuario_id, turma_id, disciplina_id),
  CONSTRAINT fk_atr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_atr_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE,
  CONSTRAINT fk_atr_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Alunos: todos os dados pessoais, de matrícula e da família
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alunos (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  matricula               VARCHAR(30) NULL,
  nome                    VARCHAR(150) NOT NULL,
  data_nascimento         DATE NULL,
  sexo                    ENUM('F','M','O') NULL,
  cpf                     VARCHAR(14) NULL,
  rg                      VARCHAR(30) NULL,
  nis                     VARCHAR(20) NULL,
  turma_id                INT UNSIGNED NULL,
  situacao                ENUM('ativo','transferido','desistente','concluido') NOT NULL DEFAULT 'ativo',
  data_matricula          DATE NULL,
  telefone                VARCHAR(20) NULL,
  email                   VARCHAR(160) NULL,
  endereco                VARCHAR(200) NULL,
  bairro                  VARCHAR(80) NULL,
  cidade                  VARCHAR(80) NULL,
  nome_mae                VARCHAR(150) NULL,
  nome_pai                VARCHAR(150) NULL,
  responsavel_nome        VARCHAR(150) NULL,
  responsavel_parentesco  VARCHAR(40) NULL,
  responsavel_telefone    VARCHAR(20) NULL,
  necessidades_especiais  TEXT NULL,
  foto                    VARCHAR(160) NULL,
  observacoes             TEXT NULL,
  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_alunos_matricula (matricula),
  KEY idx_alunos_nome (nome),
  KEY idx_alunos_turma (turma_id),
  CONSTRAINT fk_alunos_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Faltas (infrequência diária)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faltas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id        INT UNSIGNED NOT NULL,
  data_falta      DATE NOT NULL,
  justificada     TINYINT(1) NOT NULL DEFAULT 0,
  justificativa   VARCHAR(255) NULL,
  registrado_por  INT UNSIGNED NULL,
  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_falta (aluno_id, data_falta),
  KEY idx_faltas_data (data_falta),
  CONSTRAINT fk_faltas_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_faltas_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notas por bimestre
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id       INT UNSIGNED NOT NULL,
  disciplina_id  INT UNSIGNED NOT NULL,
  ano_letivo     SMALLINT UNSIGNED NOT NULL,
  bimestre       TINYINT UNSIGNED NOT NULL,          -- 1 a 4
  nota           DECIMAL(4,2) NOT NULL,
  lancado_por    INT UNSIGNED NULL,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nota (aluno_id, disciplina_id, ano_letivo, bimestre),
  KEY idx_notas_disc (disciplina_id, ano_letivo, bimestre),
  CONSTRAINT fk_notas_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_notas_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE CASCADE,
  CONSTRAINT fk_notas_usuario FOREIGN KEY (lancado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Ocorrências (disciplinares, pedagógicas, saúde, elogios)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ocorrencias (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id                INT UNSIGNED NOT NULL,
  data_ocorrencia         DATE NOT NULL,
  hora                    TIME NULL,
  categoria               VARCHAR(40) NOT NULL DEFAULT 'outro',   -- advertência verbal, retirada de sala, suspensão...
  local                   VARCHAR(60) NULL,
  suspensao_inicio        DATE NULL,
  suspensao_fim           DATE NULL,
  ato_id                  INT UNSIGNED NULL,                      -- veio dos antigos atos administrativos
  tipo                    ENUM('disciplinar','pedagogica','saude','elogio','outro') NOT NULL DEFAULT 'disciplinar',
  gravidade               ENUM('leve','media','grave') NOT NULL DEFAULT 'leve',
  descricao               TEXT NOT NULL,
  providencias            TEXT NULL,
  responsavel_notificado  TINYINT(1) NOT NULL DEFAULT 0,
  registrado_por          INT UNSIGNED NULL,
  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_oc_ato (ato_id),
  KEY idx_oc_aluno (aluno_id),
  KEY idx_oc_data (data_ocorrencia),
  CONSTRAINT fk_oc_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_oc_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Atos administrativos (advertências, suspensões, declarações...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS atos_administrativos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id          INT UNSIGNED NOT NULL,
  tipo              ENUM('advertencia_verbal','advertencia_escrita','suspensao','convocacao_responsavel',
                         'termo_compromisso','declaracao','transferencia','outro') NOT NULL,
  data_ato          DATE NOT NULL,
  numero_documento  VARCHAR(40) NULL,
  descricao         TEXT NOT NULL,
  data_inicio       DATE NULL,                       -- suspensão: início
  data_fim          DATE NULL,                       -- suspensão: fim
  registrado_por    INT UNSIGNED NULL,
  criado_em         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_atos_aluno (aluno_id),
  KEY idx_atos_data (data_ato),
  CONSTRAINT fk_atos_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_atos_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Registro de atividades (quem fez o quê, e quando)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_atividades (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NULL,
  aluno_id    INT UNSIGNED NULL,
  acao        VARCHAR(60) NOT NULL,
  detalhe     VARCHAR(255) NULL,
  ip          VARCHAR(45) NULL,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_aluno (aluno_id),
  KEY idx_log_data (criado_em),
  CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_log_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Provas com correção por leitura óptica (gabarito cadastrado)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provas (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo            VARCHAR(120) NOT NULL,
  disciplina_id     INT UNSIGNED NOT NULL,
  serie             TINYINT UNSIGNED NOT NULL,         -- 1, 2 ou 3 (vale para todas as turmas da série)
  ano_letivo        SMALLINT UNSIGNED NOT NULL,
  bimestre          TINYINT UNSIGNED NOT NULL,
  data_aplicacao    DATE NULL,
  num_questoes      TINYINT UNSIGNED NOT NULL,         -- até 60
  num_alternativas  TINYINT UNSIGNED NOT NULL DEFAULT 5, -- 4 (A–D) ou 5 (A–E)
  valor             DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  gabarito          VARCHAR(60) NULL,                  -- uma letra por questão; * = questão anulada
  formato_externo   TEXT NULL,                         -- folha de outro modelo: colunas e posição das bolinhas (JSON)
  hora_inicio       TIME NULL,                         -- duração da prova (sai nas instruções da folha)
  hora_fim          TIME NULL,
  aplicador         VARCHAR(160) NULL,                 -- professor(es) aplicador(es), opcional
  modelo_folha      TINYINT UNSIGNED NOT NULL DEFAULT 1, -- desenho da folha (1 = até a v11, 2 = atual)
  criado_por        INT UNSIGNED NULL,
  criado_em         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_provas_disc (disciplina_id, serie, ano_letivo),
  CONSTRAINT fk_provas_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE CASCADE,
  CONSTRAINT fk_provas_usuario FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloco de provas: uma folha de respostas com várias disciplinas
-- (ex.: Português 1 a 5, Espanhol 6 a 15, Matemática 16 a 22)
CREATE TABLE IF NOT EXISTS prova_partes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prova_id       INT UNSIGNED NOT NULL,
  ordem          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  disciplina_id  INT UNSIGNED NULL,
  nome           VARCHAR(80) NOT NULL,
  num_questoes   TINYINT UNSIGNED NOT NULL,
  valor          DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  PRIMARY KEY (id),
  KEY idx_ppar_prova (prova_id, ordem),
  CONSTRAINT fk_ppar_prova FOREIGN KEY (prova_id) REFERENCES provas (id) ON DELETE CASCADE,
  CONSTRAINT fk_ppar_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prova_resultados (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prova_id       INT UNSIGNED NOT NULL,
  aluno_id       INT UNSIGNED NOT NULL,
  respostas      VARCHAR(60) NOT NULL,                 -- letra marcada por questão; - = em branco; M = mais de uma
  acertos        TINYINT UNSIGNED NOT NULL,
  nota           DECIMAL(5,2) NOT NULL,
  partes         TEXT NULL,                            -- acertos e nota de cada disciplina do bloco (JSON)
  corrigido_por  INT UNSIGNED NULL,
  corrigido_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_resultado (prova_id, aluno_id),
  CONSTRAINT fk_res_prova FOREIGN KEY (prova_id) REFERENCES provas (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_usuario FOREIGN KEY (corrigido_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Liberações de saída (cupom assinado pelo usuário que autorizou)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saidas (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id                INT UNSIGNED NOT NULL,
  data_hora               DATETIME NOT NULL,
  motivo                  VARCHAR(255) NOT NULL,
  responsavel_nome        VARCHAR(150) NULL,             -- familiar que autorizou ou buscou
  responsavel_parentesco  VARCHAR(40) NULL,
  autorizado_por          INT UNSIGNED NULL,             -- usuário logado que liberou
  autorizado_nome         VARCHAR(120) NOT NULL,         -- nome e função gravados no momento
  autorizado_cargo        VARCHAR(80) NOT NULL,
  codigo                  CHAR(12) NOT NULL,             -- código de autenticação do cupom
  tipo                    VARCHAR(20) NOT NULL DEFAULT 'saida',   -- saida, tablet, outra
  titulo                  VARCHAR(80) NULL,                       -- nome da autorização (tipo "outra")
  item                    VARCHAR(60) NULL,                       -- ex.: Tablet 07
  local                   VARCHAR(60) NULL,                       -- ex.: Centro de Multimeios
  horario                 VARCHAR(60) NULL,                       -- ex.: horário do almoço
  validade_fim            DATE NULL,                              -- autorização vale até esta data
  status                  VARCHAR(20) NOT NULL DEFAULT 'autorizada', -- pendente (pedido do professor), autorizada, recusada
  solicitado_por          INT UNSIGNED NULL,                      -- professor que pediu a liberação
  solicitado_nome         VARCHAR(120) NULL,
  solicitado_em           DATETIME NULL,
  recusa_motivo           VARCHAR(255) NULL,
  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saida_codigo (codigo),
  KEY idx_saidas_aluno (aluno_id),
  KEY idx_saidas_data (data_hora),
  KEY idx_saidas_tipo (tipo),
  KEY idx_saidas_status (status),
  CONSTRAINT fk_saidas_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_saidas_usuario FOREIGN KEY (autorizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Alertas de 3 faltas seguidas já tratados pela gestão
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertas_contato (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id        INT UNSIGNED NOT NULL,
  sequencia_fim   DATE NOT NULL,                         -- último dia da sequência tratada
  dias            TINYINT UNSIGNED NOT NULL,
  observacao      VARCHAR(255) NULL,
  resolvido_por   INT UNSIGNED NULL,
  resolvido_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_alerta (aluno_id, sequencia_fim),
  CONSTRAINT fk_alerta_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_alerta_usuario FOREIGN KEY (resolvido_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Avaliações (global, parcial, conceitual) e notas de cada avaliação.
-- A média do bimestre (tabela notas) = soma das notas / quantidade.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS avaliacoes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  turma_id        INT UNSIGNED NOT NULL,
  disciplina_id   INT UNSIGNED NOT NULL,
  ano_letivo      SMALLINT UNSIGNED NOT NULL,
  bimestre        TINYINT UNSIGNED NOT NULL,
  tipo            ENUM('global','parcial','conceitual') NOT NULL,
  titulo          VARCHAR(120) NOT NULL,
  data_aplicacao  DATE NULL,
  lote            VARCHAR(16) NULL,                     -- avaliações criadas juntas (várias turmas)
  criado_por      INT UNSIGNED NULL,
  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aval_turma (turma_id, disciplina_id, ano_letivo, bimestre),
  CONSTRAINT fk_aval_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE,
  CONSTRAINT fk_aval_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE CASCADE,
  CONSTRAINT fk_aval_usuario FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS avaliacao_notas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  avaliacao_id   INT UNSIGNED NOT NULL,
  aluno_id       INT UNSIGNED NOT NULL,
  nota           DECIMAL(4,2) NOT NULL,
  lancado_por    INT UNSIGNED NULL,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_aval_nota (avaliacao_id, aluno_id),
  KEY idx_avn_aluno (aluno_id),
  CONSTRAINT fk_avn_aval FOREIGN KEY (avaliacao_id) REFERENCES avaliacoes (id) ON DELETE CASCADE,
  CONSTRAINT fk_avn_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_avn_usuario FOREIGN KEY (lancado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Centro de Multimeios (biblioteca)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS livros (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo       VARCHAR(200) NOT NULL,
  autor        VARCHAR(150) NOT NULL,
  editora      VARCHAR(100) NULL,
  ano          SMALLINT UNSIGNED NULL,
  isbn         VARCHAR(20) NULL,
  categoria    VARCHAR(60) NULL,
  exemplares   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  localizacao  VARCHAR(40) NULL,                        -- estante / prateleira
  observacao   VARCHAR(255) NULL,
  ativo        TINYINT(1) NOT NULL DEFAULT 1,
  criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_livros_titulo (titulo),
  KEY idx_livros_autor (autor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biblioteca_fichas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id    INT UNSIGNED NOT NULL,
  numero      VARCHAR(20) NOT NULL,
  bloqueada   TINYINT(1) NOT NULL DEFAULT 0,
  observacao  VARCHAR(255) NULL,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ficha_aluno (aluno_id),
  UNIQUE KEY uk_ficha_numero (numero),
  CONSTRAINT fk_ficha_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emprestimos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  livro_id        INT UNSIGNED NOT NULL,
  aluno_id        INT UNSIGNED NOT NULL,
  retirada        DATETIME NOT NULL,                    -- data e hora em que pegou
  prazo           DATE NOT NULL,                        -- até quando deve devolver
  devolvido_em    DATETIME NULL,
  renovacoes      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  observacao      VARCHAR(255) NULL,
  registrado_por  INT UNSIGNED NULL,
  devolvido_por   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_emp_aberto (devolvido_em, prazo),
  KEY idx_emp_aluno (aluno_id),
  KEY idx_emp_livro (livro_id),
  CONSTRAINT fk_emp_livro FOREIGN KEY (livro_id) REFERENCES livros (id) ON DELETE CASCADE,
  CONSTRAINT fk_emp_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_emp_reg FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dev FOREIGN KEY (devolvido_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Faltas vindas da planilha (total por bimestre; disciplina 0 = geral)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faltas_bimestre (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id       INT UNSIGNED NOT NULL,
  ano_letivo     SMALLINT UNSIGNED NOT NULL,
  bimestre       TINYINT UNSIGNED NOT NULL,
  disciplina_id  INT UNSIGNED NOT NULL DEFAULT 0,
  faltas         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_faltas_bim (aluno_id, ano_letivo, bimestre, disciplina_id),
  CONSTRAINT fk_fbim_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Histórico das importações de planilha
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS importacoes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  arquivo     VARCHAR(200) NOT NULL,
  linhas      INT UNSIGNED NOT NULL DEFAULT 0,
  resumo      TEXT NULL,
  usuario_id  INT UNSIGNED NULL,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_imp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Perfis de acesso e permissões ajustáveis
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS perfis (
  chave          VARCHAR(40) NOT NULL,
  nome           VARCHAR(80) NOT NULL,
  descricao      VARCHAR(200) NULL,
  cor            VARCHAR(20) NULL,
  sistema        TINYINT(1) NOT NULL DEFAULT 0,       -- perfis padrão não podem ser apagados
  personalizado  TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = usa as permissões gravadas abaixo
  ordem          SMALLINT NOT NULL DEFAULT 50,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perfil_permissoes (
  perfil     VARCHAR(40) NOT NULL,
  permissao  VARCHAR(60) NOT NULL,
  PRIMARY KEY (perfil, permissao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_permissoes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id   INT UNSIGNED NOT NULL,
  permissao    VARCHAR(60) NOT NULL,
  efeito       ENUM('conceder','remover') NOT NULL,
  validade     DATE NULL,                              -- vazio = permanente
  motivo       VARCHAR(200) NULL,
  criado_por   INT UNSIGNED NULL,
  criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usu_perm (usuario_id, permissao),
  CONSTRAINT fk_uperm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Central de Arquivos e Calendário SIGE (arquivos guardados na hospedagem)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS arquivos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo         VARCHAR(160) NOT NULL,
  descricao      TEXT NULL,
  categoria      VARCHAR(60) NULL,
  especial       VARCHAR(30) NULL,                     -- calendario_sige
  nome_original  VARCHAR(200) NOT NULL,
  caminho        VARCHAR(160) NOT NULL,
  mime           VARCHAR(100) NULL,
  tamanho        INT UNSIGNED NOT NULL DEFAULT 0,
  downloads      INT UNSIGNED NOT NULL DEFAULT 0,
  enviado_por    INT UNSIGNED NULL,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_arq_cat (categoria),
  KEY idx_arq_esp (especial),
  CONSTRAINT fk_arq_usuario FOREIGN KEY (enviado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Configurações gerais (datas dos bimestres etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracoes (
  chave   VARCHAR(60) NOT NULL,
  valor   TEXT NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eventos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  data_inicio  DATE NOT NULL,
  data_fim     DATE NULL,
  titulo       VARCHAR(160) NOT NULL,
  tipo         VARCHAR(30) NOT NULL DEFAULT 'evento',   -- feriado, recesso, reuniao, sabado_letivo, periodo, evento
  publico      TINYINT(1) NOT NULL DEFAULT 1,           -- aparece no calendário do site
  criado_por   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_evento (data_inicio, titulo),
  KEY idx_ev_data (data_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Reserva de ambientes e equipamentos (auditório, projetores...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recursos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(80) NOT NULL,
  tipo        VARCHAR(20) NOT NULL DEFAULT 'equipamento',   -- ambiente ou equipamento
  grupo       VARCHAR(40) NULL,                              -- ex.: Projetor
  descricao   VARCHAR(200) NULL,
  ordem       SMALLINT NOT NULL DEFAULT 50,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_recurso_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recurso_id   INT UNSIGNED NOT NULL,
  data         DATE NOT NULL,
  aula         TINYINT UNSIGNED NOT NULL,                    -- 1ª aula, 2ª aula...
  usuario_id   INT UNSIGNED NULL,
  turma_id     INT UNSIGNED NULL,
  metodologia  VARCHAR(60) NULL,                             -- só para ambientes (palestra, filme...)
  descricao    VARCHAR(255) NULL,
  retirada_em        DATETIME NULL,                          -- equipamento retirado no Centro de Multimeios
  retirada_tipo      VARCHAR(20) NULL,                       -- professor | aluno | outro
  retirada_nome      VARCHAR(120) NULL,
  retirada_obs       VARCHAR(255) NULL,
  retirada_registro  INT UNSIGNED NULL,
  devolvida_em       DATETIME NULL,
  criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reserva (recurso_id, data, aula),            -- impede dois agendamentos no mesmo horário
  KEY idx_reserva_data (data),
  CONSTRAINT fk_rsv_recurso FOREIGN KEY (recurso_id) REFERENCES recursos (id) ON DELETE CASCADE,
  CONSTRAINT fk_rsv_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_rsv_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Horário dos professores (grade semanal)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS horarios (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  dia_semana     TINYINT UNSIGNED NOT NULL,                  -- 1 = segunda ... 5 = sexta
  aula           TINYINT UNSIGNED NOT NULL,
  turma_id       INT UNSIGNED NULL,
  disciplina_id  INT UNSIGNED NULL,
  sala           VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_horario_prof (usuario_id, dia_semana, aula),
  KEY idx_horario_turma (turma_id, dia_semana, aula),
  CONSTRAINT fk_hor_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_hor_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE SET NULL,
  CONSTRAINT fk_hor_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Notificações: avisos da direção, atualizações do sistema e pedidos
-- de liberação de saída enviados pelos professores à coordenação
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificacoes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo       VARCHAR(150) NOT NULL,
  mensagem     TEXT NULL,
  tipo         VARCHAR(20) NOT NULL DEFAULT 'aviso',     -- aviso, sistema, autorizacao, resposta
  destino      VARCHAR(255) NOT NULL DEFAULT 'todos',    -- todos | ,professor,coordenacao, | usuario
  usuario_id   INT UNSIGNED NULL,                        -- destinatário único (destino = usuario)
  link         VARCHAR(255) NULL,
  importante   TINYINT(1) NOT NULL DEFAULT 0,
  autor_id     INT UNSIGNED NULL,
  autor_nome   VARCHAR(120) NULL,
  criado_em    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_notif_usuario (usuario_id),
  KEY idx_notif_data (criado_em),
  CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_autor FOREIGN KEY (autor_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notificacao_lidas (
  notificacao_id  INT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  lida_em         DATETIME NOT NULL,
  PRIMARY KEY (notificacao_id, usuario_id),
  KEY idx_nlida_usuario (usuario_id),
  CONSTRAINT fk_nlida_notif FOREIGN KEY (notificacao_id) REFERENCES notificacoes (id) ON DELETE CASCADE,
  CONSTRAINT fk_nlida_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Busca ativa escolar: cada providência tomada pelo diretor de turma
-- ou pela gestão (ligação, ofício, visita, encaminhamento...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS busca_ativa (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id         INT UNSIGNED NOT NULL,
  data_acao        DATE NOT NULL,
  hora             TIME NULL,
  acao             VARCHAR(30) NOT NULL,              -- ligacao, whatsapp, oficio, visita, reuniao, conversa, conselho, encaminhamento, outro
  contato_nome     VARCHAR(150) NULL,                 -- com quem falou (mãe, pai, responsável...)
  contato_parentesco VARCHAR(40) NULL,
  contato_telefone VARCHAR(30) NULL,
  motivo           VARCHAR(40) NOT NULL DEFAULT 'faltas',  -- faltas, infrequencia, abandono, permanencia, comportamento, outro
  faltas_informadas SMALLINT UNSIGNED NULL,           -- quantas faltas foram comunicadas
  resultado        VARCHAR(30) NOT NULL,              -- contato_feito, nao_atendeu, retorno_combinado, aluno_retornou, sem_retorno, encaminhado, outro
  descricao        TEXT NULL,                         -- o que foi informado e combinado
  documento        VARCHAR(80) NULL,                  -- nº do ofício / protocolo
  retorno_previsto DATE NULL,                         -- data combinada para o aluno voltar ou nova verificação
  registrado_por   INT UNSIGNED NULL,
  registrado_nome  VARCHAR(120) NULL,
  criado_em        DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_busca_aluno (aluno_id, data_acao),
  KEY idx_busca_data (data_acao),
  CONSTRAINT fk_busca_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_busca_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Livros didáticos digitais (manual do professor em PDF)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS livros_didaticos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo         VARCHAR(160) NOT NULL,
  tipo           VARCHAR(20) NOT NULL DEFAULT 'professor',   -- professor (manual do professor), aluno, apoio
  disciplina_id  INT UNSIGNED NULL,
  area           VARCHAR(60) NULL,                           -- quando o livro é de área (ex.: Ciências Humanas)
  serie          TINYINT UNSIGNED NULL,                      -- 1, 2, 3 ou NULL (volume único / todas)
  colecao        VARCHAR(160) NULL,                          -- coleção / editora
  autores        VARCHAR(255) NULL,
  edicao         VARCHAR(40) NULL,                           -- ex.: PNLD 2026
  descricao      TEXT NULL,
  nome_original  VARCHAR(200) NOT NULL,
  caminho        VARCHAR(160) NOT NULL,
  tamanho        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  acessos        INT UNSIGNED NOT NULL DEFAULT 0,
  enviado_por    INT UNSIGNED NULL,
  criado_em      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_livro_disc (disciplina_id, serie),
  CONSTRAINT fk_ldid_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE SET NULL,
  CONSTRAINT fk_ldid_usuario FOREIGN KEY (enviado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Agenda do professor: anotações do dia a dia (uma página por dia, só o dono vê)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agenda_notas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  data           DATE NOT NULL,
  conteudo       MEDIUMTEXT NULL,                    -- texto formatado (negrito, marca-texto...) já limpo
  texto          TEXT NULL,                          -- só o texto, para a busca e a lista
  atualizado_em  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_agenda_dia (usuario_id, data),
  CONSTRAINT fk_agenda_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Chamada por aula (complementa a coleta diária de faltas)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chamadas_aula (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  data           DATE NOT NULL,
  aula           TINYINT UNSIGNED NOT NULL,
  turma_id       INT UNSIGNED NOT NULL,
  disciplina_id  INT UNSIGNED NULL,
  usuario_id     INT UNSIGNED NULL,
  presentes      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ausentes       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  criado_em      DATETIME NOT NULL,
  atualizado_em  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_chamada_aula (data, aula, turma_id),
  KEY idx_chamada_usuario (usuario_id, data),
  CONSTRAINT fk_cha_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE,
  CONSTRAINT fk_cha_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE SET NULL,
  CONSTRAINT fk_cha_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamada_faltas (
  chamada_id  INT UNSIGNED NOT NULL,
  aluno_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (chamada_id, aluno_id),
  KEY idx_chf_aluno (aluno_id),
  CONSTRAINT fk_chf_chamada FOREIGN KEY (chamada_id) REFERENCES chamadas_aula (id) ON DELETE CASCADE,
  CONSTRAINT fk_chf_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Mapa de sala: onde cada aluno senta
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mapas_sala (
  turma_id       INT UNSIGNED NOT NULL,
  filas          TINYINT UNSIGNED NOT NULL DEFAULT 6,
  colunas        TINYINT UNSIGNED NOT NULL DEFAULT 7,
  lugares        TEXT NULL,                 -- JSON {"fila-coluna": aluno_id}
  atualizado_por INT UNSIGNED NULL,
  atualizado_em  DATETIME NULL,
  PRIMARY KEY (turma_id),
  CONSTRAINT fk_mapa_turma FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Observações sobre o aluno (sem peso disciplinar)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluno_observacoes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id     INT UNSIGNED NOT NULL,
  tipo         VARCHAR(12) NOT NULL DEFAULT 'neutra',   -- positiva, atencao, neutra
  texto        VARCHAR(600) NOT NULL,
  usuario_id   INT UNSIGNED NULL,
  usuario_nome VARCHAR(120) NULL,
  criado_em    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_obs_aluno (aluno_id, criado_em),
  CONSTRAINT fk_obs_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_obs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Lembretes da agenda (avisam no sino na data e hora marcadas)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agenda_lembretes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  quando      DATETIME NOT NULL,
  texto       VARCHAR(200) NOT NULL,
  avisado     TINYINT(1) NOT NULL DEFAULT 0,
  feito       TINYINT(1) NOT NULL DEFAULT 0,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lembrete (usuario_id, avisado, quando),
  CONSTRAINT fk_lemb_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Assistente de IA: registro de uso (cota diária por pessoa)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ia_uso (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  pergunta    VARCHAR(2000) NOT NULL,
  resposta    MEDIUMTEXT NULL,
  situacao    VARCHAR(16) NOT NULL DEFAULT 'ok',   -- ok, bloqueada, erro, sem_chave
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ia_uso (usuario_id, criado_em),
  CONSTRAINT fk_ia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Equipe exibida no site (galeria de gestores e de funcionários)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipe_site (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(120) NOT NULL,
  funcao      VARCHAR(120) NOT NULL,
  grupo       VARCHAR(20) NOT NULL DEFAULT 'professores',   -- gestao, professores, secretaria, multimeios, apoio
  texto       VARCHAR(300) NULL,                            -- frase curta (aparece na galeria de gestores)
  foto        VARCHAR(255) NULL,
  ordem       INT NOT NULL DEFAULT 100,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_equipe (ativo, grupo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Site da escola: álbuns de fotos e informes (publicados pelo painel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_albuns (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo      VARCHAR(140) NOT NULL,
  descricao   VARCHAR(600) NULL,
  data_evento DATE NULL,
  capa_id     INT UNSIGNED NULL,
  publicado   TINYINT(1) NOT NULL DEFAULT 1,
  criado_por  INT UNSIGNED NULL,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_album (publicado, data_evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_fotos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  album_id    INT UNSIGNED NOT NULL,
  arquivo     VARCHAR(255) NOT NULL,
  largura     SMALLINT UNSIGNED NULL,
  altura      SMALLINT UNSIGNED NULL,
  legenda     VARCHAR(200) NULL,
  ordem       INT NOT NULL DEFAULT 0,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_foto_album (album_id, ordem),
  CONSTRAINT fk_foto_album FOREIGN KEY (album_id) REFERENCES site_albuns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_informes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo      VARCHAR(160) NOT NULL,
  subtitulo   VARCHAR(255) NULL,
  texto       MEDIUMTEXT NOT NULL,
  imagem      VARCHAR(255) NULL,
  imagem_foco VARCHAR(20) NULL,                         -- enquadramento da capa (ex.: "50% 30%")
  categoria   VARCHAR(20) NOT NULL DEFAULT 'aviso',     -- aviso, evento, conquista, matricula
  fixado      TINYINT(1) NOT NULL DEFAULT 0,
  publicado   TINYINT(1) NOT NULL DEFAULT 1,
  data_publicacao DATE NOT NULL,
  data_evento DATE NULL,                                -- dia do evento (aparece no calendário do site)
  criado_por  INT UNSIGNED NULL,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_informe (publicado, fixado, data_publicacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v29: banners do carrossel da página inicial
CREATE TABLE IF NOT EXISTS site_banners (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo      VARCHAR(140) NOT NULL,                 -- texto alternativo (leitores de tela) e nome no painel
  imagem      VARCHAR(255) NOT NULL,                 -- arte do celular (1600 × 900)
  imagem_larga VARCHAR(255) NULL,                    -- arte larga do computador (1920 × 400)
  link        VARCHAR(255) NULL,
  ordem       INT NOT NULL DEFAULT 0,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  inicio      DATE NULL,                             -- começa a aparecer em (vazio = já)
  fim         DATE NULL,                             -- sai do ar depois deste dia (vazio = nunca)
  criado_por  INT UNSIGNED NULL,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_banner (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v35: PDFs anexados às notícias (resultados, trabalhos acadêmicos...)
CREATE TABLE IF NOT EXISTS site_informe_anexos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  informe_id  INT UNSIGNED NOT NULL,
  titulo      VARCHAR(160) NOT NULL,
  arquivo     VARCHAR(255) NOT NULL,
  tamanho     INT UNSIGNED NOT NULL DEFAULT 0,
  ordem       INT NOT NULL DEFAULT 0,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_anexo_informe (informe_id, ordem),
  CONSTRAINT fk_anexo_informe FOREIGN KEY (informe_id) REFERENCES site_informes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sofia: conversas salvas (histórico de cada pessoa) – v43
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ia_conversas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  titulo         VARCHAR(160) NOT NULL,
  mensagens      MEDIUMTEXT NOT NULL,
  criado_em      DATETIME NOT NULL,
  atualizado_em  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_iaconv_usuario (usuario_id, atualizado_em),
  CONSTRAINT fk_iaconv_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- v54: arquivos públicos do site (regimento, matriz curricular…)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_arquivos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo         VARCHAR(160) NOT NULL,
  descricao      VARCHAR(255) NULL,
  categoria      VARCHAR(60) NOT NULL DEFAULT 'Documentos da escola',
  arquivo        VARCHAR(190) NOT NULL,
  extensao       VARCHAR(10) NOT NULL,
  tamanho        INT UNSIGNED NOT NULL DEFAULT 0,
  ordem          INT NOT NULL DEFAULT 0,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  criado_por     INT UNSIGNED NULL,
  criado_em      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sarq_cat (categoria, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- v59 · Nota conceitual: 3 aspectos por aluno, disciplina e bimestre (somam até 10)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nota_conceitual (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aluno_id       INT UNSIGNED NOT NULL,
  turma_id       INT UNSIGNED NOT NULL,
  disciplina_id  INT UNSIGNED NOT NULL,
  ano_letivo     SMALLINT UNSIGNED NOT NULL,
  bimestre       TINYINT UNSIGNED NOT NULL,
  c1             DECIMAL(4,2) NOT NULL,
  c2             DECIMAL(4,2) NOT NULL,
  c3             DECIMAL(4,2) NOT NULL,
  total          DECIMAL(4,2) NOT NULL,
  lancado_por    INT UNSIGNED NULL,
  atualizado_em  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nc (aluno_id, disciplina_id, ano_letivo, bimestre),
  KEY idx_nc_turma (turma_id, ano_letivo, bimestre),
  CONSTRAINT fk_nc_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_nc_disc FOREIGN KEY (disciplina_id) REFERENCES disciplinas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- v59 · Ambientes da escola (página de cada ambiente no site)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_ambientes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo      VARCHAR(120) NOT NULL,
  icone       VARCHAR(40) NOT NULL DEFAULT 'building',
  resumo      VARCHAR(255) NULL,
  texto       MEDIUMTEXT NULL,
  estuda      TEXT NULL,
  fotos       TEXT NULL,
  ordem       INT NOT NULL DEFAULT 100,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  criado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_amb (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  DADOS INICIAIS: cursos, turmas, disciplinas, usuário administrador
--  e migração dos alunos/faltas do sistema antigo (sistema_faltas)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT IGNORE INTO cursos (id, nome, sigla) VALUES
(1, 'Informática', 'INF'),
(2, 'Desenvolvimento de Sistemas', 'DS'),
(3, 'Enfermagem', 'ENF'),
(4, 'Administração', 'ADM');

INSERT IGNORE INTO turmas (id, curso_id, serie, letra, ano_letivo, turno) VALUES
(1, 1, 1, 'D', 2026, 'Integral'),
(2, 1, 2, 'D', 2026, 'Integral'),
(3, 1, 3, 'D', 2026, 'Integral'),
(4, 2, 1, 'B', 2026, 'Integral'),
(5, 2, 2, 'B', 2026, 'Integral'),
(6, 2, 3, 'B', 2026, 'Integral'),
(7, 3, 1, 'C', 2026, 'Integral'),
(8, 3, 2, 'C', 2026, 'Integral'),
(9, 3, 3, 'C', 2026, 'Integral'),
(10, 4, 1, 'A', 2026, 'Integral'),
(11, 4, 2, 'A', 2026, 'Integral'),
(12, 4, 3, 'A', 2026, 'Integral');

INSERT IGNORE INTO disciplinas (id, nome, tipo, curso_id) VALUES
(1, 'Língua Portuguesa', 'base_comum', NULL),
(2, 'Matemática', 'base_comum', NULL),
(3, 'Física', 'base_comum', NULL),
(4, 'Química', 'base_comum', NULL),
(5, 'Biologia', 'base_comum', NULL),
(6, 'História', 'base_comum', NULL),
(7, 'Geografia', 'base_comum', NULL),
(8, 'Filosofia', 'base_comum', NULL),
(9, 'Sociologia', 'base_comum', NULL),
(10, 'Língua Inglesa', 'base_comum', NULL),
(11, 'Língua Espanhola', 'base_comum', NULL),
(12, 'Educação Física', 'base_comum', NULL),
(13, 'Arte', 'base_comum', NULL),
(14, 'Lógica de Programação', 'tecnica', 1),
(15, 'Arquitetura e Manutenção de Computadores', 'tecnica', 1),
(16, 'Redes de Computadores', 'tecnica', 1),
(17, 'Sistemas Operacionais', 'tecnica', 1),
(18, 'Banco de Dados', 'tecnica', 1),
(19, 'Lógica de Programação', 'tecnica', 2),
(20, 'Programação Web', 'tecnica', 2),
(21, 'Banco de Dados', 'tecnica', 2),
(22, 'Programação Orientada a Objetos', 'tecnica', 2),
(23, 'Engenharia de Software', 'tecnica', 2),
(24, 'Anatomia e Fisiologia Humana', 'tecnica', 3),
(25, 'Fundamentos de Enfermagem', 'tecnica', 3),
(26, 'Saúde Coletiva', 'tecnica', 3),
(27, 'Primeiros Socorros', 'tecnica', 3),
(28, 'Farmacologia', 'tecnica', 3),
(29, 'Teoria Geral da Administração', 'tecnica', 4),
(30, 'Contabilidade Geral', 'tecnica', 4),
(31, 'Gestão de Pessoas', 'tecnica', 4),
(32, 'Marketing', 'tecnica', 4),
(33, 'Matemática Financeira', 'tecnica', 4);

-- Perfis de acesso (as permissões de cada um são ajustadas em Usuários › Perfis e permissões)
INSERT IGNORE INTO perfis (chave, nome, descricao, cor, sistema, personalizado, ordem) VALUES
('admin', 'Administrador', 'Acesso total. Gerencia usuários e permissões.', 'escura', 1, 0, 1),
('diretor', 'Diretor(a)', 'Gestão escolar: acompanha tudo, menos usuários.', 'verde', 1, 0, 2),
('coordenacao', 'Coordenação', 'Coordenação pedagógica e escolar.', 'azul', 1, 0, 3),
('secretaria', 'Secretaria', 'Matrículas, documentos e atendimento.', 'ambar', 1, 0, 4),
('professor', 'Professor(a)', 'Faltas, notas, provas e ocorrências das suas turmas.', 'cinza', 1, 0, 5),
('bibliotecario', 'Professor(a) bibliotecário(a)', 'Tudo do professor + Centro de Multimeios e Apresentação.', 'azul', 1, 0, 6),
('financeiro', 'Assistente financeiro', 'Consulta de relatórios e indicadores.', 'roxo', 0, 0, 7);

-- Calendário letivo 2026 (SIGE): períodos, feriados, recessos, reuniões e sábados letivos
INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('bimestre_2026_1_inicio', '2026-02-02'),
('bimestre_2026_1_fim', '2026-04-17'),
('bimestre_2026_2_inicio', '2026-04-22'),
('bimestre_2026_2_fim', '2026-06-30'),
('bimestre_2026_3_inicio', '2026-08-03'),
('bimestre_2026_3_fim', '2026-10-09'),
('bimestre_2026_4_inicio', '2026-10-13'),
('bimestre_2026_4_fim', '2026-12-30');
INSERT IGNORE INTO eventos (data_inicio, data_fim, titulo, tipo) VALUES
('2026-02-02', '2026-02-03', 'Encontro pedagógico', 'reuniao'),
('2026-02-02', NULL, 'Início do ano letivo · 1º período', 'periodo'),
('2026-02-16', NULL, 'Recesso', 'recesso'),
('2026-02-17', NULL, 'Carnaval', 'feriado'),
('2026-02-18', NULL, 'Quarta-feira de Cinzas', 'recesso'),
('2026-03-07', NULL, 'Sábado letivo · Interclasse', 'sabado_letivo'),
('2026-03-19', NULL, 'São José', 'feriado'),
('2026-03-20', NULL, 'Recesso', 'recesso'),
('2026-03-25', NULL, 'Data Magna do Ceará', 'feriado'),
('2026-04-02', NULL, 'Recesso', 'recesso'),
('2026-04-03', NULL, 'Paixão de Cristo', 'feriado'),
('2026-04-17', NULL, 'Fim do 1º período', 'periodo'),
('2026-04-20', NULL, 'Recesso', 'recesso'),
('2026-04-21', NULL, 'Tiradentes', 'feriado'),
('2026-04-22', NULL, 'Início do 2º período', 'periodo'),
('2026-05-01', NULL, 'Dia do Trabalho', 'feriado'),
('2026-05-04', '2026-05-08', 'Reuniões bimestrais PPDT · 1º período', 'reuniao'),
('2026-05-16', NULL, 'Sábado letivo · Dia das Mães', 'sabado_letivo'),
('2026-06-04', NULL, 'Corpus Christi', 'feriado'),
('2026-06-05', NULL, 'Recesso', 'recesso'),
('2026-06-13', NULL, 'Sábado letivo · Festa junina', 'sabado_letivo'),
('2026-06-30', NULL, 'Fim do 2º período', 'periodo'),
('2026-07-01', '2026-07-30', 'Férias', 'recesso'),
('2026-08-03', NULL, 'Início do 3º período', 'periodo'),
('2026-08-03', '2026-08-07', 'Reuniões bimestrais PPDT · 2º período', 'reuniao'),
('2026-08-08', NULL, 'Sábado letivo · Dia dos Pais', 'sabado_letivo'),
('2026-09-05', NULL, 'Sábado letivo · Aulões ENEM', 'sabado_letivo'),
('2026-09-07', NULL, 'Independência do Brasil', 'feriado'),
('2026-10-03', NULL, 'Sábado letivo · Aulões ENEM', 'sabado_letivo'),
('2026-10-09', NULL, 'Fim do 3º período', 'periodo'),
('2026-10-12', NULL, 'Nossa Senhora Aparecida', 'feriado'),
('2026-10-13', NULL, 'Início do 4º período', 'periodo'),
('2026-10-15', NULL, 'Dia do Professor', 'feriado'),
('2026-10-16', NULL, 'Recesso', 'recesso'),
('2026-10-19', '2026-10-23', 'Reuniões bimestrais PPDT · 3º período', 'reuniao'),
('2026-10-24', NULL, 'Sábado letivo · Aulões ENEM', 'sabado_letivo'),
('2026-10-28', NULL, 'Dia do Servidor Público', 'feriado'),
('2026-11-02', NULL, 'Finados', 'feriado'),
('2026-11-07', NULL, 'Sábado letivo', 'sabado_letivo'),
('2026-11-15', NULL, 'Proclamação da República', 'feriado'),
('2026-11-20', NULL, 'Dia da Consciência Negra', 'feriado'),
('2026-11-21', NULL, 'Dia do Município', 'feriado'),
('2026-12-14', '2026-12-18', 'Reuniões bimestrais PPDT · 4º período', 'reuniao'),
('2026-12-19', NULL, 'Sábado letivo · Formatura / término de curso', 'sabado_letivo'),
('2026-12-23', NULL, 'Padroeira de Boa Viagem', 'feriado'),
('2026-12-24', NULL, 'Recesso natalino', 'recesso'),
('2026-12-25', NULL, 'Natal', 'feriado'),
('2026-12-30', NULL, 'Fim do 4º período', 'periodo'),
('2027-01-04', '2027-01-15', 'Recuperação final · 5º período', 'periodo');
INSERT IGNORE INTO arquivos (id, titulo, descricao, categoria, especial, nome_original, caminho, mime, tamanho) VALUES
(1, 'Calendário letivo 2026 · SIGE', 'Calendário anual da escola com períodos, feriados, recessos, sábados letivos e reuniões bimestrais.', 'Calendário', 'calendario_sige', 'Calendario_SIGE_2026.pdf', 'arquivos/2026/calendario_sige_2026.pdf', 'application/pdf', 74504);

-- Ambientes e equipamentos que podem ser reservados (ajustáveis em Reservas › Ambientes e equipamentos)
INSERT IGNORE INTO recursos (nome, tipo, grupo, descricao, ordem) VALUES
('Auditório', 'ambiente', 'Ambientes', 'Palestras, apresentações e eventos', 1),
('Laboratório de Informática 1', 'ambiente', 'Ambientes', NULL, 2),
('Laboratório de Informática 2', 'ambiente', 'Ambientes', NULL, 3),
('Laboratório de Enfermagem', 'ambiente', 'Ambientes', NULL, 4),
('Laboratório de Ciências', 'ambiente', 'Ambientes', NULL, 5),
('Centro de Multimeios', 'ambiente', 'Ambientes', 'Sala de leitura e estudo', 6),
('Quadra poliesportiva', 'ambiente', 'Ambientes', NULL, 7),
('Projetor 1', 'equipamento', 'Projetores', NULL, 11),
('Projetor 2', 'equipamento', 'Projetores', NULL, 12),
('Projetor 3', 'equipamento', 'Projetores', NULL, 13),
('Projetor 4', 'equipamento', 'Projetores', NULL, 14),
('Projetor 5', 'equipamento', 'Projetores', NULL, 15),
('Projetor 6', 'equipamento', 'Projetores', NULL, 16),
('Projetor 7', 'equipamento', 'Projetores', NULL, 17),
('Projetor 8', 'equipamento', 'Projetores', NULL, 18),
('Projetor 9', 'equipamento', 'Projetores', NULL, 19),
('Projetor 10', 'equipamento', 'Projetores', NULL, 20),
('Caixa de som portátil', 'equipamento', 'Som', NULL, 30),
('Microfone sem fio', 'equipamento', 'Som', NULL, 31),
('Notebook da coordenação', 'equipamento', 'Computadores', NULL, 40);

-- Usuário inicial. E-mail: admin@escola.com  Senha provisória: Escola@2026
-- O sistema obriga a troca da senha no primeiro acesso.

INSERT IGNORE INTO usuarios (id, nome, email, senha_hash, perfil, cargo, ativo, trocar_senha) VALUES
(1, 'Administrador', 'admin@escola.com', '$2y$10$mB04gb8ttcDtd3TN3yvIeeY3P4/LhZiBOTzsQJ1.U7l6Qok5IrnAG', 'admin', 'Administrador do sistema', 1, 1);

-- 513 alunos migrados do sistema antigo
INSERT IGNORE INTO alunos (id, nome, turma_id, situacao, criado_em) VALUES
(1, 'ANTONIO DANILO MARQUES DINIZ FRANCA', 6, 'ativo', '2026-08-19 20:57:35'),
(2, 'CAUA FACUNDO PAIVA', 6, 'ativo', '2026-08-19 20:59:25'),
(3, 'CAUAN MARREIRO ABREU', 6, 'ativo', '2026-08-19 20:59:40'),
(4, 'DEBORAH DINIZ RIBEIRO', 6, 'ativo', '2026-08-19 21:06:11'),
(5, 'EVELIN LEITAO DE ALMEIDA', 6, 'ativo', '2026-08-19 21:06:25'),
(6, 'EVERSON ARAUJO DE LIMA', 6, 'ativo', '2026-08-19 21:06:43'),
(7, 'GABRIELLY FREIRE DA SILVA', 6, 'ativo', '2026-08-19 21:07:47'),
(8, 'GUILHERME COSTA VIEIRA', 6, 'ativo', '2026-08-19 21:08:23'),
(9, 'HALLEY NOGUEIRA LIMA', 6, 'ativo', '2026-08-19 21:08:55'),
(10, 'ISABELE MARIA FEITOSA TEIXEIRA', 6, 'ativo', '2026-08-19 21:09:10'),
(11, 'IURE ALEXANDRE SOUSA LOPES', 6, 'ativo', '2026-08-19 21:09:32'),
(12, 'JEFERSON OLIVEIRA RODRIGUES', 6, 'ativo', '2026-08-19 21:09:47'),
(13, 'JEFERSON PAIVA DA SILVA', 6, 'ativo', '2026-08-19 21:10:07'),
(14, 'JOAO GABRIEL ELOI BALBINO', 6, 'ativo', '2026-08-19 21:10:40'),
(15, 'JOAO VITOR DE MORAIS DE SOUSA', 6, 'ativo', '2026-08-19 21:44:20'),
(16, 'JORGE ITALO SOUSA DA SILVA', 6, 'ativo', '2026-08-19 21:44:39'),
(17, 'KAUA DE SOUSA ROCHA', 6, 'ativo', '2026-08-19 21:44:49'),
(18, 'KAUAN LOBO FONSECA', 6, 'ativo', '2026-08-19 21:44:59'),
(19, 'LARA MONIQUE MARTINS SALES', 6, 'ativo', '2026-08-19 21:45:09'),
(20, 'LAURA FERREIRA CARNEIRO', 6, 'ativo', '2026-08-19 21:46:42'),
(21, 'LIANY OLIVEIRA MOREIRA', 6, 'ativo', '2026-08-19 21:46:54'),
(22, 'MARIA GEOVANA DA LUZ DAMASCENO', 6, 'ativo', '2026-08-19 21:47:04'),
(23, 'MARIA NATIELE ALVES SILVA', 6, 'ativo', '2026-08-19 21:47:14'),
(24, 'PEDRO HENRIQUE FERREIRA DA SILVA', 6, 'ativo', '2026-08-19 21:47:25'),
(25, 'PEDRO LUCAS PINHO GERMANO', 6, 'ativo', '2026-08-19 21:48:10'),
(26, 'RAISSA DE PAULA BESERRA', 6, 'ativo', '2026-08-20 12:24:56'),
(27, 'RENAN DE SOUSA FERREIRA', 6, 'ativo', '2026-08-20 12:25:04'),
(28, 'RUAN BARBOSA VIEIRA', 6, 'ativo', '2026-08-20 12:25:13'),
(29, 'SAMANTHA LIMA RODRIGUES', 6, 'ativo', '2026-08-20 12:25:49'),
(30, 'SAMUEL SOUSA DE OLIVEIRA', 6, 'ativo', '2026-08-20 12:25:57'),
(31, 'SARA DE LIMA TEODORO', 6, 'ativo', '2026-08-20 12:26:09'),
(32, 'VALBER RODRIGUES ALVES', 6, 'ativo', '2026-08-20 12:26:16'),
(33, 'VICTOR PEREIRA DA SILVA SOUSA', 6, 'ativo', '2026-08-20 12:26:25'),
(34, 'VINICIUS FRAGOSO TORRES', 6, 'ativo', '2026-08-20 12:26:33'),
(35, 'YANNE MARIA SAMPAIO FARIAS', 6, 'ativo', '2026-08-20 12:26:40'),
(36, 'ZELIA MARIA ARAUJO FACUNDO', 6, 'ativo', '2026-08-20 12:26:51'),
(37, 'ANA BEATRIZ ALVES XAVIER', 3, 'ativo', '2026-08-20 12:30:25'),
(38, 'ANA LARA PAIVA FERREIRA', 3, 'ativo', '2026-08-20 12:31:34'),
(39, 'ANTONIO FABRICIO DOS SANTOS DA SILVA', 3, 'ativo', '2026-08-20 12:31:42'),
(40, 'ANTONIO PIETRO DA SILVA MAGALHAES', 3, 'ativo', '2026-08-20 12:31:49'),
(41, 'BIANCA DE SOUSA MACENA', 3, 'ativo', '2026-08-20 12:32:34'),
(42, 'CAUA DA SILVA SANTOS', 3, 'ativo', '2026-08-20 12:32:43'),
(43, 'CAUA RODRIGUES FERREIRA', 3, 'ativo', '2026-08-20 12:32:53'),
(44, 'CLARA FERREIRA DA SILVA', 3, 'ativo', '2026-08-20 12:33:05'),
(45, 'ELIANA LOPES LIMA', 3, 'ativo', '2026-08-20 12:33:15'),
(46, 'ELISSON GARCIA DOS SANTOS', 3, 'ativo', '2026-08-20 12:33:24'),
(47, 'ENZO FRANCO FACUNDO', 3, 'ativo', '2026-08-20 12:33:33'),
(48, 'ERIQUE RENAN RODRIGUES GARCIA', 3, 'ativo', '2026-08-20 12:33:41'),
(49, 'FRANCISCO RAY ALVES MARIANO', 3, 'ativo', '2026-08-20 12:33:50'),
(50, 'FRANCISCO RONIELE RODRIGUES DOS SANTOS', 3, 'ativo', '2026-08-20 12:33:59'),
(51, 'GUILHERME NASCIMENTO BEZERRA', 3, 'ativo', '2026-08-20 12:34:08'),
(52, 'JACKSON DE LIMA ALVES', 3, 'ativo', '2026-08-20 12:34:41'),
(53, 'JOAO MARCOS ALVES DE OLIVEIRA', 3, 'ativo', '2026-08-20 12:34:50'),
(54, 'JOAO PEDRO DE SOUSA FERREIRA', 3, 'ativo', '2026-08-20 12:34:59'),
(55, 'JOAO VICTOR LIMA FERREIRA', 3, 'ativo', '2026-08-20 12:35:05'),
(56, 'JOAO VITOR OLIVEIRA DE LIMA', 3, 'ativo', '2026-08-20 12:35:14'),
(57, 'JORGE ALEX ALVES DA NOBREGA', 3, 'ativo', '2026-08-20 12:35:22'),
(58, 'LARA LOIS DA SILVA FERREIRA', 3, 'ativo', '2026-08-20 12:35:30'),
(59, 'LEVI RODRIGUES DOS SANTOS', 3, 'ativo', '2026-08-20 12:35:39'),
(60, 'LUANA AURELIANO DE SOUSA', 3, 'ativo', '2026-08-20 12:35:47'),
(61, 'LUIZ FERNANDO FERREIRA MOTA', 3, 'ativo', '2026-08-20 12:35:55'),
(62, 'MARIA EDUARDA FERREIRA LOPES', 3, 'ativo', '2026-08-20 12:36:03'),
(63, 'MATUSALEM CESAR PINHO QUEIROZ', 3, 'ativo', '2026-08-20 12:36:11'),
(64, 'MAVIGANIER DE FREITAS FONTES', 3, 'ativo', '2026-08-20 12:36:19'),
(65, 'NAIRA COSTA FERREIRA', 3, 'ativo', '2026-08-20 12:36:27'),
(66, 'PAULO VICTOR MARTINS DE MELO', 3, 'ativo', '2026-08-20 12:36:35'),
(67, 'RAKELLY RODRIGUES OLIVEIRA', 3, 'ativo', '2026-08-20 12:36:44'),
(68, 'RAYNA EVILY DE ALMEIDA CAMPOS', 3, 'ativo', '2026-08-20 12:36:51'),
(69, 'RENATO PEREIRA MORAIS', 3, 'ativo', '2026-08-20 12:37:00'),
(70, 'RIAN GONCALO ALVES MATOS', 3, 'ativo', '2026-08-20 12:37:07'),
(71, 'RODRIGO ALEXANDRE DA SILVA', 3, 'ativo', '2026-08-20 12:37:15'),
(72, 'ROSA MARIA DE MELO CARVALHO', 3, 'ativo', '2026-08-20 12:37:23'),
(73, 'RUAN COSTA DE SOUSA', 3, 'ativo', '2026-08-20 12:37:30'),
(74, 'SAMUEL DA SILVA SOARES', 3, 'ativo', '2026-08-20 12:37:39'),
(75, 'SAMUEL VENANCIO FACUNDO', 3, 'ativo', '2026-08-20 12:37:48'),
(76, 'SAUL VITOR DE SOUZA PINTO', 3, 'ativo', '2026-08-20 12:37:57'),
(77, 'VINICIUS SANTOS DE MELO', 3, 'ativo', '2026-08-20 12:38:06'),
(78, 'VITORIA AMARO DE ARAUJO', 3, 'ativo', '2026-08-20 12:38:13'),
(79, 'VITORIA NUNES VIEIRA', 3, 'ativo', '2026-08-20 12:38:21'),
(80, 'MATHEUS GUTIERRE LIMA BEZERRA', 3, 'ativo', '2026-08-20 12:38:29'),
(83, 'ANTONIO MENDES DE ARAUJO FILHO', 1, 'ativo', '2026-08-20 14:21:08'),
(85, 'BRENO NASCIMENTO BEZERRA', 1, 'ativo', '2026-08-20 14:21:27'),
(86, 'BRENO VIANA PEREIRA', 1, 'ativo', '2026-08-20 14:21:37'),
(88, 'CARLOS DANIEL FERREIRA DE ARAGAO', 1, 'ativo', '2026-08-20 14:21:57'),
(102, 'ISABELA GOUVEIA DA SILVA', 1, 'ativo', '2026-08-20 14:28:16'),
(105, 'JEFERSON FERREIRA DA SILVA', 1, 'ativo', '2026-08-20 14:28:54'),
(106, 'JOAO MARCOS ALVES BEZERRA', 1, 'ativo', '2026-08-20 14:29:05'),
(107, 'JOAO PEDRO LIMA SOUZA', 1, 'ativo', '2026-08-20 14:29:16'),
(108, 'JOAQUIM WALYSSON BRITO DE ALMEIDA', 1, 'ativo', '2026-08-20 14:29:25'),
(109, 'JULIA BEZERRA DE MORAES', 1, 'ativo', '2026-08-20 14:29:35'),
(113, 'MARIA CLARA MONTEIRO DE OLIVEIRA', 1, 'ativo', '2026-08-20 14:30:14'),
(115, 'MARINA ALVES RODRIGUES', 1, 'ativo', '2026-08-20 14:30:37'),
(117, 'MIKAELLY FARIAS DE SOUZA', 1, 'ativo', '2026-08-20 14:30:58'),
(118, 'NARA KELY NUNES DE SOUSA', 1, 'ativo', '2026-08-20 14:31:09'),
(119, 'PABLO HENRIQUE PEREIRA DO NASCIMENTO', 1, 'ativo', '2026-08-20 14:31:19'),
(120, 'RAQUELE LOPES DA SILVA', 1, 'ativo', '2026-08-20 14:31:32'),
(121, 'RUAN CARLOS CARVALHO DE MATOS', 1, 'ativo', '2026-08-20 14:31:41'),
(123, 'THAYLA VITORIA SOARES DA SILVA', 1, 'ativo', '2026-08-20 14:32:03'),
(124, 'VICTOR EMANOEL SARAIVA VIEIRA', 1, 'ativo', '2026-08-20 14:32:12'),
(129, 'YAN LUCAS MOTA NASCIMENTO', 1, 'ativo', '2026-08-20 14:33:05'),
(130, 'ALICE PEREIRA COSTA', 10, 'ativo', '2026-09-02 13:07:48'),
(131, 'ALEX MESQUITA MOURA', 10, 'ativo', '2026-09-02 13:08:00'),
(132, 'ANDRESSA DE SOUZA FERNANDES', 10, 'ativo', '2026-09-02 13:08:14'),
(134, 'BEATRIZ ALVES GUIMARÃES', 10, 'ativo', '2026-09-02 13:08:48'),
(135, 'CALEBE CAVALCANTE FRAGOSO', 10, 'ativo', '2026-09-02 13:09:14'),
(136, 'CRISTIANO DA CONCEICAO DE OLIVEIRA', 10, 'ativo', '2026-09-02 13:09:24'),
(137, 'CLARISSE CANDIDO DA SILVA', 10, 'ativo', '2026-09-02 13:09:38'),
(138, 'DIOGO PEREIRA DE OLIVEIRA', 10, 'ativo', '2026-09-02 13:11:27'),
(139, 'EMANUELE DA SILVA ROCHA', 10, 'ativo', '2026-09-02 13:11:37'),
(140, 'EMANUEL VIEIRA RODRIGUES', 10, 'ativo', '2026-09-02 13:11:48'),
(141, 'ELEN VIEIRA DE SOUSA', 10, 'ativo', '2026-09-02 13:12:01'),
(142, 'EVELIN GOMES SOARES', 10, 'ativo', '2026-09-02 13:12:12'),
(143, 'FRANCISCO EDUARDO DE LIMA FERREIRA', 10, 'ativo', '2026-09-02 13:12:42'),
(144, 'GALBA DE SOUSA SILVA DE OLIVEIRA', 10, 'ativo', '2026-09-02 13:12:54'),
(145, 'GABRIELE DO NASCIMENTO RODRIGUES', 10, 'ativo', '2026-09-02 13:13:07'),
(146, 'GUILHERME FERREIRA DA SILVA', 10, 'ativo', '2026-09-02 13:13:23'),
(147, 'KEVILY SAUANE RAMOS GOMES', 10, 'ativo', '2026-09-02 13:13:35'),
(148, 'ISRAEL DA COSTA ARAUJO', 10, 'ativo', '2026-09-02 13:13:45'),
(149, 'IARLISSON SILVA COSTA', 10, 'ativo', '2026-09-02 13:14:22'),
(150, 'ISABELI SOUZA DE OLIVEIRA', 10, 'ativo', '2026-09-02 13:14:33'),
(151, 'IVO EMANUEL ALVES DE SOUZA', 10, 'ativo', '2026-09-02 13:14:44'),
(152, 'LORRANA SABRINA DA SILVA ALVES', 10, 'ativo', '2026-09-02 13:14:55'),
(153, 'LUANA PEREIRA DE SOUZA', 10, 'ativo', '2026-09-02 13:15:05'),
(154, 'LUNA LUCIANO DA SILVA', 10, 'ativo', '2026-09-02 13:15:15'),
(155, 'LUISA MARIA DO NASCIMENTO DE SOUSA', 10, 'ativo', '2026-09-02 13:15:49'),
(156, 'MARIA CLARA ALVES BEZERRA', 10, 'ativo', '2026-09-02 13:15:58'),
(157, 'MARIA CLARA UCHOA CAVALCANTE', 10, 'ativo', '2026-09-02 13:16:12'),
(158, 'MARIA CELINA CAVALCANTE NUNES', 10, 'ativo', '2026-09-02 13:16:26'),
(159, 'MARIA EDUARDA PEREIRA DA SILVA', 10, 'ativo', '2026-09-02 13:16:37'),
(160, 'MARIA EDUARDA DE SOUSA VIEIRA', 10, 'ativo', '2026-09-02 13:16:54'),
(161, 'MARIA HELOISA APOLONIO DO NASCIMENTO', 10, 'ativo', '2026-09-02 13:17:05'),
(162, 'MARIA GABRIELLY DE OLIVEIRA DA SILVA', 10, 'ativo', '2026-09-02 13:17:21'),
(163, 'MANUELLA PONTES CARVALHO', 10, 'ativo', '2026-09-02 13:17:31'),
(164, 'MILENA MARQUES SIQUEIRA', 10, 'ativo', '2026-09-02 13:17:43'),
(165, 'NICOLAS BATISTA RODRIGUES', 10, 'ativo', '2026-09-02 13:17:54'),
(166, 'PIETRO FACUNDO CAVALCANTE', 10, 'ativo', '2026-09-02 13:18:05'),
(167, 'PEDRO LUCAS DE SOUSA GONCALVES', 10, 'ativo', '2026-09-02 13:18:15'),
(168, 'RENAN DANTAS MARINHO', 10, 'ativo', '2026-09-02 13:18:26'),
(169, 'REBECA NASCIMENTO DA SILVA', 10, 'ativo', '2026-09-02 13:18:36'),
(170, 'SAMARA FERREIRA BARBOSA', 10, 'ativo', '2026-09-02 13:18:47'),
(171, 'TERTULIANO LOPES DOS SANTOS NETO', 10, 'ativo', '2026-09-02 13:18:56'),
(172, 'WITALO GABRIEL DE SOUSA JALES', 10, 'ativo', '2026-09-02 13:19:06'),
(173, 'YASMIM ALVES CAMPOS', 10, 'ativo', '2026-09-02 13:19:16'),
(174, 'ANA LIVIA SILVA FACUNDO', 4, 'ativo', '2026-09-02 13:21:34'),
(175, 'ANA LARA MACIEL BARROS', 4, 'ativo', '2026-09-02 13:21:45'),
(176, 'ALICE MARIA DA SILVA TORRES', 4, 'ativo', '2026-09-02 13:21:58'),
(177, 'CLARA CANDIDO DA SILVA', 4, 'ativo', '2026-09-02 13:22:11'),
(178, 'DANIELE ALMEIDA ALVES', 4, 'ativo', '2026-09-02 13:22:26'),
(179, 'DANIEL FRAGOSO BARBOSA', 4, 'ativo', '2026-09-02 13:22:40'),
(180, 'DAVI DOS SANTOS BRASIL LEITAO', 4, 'ativo', '2026-09-02 13:22:52'),
(181, 'DEIVID RODRIGUES DE SOUSA', 4, 'ativo', '2026-09-02 13:23:02'),
(182, 'EVELIN SOFIA SILVA ALVES', 4, 'ativo', '2026-09-02 13:23:20'),
(183, 'EMILY LORRANE DE ARAUJO TORRES', 4, 'ativo', '2026-09-02 13:23:30'),
(184, 'ESTELA XAVIER MENDONCA', 4, 'ativo', '2026-09-02 13:23:39'),
(185, 'FRANCISCO EDUARDO RODRIGUES SILVA', 4, 'ativo', '2026-09-02 13:23:59'),
(186, 'GUSTAVO LIMA COSTA', 4, 'ativo', '2026-09-02 13:24:11'),
(187, 'GUSTAVO RODRIGUES CAVALCANTE', 4, 'ativo', '2026-09-02 13:24:35'),
(188, 'GLEDSON DAVI VIEIRA FRANCO', 4, 'ativo', '2026-09-02 13:24:45'),
(189, 'ISABELE DO VALE OLIVEIRA', 4, 'ativo', '2026-09-02 13:24:58'),
(190, 'JANDERSON SOUSA FREITAS', 4, 'ativo', '2026-09-02 13:25:08'),
(191, 'JOAO PEDRO PAIVA DO NASCIMENTO', 4, 'ativo', '2026-09-02 13:25:18'),
(192, 'JOAO VICTOR ROCHA VIEIRA BARBOSA', 4, 'ativo', '2026-09-02 13:25:28'),
(193, 'JOSE HENRIQUE SOARES DO NASCIMENTO', 4, 'ativo', '2026-09-02 13:25:39'),
(194, 'JUAN TEIXEIRA BATISTA', 4, 'ativo', '2026-09-02 13:25:50'),
(195, 'JOAO JARYSSON MARTINS DE MATOS', 4, 'ativo', '2026-09-02 13:26:11'),
(196, 'KAIQUE RODRIGUES DE ALMEIDA', 4, 'ativo', '2026-09-02 13:26:22'),
(197, 'KAEL ALVES ROCHA', 4, 'ativo', '2026-09-02 13:26:32'),
(198, 'KAUANE DA SILV', 4, 'ativo', '2026-09-02 13:26:44'),
(199, 'KELYSSON RUAN VIANA DOS SANTO', 4, 'ativo', '2026-09-02 13:26:54'),
(200, 'KEILA RAISSA UCHOA MARTINS', 4, 'ativo', '2026-09-02 13:27:04'),
(201, 'LUIDY GOMES RIBEIRO', 4, 'ativo', '2026-09-02 13:27:13'),
(202, 'LUIS FILIPE TORRES DE SALES', 4, 'ativo', '2026-09-02 13:27:23'),
(203, 'LUNA VITORIA OLIVEIRA UCHOA', 4, 'ativo', '2026-09-02 13:27:33'),
(204, 'MARIA CLARA LOPES DE OLIVEIRA', 4, 'ativo', '2026-09-02 13:27:42'),
(205, 'MARIA ISABELE DE LIMA LEAL', 4, 'ativo', '2026-09-02 13:28:02'),
(206, 'MYRELA BARBOSA RIBEIRO', 4, 'ativo', '2026-09-02 13:28:12'),
(207, 'MATHEUS ALVES DOS SANTOS', 4, 'ativo', '2026-09-02 13:28:23'),
(208, 'NICOLAS DA SILVA GUERREIRO', 4, 'ativo', '2026-09-02 13:28:32'),
(209, 'NAUANA KELY RODRIGUES SERAFIM', 4, 'ativo', '2026-09-02 13:28:42'),
(210, 'PEDRO ALERRANDRO DOS REIS VITORIANO', 4, 'ativo', '2026-09-02 13:28:52'),
(211, 'PEDRO VICTOR RODRIGUES DE LIMA', 4, 'ativo', '2026-09-02 13:29:02'),
(212, 'RAUAN ALVES LOBO', 4, 'ativo', '2026-09-02 13:29:11'),
(213, 'RAYLA VIEIRA DE ALMEIDA CAVALCANTE', 4, 'ativo', '2026-09-02 13:29:22'),
(214, 'RAYRA PEREIRA DO NASCIMENTO', 4, 'ativo', '2026-09-02 13:35:40'),
(215, 'RYAN TORRES DO NASCIMENTO', 4, 'ativo', '2026-09-02 13:35:50'),
(216, 'VICTOR HUGO SILVA CARDOSO', 4, 'ativo', '2026-09-02 13:36:05'),
(217, 'YASMIM CAVALCANTE MENDES VIEIRA', 4, 'ativo', '2026-09-02 13:36:15'),
(218, 'ANA LAURA FÉLIX ALVES', 7, 'ativo', '2026-09-02 13:36:49'),
(219, 'ANA LIVIA CAVALCANTE MACEDO BEZERRA', 7, 'ativo', '2026-09-02 13:37:07'),
(220, 'ANA KAROLINE VENANCIO COSTA FACUNDO', 7, 'ativo', '2026-09-02 13:37:17'),
(221, 'AVILA VITORIA LOPES RODRIGUES', 7, 'ativo', '2026-09-02 13:37:33'),
(222, 'DEBORA PAZ OLIVEIRA', 7, 'ativo', '2026-09-02 13:37:49'),
(223, 'DAVID SOUSA FERNANDES', 7, 'ativo', '2026-09-02 13:38:02'),
(224, 'ELIHANA VERCOSA DA SILVA', 7, 'ativo', '2026-09-02 13:38:41'),
(225, 'EMILE VITORIA TEXEIRA FRAGOSO', 7, 'ativo', '2026-09-02 13:38:52'),
(226, 'FERNANDO VERCOSA PEREIRA FILHO', 7, 'ativo', '2026-09-02 13:39:02'),
(227, 'FRANCISCO RENAN FACUNDO SANTOS', 7, 'ativo', '2026-09-02 13:39:13'),
(228, 'GIOVANA NICOLE DO VALE MARTINS', 7, 'ativo', '2026-09-02 13:39:23'),
(229, 'GABRIELE FARIAS SILVA', 7, 'ativo', '2026-09-02 13:39:34'),
(230, 'HELLEN ARYANY DIAS SALES', 7, 'ativo', '2026-09-02 13:39:46'),
(231, 'IZABELLI BEZERRA DOS SANTOS', 7, 'ativo', '2026-09-02 13:40:10'),
(232, 'ISABELY VIEIRA MORAIS', 7, 'ativo', '2026-09-02 13:40:20'),
(233, 'JUAN PABLO TORRES AMBROSIO', 7, 'ativo', '2026-09-02 13:40:31'),
(234, 'JHULY SALES DE SOUSA', 7, 'ativo', '2026-09-02 13:40:42'),
(235, 'KALINDA KETLEY DE ALMEIDA LOPES', 7, 'ativo', '2026-09-02 13:40:58'),
(236, 'LAIANE GOMES GONCALVES', 7, 'ativo', '2026-09-02 13:41:12'),
(237, 'LARA ISLA DE LIMA DELFINO', 7, 'ativo', '2026-09-02 13:41:23'),
(238, 'LARA PEREIRA PIRES', 7, 'ativo', '2026-09-02 13:41:43'),
(239, 'LAURA BEATRIZ DE CASTRO LOPES', 4, 'ativo', '2026-09-02 13:42:11'),
(240, 'LETICIA GOMES PEREIRA', 7, 'ativo', '2026-09-02 13:42:27'),
(241, 'LORENA NUNES EVANGELISTA', 7, 'ativo', '2026-09-02 13:42:38'),
(242, 'LARA FABIAN NEVES FERNANDES', 7, 'ativo', '2026-09-02 13:42:58'),
(243, 'LIENNI MENDEZ JIMENEZ', 7, 'ativo', '2026-09-02 13:43:10'),
(244, 'MARIELLE APARECIDA DE SOUSA ALVES', 7, 'ativo', '2026-09-02 13:43:24'),
(245, 'MARCIA ALVES PINTO', 7, 'ativo', '2026-09-02 13:43:34'),
(246, 'MARIA CLARA PEREIRA CAVALCANTE', 7, 'ativo', '2026-09-02 13:43:44'),
(247, 'MARIA GABRIELY PINHEIRO FACUNDO', 7, 'ativo', '2026-09-02 13:43:58'),
(248, 'MARIA CECILIA SOUSA OLIVEIRA', 7, 'ativo', '2026-09-02 13:44:13'),
(249, 'MARIA LAURA SOUSA RODRIGUES', 7, 'ativo', '2026-09-02 13:44:23'),
(250, 'MARIA RITA COELHO DO NASCIMENTO', 7, 'ativo', '2026-09-02 13:44:34'),
(251, 'MARIA EDUARDA FERREIRA VIEIRA', 7, 'ativo', '2026-09-02 13:44:49'),
(252, 'MARIA NICOLE MOTA MENDES', 7, 'ativo', '2026-09-02 13:45:00'),
(253, 'MARIA VITORIA DOS SANTOS VIEIRA', 7, 'ativo', '2026-09-02 13:45:22'),
(254, 'PEDRO HENRIQUE ALBUQUERQUE CARVALHO', 7, 'ativo', '2026-09-02 13:45:32'),
(255, 'PALOMA LEITAO DA SILVA', 7, 'ativo', '2026-09-02 13:45:41'),
(256, 'RAYNARA DA SILVA DE LIMA MAGALHAES', 7, 'ativo', '2026-09-02 13:45:53'),
(257, 'RAYSSA HELEN RODRIGUES PEREIRA', 7, 'ativo', '2026-09-02 13:46:03'),
(258, 'SILVIO RODRIGUES DE ABREU JUNIOR', 7, 'ativo', '2026-09-02 13:46:14'),
(259, 'VITORIA KELLY RODRIGUES MENDES', 7, 'ativo', '2026-09-02 13:46:30'),
(260, 'VANESSA SANTOS XAVIER', 7, 'ativo', '2026-09-02 13:46:39'),
(261, 'YASMIM OLIVEIRA ELOY', 7, 'ativo', '2026-09-02 13:46:51'),
(262, 'YASMIM APARECIDA SANTOS', 7, 'ativo', '2026-09-02 13:47:19'),
(263, 'ANA SOFIA LIMA MOTA', 1, 'ativo', '2026-09-02 13:48:17'),
(264, 'ALEF THIERRY FERREIRA DE SOUSA', 1, 'ativo', '2026-09-02 13:48:28'),
(268, 'BRUNO SAVIO HIPOLITO DOS SANTOS', 1, 'ativo', '2026-09-02 13:49:48'),
(269, 'BARBARA RODRIGUES DA SILVA', 1, 'ativo', '2026-09-02 13:50:22'),
(271, 'CRISTIAN FELIPPE ARAGAO E SOUSA', 1, 'ativo', '2026-09-02 13:51:05'),
(272, 'EUCLIDES RODRIGUES CORRENTEZA DA SILVA', 1, 'ativo', '2026-09-02 13:51:50'),
(273, 'FERNANDA WAMILA LOPES MOREIRA', 1, 'ativo', '2026-09-02 13:51:59'),
(274, 'FRANCISCO YURE DOS SANTOS BEZERRA', 1, 'ativo', '2026-09-02 13:52:24'),
(275, 'GABRIELLY PINHEIRO FERNANDES', 1, 'ativo', '2026-09-02 13:53:27'),
(276, 'GUSTAVO VITORINO DO NASCIMENTO BEZERRA', 1, 'ativo', '2026-09-02 13:53:37'),
(277, 'GISELE VIEIRA DE OLIVEIRA', 1, 'ativo', '2026-09-02 13:53:47'),
(278, 'GISLANE DE LIMA SEVERIANO', 1, 'ativo', '2026-09-02 13:53:56'),
(279, 'HELCIO CAVALCANTE BARBOSA FILHO', 1, 'ativo', '2026-09-02 13:54:07'),
(280, 'IVANILDO VIEIRA MARTINS', 1, 'ativo', '2026-09-02 13:54:24'),
(285, 'JAMILE KELI DE SOUSA DE ARAUJO', 1, 'ativo', '2026-09-02 13:55:20'),
(288, 'KAUÃ PEREIRA CHAVES', 1, 'ativo', '2026-09-02 13:56:01'),
(289, 'KEMILY BARBOSA DE SOUSA', 1, 'ativo', '2026-09-02 13:56:11'),
(290, 'LUAN FIGUEIREDO TELES', 1, 'ativo', '2026-09-02 13:56:26'),
(292, 'MARIA LORRANI FREITAS BARROS', 1, 'ativo', '2026-09-02 13:56:48'),
(296, 'MATEUS HIPOLITO DE SOUZA', 1, 'ativo', '2026-09-02 13:57:33'),
(302, 'VIVIANE COSTA SEVERO', 1, 'ativo', '2026-09-02 13:58:34'),
(304, 'WEVERTON GARCIA JALES', 1, 'ativo', '2026-09-02 13:58:57'),
(305, 'WESLEM RYAN ALVES DA SILVA', 1, 'ativo', '2026-09-02 13:59:20'),
(306, 'WILIAM LOPES VERAS', 1, 'ativo', '2026-09-02 13:59:29'),
(308, 'ALAN RODRIGUES SILVA', 11, 'ativo', '2026-09-02 14:03:54'),
(309, 'ANA CARLA DE ALMEIDA DOS SANTOS', 11, 'ativo', '2026-09-02 14:04:13'),
(310, 'DAFNY RIBEIRO SOUSA', 11, 'ativo', '2026-09-02 14:04:25'),
(311, 'EMILLY VIEIRA DIOGENES', 11, 'ativo', '2026-09-02 14:04:45'),
(312, 'ESTEFANY PESSOA DOS SANTOS', 11, 'ativo', '2026-09-02 14:04:58'),
(313, 'FRANCISCA MAISA FERNANDES RODRIGUES', 11, 'ativo', '2026-09-02 14:05:08'),
(314, 'FRANCISCA MAYSA FERREIRA RAQUELA', 11, 'ativo', '2026-09-02 14:05:27'),
(315, 'GISELE DAFNE ARAUJO DE SOUSA', 11, 'ativo', '2026-09-02 14:08:12'),
(316, 'HANA LAYSE ARAÚJO FERREIRA', 11, 'ativo', '2026-09-02 14:08:31'),
(317, 'HOSANO PEREIRA UCHÔA NETO', 11, 'ativo', '2026-09-02 14:08:44'),
(318, 'IOHARA CARVALHO GOMES', 11, 'ativo', '2026-09-02 14:08:57'),
(319, 'INGRID LOHANNA DE SOUSA LIMA', 11, 'ativo', '2026-09-02 14:09:12'),
(320, 'ISABELE VIEIRA DOS SANTOS', 11, 'ativo', '2026-09-02 14:09:47'),
(321, 'JASMIM RODRIGUES DE SOUSA', 11, 'ativo', '2026-09-02 14:10:12'),
(322, 'KAUANY DE SOUSA VIEIRA', 11, 'ativo', '2026-09-02 14:10:23'),
(323, 'LAIS FRAGOSO CARNEIRO', 11, 'ativo', '2026-09-02 14:10:42'),
(324, 'LETICIA LIMA DA COSTA', 11, 'ativo', '2026-09-02 14:10:55'),
(325, 'LARA KÉLVIA  MONEIRO SILVA', 11, 'ativo', '2026-09-02 14:11:23'),
(326, 'MÉLANIE JÚLIA VIEIRA GONÇALVES', 11, 'ativo', '2026-09-02 14:11:35'),
(327, 'MARIA CECILIA EVANGELISTA JALES', 11, 'ativo', '2026-09-02 14:11:53'),
(328, 'MARIA EDUARDA DE SOUSA SILVA DE ARAUJO', 11, 'ativo', '2026-09-02 14:12:13'),
(329, 'MARIA EDUARDA MENEZES DA SILVA', 11, 'ativo', '2026-09-02 14:12:23'),
(330, 'MARIA FERNANDA CARNEIRO FEITOSA DOS SANTOS', 11, 'ativo', '2026-09-02 14:12:35'),
(331, 'MARIA LETICIA DE LIMA VIEIRA', 11, 'ativo', '2026-09-02 14:12:46'),
(332, 'MARIA MILENA ALVES DA SILVA', 11, 'ativo', '2026-09-02 14:13:14'),
(333, 'MARIA RITA MARQUES BEZERRA VIEIRA', 11, 'ativo', '2026-09-02 14:13:42'),
(334, 'MARIA ROSA VIEIRA FACUNDO', 11, 'ativo', '2026-09-02 14:13:54'),
(335, 'MARIANA CHAVES LIMA', 11, 'ativo', '2026-09-02 14:14:07'),
(336, 'MARINA PINHO ROCHA', 11, 'ativo', '2026-09-02 14:14:40'),
(337, 'MARCILENE BEZERRA DA SILVA', 11, 'ativo', '2026-09-02 14:14:54'),
(338, 'MICAELE OLIVEIRA DO NASCIMENTO', 11, 'ativo', '2026-09-02 14:15:03'),
(339, 'NICOLY MARIA MENESES COSTA', 11, 'ativo', '2026-09-02 14:15:13'),
(340, 'NATANAEL SOUSA RODRIGUES', 11, 'ativo', '2026-09-02 14:15:23'),
(341, 'NAYARAFERREIRADA SILVA', 11, 'ativo', '2026-09-02 14:15:33'),
(342, 'PAOLA RAIANE VIANA DA CRUZ', 11, 'ativo', '2026-09-02 14:15:45'),
(343, 'PEDRO HENRIQUE BATISTA PARENTE', 11, 'ativo', '2026-09-02 14:15:59'),
(344, 'RAYSSA PAIVA FERREIRA', 11, 'ativo', '2026-09-02 14:16:09'),
(345, 'RIAN RILARI FEITOZA DA SILVA (RIANY)', 11, 'ativo', '2026-09-02 14:16:19'),
(346, 'SAMUEL DOS SANTOS ANDRADE', 11, 'ativo', '2026-09-02 14:16:31'),
(347, 'SUIANE ALVES DE ALBUQUERQUE', 11, 'ativo', '2026-09-02 14:16:42'),
(348, 'VINICIUS SIQUEIRA DE OLIVEIRA', 11, 'ativo', '2026-09-02 14:16:52'),
(349, 'WESLEY KAUAN ALVES SILVA', 11, 'ativo', '2026-09-02 14:17:01'),
(350, 'YURI ALVES BARROS', 11, 'ativo', '2026-09-02 14:17:11'),
(351, 'AMANDA KETELE BARBOSA ALVES', 5, 'ativo', '2026-09-02 14:18:50'),
(352, 'ANA ALÍCIA TORRES ROSARIA', 5, 'ativo', '2026-09-02 14:19:00'),
(353, 'ANA ANGELINA FERREIRA MACHADO', 5, 'ativo', '2026-09-02 14:19:14'),
(354, 'ANTONIO DAVI FERREIRA GADELHA', 5, 'ativo', '2026-09-02 14:19:27'),
(355, 'ANTONIO LUIDY RODRIGUES DA SILVA', 5, 'ativo', '2026-09-02 14:20:32'),
(356, 'ANTONIO SERAFIM NETO', 5, 'ativo', '2026-09-02 14:20:42'),
(357, 'BRYAN PEREIRA CRUZ', 5, 'ativo', '2026-09-02 14:20:51'),
(358, 'BERNARDO LIMA SARAIVA FERNANDES', 5, 'ativo', '2026-09-02 14:21:00'),
(359, 'DAVI MOURÃO LIMA', 5, 'ativo', '2026-09-02 14:21:09'),
(360, 'DIEGO RAFAEL DA SILVA CÂNDIDO', 5, 'ativo', '2026-09-02 14:21:27'),
(361, 'EDUARDO MARQUES SIQUEIRA', 5, 'ativo', '2026-09-02 14:21:38'),
(362, 'EMILY VITÓRIA GARCIA MENDES', 5, 'ativo', '2026-09-02 14:21:47'),
(363, 'ERYSSON ALVES SAMPAIO', 5, 'ativo', '2026-09-02 14:21:59'),
(364, 'EXPEDITO RUAN NASCIMENTO DOS SANTOS', 5, 'ativo', '2026-09-02 14:22:16'),
(365, 'FELIPE SOEIRO MAIA AMÂNCIO CAMPOS', 5, 'ativo', '2026-09-02 14:22:24'),
(366, 'FRANCISCO  ARIEL SOUSA SILVA', 5, 'ativo', '2026-09-02 14:22:33'),
(367, 'GIOVANNA HELEN COSTA SEVERO', 5, 'ativo', '2026-09-02 14:22:44'),
(368, 'GRAZIELE DO NASCIMENTO VIANA', 5, 'ativo', '2026-09-02 14:22:53'),
(369, 'HORRARA RIBEIRO NEVES', 5, 'ativo', '2026-09-02 14:23:01'),
(370, 'ISAC RAVEL DA SILVA ALMEIDA', 5, 'ativo', '2026-09-02 14:24:59'),
(371, 'ISABELA SOEIRO MAIA AMÂNCIO CAMPOS', 5, 'ativo', '2026-09-02 14:25:10'),
(372, 'JOÃO EMANUEL DE LIMA FELIX', 5, 'ativo', '2026-09-02 14:25:23'),
(373, 'JOÃO BATISTA SOARES OLIVEIRA', 5, 'ativo', '2026-09-02 14:25:32'),
(374, 'JOSÉ DANILO FERREIRA GADELHA', 5, 'ativo', '2026-09-02 14:25:41'),
(375, 'JOSÉ ERIC FERREIRA MOREIRA', 5, 'ativo', '2026-09-02 14:25:49'),
(376, 'JUAN CARLOS DOS SANTOS XIMENES', 5, 'ativo', '2026-09-02 14:26:11'),
(377, 'LUAN ALVES DOS SANTOS', 5, 'ativo', '2026-09-02 14:26:19'),
(378, 'LUIZ HENRIQUE DE LIMA DA SILVA', 5, 'ativo', '2026-09-02 14:26:28'),
(379, 'MARCIA GOMES FRAGOSO', 5, 'ativo', '2026-09-02 14:26:36'),
(380, 'MARIA BEATRIZ DIAS DO VALE', 5, 'ativo', '2026-09-02 14:26:45'),
(381, 'MARIA EDUARDA CARNEIRO DA CRUZ', 5, 'ativo', '2026-09-02 14:26:53'),
(382, 'MARIA CLARA ALVES DE LIMA', 5, 'ativo', '2026-09-02 14:27:01'),
(383, 'MAYRA APARECIDA DA SILVA BEZERRA', 5, 'ativo', '2026-09-02 14:27:09'),
(384, 'MATHEUS DE QUEIROZ MENDES', 5, 'ativo', '2026-09-02 14:27:19'),
(385, 'NADIELY NUNES DE SOUSA', 5, 'ativo', '2026-09-02 14:27:28'),
(386, 'NADILA DA SILVA DE OLIVEIRA', 5, 'ativo', '2026-09-02 14:27:37'),
(387, 'NICHOLAS BRUNO BARBOSA DIAS DE MEDEIROS', 5, 'ativo', '2026-09-02 14:27:46'),
(388, 'PAULA MAYARA VIANA ALVES', 5, 'ativo', '2026-09-02 14:27:55'),
(389, 'RUAN MAGNO TORRES UCHÔA', 5, 'ativo', '2026-09-02 14:28:03'),
(390, 'VALERIA LAVINEA MENDES TORRES', 5, 'ativo', '2026-09-02 14:28:13'),
(391, 'WESLEY PEREIRA DE LIMA', 5, 'ativo', '2026-09-02 14:28:23'),
(392, 'ANA BEATRIZ DOS SANTOS SEVERO', 8, 'ativo', '2026-09-02 16:10:48'),
(393, 'ANA GABRIELE DOS SANTOS ALVES', 8, 'ativo', '2026-09-02 16:10:58'),
(394, 'ANA VITORIA MARTINS ALVES', 8, 'ativo', '2026-09-02 16:11:07'),
(395, 'ANA KAYLA DIAS DA COSTA', 8, 'ativo', '2026-09-02 16:11:16'),
(396, 'ANA SOFIA ALVES FARIAS', 8, 'ativo', '2026-09-02 16:11:29'),
(397, 'ANA LAIS MACIEL DA SILVA', 8, 'ativo', '2026-09-02 16:11:37'),
(398, 'ANA LUISA DE SOUSA SALGADO SILVA', 8, 'ativo', '2026-09-02 16:11:45'),
(399, 'ARIEL SALES LAURINDO', 8, 'ativo', '2026-09-02 16:11:55'),
(400, 'CRISLANE RODRIGUES DA SILVA', 8, 'ativo', '2026-09-02 16:12:05'),
(401, 'DANYELE DE SOUSA MAIA', 8, 'ativo', '2026-09-02 16:12:15'),
(402, 'DEIZIANE DE OLIVEIRA DOS SANTOS', 8, 'ativo', '2026-09-02 16:12:25'),
(403, 'FERNANDA FRAGOSO CARNEIRO', 8, 'ativo', '2026-09-02 16:12:35'),
(404, 'FERNANDES ALVES DE SOUSA NETO', 8, 'ativo', '2026-09-02 16:12:43'),
(405, 'HENRI LIMA VIEIRA GONCALVES', 8, 'ativo', '2026-09-02 16:12:52'),
(406, 'HELENA DO NASCIMENTO LOURENCO', 8, 'ativo', '2026-09-02 16:13:03'),
(407, 'ISABELA JUSTINO SILVA', 8, 'ativo', '2026-09-02 16:13:12'),
(408, 'ISABELLY HADASSA PEDROZA MESQUITA', 8, 'ativo', '2026-09-02 16:13:20'),
(409, 'KALINE MARIA FEITOSA VERAS', 8, 'ativo', '2026-09-02 16:13:28'),
(410, 'LARA ANNE FREITAS DUARTE', 8, 'ativo', '2026-09-02 16:13:40'),
(411, 'LARA EMANUELLY VIEIRA MEDEIROS', 8, 'ativo', '2026-09-02 16:13:50'),
(412, 'LARA RAKELE OLIVEIRA MARTINS', 8, 'ativo', '2026-09-02 16:14:03'),
(413, 'LARISSA MENDES DOS SANTOS', 8, 'ativo', '2026-09-02 16:14:11'),
(414, 'LAYSLA ANGELIM MATEUS', 8, 'ativo', '2026-09-02 16:14:19'),
(415, 'LIVIA MARIA LEAL DE ARAUJO', 8, 'ativo', '2026-09-02 16:14:28'),
(416, 'LOREN DO NASCIMENTO LIMA', 8, 'ativo', '2026-09-02 16:14:36'),
(417, 'MARIA CLARA DE SOUSA VIEIRA', 8, 'ativo', '2026-09-02 16:14:44'),
(418, 'MARIA EDUARDA SOUSA RODRIGUES', 8, 'ativo', '2026-09-02 16:14:52'),
(419, 'MARIA SOFIA RODRIGUES DE LIMA', 8, 'ativo', '2026-09-02 16:15:00'),
(420, 'MIRELLA DE SOUSA MONTE', 8, 'ativo', '2026-09-02 16:15:09'),
(421, 'NARA MARGARIDA DOS ANJOS MESSIAS', 8, 'ativo', '2026-09-02 16:15:20'),
(422, 'RAFAELLA DE SOUZA FARIAS DO VALE', 8, 'ativo', '2026-09-02 16:15:29'),
(423, 'RIANA DE SOUZA SOARES', 8, 'ativo', '2026-09-02 16:15:39'),
(424, 'SARAH ELLEN HONORATO RIBEIRO', 8, 'ativo', '2026-09-02 16:15:47'),
(425, 'SOFIA ALENCAR ALVES', 8, 'ativo', '2026-09-02 16:15:56'),
(426, 'SOFIA ALENCAR ALVES', 8, 'ativo', '2026-09-02 16:17:46'),
(427, 'SAMIRA ALVES DE SOUSA', 8, 'ativo', '2026-09-02 16:17:56'),
(428, 'TAINARA DE FRANCA DE OLIVEIRA', 8, 'ativo', '2026-09-02 16:18:04'),
(429, 'TAIS FERREIRA DE OLIVEIRA', 8, 'ativo', '2026-09-02 16:18:13'),
(430, 'THAIS SAMPAIO DA SILVA', 8, 'ativo', '2026-09-02 16:18:21'),
(431, 'TALIA DA SILVA RODRIGUES', 8, 'ativo', '2026-09-02 16:18:31'),
(432, 'THAYNA CAVALCANTE CARNEIRO', 8, 'ativo', '2026-09-02 16:18:39'),
(433, 'TAYNNA PALOMA DOS SANTOS RODRIGUES', 8, 'ativo', '2026-09-02 16:18:49'),
(434, 'YANA LARA ALVES NUNES', 8, 'ativo', '2026-09-02 16:18:59'),
(435, 'YHASMIN MOURAO SOUZA', 8, 'ativo', '2026-09-02 16:19:07'),
(436, 'ANA JULIA CARNEIRO BOTELHO', 2, 'ativo', '2026-09-02 16:35:10'),
(437, 'ANA KETLY RODRIGUES PAIVA', 2, 'ativo', '2026-09-02 16:35:21'),
(438, 'ANTONIO SILVA ALMEIDA FILHO', 2, 'ativo', '2026-09-02 16:35:30'),
(439, 'BRUNO MOREIRA DE LIMA', 2, 'ativo', '2026-09-02 16:35:56'),
(440, 'CAROLINA SOEIRO MAIA AMANCIO CAMPOS', 2, 'ativo', '2026-09-02 16:36:06'),
(441, 'CARLOS DANIEL VENANCIO DE OLIVEIRA', 2, 'ativo', '2026-09-02 16:36:17'),
(442, 'DAVILA DO NASCIMENTO LIMA', 2, 'ativo', '2026-09-02 16:36:30'),
(443, 'DÁVINE RODRIGUES DOS SANTOS', 2, 'ativo', '2026-09-02 16:36:40'),
(444, 'ELANE DA FONSECA ALMEIDA', 2, 'ativo', '2026-09-02 16:36:49'),
(445, 'FAGNER PINHEIRO BARBOSA', 2, 'ativo', '2026-09-02 16:36:58'),
(446, 'FRANCIELEN ALVES DE MELO', 2, 'ativo', '2026-09-02 16:37:12'),
(447, 'FABIO ANDRADE DA SILVA JUNIOR', 2, 'ativo', '2026-09-02 16:37:21'),
(448, 'FABRICIO UCHOA SALES JUNIOR', 2, 'ativo', '2026-09-02 16:37:33'),
(449, 'FRANCISCO ÍCARO DO VALE SAMPAIO', 2, 'ativo', '2026-09-02 16:37:45'),
(450, 'GRAZIELE DO NASCIMENTO ALVES', 2, 'ativo', '2026-09-02 16:37:55'),
(451, 'GABRIEL AMARAL JUSTINO', 2, 'ativo', '2026-09-02 16:38:04'),
(452, 'IAGO ERIC SAMPAIO COSTA', 2, 'ativo', '2026-09-02 16:38:17'),
(453, 'INÁCIO DE SOUSA CARNEIRO', 2, 'ativo', '2026-09-02 16:38:29'),
(454, 'IZABELE FERNANDES FERREIRA', 2, 'ativo', '2026-09-02 16:38:41'),
(455, 'IASMIM BEZERRA DE SOUSA', 2, 'ativo', '2026-09-02 16:38:54'),
(456, 'JOELSON DA SILVA NORONHA', 2, 'ativo', '2026-09-02 16:39:06'),
(457, 'JOYCE BARBOSA DE PAIVA', 2, 'ativo', '2026-09-02 16:39:15'),
(458, 'JOAO GUILHERME CANDIDO SOUSA', 2, 'ativo', '2026-09-02 16:40:31'),
(459, 'JEAN RODRIGUES DA SILVA', 2, 'ativo', '2026-09-02 16:40:49'),
(460, 'KAIO ENZO DA SILVA RIBEIRO', 2, 'ativo', '2026-09-02 16:40:59'),
(461, 'LARA LETICIA PEREIRA DE ALBUQUERQUE', 2, 'ativo', '2026-09-02 16:41:09'),
(462, 'LETICIA OLIVEIRA MELO', 2, 'ativo', '2026-09-02 16:41:17'),
(464, 'MARIA VITORIA ARAUJO AMARAL', 2, 'ativo', '2026-09-02 16:41:39'),
(465, 'MARIA EDUARDA ARAUJO ROCHA', 2, 'ativo', '2026-09-02 16:41:48'),
(466, 'MARIA CLARA CHAVES DA SILVA', 2, 'ativo', '2026-09-02 16:41:57'),
(467, 'MARIA RAISSA DINIZ DE SOUZA', 2, 'ativo', '2026-09-02 16:42:06'),
(468, 'MARCOS GABRIEL ARAGAO E SOUSA', 2, 'ativo', '2026-09-02 16:42:23'),
(469, 'NAYANE AURELIANO DE SOUSA', 2, 'ativo', '2026-09-02 16:42:33'),
(470, 'PEDRO HENRIQUE FERREIRA ALVES', 2, 'ativo', '2026-09-02 16:42:42'),
(471, 'PEDRO HENRIQUE FERREIRA VIEIRA', 2, 'ativo', '2026-09-02 16:42:51'),
(472, 'PEDRO HENRIQUE ALVES CASTRO', 2, 'ativo', '2026-09-02 16:43:03'),
(473, 'SAULO RODRIGUES DE FREITAS', 2, 'ativo', '2026-09-02 16:43:13'),
(474, 'SARAH LETICIA VIEIRA DE PAIVA', 2, 'ativo', '2026-09-02 16:43:23'),
(475, 'TAÍS SOARESDASILVA', 2, 'ativo', '2026-09-02 16:43:33'),
(476, 'VINICIUS DOS SANTOS ARAUJO', 2, 'ativo', '2026-09-02 16:43:44'),
(477, 'WENDY MARTINS CARVALHO', 2, 'ativo', '2026-09-02 16:43:56'),
(478, 'WESLEI OLIVEIRA RODRIGUES', 2, 'ativo', '2026-09-02 16:44:06'),
(479, 'ANA CAROLINA DA SILVA ANGELIM', 12, 'ativo', '2026-09-02 16:46:04'),
(480, 'ANA GABRIELE GOMES DA SILVA', 12, 'ativo', '2026-09-02 16:46:18'),
(481, 'ANTONIO EVERTON MATEUS BATISTA', 12, 'ativo', '2026-09-02 16:46:29'),
(482, 'ALESSANDRA DE OLIVEIRA CHAVES', 12, 'ativo', '2026-09-02 16:46:40'),
(483, 'ARIANE SAMPAIO DE OLIVEIRA', 12, 'ativo', '2026-09-02 16:46:50'),
(484, 'BRUNO VIEIRA PEREIRA', 12, 'ativo', '2026-09-02 16:46:59'),
(485, 'CAMILA GONÇALVES DE ARAÚJO', 12, 'ativo', '2026-09-02 16:47:09'),
(486, 'CLAUDIO VIEIRA DE SOUSA JÚNIOR', 12, 'ativo', '2026-09-02 16:47:18'),
(487, 'CRISLANI DA CONCEIÇÃO DE OLIVEIRA', 12, 'ativo', '2026-09-02 16:47:28'),
(488, 'DAVID EMANUEL LINHARES LIMA', 12, 'ativo', '2026-09-02 16:48:08'),
(489, 'DANIEL DE SOUSA LOBO', 12, 'ativo', '2026-09-02 16:48:18'),
(490, 'DENISE DA SILVA VIEIRA', 12, 'ativo', '2026-09-02 16:48:35'),
(491, 'ELANO SÁVIO DE SOUSA MACHADO', 12, 'ativo', '2026-09-02 16:48:44'),
(492, 'EMLLY NUNES DANTAS', 12, 'ativo', '2026-09-02 16:48:54'),
(493, 'EMILY SOFIA MARTINS DA SILVA', 12, 'ativo', '2026-09-02 16:49:05'),
(494, 'ERINALDO DOS SANTOS MARTINS', 12, 'ativo', '2026-09-02 16:49:13'),
(495, 'FRANCISCA JANIELLY DA SILVA PEREIRA', 12, 'ativo', '2026-09-02 16:49:22'),
(496, 'FRANCISCA RHIANA RODRIGUES DA SILVA', 12, 'ativo', '2026-09-02 16:49:31'),
(497, 'GABIEL EDUARDO SANTOS FACUNDO', 12, 'ativo', '2026-09-02 16:49:39'),
(498, 'GABRIEL VIEIRA LIMA', 12, 'ativo', '2026-09-02 16:49:57'),
(499, 'IAN ALVES DE SOUSA', 12, 'ativo', '2026-09-02 16:50:05'),
(500, 'ISABELA COÊLHO OLIVEIRA', 12, 'ativo', '2026-09-02 16:50:15'),
(501, 'JOÃO PEDRO ABREU ALVES', 12, 'ativo', '2026-09-02 16:50:23'),
(502, 'JOSÉ VICTOR DE ALMEIDA LIMA', 12, 'ativo', '2026-09-02 16:50:34'),
(503, 'JULIA DE SOUSA DE ALENCAR', 12, 'ativo', '2026-09-02 16:50:42'),
(504, 'KILDERY DE SOUZA VIEIRA', 12, 'ativo', '2026-09-02 16:50:52'),
(505, 'LAÍS MACIEL DE LIMA', 12, 'ativo', '2026-09-02 16:51:00'),
(506, 'LARA BRYGIDA VIEIRA RAMOS', 12, 'ativo', '2026-09-02 16:51:10'),
(507, 'LARA MACIEL DE LIMA', 12, 'ativo', '2026-09-02 16:51:21'),
(508, 'LARA MARIA DE OLIVEIRA LIMA', 12, 'ativo', '2026-09-02 16:51:29'),
(509, 'LARA RILARI ALVES BRAGA', 12, 'ativo', '2026-09-02 16:51:40'),
(510, 'LETICIA VIEIRA MENEZES', 12, 'ativo', '2026-09-02 16:51:49'),
(511, 'LORRANE MONTEIRO VIANA', 12, 'ativo', '2026-09-02 16:51:59'),
(512, 'MARIA AMANDA DA SILVA DO NASCIMENTO', 12, 'ativo', '2026-09-02 16:52:10'),
(513, 'MARIA EDUARDA MARIANO ALVES', 12, 'ativo', '2026-09-02 16:52:18'),
(514, 'MARIA ISABELA OLIVEIRA RODRIGUES', 12, 'ativo', '2026-09-02 16:52:27'),
(515, 'MARIA NICOLE ALVES DE SOUSA', 12, 'ativo', '2026-09-02 16:52:37'),
(516, 'RAYSSA ALVES SILVA', 12, 'ativo', '2026-09-02 16:52:48'),
(517, 'VANESSA DA SILVA ALBUQUERQUE', 12, 'ativo', '2026-09-02 16:52:57'),
(518, 'ANTONIA FLADIA ARAUJO VERCOSA DA SILVA', 9, 'ativo', '2026-09-02 16:55:30'),
(519, 'ANTONIO CAIQUE INVENÇÃO ARAÚJO', 9, 'ativo', '2026-09-02 16:56:11'),
(520, 'CARLOS EDUARDO FACUNDO LIMA', 9, 'ativo', '2026-09-02 16:56:20'),
(521, 'CATARINA LILIAN BRITO VIANA', 9, 'ativo', '2026-09-02 16:56:29'),
(522, 'CLARICE SECONELLI DE ALMEIDA', 9, 'ativo', '2026-09-02 16:56:41'),
(523, 'CLAUDIA RAKELE VIEIRA LIMA', 9, 'ativo', '2026-09-02 16:56:50'),
(524, 'EMILE THAINE NERY BRAGA', 9, 'ativo', '2026-09-02 16:57:23'),
(525, 'EMILI DA SILVA EVANGELISTA', 9, 'ativo', '2026-09-02 16:57:36'),
(526, 'EMILI VITORIA ALVES GOMES', 9, 'ativo', '2026-09-02 16:57:46'),
(527, 'GABRIELE DE SOUSA COSTA', 9, 'ativo', '2026-09-02 16:57:55'),
(528, 'GABRYELA MOTA DA CRUZ', 9, 'ativo', '2026-09-02 16:58:56'),
(529, 'HELOISA DA CRUZ ARAUJO', 9, 'ativo', '2026-09-02 16:59:06'),
(530, 'HELOISA MARIA SOARES DA SILVA', 9, 'ativo', '2026-09-02 16:59:17'),
(531, 'HELOISA SERAFIM BASILIO', 9, 'ativo', '2026-09-02 16:59:26'),
(532, 'IARA TORRES RIBEIRO', 9, 'ativo', '2026-09-02 16:59:36'),
(533, 'IARLEY SANTOS DE OLIVEIRA', 9, 'ativo', '2026-09-02 17:01:03'),
(534, 'ILANA LAIS PAIVA MOTA', 9, 'ativo', '2026-09-02 17:01:15'),
(535, 'INGRID VITORIA DOS SANTOS DA SILVA', 9, 'ativo', '2026-09-02 17:01:31'),
(536, 'ISABELA DA SILVA VENANCIO', 9, 'ativo', '2026-09-02 17:01:42'),
(537, 'ISABELA VIEIRA CAETANO DOS SANTOS', 9, 'ativo', '2026-09-02 17:01:52'),
(538, 'KAILANE DOS SANTOS DE OLIVEIRA', 9, 'ativo', '2026-09-02 17:02:00'),
(539, 'LARA FRANCINE RODRIGUES DOS SANTOS', 9, 'ativo', '2026-09-02 17:02:08'),
(540, 'LARA KEZIA DA SILVA GONCALVES', 9, 'ativo', '2026-09-02 17:02:17'),
(541, 'MARIA BRUNA CUNHA DE ABREU', 9, 'ativo', '2026-09-02 17:02:27'),
(542, 'MARIA CLARA ESTEVES RODRIGUES', 9, 'ativo', '2026-09-02 17:02:38'),
(543, 'MARIA EDUARDA BRASIL DO VALE', 9, 'ativo', '2026-09-02 17:02:47'),
(544, 'MARIA EDUARDA RODRIGUES DOS REIS', 9, 'ativo', '2026-09-02 17:02:56'),
(545, 'MARIA IANA CAMPOS ROCHA', 9, 'ativo', '2026-09-02 17:03:05'),
(546, 'MARIA LORENA MUNIZ NASCIMENTO', 9, 'ativo', '2026-09-02 17:03:15'),
(547, 'MARIA SHEILIANE FERREIRA DA SILVA', 9, 'ativo', '2026-09-02 17:03:32'),
(548, 'PAMELA RAFAELE RODRIGUES BARBOSA', 9, 'ativo', '2026-09-02 17:03:39'),
(549, 'RAIANE DE OLIVEIRA DE ARAUJO', 9, 'ativo', '2026-09-02 17:03:50'),
(550, 'RAIANE GOMES GONCALVES', 9, 'ativo', '2026-09-02 17:03:58'),
(551, 'RAICA VIEIRA RODRIGUES', 9, 'ativo', '2026-09-02 17:04:06'),
(552, 'RAISSA BEZERRA DA SILVA', 9, 'ativo', '2026-09-02 17:04:24'),
(553, 'RAISSA DO NASCIMENTO SALES', 9, 'ativo', '2026-09-02 17:04:35'),
(554, 'RAYSSA DE ALMEIDA VIEIRA', 9, 'ativo', '2026-09-02 17:04:53'),
(555, 'RAYSSA VITORIA PEREIRA VIEIRA', 9, 'ativo', '2026-09-02 17:05:05'),
(556, 'ROBERLANIA DA SILVA FERREIRA', 9, 'ativo', '2026-09-02 17:05:14'),
(557, 'SARAH FRAGOSO MODESTO', 9, 'ativo', '2026-09-02 17:05:24'),
(558, 'SUIANE FELIX DE FARIAS', 9, 'ativo', '2026-09-02 17:05:31'),
(559, 'VERONICA BRASIL ALVES', 9, 'ativo', '2026-09-02 17:05:39'),
(560, 'VITORIA MARTINS PEREIRA DA SILVA', 9, 'ativo', '2026-09-02 17:05:48'),
(561, 'YALE MARIA DE OLIVEIRA CAVALCANTE', 9, 'ativo', '2026-09-02 17:05:57'),
(562, 'YASMIM DE PAIVA FARIAS', 9, 'ativo', '2026-09-02 17:06:14'),
(563, 'THAILA BEZERRA PINTO', 1, 'ativo', '2026-09-02 17:23:38'),
(564, 'YAN VICTOR CAVALCANTE DA SILVA', 10, 'ativo', '2026-09-02 18:09:44'),
(565, 'ANA VITORIA FRANCA DE SOUSA', 10, 'ativo', '2026-09-02 18:14:15');

-- 177 faltas migradas do sistema antigo
INSERT IGNORE INTO faltas (id, aluno_id, data_falta, justificada, criado_em) VALUES
(1, 21, '2026-08-10', 0, '2026-08-20 12:42:15'),
(2, 28, '2026-08-10', 0, '2026-08-20 12:42:34'),
(3, 75, '2026-08-10', 0, '2026-08-20 12:43:04'),
(4, 74, '2026-08-10', 0, '2026-08-20 12:49:03'),
(5, 59, '2026-08-10', 0, '2026-08-20 12:49:12'),
(6, 71, '2026-08-10', 0, '2026-08-20 12:49:23'),
(7, 78, '2026-08-10', 0, '2026-08-20 12:49:49'),
(8, 46, '2026-08-11', 0, '2026-08-20 12:51:27'),
(9, 6, '2026-08-11', 0, '2026-08-20 12:51:42'),
(10, 33, '2026-08-11', 0, '2026-08-20 12:51:52'),
(11, 25, '2026-08-12', 0, '2026-08-20 12:54:12'),
(12, 36, '2026-08-12', 0, '2026-08-20 12:54:25'),
(13, 19, '2026-08-12', 0, '2026-08-20 12:54:35'),
(14, 15, '2026-08-12', 0, '2026-08-20 12:54:59'),
(15, 74, '2026-08-12', 0, '2026-08-20 12:55:33'),
(16, 71, '2026-08-12', 0, '2026-08-20 12:55:46'),
(17, 41, '2026-08-14', 0, '2026-08-20 12:57:44'),
(18, 58, '2026-08-14', 0, '2026-08-20 12:57:53'),
(19, 62, '2026-08-14', 0, '2026-08-20 12:58:04'),
(20, 68, '2026-08-14', 0, '2026-08-20 12:58:20'),
(21, 45, '2026-08-14', 0, '2026-08-20 12:58:30'),
(22, 72, '2026-08-14', 0, '2026-08-20 12:58:39'),
(23, 53, '2026-08-14', 0, '2026-08-20 12:58:49'),
(24, 65, '2026-08-14', 0, '2026-08-20 12:58:58'),
(25, 49, '2026-08-14', 0, '2026-08-20 12:59:14'),
(26, 14, '2026-08-17', 0, '2026-08-20 13:00:03'),
(27, 4, '2026-08-17', 0, '2026-08-20 13:00:11'),
(28, 17, '2026-08-17', 0, '2026-08-20 13:00:23'),
(29, 8, '2026-08-17', 0, '2026-08-20 13:00:32'),
(30, 19, '2026-08-17', 0, '2026-08-20 13:00:47'),
(31, 69, '2026-08-17', 0, '2026-08-20 13:01:01'),
(32, 75, '2026-08-17', 0, '2026-08-20 13:01:10'),
(33, 63, '2026-08-17', 0, '2026-08-20 13:01:22'),
(34, 12, '2026-09-02', 0, '2026-09-02 11:22:18'),
(35, 565, '2026-09-02', 0, '2026-09-02 18:14:37'),
(36, 173, '2026-09-02', 0, '2026-09-02 18:14:53'),
(37, 140, '2026-09-02', 0, '2026-09-02 18:15:01'),
(38, 157, '2026-09-02', 0, '2026-09-02 18:15:39'),
(39, 188, '2026-09-02', 0, '2026-09-02 18:15:59'),
(40, 198, '2026-09-02', 0, '2026-09-02 18:16:07'),
(41, 181, '2026-09-02', 0, '2026-09-02 18:16:15'),
(42, 183, '2026-09-02', 0, '2026-09-02 18:16:22'),
(43, 195, '2026-09-02', 0, '2026-09-02 18:16:30'),
(44, 254, '2026-09-02', 0, '2026-09-02 18:17:00'),
(45, 260, '2026-09-02', 0, '2026-09-02 18:17:18'),
(46, 319, '2026-09-02', 0, '2026-09-02 18:17:44'),
(47, 381, '2026-09-02', 0, '2026-09-02 18:18:00'),
(48, 354, '2026-09-02', 0, '2026-09-02 18:18:08'),
(49, 374, '2026-09-02', 0, '2026-09-02 18:19:16'),
(50, 353, '2026-09-02', 0, '2026-09-02 18:19:23'),
(51, 359, '2026-09-02', 0, '2026-09-02 18:19:30'),
(52, 372, '2026-09-02', 0, '2026-09-02 18:19:36'),
(53, 391, '2026-09-02', 0, '2026-09-02 18:19:40'),
(54, 414, '2026-09-02', 0, '2026-09-02 18:19:54'),
(55, 514, '2026-09-02', 0, '2026-09-02 18:20:19'),
(56, 515, '2026-09-02', 0, '2026-09-02 18:20:26'),
(57, 483, '2026-09-02', 0, '2026-09-02 18:22:42'),
(58, 516, '2026-09-02', 0, '2026-09-02 18:22:50'),
(59, 519, '2026-09-02', 0, '2026-09-02 18:23:10'),
(60, 529, '2026-09-02', 0, '2026-09-02 18:23:17'),
(61, 49, '2026-09-02', 0, '2026-09-02 18:23:30'),
(62, 80, '2026-09-02', 0, '2026-09-02 18:23:37'),
(63, 290, '2026-09-02', 0, '2026-09-02 18:24:18'),
(64, 288, '2026-09-02', 0, '2026-09-02 18:24:45'),
(65, 86, '2026-09-02', 0, '2026-09-02 18:24:57'),
(66, 144, '2026-09-03', 0, '2026-09-03 16:02:29'),
(67, 565, '2026-09-03', 0, '2026-09-03 16:02:34'),
(68, 157, '2026-09-03', 0, '2026-09-03 16:02:42'),
(69, 139, '2026-09-03', 0, '2026-09-03 16:02:49'),
(70, 147, '2026-09-03', 0, '2026-09-03 16:02:56'),
(71, 182, '2026-09-03', 0, '2026-09-03 16:04:34'),
(72, 183, '2026-09-03', 0, '2026-09-03 16:04:43'),
(73, 188, '2026-09-03', 0, '2026-09-03 16:04:51'),
(74, 195, '2026-09-03', 0, '2026-09-03 16:04:58'),
(75, 200, '2026-09-03', 0, '2026-09-03 16:05:05'),
(76, 198, '2026-09-03', 0, '2026-09-03 16:05:12'),
(77, 210, '2026-09-03', 0, '2026-09-03 16:05:24'),
(78, 212, '2026-09-03', 0, '2026-09-03 16:05:34'),
(79, 225, '2026-09-03', 0, '2026-09-03 16:05:49'),
(80, 221, '2026-09-03', 0, '2026-09-03 16:05:56'),
(81, 232, '2026-09-03', 0, '2026-09-03 16:06:04'),
(82, 310, '2026-09-03', 0, '2026-09-03 16:06:26'),
(83, 315, '2026-09-03', 0, '2026-09-03 16:06:33'),
(84, 313, '2026-09-03', 0, '2026-09-03 16:07:06'),
(85, 348, '2026-09-03', 0, '2026-09-03 16:07:15'),
(86, 393, '2026-09-03', 0, '2026-09-03 16:07:34'),
(87, 424, '2026-09-03', 0, '2026-09-03 16:07:41'),
(88, 421, '2026-09-03', 0, '2026-09-03 16:07:48'),
(89, 396, '2026-09-03', 0, '2026-09-03 16:07:57'),
(90, 432, '2026-09-03', 0, '2026-09-03 16:08:18'),
(91, 358, '2026-09-03', 0, '2026-09-03 16:08:47'),
(92, 360, '2026-09-03', 0, '2026-09-03 16:08:54'),
(93, 375, '2026-09-03', 0, '2026-09-03 16:09:04'),
(94, 357, '2026-09-03', 0, '2026-09-03 16:09:23'),
(95, 372, '2026-09-03', 0, '2026-09-03 16:09:34'),
(96, 353, '2026-09-03', 0, '2026-09-03 16:09:41'),
(97, 378, '2026-09-03', 0, '2026-09-03 16:09:51'),
(98, 474, '2026-09-02', 0, '2026-09-03 16:20:50'),
(99, 444, '2026-09-03', 0, '2026-09-03 16:20:57'),
(100, 465, '2026-09-03', 0, '2026-09-03 16:21:08'),
(101, 457, '2026-09-03', 0, '2026-09-03 16:21:21'),
(102, 439, '2026-09-03', 0, '2026-09-03 16:21:41'),
(103, 462, '2026-09-03', 0, '2026-09-03 16:21:48'),
(104, 565, '2026-09-04', 0, '2026-09-04 12:58:03'),
(105, 163, '2026-09-04', 0, '2026-09-04 12:58:12'),
(106, 161, '2026-09-04', 0, '2026-09-04 12:58:24'),
(107, 157, '2026-09-04', 0, '2026-09-04 12:58:32'),
(108, 151, '2026-09-04', 0, '2026-09-04 12:58:38'),
(109, 188, '2026-09-04', 0, '2026-09-04 12:58:55'),
(110, 198, '2026-09-04', 0, '2026-09-04 12:59:04'),
(111, 181, '2026-09-04', 0, '2026-09-04 12:59:12'),
(112, 210, '2026-09-04', 0, '2026-09-04 12:59:20'),
(113, 217, '2026-09-04', 0, '2026-09-04 12:59:27'),
(114, 192, '2026-09-04', 0, '2026-09-04 12:59:33'),
(115, 225, '2026-09-04', 0, '2026-09-04 12:59:44'),
(116, 234, '2026-09-04', 0, '2026-09-04 12:59:49'),
(117, 276, '2026-09-04', 0, '2026-09-04 13:00:01'),
(118, 272, '2026-09-04', 0, '2026-09-04 13:00:07'),
(119, 290, '2026-09-04', 0, '2026-09-04 13:00:17'),
(120, 563, '2026-09-04', 0, '2026-09-04 13:00:25'),
(121, 121, '2026-09-04', 0, '2026-09-04 13:00:34'),
(122, 129, '2026-09-04', 0, '2026-09-04 13:00:43'),
(123, 311, '2026-09-04', 0, '2026-09-04 13:01:06'),
(124, 338, '2026-09-04', 0, '2026-09-04 13:01:14'),
(125, 323, '2026-09-04', 0, '2026-09-04 13:01:21'),
(126, 341, '2026-09-04', 0, '2026-09-04 13:01:31'),
(127, 385, '2026-09-04', 0, '2026-09-04 13:01:54'),
(128, 353, '2026-09-04', 0, '2026-09-04 13:02:00'),
(129, 364, '2026-09-04', 0, '2026-09-04 13:02:07'),
(130, 391, '2026-09-04', 0, '2026-09-04 13:02:17'),
(131, 394, '2026-09-04', 0, '2026-09-04 13:02:39'),
(132, 393, '2026-09-04', 0, '2026-09-04 13:02:46'),
(133, 395, '2026-09-04', 0, '2026-09-04 13:02:53'),
(134, 414, '2026-09-04', 0, '2026-09-04 13:03:02'),
(135, 396, '2026-09-04', 0, '2026-09-04 13:03:08'),
(136, 439, '2026-09-04', 0, '2026-09-04 13:03:21'),
(137, 448, '2026-09-04', 0, '2026-09-04 13:03:25'),
(138, 516, '2026-09-04', 0, '2026-09-04 13:03:45'),
(139, 490, '2026-09-04', 0, '2026-09-04 13:03:50'),
(140, 511, '2026-09-04', 0, '2026-09-04 13:03:59'),
(141, 513, '2026-09-04', 0, '2026-09-04 13:04:06'),
(142, 500, '2026-09-04', 0, '2026-09-04 13:04:12'),
(143, 497, '2026-09-04', 0, '2026-09-04 13:04:18'),
(144, 16, '2026-09-04', 0, '2026-09-04 13:04:39'),
(145, 17, '2026-09-04', 0, '2026-09-04 13:04:47'),
(146, 25, '2026-09-04', 0, '2026-09-04 13:04:52'),
(147, 8, '2026-09-04', 0, '2026-09-04 13:04:57'),
(148, 10, '2026-09-04', 0, '2026-09-04 13:05:05'),
(149, 537, '2026-09-04', 0, '2026-09-04 13:05:36'),
(150, 527, '2026-09-04', 0, '2026-09-04 13:05:46'),
(151, 534, '2026-09-04', 0, '2026-09-04 13:05:52'),
(152, 561, '2026-09-04', 0, '2026-09-04 13:06:01'),
(153, 535, '2026-09-04', 0, '2026-09-04 13:06:07'),
(154, 538, '2026-09-04', 0, '2026-09-04 13:06:14'),
(155, 80, '2026-09-04', 0, '2026-09-04 13:06:26'),
(156, 49, '2026-09-04', 0, '2026-09-04 13:07:01'),
(157, 72, '2026-09-04', 0, '2026-09-04 13:07:18'),
(158, 148, '2026-01-09', 0, '2026-09-04 13:31:30'),
(159, 167, '2026-02-02', 0, '2026-09-04 13:32:03'),
(160, 148, '2026-02-02', 0, '2026-09-04 13:32:16'),
(161, 217, '2026-02-02', 0, '2026-09-04 13:33:15'),
(162, 178, '2026-02-02', 0, '2026-09-04 13:33:29'),
(163, 213, '2026-02-02', 0, '2026-09-04 13:33:44'),
(164, 186, '2026-02-02', 0, '2026-09-04 13:33:58'),
(165, 260, '2026-09-04', 0, '2026-09-04 13:34:11'),
(166, 277, '2026-09-04', 0, '2026-09-04 13:34:25'),
(167, 316, '2026-02-02', 0, '2026-09-04 13:34:46'),
(168, 363, '2026-02-02', 0, '2026-09-04 13:35:20'),
(169, 375, '2026-09-04', 0, '2026-09-04 13:35:31'),
(170, 378, '2026-02-02', 0, '2026-09-04 13:36:25'),
(171, 379, '2026-02-02', 0, '2026-09-04 13:36:39'),
(172, 388, '2026-03-02', 0, '2026-09-04 13:37:00'),
(173, 390, '2026-02-02', 0, '2026-09-04 13:37:29'),
(174, 391, '2026-02-02', 0, '2026-09-04 13:37:42'),
(175, 409, '2026-02-02', 0, '2026-09-04 13:38:00'),
(176, 437, '2026-09-04', 0, '2026-09-04 13:38:14'),
(177, 488, '2026-02-02', 0, '2026-09-04 13:38:44');

SET FOREIGN_KEY_CHECKS = 1;

-- Aviso de novidades da versão 8 (aparece no sino de todos os usuários)
INSERT IGNORE INTO notificacoes (id, titulo, mensagem, tipo, destino, importante, autor_nome, criado_em) VALUES
(1, 'Novidades do sistema: notificações, liberação de saída e bloco de provas',
 'Agora o painel tem notificações (o sino no topo). A liberação de saída mudou: o professor envia o pedido de dentro da sala e a coordenação confirma e imprime o cupom. Na Correção de provas dá para cadastrar um bloco de provas (várias disciplinas numa só folha), e a folha de respostas ganhou um modelo novo, pensado para impressão em preto e branco.',
 'sistema', 'todos', 1, 'Sistema escolar', '2026-10-22 19:00:00');
