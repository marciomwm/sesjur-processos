-- =====================================================================
-- Migracao 2026-10-15 - Modelos do Gerador de Expedientes no banco.
--
-- Ate aqui os modelos ficavam no localStorage de cada navegador. Passam a
-- ser gravados no banco, com visibilidade:
--   * INDIVIDUAL    (padrao) - visivel apenas para quem o criou;
--   * COMPARTILHADO - visivel e utilizavel por todos os usuarios. So
--     Administrador ou Gestor cria, altera, exclui ou muda a visibilidade
--     de um modelo compartilhado.
-- Favorito e contagem de uso sao de cada usuario
-- (expediente_modelo_usuarios), nunca do modelo.
--
-- Os modelos antigos do localStorage NAO sao importados: cada usuario
-- recria os seus.
--
-- Para bancos criados antes desta data. Nenhum dado existente e alterado.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > backup.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-15_modelos_expediente.sql
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `expediente_modelos` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nome`           VARCHAR(150)  NOT NULL,
    `tipo`           VARCHAR(80)   NOT NULL DEFAULT 'Outro' COMMENT 'Ofício, Despacho... (lista mantida no gerador)',
    `descricao`      VARCHAR(1000) NULL,
    `conteudo`       JSON          NOT NULL COMMENT 'base, html, personalizadas, selecao, formato, assuntoPadrao',
    `visibilidade`   ENUM('INDIVIDUAL','COMPARTILHADO') NOT NULL DEFAULT 'INDIVIDUAL',
    `criado_por`     INT UNSIGNED  NOT NULL,
    `atualizado_por` INT UNSIGNED  NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_em_visibilidade` (`visibilidade`),
    KEY `ix_em_criado_por` (`criado_por`),
    CONSTRAINT `fk_em_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_em_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expediente_modelo_usuarios` (
    `modelo_id`     INT UNSIGNED NOT NULL,
    `usuario_id`    INT UNSIGNED NOT NULL,
    `favorito`      TINYINT(1)   NOT NULL DEFAULT 0,
    `usos`          INT UNSIGNED NOT NULL DEFAULT 0,
    `ultimo_uso_em` DATETIME     NULL,
    PRIMARY KEY (`modelo_id`, `usuario_id`),
    KEY `ix_emu_usuario` (`usuario_id`),
    CONSTRAINT `fk_emu_modelo`
        FOREIGN KEY (`modelo_id`) REFERENCES `expediente_modelos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_emu_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
