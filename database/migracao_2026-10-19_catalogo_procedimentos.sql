-- =====================================================================
-- Migracao 2026-10-19 - Catalogo de Procedimentos (Configuracoes).
--
-- O Procedimento do processo administrativo deixa de ser texto livre e
-- passa a ser escolhido num catalogo mantido pelos administradores
-- (Sistema > Configuracoes > Procedimentos):
--   * cria a tabela `procedimentos` (nome unico, descricao, ativo);
--   * cria `processos_administrativos.procedimento_id` (FK, opcional).
--
-- Os valores de texto livre ja gravados em `processos_administrativos.
-- procedimento` NAO sao alterados nem apagados: ficam como classificacao
-- anterior, exibida na ficha do processo, ate a conciliacao. Nenhum item
-- e cadastrado no catalogo e nenhum processo e vinculado por esta
-- migracao (a nomenclatura e definida pelos administradores). Depois de
-- cadastrar o catalogo, rode o relatorio de conciliacao:
--   C:\laragon\bin\php\...\php.exe database\conciliar-procedimentos.php
-- que lista os valores antigos e so vincula, com --aplicar, os que tem
-- correspondencia exata (ignorando caixa, acentos e espacos repetidos).
--
-- Idempotente: pode ser executada mais de uma vez.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > database\backup_antes_procedimentos_AAAAMMDD.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-19_catalogo_procedimentos.sql
-- Reverter (somente se nenhum processo tiver sido vinculado ainda):
--   ALTER TABLE processos_administrativos DROP FOREIGN KEY fk_pa_procedimento,
--       DROP KEY ix_pa_procedimento_id, DROP COLUMN procedimento_id;
--   DROP TABLE procedimentos;
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `procedimentos` (
    `id`             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`           VARCHAR(160)      NOT NULL,
    `descricao`      VARCHAR(500)      NULL,
    `ativo`          TINYINT(1)        NOT NULL DEFAULT 1,
    `criado_por`     INT UNSIGNED      NULL,
    `atualizado_por` INT UNSIGNED      NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_procedimentos_nome` (`nome`),
    KEY `ix_procedimentos_ativo` (`ativo`, `nome`),
    CONSTRAINT `ck_procedimentos_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_procedimentos_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_procedimentos_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coluna procedimento_id (MySQL nao tem ADD COLUMN IF NOT EXISTS).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processos_administrativos'
                   AND COLUMN_NAME = 'procedimento_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `processos_administrativos`
        ADD COLUMN `procedimento_id` SMALLINT UNSIGNED NULL
            COMMENT ''Procedimento do catalogo'' AFTER `procedimento`,
        ADD KEY `ix_pa_procedimento_id` (`procedimento_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Chave estrangeira (so se ainda nao existir).
SET @existe := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'processos_administrativos'
                   AND CONSTRAINT_NAME = 'fk_pa_procedimento');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `processos_administrativos`
        ADD CONSTRAINT `fk_pa_procedimento`
            FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- O texto livre passa a ser historico: so o comentario da coluna muda.
ALTER TABLE `processos_administrativos`
    MODIFY COLUMN `procedimento` VARCHAR(160) NULL
        COMMENT 'Legado: texto livre anterior ao catalogo (procedimento_id). Preservado, nao editado';
