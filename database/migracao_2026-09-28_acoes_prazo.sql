-- =====================================================================
-- Migracao 2026-09-28 - Acoes dos processos administrativos, prazo e
-- atividade do processo; remocao de Vara, Comarca e Designacao do
-- processo judicial.
--
-- Para bancos criados antes desta data.
-- ATENCAO: esta migracao APAGA as colunas vara/comarca de
-- processos_judiciais e a tabela processo_judicial_designacoes (com as
-- designacoes de processos judiciais). Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-09-28_acoes_prazo.sql
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Prazo e atividade do processo administrativo.
--
-- prazo/prazo_hora: prazo proprio do processo (padrao: abertura + 30 dias).
-- prazo_vigente*:   calculado pela aplicacao - o mais proximo entre o prazo
--                   proprio e o das acoes ainda nao finalizadas.
-- ativo:            calculado - 1 enquanto houver acao nao finalizada.
-- ---------------------------------------------------------------------
ALTER TABLE `processos_administrativos`
    ADD COLUMN `prazo`              DATE       NULL COMMENT 'Prazo proprio; padrao: abertura + 30 dias' AFTER `responsavel_lotacao_nome`,
    ADD COLUMN `prazo_hora`         TIME       NULL COMMENT 'NULL = ate o fim do dia' AFTER `prazo`,
    ADD COLUMN `prazo_vigente`      DATE       NULL COMMENT 'Calculado: o mais proximo entre o prazo proprio e o das acoes nao finalizadas' AFTER `prazo_hora`,
    ADD COLUMN `prazo_vigente_hora` TIME       NULL AFTER `prazo_vigente`,
    ADD COLUMN `ativo`              TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Calculado: 1 = ha acao nao finalizada' AFTER `prazo_vigente_hora`,
    ADD KEY `ix_pa_prazo_vigente` (`prazo_vigente`, `prazo_vigente_hora`),
    ADD KEY `ix_pa_ativo` (`ativo`);

-- Processos existentes: 30 dias da abertura (ou do cadastro, sem abertura).
-- Ainda nao ha acoes, entao o prazo vigente e o proprio e todos ficam inativos.
UPDATE `processos_administrativos`
   SET `prazo` = DATE_ADD(COALESCE(`data_abertura`, DATE(`created_at`)), INTERVAL 30 DAY),
       `prazo_vigente` = DATE_ADD(COALESCE(`data_abertura`, DATE(`created_at`)), INTERVAL 30 DAY),
       `ativo` = 0;

-- ---------------------------------------------------------------------
-- Acoes (tarefas) de um processo administrativo.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_administrativo_acoes` (
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
-- Processo judicial: Vara, Comarca e Designacao deixam de existir.
-- ---------------------------------------------------------------------
ALTER TABLE `processos_judiciais`
    DROP INDEX `ix_pj_comarca`,
    DROP COLUMN `vara`,
    DROP COLUMN `comarca`;

DROP TABLE IF EXISTS `processo_judicial_designacoes`;
