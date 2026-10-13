-- =====================================================================
-- Migracao 2026-09-25 - Perfis de acesso, login e designacao de processos.
--   * usuarios.perfil (ADMINISTRADOR | GESTOR | USUARIO)
--   * usuario_credenciais (senha para login)
--   * designacao de processos administrativos e judiciais a usuarios
--
-- Para bancos criados antes desta data. NAO apaga dados e pode ser
-- executada mais de uma vez.
--   C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root gestao_juridica < database\migracao_2026-09-25_perfis_designacoes.sql
--
-- Depois de executar, defina o primeiro administrador (e a senha dele):
--   php database\definir-administrador.php email@ses.mt.gov.br
-- =====================================================================

USE `gestao_juridica`;

-- ---------------------------------------------------------------------
-- Perfil unico por usuario. Usuarios existentes recebem USUARIO.
-- (MySQL nao tem ADD COLUMN IF NOT EXISTS: a coluna so e criada se faltar.)
-- ---------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'perfil');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `usuarios`
        ADD COLUMN `perfil` ENUM(''ADMINISTRADOR'',''GESTOR'',''USUARIO'') NOT NULL DEFAULT ''USUARIO''
            COMMENT ''Perfil de acesso'' AFTER `lotacao_id`,
        ADD KEY `ix_usuarios_perfil` (`perfil`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Credenciais de acesso (1:1 com usuarios). Tabela propria para que o
-- hash da senha nunca apareca em consultas de cadastro/listagem.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuario_credenciais` (
    `usuario_id`         INT UNSIGNED      NOT NULL,
    `senha_hash`         VARCHAR(255)      NOT NULL COMMENT 'password_hash(); nunca texto puro',
    `exigir_troca_senha` TINYINT(1)        NOT NULL DEFAULT 1,
    `tentativas_login`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `bloqueado_ate`      DATETIME          NULL COMMENT 'Bloqueio temporario apos tentativas falhas',
    `ultimo_login`       DATETIME          NULL,
    `senha_alterada_em`  DATETIME          NULL,
    `created_at`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`),
    CONSTRAINT `ck_credenciais_troca` CHECK (`exigir_troca_senha` IN (0, 1)),
    CONSTRAINT `fk_credenciais_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Designacao de processos a usuarios para acompanhamento. Um processo
-- pode ter varios designados. Excluir o processo remove as designacoes;
-- usuarios nunca sao excluidos (apenas inativados).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processo_administrativo_designacoes` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_administrativo_id` INT UNSIGNED NOT NULL,
    `usuario_id`                 INT UNSIGNED NOT NULL,
    `designado_por`              INT UNSIGNED NULL COMMENT 'Administrador/gestor que designou',
    `observacao`                 VARCHAR(255) NULL,
    `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pad_processo_usuario` (`processo_administrativo_id`, `usuario_id`),
    KEY `ix_pad_usuario` (`usuario_id`),
    CONSTRAINT `fk_pad_processo`
        FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pad_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pad_designado_por`
        FOREIGN KEY (`designado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `processo_judicial_designacoes` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `processo_judicial_id` INT UNSIGNED NOT NULL,
    `usuario_id`           INT UNSIGNED NOT NULL,
    `designado_por`        INT UNSIGNED NULL COMMENT 'Administrador/gestor que designou',
    `observacao`           VARCHAR(255) NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pjd_processo_usuario` (`processo_judicial_id`, `usuario_id`),
    KEY `ix_pjd_usuario` (`usuario_id`),
    CONSTRAINT `fk_pjd_processo`
        FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pjd_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pjd_designado_por`
        FOREIGN KEY (`designado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
