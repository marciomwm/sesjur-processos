-- =====================================================================
-- Migracao 2026-10-19 - Entregadores e Dados do Pagamento.
--
--   * cria `entregadores`: pessoas fisicas e juridicas que entregam o
--     objeto (prestam servicos ou fornecem bens) a Administracao. O
--     documento (CPF ou CNPJ, inclusive o CNPJ alfanumerico) e unico;
--   * cria `processo_administrativo_pagamentos`: Dados do Pagamento do
--     processo com procedimento "Pagamento Indenizatorio" (um registro por
--     processo): Demandante (unidade administrativa da SES-MT), Credor
--     (entregador), Objeto, Data de Execucao, Competencia (AAAA-MM da data
--     de execucao) e Valor.
--
-- Nenhuma tabela existente e alterada. Idempotente: pode ser executada
-- mais de uma vez.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > database\backup_antes_entregadores_AAAAMMDD.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-19_entregadores_pagamentos.sql
-- Reverter (apaga os dados registrados):
--   DROP TABLE processo_administrativo_pagamentos;
--   DROP TABLE entregadores;
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `entregadores` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_pessoa`    ENUM('FISICA','JURIDICA') NOT NULL COMMENT 'Derivado do documento: CPF = FISICA, CNPJ = JURIDICA',
    `documento`      VARCHAR(14)  NOT NULL COMMENT 'CPF (11 digitos) ou CNPJ (14 caracteres, pode ser alfanumerico), sem mascara',
    `nome`           VARCHAR(200) NOT NULL COMMENT 'Nome da pessoa fisica ou razao social',
    `criado_por`     INT UNSIGNED NULL,
    `atualizado_por` INT UNSIGNED NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_entregadores_documento` (`documento`),
    KEY `ix_entregadores_nome` (`nome`),
    CONSTRAINT `fk_entregadores_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_entregadores_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `processo_administrativo_pagamentos` (
    `processo_administrativo_id` INT UNSIGNED      NOT NULL,
    `demandante_lotacao_id`      SMALLINT UNSIGNED NOT NULL COMMENT 'Unidade administrativa da SES-MT demandante',
    `entregador_id`              INT UNSIGNED      NOT NULL COMMENT 'Credor',
    `objeto`                     TEXT              NOT NULL COMMENT 'Bem fornecido ou servico prestado',
    `data_execucao`              DATE              NOT NULL COMMENT 'Data da entrega do objeto',
    `competencia`                CHAR(7)           NOT NULL COMMENT 'AAAA-MM, derivada da data de execucao',
    `valor`                      DECIMAL(15,2)     NOT NULL,
    `criado_por`                 INT UNSIGNED      NULL,
    `atualizado_por`             INT UNSIGNED      NULL,
    `created_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`processo_administrativo_id`),
    KEY `ix_pap_competencia` (`competencia`),
    KEY `ix_pap_demandante` (`demandante_lotacao_id`),
    KEY `ix_pap_entregador` (`entregador_id`),
    CONSTRAINT `ck_pap_valor` CHECK (`valor` > 0),
    CONSTRAINT `ck_pap_competencia` CHECK (`competencia` = LEFT(`data_execucao`, 7)),
    CONSTRAINT `fk_pap_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pap_demandante`
        FOREIGN KEY (`demandante_lotacao_id`) REFERENCES `lotacoes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pap_entregador`
        FOREIGN KEY (`entregador_id`) REFERENCES `entregadores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pap_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pap_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
