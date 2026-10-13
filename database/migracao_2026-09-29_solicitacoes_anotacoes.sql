-- =====================================================================
-- Migracao 2026-09-29 - Solicitacoes e Anotacoes dos processos
-- administrativos.
--
-- Para bancos criados antes desta data. Apenas cria tabelas novas; nenhum
-- dado existente e alterado. Mesmo assim, faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-09-29_solicitacoes_anotacoes.sql
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Solicitacoes (pedidos enviados a outras unidades) de um processo
-- administrativo. Um processo tem N solicitacoes. O prazo e so informativo:
-- nao altera o prazo vigente nem a atividade do processo. Status manual.
-- Alteradas por administrador, gestor ou por quem as criou (regra no
-- SolicitacaoService).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_administrativo_solicitacoes` (
    `id`                         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED      NOT NULL,
    `data_solicitacao`           DATE              NOT NULL,
    `expediente`                 VARCHAR(60)       NOT NULL COMMENT 'Protocolo do expediente no SIGADOC',
    `destinatario_lotacao_id`    SMALLINT UNSIGNED NOT NULL,
    `assunto`                    VARCHAR(500)      NOT NULL,
    `prazo`                      DATE              NULL,
    `status`                     ENUM('AGUARDANDO','ATENDIDO','ENCERRADO') NOT NULL DEFAULT 'AGUARDANDO',
    `criado_por`                 INT UNSIGNED      NULL,
    `created_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pas_processo` (`processo_administrativo_id`, `data_solicitacao`),
    KEY `ix_pas_status_prazo` (`status`, `prazo`),
    KEY `ix_pas_destinatario` (`destinatario_lotacao_id`),
    KEY `ix_pas_criado_por` (`criado_por`),
    CONSTRAINT `fk_pas_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pas_destinatario`
        FOREIGN KEY (`destinatario_lotacao_id`) REFERENCES `lotacoes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pas_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Anotacoes (notas livres internas) de um processo administrativo.
-- Nao confundir com o tipo de evento "Anotacao" do SIGADOC.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_administrativo_anotacoes` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `texto`                      TEXT         NOT NULL,
    `criado_por`                 INT UNSIGNED NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_paan_processo` (`processo_administrativo_id`, `created_at`),
    KEY `ix_paan_criado_por` (`criado_por`),
    CONSTRAINT `fk_paan_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paan_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
