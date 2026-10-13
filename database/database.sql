-- =====================================================================
-- Gestao Juridica - Estrutura do banco de dados
-- MariaDB / MySQL - charset utf8mb4
-- Etapa 1 - Modelagem
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `gestao_juridica`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `gestao_juridica`;
-- Valores iniciais com acentos: o cliente mysql do Windows nao usa UTF-8 por padrao.
SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `recurso_categorias`;
DROP TABLE IF EXISTS `recursos`;
DROP TABLE IF EXISTS `categorias_recurso`;
DROP TABLE IF EXISTS `tipos_recurso`;
DROP TABLE IF EXISTS `processo_administrativo_extrajudicial`;
DROP TABLE IF EXISTS `processos_extrajudiciais`;
DROP TABLE IF EXISTS `orgaos_externos`;
DROP TABLE IF EXISTS `instituicoes_externas`;
DROP TABLE IF EXISTS `processo_administrativo_produtos_saude`;
DROP TABLE IF EXISTS `sigtap_procedimentos`;
DROP TABLE IF EXISTS `processo_administrativo_prioridades`;
DROP TABLE IF EXISTS `prioridades`;
DROP TABLE IF EXISTS `agentes_publicos`;
DROP TABLE IF EXISTS `processo_administrativo_anotacoes`;
DROP TABLE IF EXISTS `processo_administrativo_solicitacoes`;
DROP TABLE IF EXISTS `processo_administrativo_acoes`;
DROP TABLE IF EXISTS `processo_judicial_designacoes`;
DROP TABLE IF EXISTS `processo_administrativo_designacoes`;
DROP TABLE IF EXISTS `usuario_credenciais`;
DROP TABLE IF EXISTS `processo_administrativo_eventos`;
DROP TABLE IF EXISTS `processo_administrativo_judicial`;
DROP TABLE IF EXISTS `processo_judicial_documentos`;
DROP TABLE IF EXISTS `processo_judicial_movimentacoes`;
DROP TABLE IF EXISTS `partes_processuais`;
DROP TABLE IF EXISTS `assuntos_processuais`;
DROP TABLE IF EXISTS `processos_judiciais`;
DROP TABLE IF EXISTS `processos_administrativos`;
DROP TABLE IF EXISTS `configuracoes`;
DROP TABLE IF EXISTS `auditoria`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `cargos`;
DROP TABLE IF EXISTS `lotacao_servidores`;
DROP TABLE IF EXISTS `lotacoes`;
DROP TABLE IF EXISTS `niveis_organizacionais`;
DROP TABLE IF EXISTS `instituicoes`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Processos Administrativos (origem: SIGADOC)
-- ---------------------------------------------------------------------
CREATE TABLE `processos_administrativos` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `protocolo`      VARCHAR(60)     NOT NULL COMMENT 'Ex.: SEPLAG-PRO-2026/16696',
    `data_abertura`  DATE            NULL,
    `objeto`         TEXT            NULL,
    `procedimento`   VARCHAR(160)    NULL COMMENT 'Ex.: Fornecimento de Produto de Saude, Ressarcimento Interfederativo',
    `origem`         ENUM('MANUAL','SIGADOC') NOT NULL DEFAULT 'MANUAL',
    `url_origem`     VARCHAR(500)    NULL,
    `extrator_versao` VARCHAR(20)    NULL,
    `data_extracao`  DATETIME        NULL,
    `situacao`       VARCHAR(30)     NULL COMMENT 'AGUARDANDO_ANDAMENTO | SOBRESTADO | ARQUIVADO | EM_TRANSITO | OUTRA',
    `situacao_descricao` VARCHAR(120) NULL COMMENT 'Rotulo oficial do SIGADOC, ex.: Arquivo Corrente',
    `responsavel_nome`   VARCHAR(180) NULL COMMENT 'Agente publico com o processo',
    `responsavel_matricula` VARCHAR(30) NULL,
    `responsavel_lotacao`   VARCHAR(60) NULL COMMENT 'Sigla da lotacao',
    `responsavel_lotacao_nome` VARCHAR(180) NULL,
    `prazo`          DATE            NULL COMMENT 'Prazo proprio; padrao: abertura + 30 dias',
    `prazo_hora`     TIME            NULL COMMENT 'NULL = ate o fim do dia',
    `prazo_vigente`      DATE        NULL COMMENT 'Calculado: o mais proximo entre o prazo proprio e o das acoes nao finalizadas',
    `prazo_vigente_hora` TIME        NULL,
    `ativo`          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Calculado: 1 = ha acao nao finalizada',
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pa_protocolo` (`protocolo`),
    KEY `ix_pa_data_abertura` (`data_abertura`),
    KEY `ix_pa_prazo_vigente` (`prazo_vigente`, `prazo_vigente_hora`),
    KEY `ix_pa_ativo` (`ativo`),
    KEY `ix_pa_situacao` (`situacao`),
    KEY `ix_pa_procedimento` (`procedimento`),
    KEY `ix_pa_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Movimentacoes do processo administrativo (juntada, anotacao,
-- transferencia, recebimento, sobrestamento, arquivamento...).
-- Espelho do SIGADOC: substituido integralmente a cada extracao.
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_eventos` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `data_evento`       DATE         NULL,
    `tipo`              VARCHAR(30)  NOT NULL DEFAULT 'OUTRO' COMMENT 'JUNTADA, ANOTACAO, TRANSFERENCIA...',
    `tipo_descricao`    VARCHAR(80)  NULL COMMENT 'Rotulo exibido pelo SIGADOC',
    `lotacao`           VARCHAR(60)  NULL COMMENT 'Lotacao do cadastrante',
    `responsavel`       VARCHAR(180) NULL COMMENT 'Cadastrante',
    `atendente_lotacao` VARCHAR(60)  NULL,
    `atendente`         VARCHAR(180) NULL,
    `documento`         VARCHAR(60)  NULL COMMENT 'Sigla do documento juntado/apensado',
    `descricao`         TEXT         NULL,
    `ordem`             SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = mais recente',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pae_processo` (`processo_administrativo_id`, `ordem`),
    KEY `ix_pae_tipo` (`tipo`),
    CONSTRAINT `fk_pae_processo_administrativo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Processos Judiciais (origem: PJe)
-- ---------------------------------------------------------------------
CREATE TABLE `processos_judiciais` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `numero_cnj`        VARCHAR(25)   NOT NULL COMMENT 'Ex.: 1008695-17.2026.8.11.0006',
    `tribunal`          VARCHAR(120)  NULL,
    `orgao_julgador`    VARCHAR(180)  NULL,
    `cargo_judicial`    VARCHAR(120)  NULL COMMENT 'Ex.: Juiz Titular',
    `competencia`       VARCHAR(255)  NULL COMMENT 'Competencia do orgao julgador',
    `jurisdicao`        VARCHAR(180)  NULL,
    `classe_judicial`   VARCHAR(180)  NULL,
    `classe_codigo`     VARCHAR(20)   NULL COMMENT 'Codigo CNJ da classe, ex.: 156',
    `classe_abreviada`  VARCHAR(30)   NULL COMMENT 'Sigla da classe no cabecalho do PJe, ex.: ProceComCiv',
    `assunto_principal` VARCHAR(255)  NULL,
    `resumo_partes`     VARCHAR(500)  NULL COMMENT 'Resumo "Polo ativo X Polo passivo" do cabecalho',
    `data_autuacao`     DATE          NULL COMMENT 'Autuacao original (distinta da ultima distribuicao)',
    `data_distribuicao` DATE          NULL COMMENT 'Ultima distribuicao informada pela fonte',
    `valor_causa`       DECIMAL(15,2) NULL,
    `situacao`          VARCHAR(120)  NULL,
    `segredo_justica`   TINYINT(1)    NULL COMMENT 'NULL = informacao nao disponivel',
    `justica_gratuita`  TINYINT(1)    NULL COMMENT 'NULL = informacao nao disponivel',
    `tutela_liminar`    TINYINT(1)    NULL COMMENT 'NULL = informacao nao disponivel',
    `prioridade`        TINYINT(1)    NULL COMMENT 'NULL = informacao nao disponivel',
    `prioridade_descricao` VARCHAR(100) NULL COMMENT 'Motivo da prioridade, ex.: Idoso',
    `resumo`            TEXT          NULL,
    `observacoes`       TEXT          NULL,
    `origem`            ENUM('MANUAL','PJE') NOT NULL DEFAULT 'MANUAL',
    `url_origem`        VARCHAR(500)  NULL,
    `extrator_versao`   VARCHAR(20)   NULL,
    `data_extracao`     DATETIME      NULL,
    `data_ultima_atualizacao` DATE    NULL COMMENT 'Ultima atualizacao informada pela fonte',
    `historico_completo` TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '1 = a cronologia importada cobre todo o processo; 0 = parcial (timeline paginada)',
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pj_numero_cnj` (`numero_cnj`),
    KEY `ix_pj_tribunal` (`tribunal`),
    KEY `ix_pj_situacao` (`situacao`),
    KEY `ix_pj_data_distribuicao` (`data_distribuicao`),
    KEY `ix_pj_data_autuacao` (`data_autuacao`),
    KEY `ix_pj_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Assuntos processuais (principal + secundarios) - normalizado
-- ---------------------------------------------------------------------
CREATE TABLE `assuntos_processuais` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `descricao`           VARCHAR(255) NOT NULL,
    `codigo`              VARCHAR(40)  NULL COMMENT 'Codigo CNJ do assunto, quando exibido',
    `principal`           TINYINT(1)   NOT NULL DEFAULT 0,
    `ordem`               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ap_processo` (`processo_judicial_id`),
    KEY `ix_ap_descricao` (`descricao`),
    CONSTRAINT `fk_ap_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Partes processuais
-- ---------------------------------------------------------------------
CREATE TABLE `partes_processuais` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `nome`                 VARCHAR(255) NOT NULL,
    `documento`            VARCHAR(40)  NULL COMMENT 'CPF/CNPJ quando legitimamente exibido',
    `polo`                 ENUM('ATIVO','PASSIVO','TERCEIRO','OUTRO') NOT NULL DEFAULT 'OUTRO',
    `tipo`                 VARCHAR(120) NULL COMMENT 'Autor, Reu, Exequente, Interessado, etc.',
    `representacao`        TEXT         NULL COMMENT 'Tipo: nome de cada representante, separados por ";"',
    `observacoes`          TEXT         NULL,
    `ordem`                SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pp_processo` (`processo_judicial_id`),
    KEY `ix_pp_nome` (`nome`),
    KEY `ix_pp_polo` (`polo`),
    CONSTRAINT `fk_pp_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Cronologia do processo judicial (timeline do PJe).
-- Espelho da fonte: cada extracao confirmada substitui a lista inteira,
-- preservando a ordem de exibicao (ordem 0 = mais recente).
-- Data e hora sao colunas separadas porque a fonte as exibe assim: a data
-- vem do separador do grupo e a hora do proprio item.
-- ---------------------------------------------------------------------
CREATE TABLE `processo_judicial_movimentacoes` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `data_movimentacao`    DATE         NULL,
    `hora`                 TIME         NULL,
    `tipo`                 VARCHAR(20)  NOT NULL DEFAULT 'MOVIMENTO' COMMENT 'MOVIMENTO | DOCUMENTO',
    `descricao`            TEXT         NULL COMMENT 'Texto do movimento processual',
    `documento_id`         VARCHAR(30)  NULL COMMENT 'Numero do documento juntado, ex.: 249562011',
    `documento_tipo`       VARCHAR(120) NULL COMMENT 'Especie do documento, ex.: Sentenca',
    `natureza`             VARCHAR(60)  NULL COMMENT 'Interno | Externo | Polo passivo | Movimento processual lancado',
    `origem`               VARCHAR(20)  NULL COMMENT 'SISTEMA | INTERNO | POLO_ATIVO | POLO_PASSIVO | EXTERNO | OUTROS',
    `ordem`                SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = mais recente',
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pjm_processo` (`processo_judicial_id`, `ordem`),
    KEY `ix_pjm_tipo` (`tipo`),
    KEY `ix_pjm_data` (`data_movimentacao`),
    CONSTRAINT `fk_pjm_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Documentos do processo judicial, com a integra das pecas decisorias.
-- Registro unico por (processo, numero do documento), atualizado por upsert:
-- ao contrario da cronologia, NAO e substituido a cada extracao, e a integra
-- ja obtida so e trocada por uma nova leitura.
-- movimentacao_id vira NULL quando a cronologia e substituida sem o evento.
-- ---------------------------------------------------------------------
CREATE TABLE `processo_judicial_documentos` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `movimentacao_id`      INT UNSIGNED NULL,
    `documento_id`         VARCHAR(30)  NOT NULL COMMENT 'Numero do documento no PJe, ex.: 249562011',
    `tipo`                 VARCHAR(120) NULL COMMENT 'Especie, ex.: Sentenca',
    `classificacao`        VARCHAR(20)  NULL COMMENT 'SENTENCA | DECISAO | ACORDAO | DESPACHO | EMBARGOS; NULL = nao decisoria',
    `data_documento`       DATE         NULL COMMENT 'Data do evento que juntou o documento',
    `hora`                 TIME         NULL,
    `juntado_por`          VARCHAR(180) NULL,
    `data_juntada`         DATETIME     NULL,
    `texto_integral`       MEDIUMTEXT   NULL,
    `metodo_extracao`      VARCHAR(20)  NULL COMMENT 'IFRAME_HTML | FETCH_HTML | PDF_SEM_TEXTO | NAO_LOCALIZADO | FALHA',
    `integra_extraida_em`  DATETIME     NULL,
    `ordem`                SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = mais recente',
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pjd_documento` (`processo_judicial_id`, `documento_id`),
    KEY `ix_pjd_classificacao` (`classificacao`),
    KEY `ix_pjd_movimentacao` (`movimentacao_id`),
    CONSTRAINT `fk_pjd_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pjd_movimentacao`
        FOREIGN KEY (`movimentacao_id`) REFERENCES `processo_judicial_movimentacoes` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Relacionamento N:N Administrativo <-> Judicial
-- A remocao do vinculo NAO remove nenhum dos processos.
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_judicial` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `processo_judicial_id`      INT UNSIGNED NOT NULL,
    `data_vinculo`              DATE         NOT NULL DEFAULT (CURRENT_DATE),
    `observacao`                VARCHAR(255) NULL,
    `created_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_paj_par` (`processo_administrativo_id`, `processo_judicial_id`),
    KEY `ix_paj_judicial` (`processo_judicial_id`),
    CONSTRAINT `fk_paj_administrativo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paj_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Configuracoes da aplicacao (allowlist de dominios, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE `configuracoes` (
    `chave`      VARCHAR(80)  NOT NULL,
    `valor`      TEXT         NULL,
    `descricao`  VARCHAR(255) NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `configuracoes` (`chave`, `valor`, `descricao`) VALUES
    ('sigadoc_hosts', '', 'Hosts autorizados do SIGADOC, separados por virgula. Preencher com o dominio institucional real.'),
    ('pje_hosts',     '', 'Hosts autorizados do PJe, separados por virgula. Preencher com o(s) dominio(s) real(is).'),
    ('exigir_https',  '1', 'Exige HTTPS nas URLs externas (1 = sim, 0 = nao).'),
    ('sigadoc_url_template', '', 'Template opcional para consulta direta de um protocolo no SIGADOC. Substituicoes: {host} e {protocolo}. Vazio = usa o padrao embutido na extensao.');

-- ---------------------------------------------------------------------
-- Tabelas de apoio do cadastro de usuarios. Cargo e lotacao sao
-- referenciados por FK (e nao gravados como texto livre) para evitar
-- variacoes de grafia e permitir renomear uma unidade sem reescrever
-- o historico. Itens obsoletos sao inativados, nunca excluidos.
-- ---------------------------------------------------------------------
CREATE TABLE `cargos` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cargos_nome` (`nome`),
    CONSTRAINT `ck_cargos_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Instituicoes (SES-MT, PGE-MT...) e niveis da estrutura organizacional
-- (Nivel I - Decisao Colegiada...): tabelas de apoio das Unidades
-- Administrativas.
-- ---------------------------------------------------------------------
CREATE TABLE `instituicoes` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(150) NOT NULL,
    `sigla`      VARCHAR(30)  NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_instituicoes_nome` (`nome`),
    UNIQUE KEY `uq_instituicoes_sigla` (`sigla`),
    CONSTRAINT `ck_instituicoes_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `niveis_organizacionais` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_niveis_nome` (`nome`),
    CONSTRAINT `ck_niveis_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Unidades Administrativas (a tabela mantem o nome historico "lotacoes":
-- e a lotacao dos usuarios e o destinatario das solicitacoes).
-- instituicao_id fica NULL apenas em unidades anteriores a 2026-09-29;
-- a aplicacao exige a instituicao ao cadastrar ou editar.
-- ---------------------------------------------------------------------
CREATE TABLE `lotacoes` (
    `id`                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `instituicao_id`      SMALLINT UNSIGNED NULL,
    `nivel_id`            SMALLINT UNSIGNED NULL,
    `unidade_superior_id` SMALLINT UNSIGNED NULL COMMENT 'Unidade imediatamente acima na hierarquia',
    `nome`                VARCHAR(150) NOT NULL,
    `sigla`               VARCHAR(30)  NULL COMMENT 'Sigla da unidade no SIGADOC, ex.: SESUNIJUR',
    `gestor_nome`         VARCHAR(150) NULL COMMENT 'Servidor responsavel pela unidade (texto livre)',
    `gestor_matricula`    VARCHAR(30)  NULL,
    `competencias`        TEXT         NULL COMMENT 'Competencias conforme regimento interno',
    `ativo`               TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lotacoes_nome` (`instituicao_id`, `nome`),
    UNIQUE KEY `uq_lotacoes_sigla` (`sigla`),
    KEY `ix_lotacoes_nivel` (`nivel_id`),
    KEY `ix_lotacoes_superior` (`unidade_superior_id`),
    CONSTRAINT `ck_lotacoes_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_lotacoes_instituicao`
        FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_lotacoes_nivel`
        FOREIGN KEY (`nivel_id`) REFERENCES `niveis_organizacionais` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_lotacoes_superior`
        FOREIGN KEY (`unidade_superior_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Lotacionograma: servidores lotados na unidade, digitados na propria
-- unidade (independem do cadastro de usuarios). Substituido por inteiro
-- a cada gravacao da unidade.
-- ---------------------------------------------------------------------
CREATE TABLE `lotacao_servidores` (
    `id`         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `lotacao_id` SMALLINT UNSIGNED NOT NULL,
    `ordem`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `nome`       VARCHAR(150)      NOT NULL,
    `matricula`  VARCHAR(30)       NULL,
    `cargo`      VARCHAR(100)      NULL COMMENT 'Cargo ou funcao (texto livre)',
    `created_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ls_lotacao` (`lotacao_id`, `ordem`),
    CONSTRAINT `fk_ls_lotacao`
        FOREIGN KEY (`lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Agentes Publicos: cadastro centralizado das pessoas com vinculo
-- funcional ou profissional com a Administracao (efetivos, comissionados,
-- estagiarios, terceirizados...). E a base dos usuarios do sistema, mas nem
-- todo agente tem acesso. O orgao/entidade nao e gravado aqui: e a
-- instituicao da Unidade Administrativa (lotacao_id), sem duplicar dado.
-- Sem exclusao fisica: o desligamento e a situacao DESLIGADO.
-- criado_por/atualizado_por recebem a FK depois da tabela usuarios.
-- ---------------------------------------------------------------------
CREATE TABLE `agentes_publicos` (
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

-- ---------------------------------------------------------------------
-- Usuarios: a credencial de acesso de um Agente Publico (perfil, login e
-- status da conta). nome, matricula, cargo, lotacao e nascimento sao uma
-- copia sincronizada do agente (fonte da verdade), mantida para as
-- consultas de autoria e sessao.
-- Sem exclusao fisica: desligamento = ativo 0. Credenciais de acesso
-- ficam em tabela propria (usuario_credenciais).
-- ---------------------------------------------------------------------
CREATE TABLE `usuarios` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `agente_publico_id` INT UNSIGNED    NULL COMMENT 'Agente publico titular da conta (1:1)',
    `nome`            VARCHAR(150)      NOT NULL,
    `email`           VARCHAR(254)      NOT NULL COMMENT 'Sempre em minusculas; futuro identificador de login',
    `matricula`       VARCHAR(30)       NOT NULL COMMENT 'Texto: preserva zeros a esquerda',
    `cargo_id`        SMALLINT UNSIGNED NOT NULL,
    `lotacao_id`      SMALLINT UNSIGNED NOT NULL,
    `perfil`          ENUM('ADMINISTRADOR','GESTOR','USUARIO') NOT NULL DEFAULT 'USUARIO' COMMENT 'Perfil de acesso',
    `data_nascimento` DATE              NULL,
    `ativo`           TINYINT(1)        NOT NULL DEFAULT 1,
    `inativado_em`    DATETIME          NULL,
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuarios_email` (`email`),
    UNIQUE KEY `uq_usuarios_matricula` (`matricula`),
    UNIQUE KEY `uq_usuarios_agente` (`agente_publico_id`),
    KEY `ix_usuarios_nome` (`nome`),
    KEY `ix_usuarios_ativo` (`ativo`),
    KEY `ix_usuarios_cargo` (`cargo_id`),
    KEY `ix_usuarios_lotacao` (`lotacao_id`),
    KEY `ix_usuarios_perfil` (`perfil`),
    CONSTRAINT `ck_usuarios_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_usuarios_cargo`
        FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_usuarios_lotacao`
        FOREIGN KEY (`lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_usuarios_agente`
        FOREIGN KEY (`agente_publico_id`) REFERENCES `agentes_publicos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `agentes_publicos`
    ADD CONSTRAINT `fk_agentes_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_agentes_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- Trilha de auditoria (somente insercao). Generica por entidade para
-- servir tambem a processos, vinculos e, no futuro, a login.
-- usuario_id = autor da acao; NULL enquanto nao houver autenticacao.
-- ---------------------------------------------------------------------
CREATE TABLE `auditoria` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id`       INT UNSIGNED    NULL,
    `acao`             VARCHAR(40)     NOT NULL COMMENT 'USUARIO_CRIADO, USUARIO_EDITADO, LOGIN_FALHOU...',
    `entidade`         VARCHAR(60)     NOT NULL COMMENT 'Nome da tabela afetada',
    `entidade_id`      BIGINT UNSIGNED NULL,
    `dados_anteriores` JSON            NULL,
    `dados_novos`      JSON            NULL,
    `ip`               VARCHAR(45)     NULL COMMENT 'IPv4 ou IPv6',
    `user_agent`       VARCHAR(255)    NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_auditoria_entidade` (`entidade`, `entidade_id`),
    KEY `ix_auditoria_usuario` (`usuario_id`),
    KEY `ix_auditoria_acao` (`acao`),
    KEY `ix_auditoria_created_at` (`created_at`),
    CONSTRAINT `fk_auditoria_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Credenciais de acesso (1:1 com usuarios). Tabela propria para que o
-- hash da senha nunca apareca em consultas de cadastro/listagem.
-- ---------------------------------------------------------------------
CREATE TABLE `usuario_credenciais` (
    `usuario_id`         INT UNSIGNED      NOT NULL,
    `senha_hash`         VARCHAR(255)      NOT NULL COMMENT 'password_hash(); nunca texto puro',
    `exigir_troca_senha` TINYINT(1)        NOT NULL DEFAULT 1,
    `tentativas_login`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `bloqueado_ate`      DATETIME          NULL COMMENT 'Bloqueio temporario apos tentativas falhas',
    `ultimo_login`       DATETIME          NULL,
    `senha_alterada_em`  DATETIME          NULL,
    `created_at`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`),
    CONSTRAINT `ck_credenciais_troca` CHECK (`exigir_troca_senha` IN (0, 1)),
    CONSTRAINT `fk_credenciais_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Designacao de processos a usuarios para acompanhamento. Um processo
-- pode ter varios designados. Excluir o processo remove as designacoes;
-- usuarios nunca sao excluidos (apenas inativados).
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_designacoes` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `usuario_id`                 INT UNSIGNED NOT NULL,
    `designado_por`              INT UNSIGNED NULL COMMENT 'Administrador/gestor que designou',
    `observacao`                 VARCHAR(255) NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pad_processo_usuario` (`processo_administrativo_id`, `usuario_id`),
    KEY `ix_pad_usuario` (`usuario_id`),
    CONSTRAINT `fk_pad_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pad_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pad_designado_por`
        FOREIGN KEY (`designado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Acoes (tarefas) de um processo administrativo. Um processo tem N acoes.
-- Alteradas por administrador, gestor ou por quem as criou (regra no
-- AcaoService). Cada gravacao recalcula, no processo, o prazo vigente e
-- a atividade (ver processos_administrativos).
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_acoes` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `descricao`                  VARCHAR(500) NOT NULL,
    `prazo`                      DATE         NULL,
    `prazo_hora`                 TIME         NULL COMMENT 'NULL = ate o fim do dia',
    `status`                     ENUM('PENDENTE','EM_ANDAMENTO','CONCLUIDA','CANCELADA') NOT NULL DEFAULT 'PENDENTE'
        COMMENT 'CONCLUIDA e CANCELADA = finalizada',
    `observacao`                 TEXT         NULL,
    `criado_por`                 INT UNSIGNED NULL,
    `finalizada_em`              DATETIME     NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_paa_processo` (`processo_administrativo_id`, `status`),
    KEY `ix_paa_status_prazo` (`status`, `prazo`),
    KEY `ix_paa_criado_por` (`criado_por`),
    CONSTRAINT `fk_paa_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paa_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Solicitacoes (pedidos enviados a outras unidades) de um processo
-- administrativo. Um processo tem N solicitacoes. O prazo e so informativo:
-- nao altera o prazo vigente nem a atividade do processo. Status manual.
-- Alteradas por administrador, gestor ou por quem as criou (regra no
-- SolicitacaoService).
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_solicitacoes` (
    `id`                         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED      NOT NULL,
    `data_solicitacao`           DATE              NOT NULL,
    `expediente`                 VARCHAR(60)       NOT NULL COMMENT 'Protocolo do expediente no SIGADOC',
    `destinatario_lotacao_id`    SMALLINT UNSIGNED NOT NULL,
    `assunto`                    VARCHAR(500)      NOT NULL,
    `prazo`                      DATE              NULL,
    `status`                     ENUM('AGUARDANDO','ATENDIDO','ENCERRADO') NOT NULL DEFAULT 'AGUARDANDO',
    `criado_por`                 INT UNSIGNED      NULL,
    `created_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pas_processo` (`processo_administrativo_id`, `data_solicitacao`),
    KEY `ix_pas_status_prazo` (`status`, `prazo`),
    KEY `ix_pas_destinatario` (`destinatario_lotacao_id`),
    KEY `ix_pas_criado_por` (`criado_por`),
    CONSTRAINT `fk_pas_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pas_destinatario`
        FOREIGN KEY (`destinatario_lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pas_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Anotacoes (notas livres internas) de um processo administrativo.
-- Nao confundir com o tipo de evento "Anotacao" do SIGADOC.
-- ---------------------------------------------------------------------
CREATE TABLE `processo_administrativo_anotacoes` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `texto`                      TEXT         NOT NULL,
    `criado_por`                 INT UNSIGNED NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_paan_processo` (`processo_administrativo_id`, `created_at`),
    KEY `ix_paan_criado_por` (`criado_por`),
    CONSTRAINT `fk_paan_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paan_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Prioridades: criterios (Idoso, Determinacao Judicial...) com nivel de 1
-- (baixa) a 5 (maxima), atribuidos aos processos administrativos (N:N).
-- Sem exclusao: prioridade em desuso e inativada e continua valendo nos
-- processos que ja a possuem.
-- ---------------------------------------------------------------------
CREATE TABLE `prioridades` (
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
CREATE TABLE `processo_administrativo_prioridades` (
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
CREATE TABLE `sigtap_procedimentos` (
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
CREATE TABLE `processo_administrativo_produtos_saude` (
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
CREATE TABLE `instituicoes_externas` (
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

CREATE TABLE `orgaos_externos` (
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
CREATE TABLE `processos_extrajudiciais` (
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
CREATE TABLE `processo_administrativo_extrajudicial` (
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

-- ---------------------------------------------------------------------
-- Recursos (Central de Recursos): sites, sistemas, documentos e paineis
-- da equipe. Pasta = Tipo (1:1); Area = Categoria (N:N, opcional).
-- ---------------------------------------------------------------------
CREATE TABLE `tipos_recurso` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tipos_recurso_nome` (`nome`),
    CONSTRAINT `ck_tipos_recurso_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categorias_recurso` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categorias_recurso_nome` (`nome`),
    CONSTRAINT `ck_categorias_recurso_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `recursos` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `titulo`         VARCHAR(160)      NOT NULL,
    `descricao`      VARCHAR(600)      NULL,
    `url`            VARCHAR(500)      NOT NULL COMMENT 'Sempre http(s)',
    `tipo_id`        SMALLINT UNSIGNED NOT NULL COMMENT 'Pasta',
    `criado_por`     INT UNSIGNED      NULL,
    `atualizado_por` INT UNSIGNED      NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_recursos_tipo` (`tipo_id`),
    KEY `ix_recursos_criado_por` (`criado_por`),
    CONSTRAINT `fk_recursos_tipo`
        FOREIGN KEY (`tipo_id`) REFERENCES `tipos_recurso` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_recursos_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_recursos_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Areas de cada recurso (N:N, sem repeticao). Sem area, o item aparece so na sua Pasta.
CREATE TABLE `recurso_categorias` (
    `id`           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `recurso_id`   INT UNSIGNED      NOT NULL,
    `categoria_id` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recurso_categorias` (`recurso_id`, `categoria_id`),
    KEY `ix_recurso_categorias_categoria` (`categoria_id`),
    CONSTRAINT `fk_rc_recurso`
        FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rc_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias_recurso` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bloqueios judiciais: lancamentos de Bloqueio e Desbloqueio de um
-- processo judicial (data e valor). Saldo retido = bloqueios - desbloqueios,
-- nunca negativo em ordem cronologica (regra no BloqueioJudicialService).
-- Com lancamentos ou guias, o processo judicial nao pode ser excluido.
-- ---------------------------------------------------------------------
CREATE TABLE `bloqueios_judiciais` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED  NOT NULL,
    `tipo`                 ENUM('BLOQUEIO','DESBLOQUEIO') NOT NULL,
    `data_lancamento`      DATE          NOT NULL COMMENT 'Data do bloqueio ou do desbloqueio',
    `valor`                DECIMAL(15,2) NOT NULL,
    `identificador`        VARCHAR(60)   NULL COMMENT 'Protocolo SISBAJUD, ordem ou conta',
    `observacao`           VARCHAR(1000) NULL,
    `criado_por`           INT UNSIGNED  NULL,
    `atualizado_por`       INT UNSIGNED  NULL,
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_bj_processo_data` (`processo_judicial_id`, `data_lancamento`),
    KEY `ix_bj_data` (`data_lancamento`),
    KEY `ix_bj_criado_por` (`criado_por`),
    CONSTRAINT `ck_bj_valor` CHECK (`valor` > 0),
    CONSTRAINT `fk_bj_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_bj_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_bj_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guias emitidas para deposito de valores em juizo. Vencida = EMITIDA com
-- vencimento passado (calculada, nao gravada).
CREATE TABLE `guias_deposito_judicial` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED  NOT NULL,
    `numero`               VARCHAR(60)   NULL COMMENT 'Numero da guia / ID do deposito',
    `data_emissao`         DATE          NOT NULL,
    `vencimento`           DATE          NULL,
    `valor`                DECIMAL(15,2) NOT NULL,
    `finalidade`           VARCHAR(255)  NULL,
    `status`               ENUM('EMITIDA','PAGA','CANCELADA') NOT NULL DEFAULT 'EMITIDA',
    `data_pagamento`       DATE          NULL COMMENT 'So quando PAGA',
    `observacao`           VARCHAR(1000) NULL,
    `criado_por`           INT UNSIGNED  NULL,
    `atualizado_por`       INT UNSIGNED  NULL,
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_gdj_processo` (`processo_judicial_id`, `data_emissao`),
    KEY `ix_gdj_status_vencimento` (`status`, `vencimento`),
    KEY `ix_gdj_criado_por` (`criado_por`),
    CONSTRAINT `ck_gdj_valor` CHECK (`valor` > 0),
    CONSTRAINT `fk_gdj_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_gdj_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_gdj_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores iniciais das tabelas de apoio. AJUSTAR as lotacoes para as
-- unidades reais da SES-MT (nome oficial e sigla do SIGADOC).
INSERT INTO `cargos` (`nome`) VALUES
    ('Procurador do Estado'),
    ('Analista'),
    ('Técnico Administrativo'),
    ('Assessor Jurídico'),
    ('Agente Público'),
    ('Residente Técnico'),
    ('Estagiário');

INSERT INTO `instituicoes` (`nome`, `sigla`) VALUES
    ('Secretaria de Estado de Saúde de Mato Grosso', 'SES-MT'),
    ('Procuradoria-Geral do Estado de Mato Grosso', 'PGE-MT');

INSERT INTO `lotacoes` (`nome`) VALUES
    ('Subprocuradoria-Geral da Saúde'),
    ('Unidade Jurídica'),
    ('Gabinete'),
    ('Superintendência'),
    ('Coordenadoria'),
    ('Gerência');

INSERT INTO `prioridades` (`nome`, `nivel`, `descricao`) VALUES
    ('Idoso', 3, 'Parte interessada com 60 anos ou mais (Estatuto da Pessoa Idosa).'),
    ('Determinação Judicial', 5, 'Há ordem judicial a cumprir no processo.'),
    ('Risco à vida', 5, 'Relato de risco iminente à vida ou à saúde do paciente.'),
    ('Pessoa com deficiência', 3, 'Parte interessada com deficiência.'),
    ('Criança ou adolescente', 3, 'Paciente menor de 18 anos.');

INSERT INTO `instituicoes_externas` (`nome`, `sigla`) VALUES
    ('Ministério Público do Estado de Mato Grosso', 'MP-MT'),
    ('Defensoria Pública do Estado de Mato Grosso', 'DPE-MT'),
    ('Tribunal de Contas do Estado de Mato Grosso', 'TCE-MT');

INSERT INTO `orgaos_externos` (`instituicao_externa_id`, `nome`)
SELECT i.`id`, o.`nome`
  FROM `instituicoes_externas` i
  JOIN (SELECT 'MP-MT' AS sigla, '1ª Promotoria de Justiça de Cuiabá' AS nome
        UNION ALL SELECT 'DPE-MT', 'Núcleo de Defesa da Saúde'
        UNION ALL SELECT 'TCE-MT', 'Secretaria de Controle Externo de Saúde') o ON o.sigla = i.`sigla`;

INSERT INTO `tipos_recurso` (`nome`) VALUES
    ('Sistemas'), ('Sites'), ('Documentos'), ('Planilhas'), ('Formulários'), ('Painéis'), ('Bases');

INSERT INTO `categorias_recurso` (`nome`) VALUES
    ('Judicial'), ('Consultiva'), ('Administrativa'), ('Estratégica');

-- =====================================================================
-- Dados de demonstracao (secao 10 da especificacao)
-- =====================================================================
INSERT INTO `processos_judiciais`
    (`numero_cnj`, `resumo`, `origem`)
VALUES
    ('1008695-17.2026.8.11.0006',
     'Processo judicial de demonstracao. Metadados a serem completados pelo PJe.',
     'MANUAL');

INSERT INTO `processos_administrativos`
    (`protocolo`, `data_abertura`, `objeto`, `origem`, `prazo`, `prazo_vigente`)
VALUES
    ('SEPLAG-PRO-2026/16696',
     '2026-09-17',
     'Solicitação de informações para defesa do Estado autos nº 1008695-17.2026.8.11.0006 - FGTS - SES',
     'MANUAL', '2026-10-17', '2026-10-17');

INSERT INTO `processo_administrativo_judicial`
    (`processo_administrativo_id`, `processo_judicial_id`, `data_vinculo`, `observacao`)
SELECT pa.`id`, pj.`id`, '2026-09-20', 'Vinculo de demonstracao'
FROM `processos_administrativos` pa
CROSS JOIN `processos_judiciais` pj
WHERE pa.`protocolo` = 'SEPLAG-PRO-2026/16696'
  AND pj.`numero_cnj` = '1008695-17.2026.8.11.0006';
