-- =====================================================================
-- Migracao 2026-10-07 - Recursos (Central de Recursos).
--
-- Sites, sistemas, documentos e paineis usados pela equipe, organizados
-- de duas formas sobre os mesmos itens:
--   Pasta = Tipo do recurso      (1:1 - o item fica em uma so pasta)
--   Area  = Categoria do recurso (N:N - o item aparece em varias areas)
-- Tipos e categorias sao tabelas de apoio: inativadas, nunca excluidas.
--
-- Para bancos criados antes desta data. Nenhum dado existente e alterado.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-07_recursos.sql
-- =====================================================================

USE `gestao_juridica`;
-- Os nomes iniciais tem acentos: o cliente mysql do Windows nao usa UTF-8 por padrao.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tipos_recurso` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tipos_recurso_nome` (`nome`),
    CONSTRAINT `ck_tipos_recurso_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categorias_recurso` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categorias_recurso_nome` (`nome`),
    CONSTRAINT `ck_categorias_recurso_ativo` CHECK (`ativo` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recursos` (
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
CREATE TABLE IF NOT EXISTS `recurso_categorias` (
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

INSERT IGNORE INTO `tipos_recurso` (`nome`) VALUES
    ('Sistemas'), ('Sites'), ('Documentos'), ('Planilhas'), ('Formulários'), ('Painéis'), ('Bases');

INSERT IGNORE INTO `categorias_recurso` (`nome`) VALUES
    ('Judicial'), ('Consultiva'), ('Administrativa'), ('Estratégica');
