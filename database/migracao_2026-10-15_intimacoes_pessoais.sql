-- =====================================================================
-- Migracao 2026-10-15 - Intimacoes Pessoais.
--
-- Intimacao dirigida a um Agente Publico num processo ja cadastrado
-- (judicial, extrajudicial ou administrativo):
--   * exatamente um processo (ck_ip_um_processo), sempre por chave
--     estrangeira - nunca pelo numero/protocolo;
--   * pessoa intimada e quem recebeu sao Agentes Publicos (podem ser
--     pessoas diferentes); quem registrou no sistema fica em criado_por;
--   * status operacional informado pelo usuario (Pendente, Em andamento,
--     Cumprida, Cancelada); a situacao temporal (vencida, proxima do
--     vencimento, cumprida fora do prazo...) e calculada, nunca gravada;
--   * exclusao logica (excluida_em): o registro e o historico permanecem.
--
-- Processos e agentes com intimacoes nao podem ser excluidos (RESTRICT).
--
-- Para bancos criados antes desta data. Nenhum dado existente e alterado.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-15_intimacoes_pessoais.sql
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `intimacoes_pessoais` (
    `id`                         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `processo_judicial_id`       INT UNSIGNED  NULL,
    `processo_extrajudicial_id`  INT UNSIGNED  NULL,
    `processo_administrativo_id` INT UNSIGNED  NULL,
    `agente_intimado_id`         INT UNSIGNED  NOT NULL COMMENT 'Pessoa intimada',
    `agente_recebedor_id`        INT UNSIGNED  NOT NULL COMMENT 'Agente que recebeu a intimacao',
    `data_recebimento`           DATE          NOT NULL,
    `data_prazo`                 DATE          NOT NULL COMMENT 'Data-limite informada (sem calculo processual)',
    `data_cumprimento`           DATE          NULL COMMENT 'Data efetiva da providencia; so quando CUMPRIDA',
    `status`                     ENUM('PENDENTE','EM_ANDAMENTO','CUMPRIDA','CANCELADA') NOT NULL DEFAULT 'PENDENTE',
    `documento_referencia`       VARCHAR(120)  NULL COMMENT 'Expediente do SIGADOC ou outro documento comprobatorio',
    `observacoes`                TEXT          NULL,
    `criado_por`                 INT UNSIGNED  NULL COMMENT 'Usuario que registrou (nao e o recebedor)',
    `atualizado_por`             INT UNSIGNED  NULL,
    `excluida_em`                DATETIME      NULL COMMENT 'Exclusao logica',
    `excluida_por`               INT UNSIGNED  NULL,
    `motivo_exclusao`            VARCHAR(500)  NULL,
    `created_at`                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ip_judicial` (`processo_judicial_id`),
    KEY `ix_ip_extrajudicial` (`processo_extrajudicial_id`),
    KEY `ix_ip_administrativo` (`processo_administrativo_id`),
    KEY `ix_ip_intimado` (`agente_intimado_id`),
    KEY `ix_ip_recebedor` (`agente_recebedor_id`),
    KEY `ix_ip_status_prazo` (`excluida_em`, `status`, `data_prazo`),
    KEY `ix_ip_recebimento` (`data_recebimento`),
    KEY `ix_ip_criado_por` (`criado_por`),
    CONSTRAINT `ck_ip_um_processo` CHECK (
        (`processo_judicial_id` IS NOT NULL) + (`processo_extrajudicial_id` IS NOT NULL) + (`processo_administrativo_id` IS NOT NULL) = 1),
    CONSTRAINT `ck_ip_prazo` CHECK (`data_prazo` >= `data_recebimento`),
    CONSTRAINT `ck_ip_cumprimento` CHECK (
        (`status` = 'CUMPRIDA' AND `data_cumprimento` IS NOT NULL) OR (`status` <> 'CUMPRIDA' AND `data_cumprimento` IS NULL)),
    -- Sem ON UPDATE CASCADE nas colunas do ck_ip_um_processo: o MySQL nao
    -- aceita acao referencial em coluna usada por CHECK.
    CONSTRAINT `fk_ip_processo_judicial`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ip_processo_extrajudicial`
        FOREIGN KEY (`processo_extrajudicial_id`) REFERENCES `processos_extrajudiciais` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ip_processo_administrativo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ip_agente_intimado`
        FOREIGN KEY (`agente_intimado_id`) REFERENCES `agentes_publicos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ip_agente_recebedor`
        FOREIGN KEY (`agente_recebedor_id`) REFERENCES `agentes_publicos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ip_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ip_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ip_excluida_por`
        FOREIGN KEY (`excluida_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
