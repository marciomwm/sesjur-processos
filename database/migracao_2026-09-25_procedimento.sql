-- =====================================================================
-- Migracao 2026-09-25 - Campo "Procedimento" do processo administrativo.
--
-- Para bancos criados antes desta data. NAO apaga dados.
--   C:\xampp\mysql\bin\mysql.exe -u root gestao_juridica < database\migracao_2026-09-25_procedimento.sql
-- =====================================================================

USE `gestao_juridica`;

ALTER TABLE `processos_administrativos`
    ADD COLUMN `procedimento` VARCHAR(160) NULL COMMENT 'Ex.: Fornecimento de Produto de Saude, Ressarcimento Interfederativo' AFTER `objeto`,
    ADD KEY `ix_pa_procedimento` (`procedimento`);
