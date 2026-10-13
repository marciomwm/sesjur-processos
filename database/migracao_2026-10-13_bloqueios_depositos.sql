-- =====================================================================
-- Migracao 2026-10-13 - Bloqueios judiciais e guias de deposito judicial.
--
-- Controle financeiro dos Processos Judiciais:
--   bloqueios_judiciais     = lancamentos de Bloqueio e Desbloqueio (data
--                             e valor); o Saldo retido do processo e a
--                             soma dos bloqueios menos a dos desbloqueios
--                             e nunca fica negativo (regra no servico).
--   guias_deposito_judicial = guias emitidas para deposito de valores em
--                             juizo (Emitida, Paga ou Cancelada).
--
-- O processo judicial com lancamentos ou guias nao pode ser excluido
-- (ON DELETE RESTRICT): os registros financeiros nunca somem em cascata.
--
-- Para bancos criados antes desta data. Nenhum dado existente e alterado.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-13_bloqueios_depositos.sql
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bloqueios_judiciais` (
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

CREATE TABLE IF NOT EXISTS `guias_deposito_judicial` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED  NOT NULL,
    `numero`               VARCHAR(60)   NULL COMMENT 'Numero da guia / ID do deposito',
    `data_emissao`         DATE          NOT NULL,
    `vencimento`           DATE          NULL,
    `valor`                DECIMAL(15,2) NOT NULL,
    `finalidade`           VARCHAR(255)  NULL,
    `status`               ENUM('EMITIDA','PAGA','CANCELADA') NOT NULL DEFAULT 'EMITIDA'
        COMMENT 'Vencida = EMITIDA com vencimento passado (calculada)',
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
