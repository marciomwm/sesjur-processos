-- =====================================================================
-- Migracao 2026-09-30 - Agentes Publicos, Prioridades, Produtos de Saude
-- (SIGTAP) e Processos Extrajudiciais.
--
-- Para bancos criados antes desta data (aplicar DEPOIS de
-- migracao_2026-09-29_unidades_administrativas.sql). Cria tabelas novas,
-- acrescenta usuarios.agente_publico_id e cria um Agente Publico para cada
-- usuario existente (mesmo nome, e-mail, matricula, cargo, unidade e
-- nascimento), ligando os dois. Nenhum dado existente e apagado.
-- Execute uma unica vez. Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-09-30_agentes_prioridades_produtos_extrajudiciais.sql
-- =====================================================================

USE `gestao_juridica`;
-- Valores iniciais com acentos: o cliente mysql do Windows nao usa UTF-8 por padrao.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Agentes Publicos: cadastro centralizado das pessoas com vinculo
-- funcional ou profissional com a Administracao (efetivos, comissionados,
-- estagiarios, terceirizados...). E a base dos usuarios do sistema, mas nem
-- todo agente tem acesso. O orgao/entidade nao e gravado aqui: e a
-- instituicao da Unidade Administrativa (lotacao_id), sem duplicar dado.
-- Sem exclusao fisica: o desligamento e a situacao DESLIGADO.
-- criado_por/atualizado_por recebem a FK depois da tabela usuarios.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agentes_publicos` (
    `id`                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `nome`                VARCHAR(150)      NOT NULL,
    `nome_social`         VARCHAR(150)      NULL,
    `cpf`                 CHAR(11)          NULL COMMENT 'Somente digitos',
    `data_nascimento`     DATE              NULL,
    `email_institucional` VARCHAR(254)      NULL COMMENT 'Sempre em minusculas',
    `email_alternativo`   VARCHAR(254)      NULL,
    `telefone`            VARCHAR(11)       NULL COMMENT 'Somente digitos, com DDD',
    `matricula`           VARCHAR(30)       NULL COMMENT 'Texto: preserva zeros a esquerda',
    `tipo_vinculo`        ENUM('EFETIVO','COMISSIONADO','EMPREGADO_PUBLICO','ESTAGIARIO','RESIDENTE_TECNICO',
                               'TERCEIRIZADO','CEDIDO','COLABORADOR','OUTRO') NOT NULL DEFAULT 'OUTRO',
    `cargo_id`            SMALLINT UNSIGNED NULL,
    `funcao`              VARCHAR(120)      NULL COMMENT 'Funcao exercida (texto livre), ex.: Coordenador',
    `lotacao_id`          SMALLINT UNSIGNED NOT NULL COMMENT 'Unidade Administrativa de lotacao',
    `data_ingresso`       DATE              NULL,
    `data_desligamento`   DATE              NULL,
    `situacao`            ENUM('ATIVO','AFASTADO','DESLIGADO','OUTRO') NOT NULL DEFAULT 'ATIVO',
    `observacoes`         TEXT              NULL,
    `criado_por`          INT UNSIGNED      NULL,
    `atualizado_por`      INT UNSIGNED      NULL,
    `created_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agentes_cpf` (`cpf`),
    UNIQUE KEY `uq_agentes_matricula` (`matricula`),
    UNIQUE KEY `uq_agentes_email` (`email_institucional`),
    KEY `ix_agentes_nome` (`nome`),
    KEY `ix_agentes_lotacao` (`lotacao_id`),
    KEY `ix_agentes_cargo` (`cargo_id`),
    KEY `ix_agentes_vinculo` (`tipo_vinculo`),
    KEY `ix_agentes_situacao` (`situacao`),
    CONSTRAINT `ck_agentes_datas` CHECK (`data_desligamento` IS NULL OR `data_ingresso` IS NULL OR `data_desligamento` >= `data_ingresso`),
    CONSTRAINT `fk_agentes_cargo`
        FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_agentes_lotacao`
        FOREIGN KEY (`lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `agentes_publicos`
    ADD CONSTRAINT `fk_agentes_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_agentes_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `usuarios`
    ADD COLUMN `agente_publico_id` INT UNSIGNED NULL COMMENT 'Agente publico titular da conta (1:1)' AFTER `id`,
    ADD UNIQUE KEY `uq_usuarios_agente` (`agente_publico_id`),
    ADD CONSTRAINT `fk_usuarios_agente`
        FOREIGN KEY (`agente_publico_id`) REFERENCES `agentes_publicos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Um agente para cada usuario existente. O tipo de vinculo so e deduzido
-- quando o cargo o diz (estagiario, residente); os demais ficam "Outro" e
-- devem ser revisados na tela Agentes Publicos.
INSERT INTO `agentes_publicos`
    (`nome`, `email_institucional`, `matricula`, `cargo_id`, `lotacao_id`, `data_nascimento`,
     `tipo_vinculo`, `situacao`, `created_at`)
SELECT u.`nome`, u.`email`, u.`matricula`, u.`cargo_id`, u.`lotacao_id`, u.`data_nascimento`,
       CASE c.`nome` WHEN 'Estagiário' THEN 'ESTAGIARIO' WHEN 'Residente Técnico' THEN 'RESIDENTE_TECNICO' ELSE 'OUTRO' END,
       IF(u.`ativo` = 1, 'ATIVO', 'DESLIGADO'), u.`created_at`
  FROM `usuarios` u
  JOIN `cargos` c ON c.`id` = u.`cargo_id`
 WHERE u.`agente_publico_id` IS NULL
   AND NOT EXISTS (SELECT 1 FROM `agentes_publicos` a WHERE a.`matricula` = u.`matricula`);

UPDATE `usuarios` u
  JOIN `agentes_publicos` a ON a.`matricula` = u.`matricula`
   SET u.`agente_publico_id` = a.`id`
 WHERE u.`agente_publico_id` IS NULL;

-- ---------------------------------------------------------------------
-- Prioridades: criterios (Idoso, Determinacao Judicial...) com nivel de 1
-- (baixa) a 5 (maxima), atribuidos aos processos administrativos (N:N).
-- Sem exclusao: prioridade em desuso e inativada e continua valendo nos
-- processos que ja a possuem.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prioridades` (
    `id`             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`           VARCHAR(100)      NOT NULL,
    `nivel`          TINYINT UNSIGNED  NOT NULL COMMENT '1 = baixa ... 5 = maxima',
    `descricao`      VARCHAR(500)      NULL,
    `ativo`          TINYINT(1)        NOT NULL DEFAULT 1,
    `criado_por`     INT UNSIGNED      NULL,
    `atualizado_por` INT UNSIGNED      NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_prioridades_nome` (`nome`),
    KEY `ix_prioridades_nivel` (`nivel`, `ativo`),
    CONSTRAINT `ck_prioridades_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `ck_prioridades_nivel` CHECK (`nivel` BETWEEN 1 AND 5),
    CONSTRAINT `fk_prioridades_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_prioridades_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prioridades de cada processo administrativo (N:N, sem repeticao).
-- Remover a atribuicao nunca exclui a prioridade; fica na auditoria.
CREATE TABLE IF NOT EXISTS `processo_administrativo_prioridades` (
    `id`                         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED      NOT NULL,
    `prioridade_id`              SMALLINT UNSIGNED NOT NULL,
    `observacao`                 VARCHAR(255)      NULL,
    `criado_por`                 INT UNSIGNED      NULL,
    `created_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_papr_par` (`processo_administrativo_id`, `prioridade_id`),
    KEY `ix_papr_prioridade` (`prioridade_id`),
    CONSTRAINT `fk_papr_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_papr_prioridade`
        FOREIGN KEY (`prioridade_id`) REFERENCES `prioridades` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_papr_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Classificacao SIGTAP (Tabela de Procedimentos, Medicamentos e OPM do
-- SUS). Hoje preenchida manualmente pelo cadastro dos produtos de saude;
-- preparada para a importacao da tabela oficial: codigo de 10 digitos
-- (GG SS FF PPP D), estrutura grupo > subgrupo > forma de organizacao e a
-- competencia (AAAAMM) da tabela importada. Os codigos da estrutura sao
-- derivados do proprio codigo do procedimento.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sigtap_procedimentos` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo`                   CHAR(10)     NOT NULL COMMENT 'Somente digitos, ex.: 0301010072',
    `nome`                     VARCHAR(250) NOT NULL COMMENT 'Descricao oficial do procedimento',
    `grupo_codigo`             CHAR(2)      NOT NULL,
    `grupo_nome`               VARCHAR(150) NULL,
    `subgrupo_codigo`          CHAR(2)      NOT NULL,
    `subgrupo_nome`            VARCHAR(150) NULL,
    `forma_organizacao_codigo` CHAR(2)      NOT NULL,
    `forma_organizacao_nome`   VARCHAR(150) NULL,
    `competencia`              CHAR(6)      NULL COMMENT 'AAAAMM da tabela oficial importada; NULL = cadastro manual',
    `origem`                   ENUM('MANUAL','SIGTAP') NOT NULL DEFAULT 'MANUAL',
    `ativo`                    TINYINT(1)   NOT NULL DEFAULT 1,
    `criado_por`               INT UNSIGNED NULL,
    `atualizado_por`           INT UNSIGNED NULL,
    `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sigtap_codigo` (`codigo`),
    KEY `ix_sigtap_nome` (`nome`),
    KEY `ix_sigtap_estrutura` (`grupo_codigo`, `subgrupo_codigo`, `forma_organizacao_codigo`),
    CONSTRAINT `ck_sigtap_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_sigtap_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sigtap_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produtos de saude (medicamento, procedimento, exame...) objeto do
-- processo administrativo. Um processo tem N produtos; o mesmo item nao se
-- repete no processo. Alterados por administrador, gestor ou quem os criou.
CREATE TABLE IF NOT EXISTS `processo_administrativo_produtos_saude` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `categoria`                  ENUM('MEDICAMENTO','MATERIAL','PROCEDIMENTO','EXAME','CONSULTA','CIRURGIA','ORTESE',
                                      'PROTESE','INSUMO','EQUIPAMENTO','TRANSPORTE_SANITARIO','OUTRO') NOT NULL,
    `descricao`                  VARCHAR(500) NOT NULL,
    `sigtap_procedimento_id`     INT UNSIGNED NULL,
    `criado_por`                 INT UNSIGNED NULL,
    `atualizado_por`             INT UNSIGNED NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_paps_item` (`processo_administrativo_id`, `categoria`, `descricao`),
    KEY `ix_paps_categoria` (`categoria`),
    KEY `ix_paps_sigtap` (`sigtap_procedimento_id`),
    CONSTRAINT `fk_paps_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paps_sigtap`
        FOREIGN KEY (`sigtap_procedimento_id`) REFERENCES `sigtap_procedimentos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_paps_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_paps_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Processos Extrajudiciais: procedimentos originados em instituicoes
-- externas ao Poder Executivo estadual (Ministerio Publico, Defensoria,
-- Tribunal de Contas, conselhos...). Instituicao Externa e Orgao Externo
-- (unidade interna da instituicao) sao tabelas de apoio proprias, para
-- nao misturar a estrutura externa com as Unidades Administrativas.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `instituicoes_externas` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(150) NOT NULL,
    `sigla`      VARCHAR(30)  NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_instituicoes_externas_nome` (`nome`),
    UNIQUE KEY `uq_instituicoes_externas_sigla` (`sigla`),
    CONSTRAINT `ck_instituicoes_externas_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orgaos_externos` (
    `id`                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `instituicao_externa_id` SMALLINT UNSIGNED NOT NULL,
    `nome`                   VARCHAR(150)      NOT NULL,
    `ativo`                  TINYINT(1)        NOT NULL DEFAULT 1,
    `created_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orgaos_externos_nome` (`instituicao_externa_id`, `nome`),
    CONSTRAINT `ck_orgaos_externos_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_orgaos_externos_instituicao`
        FOREIGN KEY (`instituicao_externa_id`) REFERENCES `instituicoes_externas` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O protocolo e o da instituicao de origem (formato livre) e se repete
-- apenas entre instituicoes diferentes. O orgao, quando informado, e da
-- mesma instituicao (regra no ProcessoExtrajudicialService).
CREATE TABLE IF NOT EXISTS `processos_extrajudiciais` (
    `id`                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `protocolo`              VARCHAR(80)       NOT NULL COMMENT 'Numero na instituicao de origem, ex.: SIMP 000123-001/2026',
    `data_expediente`        DATE              NOT NULL,
    `instituicao_externa_id` SMALLINT UNSIGNED NOT NULL,
    `orgao_externo_id`       SMALLINT UNSIGNED NULL,
    `assunto`                VARCHAR(255)      NOT NULL,
    `descricao`              TEXT              NULL,
    `situacao`               VARCHAR(30)       NOT NULL DEFAULT 'EM_ANDAMENTO'
        COMMENT 'EM_ANDAMENTO | PENDENTE_RESPOSTA | RESPONDIDO | ARQUIVADO | OUTRA',
    `observacoes`            TEXT              NULL,
    `criado_por`             INT UNSIGNED      NULL,
    `atualizado_por`         INT UNSIGNED      NULL,
    `created_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pe_protocolo` (`instituicao_externa_id`, `protocolo`),
    KEY `ix_pe_protocolo` (`protocolo`),
    KEY `ix_pe_data` (`data_expediente`),
    KEY `ix_pe_orgao` (`orgao_externo_id`),
    KEY `ix_pe_situacao` (`situacao`),
    CONSTRAINT `fk_pe_instituicao`
        FOREIGN KEY (`instituicao_externa_id`) REFERENCES `instituicoes_externas` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pe_orgao`
        FOREIGN KEY (`orgao_externo_id`) REFERENCES `orgaos_externos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pe_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pe_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relacionamento N:N Administrativo <-> Extrajudicial, espelho do vinculo
-- com o judicial. A remocao do vinculo NAO remove nenhum dos processos.
CREATE TABLE IF NOT EXISTS `processo_administrativo_extrajudicial` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `processo_extrajudicial_id`  INT UNSIGNED NOT NULL,
    `data_vinculo`               DATE         NOT NULL DEFAULT (CURRENT_DATE),
    `observacao`                 VARCHAR(255) NULL,
    `criado_por`                 INT UNSIGNED NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pae_par` (`processo_administrativo_id`, `processo_extrajudicial_id`),
    KEY `ix_pae_extrajudicial` (`processo_extrajudicial_id`),
    CONSTRAINT `fk_paex_administrativo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paex_extrajudicial`
        FOREIGN KEY (`processo_extrajudicial_id`) REFERENCES `processos_extrajudiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paex_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `prioridades` (`nome`, `nivel`, `descricao`) VALUES
    ('Idoso', 3, 'Parte interessada com 60 anos ou mais (Estatuto da Pessoa Idosa).'),
    ('Determinação Judicial', 5, 'Há ordem judicial a cumprir no processo.'),
    ('Risco à vida', 5, 'Relato de risco iminente à vida ou à saúde do paciente.'),
    ('Pessoa com deficiência', 3, 'Parte interessada com deficiência.'),
    ('Criança ou adolescente', 3, 'Paciente menor de 18 anos.');

INSERT IGNORE INTO `instituicoes_externas` (`nome`, `sigla`) VALUES
    ('Ministério Público do Estado de Mato Grosso', 'MP-MT'),
    ('Defensoria Pública do Estado de Mato Grosso', 'DPE-MT'),
    ('Tribunal de Contas do Estado de Mato Grosso', 'TCE-MT');

INSERT IGNORE INTO `orgaos_externos` (`instituicao_externa_id`, `nome`)
SELECT i.`id`, o.`nome`
  FROM `instituicoes_externas` i
  JOIN (SELECT 'MP-MT' AS sigla, '1ª Promotoria de Justiça de Cuiabá' AS nome
        UNION ALL SELECT 'DPE-MT', 'Núcleo de Defesa da Saúde'
        UNION ALL SELECT 'TCE-MT', 'Secretaria de Controle Externo de Saúde') o ON o.sigla = i.`sigla`;
