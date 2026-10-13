-- =====================================================================
-- Migracao 2026-09-25 - Modulo de Cadastro de Usuarios.
-- Cria cargos, lotacoes, usuarios e auditoria.
--
-- Para bancos criados antes desta data. NAO apaga dados e pode ser
-- executada mais de uma vez (IF NOT EXISTS / INSERT IGNORE).
--   C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root gestao_juridica < database\migracao_2026-09-25_usuarios.sql
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Tabelas de apoio do cadastro de usuarios. Cargo e lotacao sao
-- referenciados por FK (e nao gravados como texto livre) para evitar
-- variacoes de grafia e permitir renomear uma unidade sem reescrever
-- o historico. Itens obsoletos sao inativados, nunca excluidos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cargos` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cargos_nome` (`nome`),
    CONSTRAINT `ck_cargos_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lotacoes` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(150) NOT NULL,
    `sigla`      VARCHAR(30)  NULL COMMENT 'Sigla da unidade no SIGADOC, ex.: SESUNIJUR',
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lotacoes_nome` (`nome`),
    UNIQUE KEY `uq_lotacoes_sigla` (`sigla`),
    CONSTRAINT `ck_lotacoes_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Usuarios (agentes publicos do setor juridico).
-- Sem exclusao fisica: desligamento = ativo 0. Credenciais de acesso
-- ficarao em tabela propria (ver database/planejamento_autenticacao_rbac.sql).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `nome`            VARCHAR(150)      NOT NULL,
    `email`           VARCHAR(254)      NOT NULL COMMENT 'Sempre em minusculas; futuro identificador de login',
    `matricula`       VARCHAR(30)       NOT NULL COMMENT 'Texto: preserva zeros a esquerda',
    `cargo_id`        SMALLINT UNSIGNED NOT NULL,
    `lotacao_id`      SMALLINT UNSIGNED NOT NULL,
    `data_nascimento` DATE              NULL,
    `ativo`           TINYINT(1)        NOT NULL DEFAULT 1,
    `inativado_em`    DATETIME          NULL,
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuarios_email` (`email`),
    UNIQUE KEY `uq_usuarios_matricula` (`matricula`),
    KEY `ix_usuarios_nome` (`nome`),
    KEY `ix_usuarios_ativo` (`ativo`),
    KEY `ix_usuarios_cargo` (`cargo_id`),
    KEY `ix_usuarios_lotacao` (`lotacao_id`),
    CONSTRAINT `ck_usuarios_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_usuarios_cargo`
        FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_usuarios_lotacao`
        FOREIGN KEY (`lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Trilha de auditoria (somente insercao). Generica por entidade para
-- servir tambem a processos, vinculos e, no futuro, a login.
-- usuario_id = autor da acao; NULL enquanto nao houver autenticacao.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auditoria` (
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

-- Valores iniciais das tabelas de apoio. AJUSTAR as lotacoes para as
-- unidades reais da SES-MT (nome oficial e sigla do SIGADOC).
INSERT IGNORE INTO `cargos` (`nome`) VALUES
    ('Procurador do Estado'),
    ('Analista'),
    ('Técnico Administrativo'),
    ('Assessor Jurídico'),
    ('Agente Público'),
    ('Residente Técnico'),
    ('Estagiário');

INSERT IGNORE INTO `lotacoes` (`nome`) VALUES
    ('Subprocuradoria-Geral da Saúde'),
    ('Unidade Jurídica'),
    ('Gabinete'),
    ('Superintendência'),
    ('Coordenadoria'),
    ('Gerência');

