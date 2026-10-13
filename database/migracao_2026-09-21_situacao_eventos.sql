-- =====================================================================
-- Migracao 2026-09-21 - Situacao, responsavel atual e movimentacoes
-- do processo administrativo (SIGADOC).
--
-- Para bancos criados antes desta data. NAO apaga dados.
--   C:\xampp\mysql\bin\mysql.exe -u root gestao_juridica < database\migracao_2026-09-21_situacao_eventos.sql
-- =====================================================================

USE `gestao_juridica`;

ALTER TABLE `processos_administrativos`
    ADD COLUMN `situacao`       VARCHAR(30)  NULL COMMENT 'AGUARDANDO_ANDAMENTO | SOBRESTADO | ARQUIVADO | EM_TRANSITO | OUTRA' AFTER `data_extracao`,
    ADD COLUMN `situacao_descricao` VARCHAR(120) NULL COMMENT 'Rotulo oficial do SIGADOC, ex.: Arquivo Corrente' AFTER `situacao`,
    ADD COLUMN `responsavel_nome`   VARCHAR(180) NULL COMMENT 'Agente publico com o processo' AFTER `situacao_descricao`,
    ADD COLUMN `responsavel_matricula` VARCHAR(30) NULL AFTER `responsavel_nome`,
    ADD COLUMN `responsavel_lotacao`   VARCHAR(60) NULL COMMENT 'Sigla da lotacao' AFTER `responsavel_matricula`,
    ADD COLUMN `responsavel_lotacao_nome` VARCHAR(180) NULL AFTER `responsavel_lotacao`,
    ADD KEY `ix_pa_situacao` (`situacao`);

CREATE TABLE IF NOT EXISTS `processo_administrativo_eventos` (
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
