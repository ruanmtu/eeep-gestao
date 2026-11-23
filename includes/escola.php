<?php
/* =====================================================================
 *  CONTEÚDO DO SITE DA ESCOLA
 *  Tudo o que aparece na página inicial está aqui. Para trocar textos ou
 *  fotos, edite só este arquivo. As fotos ficam em assets/img/
 *  (envie com o mesmo nome do arquivo ou mude o nome aqui).
 *
 *  >>> Os itens marcados com [EDITAR] são textos de exemplo: troque pelos
 *      dados reais da escola antes de divulgar o site.
 * ===================================================================== */

$ESCOLA = [
    'nome'        => 'EEEP Venceslau Vieira Batista',
    'nome_curto'  => 'EEEP Venceslau',
    'nome_oficial'=> 'Escola Estadual de Educação Profissional Venceslau Vieira Batista',
    'slogan'      => 'Ensino médio integrado à educação profissional',
    'endereco'    => 'R. José Viêira Costa, 1048 - Alto da Queiroz',
    'cidade'      => 'Boa Viagem - CE',
    'telefone'    => '(88) 3427-1546',                                   // [EDITAR] ex.: "(88) 0000-0000" (vazio = não aparece)
    'email'       => 'eeepboaviagem@escola.ce.gov.br',
    'instagram'   => 'eeepvenceslau',
    'atendimento' => '08h às 11h | 13h às 16h',   // horário de atendimento ao público
    'mapa'        => 'Escola Estadual de Educação Profissional Venceslau Vieira Batista, Boa Viagem - CE',
    'logo'        => 'assets/img/logo-escola-2026.png',
    'logo_branca' => 'assets/img/logo-branca.png',     // logomarca nova (letras brancas): cabeçalho, rodapé e painel
    'brasao'      => 'assets/img/brasao.png',
];

/* Atalhos da barra de cima do site (abrem em outra aba) [EDITAR] confira os endereços */
$ATALHOS = [
    ['rotulo' => 'Aluno Online',     'icone' => 'mortarboard',      'url' => 'https://aluno.seduc.ce.gov.br/'],
    ['rotulo' => 'Professor Online', 'icone' => 'easel2',           'url' => 'https://professor.seduc.ce.gov.br/'],
    ['rotulo' => 'SIGE Escola',      'icone' => 'clipboard2-data',  'url' => 'https://sige.seduc.ce.gov.br/'],
    ['rotulo' => 'SEDUC Ceará',      'icone' => 'buildings',        'url' => 'https://www.seduc.ce.gov.br/'],
];

/* Fotos dos estudantes (seção "Nossos estudantes") [EDITAR] legendas */
$ESTUDANTES = [
    ['imagem' => 'assets/img/estudantes-2.jpg', 'legenda' => 'Resiliência em cada desafio'],
    ['imagem' => 'assets/img/estudantes-5.jpg', 'legenda' => 'Formação técnica na prática'],
    ['imagem' => 'assets/img/estudantes-3.jpg', 'legenda' => 'Protagonismo estudantil'],
    ['imagem' => 'assets/img/estudantes-1.jpg', 'legenda' => 'Postura profissional sempre'],
    ['imagem' => 'assets/img/estudantes-6.jpg', 'legenda' => 'Foco no que importa'],
    ['imagem' => 'assets/img/estudantes-4.jpg', 'legenda' => 'Interesse em aprender mais'],
];

/* Gestão escolar (seção com a foto do diretor) */
$GESTAO = [
    'foto'   => 'assets/img/diretor.jpg',
    'nome'   => 'Erasmo Souza',
    'cargo'  => 'Diretor geral',
    'texto'  => 'Nossa escola recebe cada estudante para três anos de formação completa: ensino médio, curso técnico e estágio. '
              . 'O compromisso da gestão é acompanhar de perto a frequência, o aprendizado e o projeto de vida de cada aluno, '
              . 'junto com as famílias e a comunidade de Boa Viagem.',   // [EDITAR] pode trocar por uma mensagem do próprio diretor
];

/* Grupos da galeria de funcionários (Nossa equipe). A equipe é cadastrada no painel: Menu › Equipe no site */
$GRUPOS_EQUIPE = ['gestao' => 'Gestão', 'professores' => 'Professores', 'secretaria' => 'Secretaria e administrativo', 'multimeios' => 'Multimeios e laboratórios', 'apoio' => 'Apoio e serviços gerais'];

/* Equipe gestora (usada enquanto ninguém for cadastrado no grupo Gestão do painel da seção "Gestão"). Para pôr a foto, envie o arquivo
   para assets/img/ com o nome indicado em 'foto' (de preferência em pé, 4:5).
   Sem foto, aparecem as iniciais. */
$GESTORES = [
    ['nome' => 'Erasmo Souza',      'cargo' => 'Diretor geral',              'foto' => 'assets/img/diretor.jpg',
     'texto' => 'Coordena a escola como um todo: pessoas, recursos, resultados e o diálogo com as famílias e a comunidade.'],
    ['nome' => 'Fernando Batista',  'cargo' => 'Coordenador pedagógico',     'foto' => 'assets/img/gestao-fernando.jpg',
     'texto' => 'Acompanha o planejamento das aulas, a frequência e o desempenho das turmas junto com os professores.'],
    ['nome' => 'Evilândia Araújo',  'cargo' => 'Coordenadora pedagógica',    'foto' => 'assets/img/gestao-evilandia.jpg',
     'texto' => 'Cuida do acompanhamento pedagógico, das avaliações e do apoio aos estudantes e aos diretores de turma.'],
    ['nome' => 'Hernandes',         'cargo' => 'Coordenador de estágio',     'foto' => 'assets/img/gestao-hernandes.jpg',
     'texto' => 'Organiza o estágio dos cursos técnicos e a parceria com empresas e instituições da região.'],
];

/* Carrossel de abertura: uma foto grande por slide */
$HERO = [
    [
        'imagem' => 'assets/img/escola-aerea.jpg', 'foco' => '55% 50%',
        'titulo' => 'Aqui o ensino médio já sai com uma profissão.',
        'texto'  => 'Três anos em tempo integral, com formação técnica, estágio e base comum completa.',
    ],
    [
        'imagem' => 'assets/img/escola-aerea.jpg', 'foco' => '62% 45%', // troque por outra foto quando tiver
        'titulo' => 'Laboratórios onde a teoria vira prática.',
        'texto'  => 'Informática, saúde e gestão: cada curso tem espaço próprio para aprender fazendo.',
    ],
    [
        'imagem' => 'assets/img/escola-aerea.jpg', 'foco' => '40% 70%',
        'titulo' => 'Do estágio para o mercado de trabalho.',
        'texto'  => 'No 3º ano, os alunos vivem a rotina da profissão em empresas e instituições parceiras.',
    ],
];

/* História: cada item vira um marco na linha do tempo (em ordem) */
$HISTORIA_INTRO = 'Nossa história começou em 2009, com a EEEP David Vieira da Silva, oferecendo o ensino médio integrado à educação profissional em tempo integral. '
                . 'Em 2014 ganhamos uma nova sede e um novo nome: hoje somos a EEEP Venceslau Vieira Batista, em Boa Viagem, no Sertão Central, formando jovens nas áreas de gestão, saúde e tecnologia.';

/* História: cada item vira um cartão na linha do tempo (em ordem). "icone" = ícone do Bootstrap Icons */
$HISTORIA = [
    ['ano' => '2009', 'icone' => 'flag',            'titulo' => 'Onde tudo começou',             'texto' => 'Em 16 de março, nasce a EEEP David Vieira da Silva, com o ensino médio integrado à educação profissional e aulas em tempo integral.'],
    ['ano' => '2009', 'icone' => 'mortarboard',     'titulo' => 'Primeiros cursos técnicos',     'texto' => 'São abertas as primeiras turmas dos cursos técnicos de Enfermagem, Informática e Finanças.'],
    ['ano' => '2010', 'icone' => 'shop',            'titulo' => 'Novas oportunidades',           'texto' => 'O curso técnico em Finanças é substituído por Comércio, ampliando a formação na área de gestão e negócios.'],
    ['ano' => '2011', 'icone' => 'briefcase',       'titulo' => 'Chegada da Administração',      'texto' => 'O curso técnico em Administração substitui Comércio, consolidando uma nova área de formação profissional.'],
    ['ano' => '2012', 'icone' => 'award',           'titulo' => 'Primeira formatura',            'texto' => 'Em 10 de fevereiro, a escola celebra a formatura de 100 estudantes dos cursos de Enfermagem, Informática e Finanças.'],
    ['ano' => '2013', 'icone' => 'bricks',          'titulo' => 'Construção de um novo futuro',  'texto' => 'Tem início a construção da nova sede, planejada para oferecer uma estrutura moderna e adequada ao ensino profissional.'],
    ['ano' => '2014', 'icone' => 'buildings',       'titulo' => 'Inauguração da nova sede',      'texto' => 'Em 2 de setembro, a escola inaugura seu novo prédio, com salas de aula, laboratórios e espaços para formação integral.'],
    ['ano' => '2014', 'icone' => 'bank',            'titulo' => 'Uma nova identidade',           'texto' => 'A Lei Estadual nº 15.619 oficializa o nome Venceslau Vieira Batista, homenageando uma personalidade ligada à história de Boa Viagem.'],
    ['ano' => '2015', 'icone' => 'patch-check',     'titulo' => 'Consolidação da identidade',    'texto' => 'A instituição passa a utilizar a denominação EEEP Venceslau Vieira Batista, fortalecendo sua identidade na educação profissional.'],
    ['ano' => '2024', 'icone' => 'code-slash',      'titulo' => 'Desenvolvimento de Sistemas',   'texto' => 'O curso técnico em Desenvolvimento de Sistemas integra a oferta de ingresso, ampliando as oportunidades na área de programação e tecnologia.'],
    ['ano' => '2026', 'icone' => 'stars',           'titulo' => 'Uma história que continua',     'texto' => 'A escola completa 17 anos de educação profissional, formando jovens nas áreas de gestão, saúde e tecnologia.'],
];

/* Cursos técnicos */
$CURSOS_SITE = [
    [
        'nome'    => 'Informática',
        'imagem'  => 'assets/img/curso-informatica.jpg',
        'resumo'  => 'Forma técnicos para montar, configurar e dar suporte a computadores e redes, além de criar soluções simples de software.',
        'aprende' => ['Montagem e manutenção de computadores', 'Redes de computadores', 'Sistemas operacionais', 'Lógica de programação e banco de dados'],
        'atuacao' => 'Suporte técnico, empresas de informática, provedores de internet e setores de TI.',
    ],
    [
        'nome'    => 'Desenvolvimento de Sistemas',
        'imagem'  => 'assets/img/curso-ds.jpg',
        'resumo'  => 'Prepara para criar sites, aplicativos e sistemas, do planejamento ao código e ao banco de dados.',
        'aprende' => ['Lógica e linguagens de programação', 'Desenvolvimento web', 'Banco de dados', 'Engenharia de software e trabalho em equipe'],
        'atuacao' => 'Fábricas de software, startups, setores de TI e trabalho como desenvolvedor autônomo.',
    ],
    [
        'nome'    => 'Enfermagem',
        'imagem'  => 'assets/img/curso-enfermagem.jpg',
        'resumo'  => 'Forma técnicos para o cuidado com a saúde das pessoas, com aulas práticas e estágio em unidades de saúde.',
        'aprende' => ['Anatomia e fisiologia humana', 'Fundamentos de enfermagem', 'Primeiros socorros', 'Saúde coletiva e farmacologia'],
        'atuacao' => 'Hospitais, postos de saúde, clínicas, laboratórios e atendimento domiciliar.',
    ],
    [
        'nome'    => 'Administração',
        'imagem'  => 'assets/img/curso-administracao.jpg',
        'resumo'  => 'Ensina a organizar e gerir rotinas de empresas: finanças, pessoas, vendas e planejamento.',
        'aprende' => ['Rotinas administrativas', 'Contabilidade e matemática financeira', 'Gestão de pessoas', 'Marketing e empreendedorismo'],
        'atuacao' => 'Empresas de todos os portes, comércio, bancos, órgãos públicos e o próprio negócio.',
    ],
];

/* Estrutura da escola */
$ESTRUTURA = [ // [EDITAR] ajuste para a realidade da escola
    ['icone' => 'pc-display',       'titulo' => 'Laboratórios de informática',   'texto' => 'Computadores para as aulas práticas de tecnologia.'],
    ['icone' => 'heart-pulse',      'titulo' => 'Laboratório de enfermagem',     'texto' => 'Simula o ambiente de saúde para treinar procedimentos.'],
    ['icone' => 'lightning-charge', 'titulo' => 'Laboratório de física',         'texto' => 'Experimentos de mecânica, eletricidade e óptica.'],
    ['icone' => 'eyedropper',       'titulo' => 'Laboratório de química',        'texto' => 'Práticas com reagentes, vidrarias e segurança.'],
    ['icone' => 'flower1',          'titulo' => 'Laboratório de biologia',       'texto' => 'Microscópios e estudo dos seres vivos.'],
    ['icone' => 'calculator',       'titulo' => 'Laboratório de matemática',     'texto' => 'Materiais concretos e jogos para aprender fazendo.'],
    ['icone' => 'translate',        'titulo' => 'Laboratório de línguas',        'texto' => 'Inglês e espanhol com áudio e conversação.'],
    ['icone' => 'book',             'titulo' => 'Biblioteca',                    'texto' => 'Acervo para pesquisa, leitura e estudo em grupo.'],
    ['icone' => 'mic-fill',         'titulo' => 'Auditório',                     'texto' => 'Palestras, reuniões e apresentações.'],
    ['icone' => 'columns-gap',      'titulo' => 'Anfiteatro',                    'texto' => 'Espaço aberto para eventos e atividades culturais.'],
    ['icone' => 'dribbble',         'titulo' => 'Quadra poliesportiva',          'texto' => 'Educação física, jogos escolares e eventos.'],
    ['icone' => 'cup-hot',          'titulo' => 'Refeitório',                    'texto' => 'Três refeições por dia no tempo integral.'],
];

/* Galeria de fotos */
$GALERIA = [ // [EDITAR] legendas
    ['imagem' => 'assets/img/galeria-1.jpg', 'legenda' => 'Fachada da escola'],
    ['imagem' => 'assets/img/galeria-2.jpg', 'legenda' => 'Aula prática no laboratório'],
    ['imagem' => 'assets/img/galeria-3.jpg', 'legenda' => 'Feira de ciências'],
    ['imagem' => 'assets/img/galeria-4.jpg', 'legenda' => 'Jogos escolares'],
    ['imagem' => 'assets/img/galeria-5.jpg', 'legenda' => 'Formatura dos técnicos'],
    ['imagem' => 'assets/img/galeria-6.jpg', 'legenda' => 'Estágio supervisionado'],
];

/* Banners do carrossel usados enquanto a escola não cadastrar os seus em Painel › Site da escola › Banners.
 * 'fim' = último dia em que aparece (vazio = sempre). Artes: 1920 × 400 px (computador) e 1600 × 900 px (celular). */
$BANNERS_PADRAO = [
    ['imagem' => 'assets/img/banners/banner-matriculas-2027.jpg', 'larga' => 'assets/img/banners/banner-largo-matriculas-2027.jpg', 'titulo' => 'Inscrições abertas: matrículas 2027', 'link' => 'informes.php', 'fim' => ''],
    ['imagem' => 'assets/img/banners/banner-enem-2026.jpg', 'larga' => 'assets/img/banners/banner-largo-enem-2026.jpg', 'titulo' => 'ENEM 2026: boa prova, 3º ano! 8 e 15 de novembro', 'link' => '', 'fim' => '2026-11-15'],
    ['imagem' => 'assets/img/banners/banner-vestibular-uece.jpg', 'larga' => 'assets/img/banners/banner-largo-vestibular-uece.jpg', 'titulo' => 'Vestibular UECE: 2ª fase em 6 e 7 de dezembro', 'link' => '', 'fim' => '2026-12-07'],
    ['imagem' => 'assets/img/banners/banner-venha-ser-eeep.jpg', 'larga' => 'assets/img/banners/banner-largo-venha-ser-eeep.jpg', 'titulo' => 'Venha ser EEEP! Ensino médio + técnico em tempo integral', 'link' => '#cursos', 'fim' => ''],
];
