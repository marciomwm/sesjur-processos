-- =====================================================================
-- Migracao 2026-09-27 - Campos detalhados e cronologia do processo
-- judicial, liberados pelo mapeamento do DOM real do PJe (TJMT 1o Grau).
-- Ver docs/pje-selectors.md e chrome-extension/config/selectors.js.
--
-- Para bancos criados antes desta data. NAO apaga dados.
--   C:\xampp\mysql\bin\mysql.exe -u root gestao_juridica < database\migracao_2026-09-27_pje_detalhado.sql
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Novos campos cadastrais.
--
-- data_autuacao e data_distribuicao sao datas DISTINTAS e ambas exibidas
-- pelo PJe ("Autuacao" e "Ultima distribuicao"): em processos redistribuidos
-- elas divergem, e ate aqui so a segunda era registrada.
--
-- Os tres sinalizadores sao tri-estado: NULL = a fonte nao informou, que e
-- diferente de "nao". Mesma convencao ja adotada em segredo_justica.
-- ---------------------------------------------------------------------
ALTER TABLE `processos_judiciais`
    ADD COLUMN `classe_codigo`    VARCHAR(20)  NULL COMMENT 'Codigo CNJ da classe, ex.: 156' AFTER `classe_judicial`,
    ADD COLUMN `data_autuacao`    DATE         NULL COMMENT 'Autuacao original (distinta da ultima distribuicao)' AFTER `assunto_principal`,
    ADD COLUMN `cargo_judicial`   VARCHAR(120) NULL COMMENT 'Ex.: Juiz Titular' AFTER `orgao_julgador`,
    ADD COLUMN `competencia`      VARCHAR(255) NULL COMMENT 'Competencia do orgao julgador' AFTER `cargo_judicial`,
    ADD COLUMN `justica_gratuita` TINYINT(1)   NULL COMMENT 'NULL = informacao nao disponivel' AFTER `segredo_justica`,
    ADD COLUMN `tutela_liminar`   TINYINT(1)   NULL COMMENT 'NULL = informacao nao disponivel' AFTER `justica_gratuita`,
    ADD COLUMN `prioridade`       TINYINT(1)   NULL COMMENT 'NULL = informacao nao disponivel' AFTER `tutela_liminar`,
    ADD COLUMN `historico_completo` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = a cronologia importada cobre todo o processo; 0 = parcial (timeline paginada)'
        AFTER `data_ultima_atualizacao`,
    ADD KEY `ix_pj_data_autuacao` (`data_autuacao`);

-- ---------------------------------------------------------------------
-- Cronologia do processo judicial (timeline do PJe).
--
-- Espelha a fonte: a cada extracao confirmada a lista e substituida
-- integralmente, preservando a ordem de exibicao (ordem 0 = mais recente).
--
-- A data vem do separador do grupo e a hora do proprio item; por isso sao
-- colunas separadas, e nao um DATETIME - a fonte nao garante as duas.
-- Um item pode trazer o texto do movimento, um documento juntado, ou ambos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_judicial_movimentacoes` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `data_movimentacao`    DATE         NULL,
    `hora`                 TIME         NULL,
    `tipo`                 VARCHAR(20)  NOT NULL DEFAULT 'MOVIMENTO' COMMENT 'MOVIMENTO | DOCUMENTO',
    `descricao`            TEXT         NULL COMMENT 'Texto do movimento processual',
    `documento_id`         VARCHAR(30)  NULL COMMENT 'Numero do documento juntado, ex.: 249562011',
    `documento_tipo`       VARCHAR(120) NULL COMMENT 'Especie do documento, ex.: Sentenca',
    `natureza`             VARCHAR(60)  NULL COMMENT 'Interno | Externo | Polo passivo | Movimento processual lancado',
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
