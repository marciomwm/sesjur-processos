-- =====================================================================
-- Migracao 2026-09-28 - Cabecalho, origem dos eventos e documentos do
-- processo judicial (com a integra das pecas decisorias), liberados pelo
-- remapeamento do DOM do PJe (Recursos/pje-elementos-dom.json).
--
-- Para bancos criados antes desta data. NAO apaga dados.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-09-28_pje_documentos.sql
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Cabecalho do processo no PJe e motivo da prioridade.
--
-- classe_abreviada: prefixo do titulo do topo ("ProceComCiv").
-- resumo_partes:    <small> do titulo ("FULANO e outros X MUNICIPIO").
-- prioridade_descricao: "Prioridade?" nem sempre e SIM/NAO - pode trazer o
--   motivo ("Idoso"). Nesse caso prioridade = 1 e o texto fica aqui.
-- ---------------------------------------------------------------------
ALTER TABLE `processos_judiciais`
    ADD COLUMN `classe_abreviada`     VARCHAR(30)  NULL COMMENT 'Sigla da classe no cabecalho do PJe, ex.: ProceComCiv' AFTER `classe_codigo`,
    ADD COLUMN `resumo_partes`        VARCHAR(500) NULL COMMENT 'Resumo "Polo ativo X Polo passivo" do cabecalho' AFTER `assunto_principal`,
    ADD COLUMN `prioridade_descricao` VARCHAR(100) NULL COMMENT 'Motivo da prioridade, ex.: Idoso' AFTER `prioridade`;

-- Varios advogados/orgaos por parte podem passar de 255 caracteres.
ALTER TABLE `partes_processuais`
    MODIFY COLUMN `representacao` TEXT NULL COMMENT 'Tipo: nome de cada representante, separados por ";"';

-- Quem originou o evento: 2o token da classe do icone na timeline.
ALTER TABLE `processo_judicial_movimentacoes`
    ADD COLUMN `origem` VARCHAR(20) NULL
        COMMENT 'SISTEMA | INTERNO | POLO_ATIVO | POLO_PASSIVO | EXTERNO | OUTROS' AFTER `natureza`;

-- ---------------------------------------------------------------------
-- Documentos do processo judicial.
--
-- Registro unico por (processo, numero do documento). Diferente da
-- cronologia, NAO e substituido a cada extracao: e atualizado (upsert), e a
-- integra ja obtida so e trocada por uma nova leitura - uma extracao sem a
-- opcao "integra" nunca apaga o texto guardado.
--
-- movimentacao_id aponta para o evento da cronologia que juntou o documento;
-- vira NULL quando a cronologia e substituida sem o evento.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_judicial_documentos` (
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
