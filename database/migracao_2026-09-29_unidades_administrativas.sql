-- =====================================================================
-- Migracao 2026-09-29 - Unidades Administrativas.
--
-- A antiga "Lotacao" passa a ser a Unidade Administrativa, com
-- instituicao, nivel, unidade superior, gestor, lotacionograma e
-- competencias. A tabela continua se chamando `lotacoes`.
--
-- Para bancos criados antes desta data (aplicar DEPOIS de
-- migracao_2026-09-29_solicitacoes_anotacoes.sql). Nenhum dado e apagado:
-- as unidades existentes ficam sem instituicao ate serem editadas.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-09-29_unidades_administrativas.sql
-- =====================================================================

USE `gestao_juridica`;
-- Os nomes das instituicoes tem acentos: o cliente mysql do Windows nao usa
-- UTF-8 por padrao.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `instituicoes` (
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

INSERT IGNORE INTO `instituicoes` (`nome`, `sigla`) VALUES
    ('Secretaria de Estado de Saúde de Mato Grosso', 'SES-MT'),
    ('Procuradoria-Geral do Estado de Mato Grosso', 'PGE-MT');

CREATE TABLE IF NOT EXISTS `niveis_organizacionais` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_niveis_nome` (`nome`),
    CONSTRAINT `ck_niveis_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nome unico dentro da instituicao (e nao mais no sistema todo): SES-MT e
-- PGE-MT podem ter, cada uma, o seu "Gabinete".
ALTER TABLE `lotacoes`
    ADD COLUMN `instituicao_id`      SMALLINT UNSIGNED NULL AFTER `id`,
    ADD COLUMN `nivel_id`            SMALLINT UNSIGNED NULL AFTER `instituicao_id`,
    ADD COLUMN `unidade_superior_id` SMALLINT UNSIGNED NULL COMMENT 'Unidade imediatamente acima na hierarquia' AFTER `nivel_id`,
    ADD COLUMN `gestor_nome`         VARCHAR(150) NULL COMMENT 'Servidor responsavel pela unidade (texto livre)' AFTER `sigla`,
    ADD COLUMN `gestor_matricula`    VARCHAR(30)  NULL AFTER `gestor_nome`,
    ADD COLUMN `competencias`        TEXT         NULL COMMENT 'Competencias conforme regimento interno' AFTER `gestor_matricula`,
    DROP INDEX `uq_lotacoes_nome`,
    ADD UNIQUE KEY `uq_lotacoes_nome` (`instituicao_id`, `nome`),
    ADD KEY `ix_lotacoes_nivel` (`nivel_id`),
    ADD KEY `ix_lotacoes_superior` (`unidade_superior_id`),
    ADD CONSTRAINT `fk_lotacoes_instituicao`
        FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_lotacoes_nivel`
        FOREIGN KEY (`nivel_id`) REFERENCES `niveis_organizacionais` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_lotacoes_superior`
        FOREIGN KEY (`unidade_superior_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS `lotacao_servidores` (
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
