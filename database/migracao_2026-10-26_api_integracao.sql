-- =====================================================================
-- Migracao 2026-10-26 - API de Integracao (exportacao somente leitura).
--
--   * cria `api_clientes`: sistemas externos autorizados a importar dados
--     do SESJUR pela API v1 (api/v1/). Cada cliente tem um token proprio,
--     guardado apenas como hash SHA-256 (o token em claro e exibido uma
--     unica vez, na criacao), escopos, IPs permitidos opcionais, validade
--     opcional e um Usuario responsavel;
--   * cria `api_requisicoes`: registro de cada chamada (cliente, recurso,
--     parametros, status HTTP, IP, duracao), usado tambem no limite de
--     requisicoes por minuto.
--
-- Nenhuma tabela existente e alterada. Idempotente: pode ser executada
-- mais de uma vez.
-- Faca backup antes:
--   C:\laragon\bin\mysql\...\mysqldump.exe -u root gestao_juridica > database\backup_antes_api_integracao_AAAAMMDD.sql
-- Aplicar:
--   C:\laragon\bin\mysql\...\mysql.exe -u root gestao_juridica < database\migracao_2026-10-26_api_integracao.sql
-- Reverter (revoga todos os acessos e apaga o registro de chamadas):
--   DROP TABLE api_requisicoes;
--   DROP TABLE api_clientes;
-- =====================================================================

USE `gestao_juridica`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `api_clientes` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`                   VARCHAR(150) NOT NULL COMMENT 'Sistema consumidor, ex.: Sistema do Dieggo',
    `responsavel_usuario_id` INT UNSIGNED NULL COMMENT 'Usuario responsavel pelo uso do token',
    `token_prefixo`          CHAR(12)     NOT NULL COMMENT 'Parte publica do token, para localizar o cliente',
    `token_hash`             CHAR(64)     NOT NULL COMMENT 'SHA-256 do token completo; o token em claro nunca e gravado',
    `escopos`                JSON         NOT NULL COMMENT 'Recursos liberados, ex.: ["processos_judiciais"]',
    `ips_permitidos`         VARCHAR(500) NULL COMMENT 'IPs ou faixas IPv4 CIDR separados por virgula; NULL = qualquer IP',
    `ativo`                  TINYINT(1)   NOT NULL DEFAULT 1,
    `expira_em`              DATETIME     NULL COMMENT 'NULL = sem validade',
    `ultimo_uso_em`          DATETIME     NULL,
    `ultimo_ip`              VARCHAR(45)  NULL,
    `revogado_em`            DATETIME     NULL,
    `observacao`             VARCHAR(500) NULL,
    `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_clientes_prefixo` (`token_prefixo`),
    KEY `ix_api_clientes_responsavel` (`responsavel_usuario_id`),
    CONSTRAINT `ck_api_clientes_ativo` CHECK (`ativo` IN (0, 1)),
    CONSTRAINT `fk_api_clientes_responsavel`
        FOREIGN KEY (`responsavel_usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_requisicoes` (
    `id`          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cliente_id`  INT UNSIGNED      NULL COMMENT 'NULL = token ausente ou invalido',
    `metodo`      VARCHAR(10)       NOT NULL,
    `recurso`     VARCHAR(60)       NOT NULL,
    `parametros`  VARCHAR(500)      NULL COMMENT 'Query string (nunca contem o token)',
    `status_http` SMALLINT UNSIGNED NOT NULL,
    `ip`          VARCHAR(45)       NULL,
    `user_agent`  VARCHAR(255)      NULL,
    `duracao_ms`  INT UNSIGNED      NULL,
    `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_api_req_cliente_data` (`cliente_id`, `created_at`),
    KEY `ix_api_req_ip_data` (`ip`, `created_at`),
    CONSTRAINT `fk_api_req_cliente`
        FOREIGN KEY (`cliente_id`) REFERENCES `api_clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
