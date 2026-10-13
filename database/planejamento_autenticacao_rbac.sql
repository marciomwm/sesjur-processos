-- =====================================================================
-- PLANEJAMENTO - Autenticacao e perfis de acesso (RBAC).
--
-- *** NAO EXECUTAR. SUBSTITUIDO em 2026-09-25 por
--     migracao_2026-09-25_perfis_designacoes.sql, que adotou um perfil
--     unico por usuario (usuarios.perfil: ADMINISTRADOR/GESTOR/USUARIO)
--     e usuario_credenciais. Mantido apenas como registro historico. ***
-- Documenta como a autenticacao sera acoplada ao cadastro de usuarios
-- sem alterar a tabela `usuarios` nem os dados ja gravados.
--
-- Decisoes:
-- 1) Credenciais em tabela propria (1:1), e nao como colunas em `usuarios`.
--    - Nem todo agente cadastrado tera acesso ao sistema.
--    - `senha_hash` nunca aparece em consultas de cadastro/listagem
--      (nao ha risco de vazar o hash num SELECT u.* qualquer).
--    - Permite trocar o mecanismo (ex.: login institucional/LDAP) sem
--      mexer no cadastro.
-- 2) Perfis N:N (usuario_perfis) em vez de um unico `perfil_id`:
--    um gestor pode acumular "Gestor" e "Assessor"; RBAC real associa
--    permissoes a perfis, e perfis a usuarios.
-- 3) Senha: somente password_hash() / password_verify() do PHP
--    (PASSWORD_DEFAULT; VARCHAR(255) comporta futuras trocas de algoritmo,
--    com password_needs_rehash() no login).
-- =====================================================================

USE `gestao_juridica`;

CREATE TABLE `usuario_credenciais` (
    `usuario_id`         INT UNSIGNED NOT NULL,
    `senha_hash`         VARCHAR(255) NOT NULL COMMENT 'password_hash(); nunca texto puro',
    `ultimo_login`       DATETIME     NULL,
    `tentativas_login`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `bloqueado_ate`      DATETIME     NULL COMMENT 'Bloqueio temporario apos N tentativas',
    `senha_alterada_em`  DATETIME     NULL,
    `exigir_troca_senha` TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`),
    CONSTRAINT `fk_credenciais_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `perfis` (
    `id`        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo`    VARCHAR(40)  NOT NULL COMMENT 'Estavel, usado no codigo: ADMINISTRADOR, GESTOR...',
    `nome`      VARCHAR(80)  NOT NULL,
    `descricao` VARCHAR(255) NULL,
    `ativo`     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_perfis_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissoes` (
    `id`        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo`    VARCHAR(60)  NOT NULL COMMENT 'Ex.: usuarios.criar, usuarios.editar, processos.excluir',
    `descricao` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissoes_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `perfil_permissoes` (
    `perfil_id`    SMALLINT UNSIGNED NOT NULL,
    `permissao_id` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`perfil_id`, `permissao_id`),
    CONSTRAINT `fk_pp_perfil`    FOREIGN KEY (`perfil_id`)    REFERENCES `perfis` (`id`)     ON DELETE CASCADE,
    CONSTRAINT `fk_pp_permissao` FOREIGN KEY (`permissao_id`) REFERENCES `permissoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuario_perfis` (
    `usuario_id` INT UNSIGNED      NOT NULL,
    `perfil_id`  SMALLINT UNSIGNED NOT NULL,
    `created_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `perfil_id`),
    KEY `ix_up_perfil` (`perfil_id`),
    CONSTRAINT `fk_up_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_up_perfil`  FOREIGN KEY (`perfil_id`)  REFERENCES `perfis` (`id`)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `perfis` (`codigo`, `nome`) VALUES
    ('ADMINISTRADOR', 'Administrador'),
    ('GESTOR',        'Gestor'),
    ('ASSESSOR',      'Assessor'),
    ('SERVIDOR',      'Servidor'),
    ('CONSULTA',      'Consulta');

-- Regras do login (a implementar na camada PHP):
-- * usuario inativo (usuarios.ativo = 0) nunca autentica, mesmo com senha correta;
-- * session_regenerate_id(true) apos login bem-sucedido;
-- * mensagem unica para e-mail ou senha incorretos (nao revelar qual falhou);
-- * LOGIN_REALIZADO / LOGIN_FALHOU gravados em `auditoria`;
-- * a funcao usuario_logado_id() (app/Core/helpers.php) passa a ler a sessao,
--   e toda a auditoria ja existente comeca a registrar o autor automaticamente.
