-- MariaDB dump 10.19  Distrib 10.4.28-MariaDB, for osx10.10 (x86_64)
--
-- Host: 127.0.0.1    Database: gestao_juridica
-- ------------------------------------------------------
-- Server version	10.4.28-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `assuntos_processuais`
--

DROP TABLE IF EXISTS `assuntos_processuais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assuntos_processuais` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `processo_judicial_id` int(10) unsigned NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `codigo` varchar(40) DEFAULT NULL COMMENT 'Codigo CNJ do assunto, quando exibido',
  `principal` tinyint(1) NOT NULL DEFAULT 0,
  `ordem` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ap_processo` (`processo_judicial_id`),
  KEY `ix_ap_descricao` (`descricao`),
  CONSTRAINT `fk_ap_processo_judicial` FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assuntos_processuais`
--

LOCK TABLES `assuntos_processuais` WRITE;
/*!40000 ALTER TABLE `assuntos_processuais` DISABLE KEYS */;
/*!40000 ALTER TABLE `assuntos_processuais` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracoes`
--

DROP TABLE IF EXISTS `configuracoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracoes` (
  `chave` varchar(80) NOT NULL,
  `valor` text DEFAULT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracoes`
--

LOCK TABLES `configuracoes` WRITE;
/*!40000 ALTER TABLE `configuracoes` DISABLE KEYS */;
INSERT INTO `configuracoes` VALUES ('exigir_https','1','Exige HTTPS nas URLs externas (1 = sim, 0 = nao).','2026-09-20 19:18:01'),('pje_hosts','','Hosts autorizados do PJe, separados por virgula. Preencher com o(s) dominio(s) real(is).','2026-09-20 19:18:01'),('sigadoc_hosts','www.sigadoc.mt.gov.br','Hosts autorizados do SIGADOC, separados por virgula. Preencher com o dominio institucional real.','2026-09-20 20:02:21'),('sigadoc_url_template','','Template de consulta direta no SIGADOC. Vazio = padrao da extensao.','2026-09-20 20:16:37');
/*!40000 ALTER TABLE `configuracoes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partes_processuais`
--

DROP TABLE IF EXISTS `partes_processuais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `partes_processuais` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `processo_judicial_id` int(10) unsigned NOT NULL,
  `nome` varchar(255) NOT NULL,
  `documento` varchar(40) DEFAULT NULL COMMENT 'CPF/CNPJ quando legitimamente exibido',
  `polo` enum('ATIVO','PASSIVO','TERCEIRO','OUTRO') NOT NULL DEFAULT 'OUTRO',
  `tipo` varchar(120) DEFAULT NULL COMMENT 'Autor, Reu, Exequente, Interessado, etc.',
  `representacao` varchar(255) DEFAULT NULL COMMENT 'Advogado/procurador, quando disponivel',
  `observacoes` text DEFAULT NULL,
  `ordem` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_pp_processo` (`processo_judicial_id`),
  KEY `ix_pp_nome` (`nome`),
  KEY `ix_pp_polo` (`polo`),
  CONSTRAINT `fk_pp_processo_judicial` FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partes_processuais`
--

LOCK TABLES `partes_processuais` WRITE;
/*!40000 ALTER TABLE `partes_processuais` DISABLE KEYS */;
/*!40000 ALTER TABLE `partes_processuais` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `processo_administrativo_judicial`
--

DROP TABLE IF EXISTS `processo_administrativo_judicial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processo_administrativo_judicial` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `processo_administrativo_id` int(10) unsigned NOT NULL,
  `processo_judicial_id` int(10) unsigned NOT NULL,
  `data_vinculo` date NOT NULL DEFAULT curdate(),
  `observacao` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paj_par` (`processo_administrativo_id`,`processo_judicial_id`),
  KEY `ix_paj_judicial` (`processo_judicial_id`),
  CONSTRAINT `fk_paj_administrativo` FOREIGN KEY (`processo_administrativo_id`) REFERENCES `processos_administrativos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paj_judicial` FOREIGN KEY (`processo_judicial_id`) REFERENCES `processos_judiciais` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `processo_administrativo_judicial`
--

LOCK TABLES `processo_administrativo_judicial` WRITE;
/*!40000 ALTER TABLE `processo_administrativo_judicial` DISABLE KEYS */;
INSERT INTO `processo_administrativo_judicial` VALUES (1,1,1,'2026-09-20','Vinculo de demonstracao','2026-09-20 19:18:01'),(5,4,3,'2026-09-20',NULL,'2026-09-20 21:09:13'),(6,5,4,'2026-09-20',NULL,'2026-09-20 21:10:33');
/*!40000 ALTER TABLE `processo_administrativo_judicial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `processos_administrativos`
--

DROP TABLE IF EXISTS `processos_administrativos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processos_administrativos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `protocolo` varchar(60) NOT NULL COMMENT 'Ex.: SEPLAG-PRO-2026/16696',
  `data_abertura` date DEFAULT NULL,
  `objeto` text DEFAULT NULL,
  `origem` enum('MANUAL','SIGADOC') NOT NULL DEFAULT 'MANUAL',
  `url_origem` varchar(500) DEFAULT NULL,
  `extrator_versao` varchar(20) DEFAULT NULL,
  `data_extracao` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pa_protocolo` (`protocolo`),
  KEY `ix_pa_data_abertura` (`data_abertura`),
  KEY `ix_pa_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `processos_administrativos`
--

LOCK TABLES `processos_administrativos` WRITE;
/*!40000 ALTER TABLE `processos_administrativos` DISABLE KEYS */;
INSERT INTO `processos_administrativos` VALUES (1,'SEPLAG-PRO-2026/16696','2026-09-17','Solicitação de informações para defesa do Estado autos nº 1008695-17.2026.8.11.0006 - FGTS - SES','MANUAL',NULL,NULL,NULL,'2026-09-20 19:18:01','2026-09-20 19:18:01'),(3,'SES-PRO-2026/23509','2026-04-07','PACIENTE GELSON DE ARRUDA LOBO - SOLICITA FORNECIMENTO SUPLEMENTO ALIMENTAR, FRASCOS E EQUIPOS PARA DIETA ENTERAL - N° 1037360-26.2024.8.11.0002 (ORIGINARIO: 1037360-26.2024.8.11.0002 - SES-PRO-2024/96182)','SIGADOC',NULL,NULL,'2026-09-20 20:21:00','2026-09-20 20:21:23','2026-09-20 20:21:23'),(4,'PGE-PRO-2026/16189','2026-09-04','INTERESSADO: BENJAMIN NUNES SANTOS SANTAROSA - SOLICITA: EXAME PONTENCIAL EVOCADO AUDITIVO/BERA COM SEDAÇÃO - PROCESSO JUDICIAL Nº 1032204-20.2025.8.11.0003','SIGADOC',NULL,NULL,'2026-09-20 21:08:52','2026-09-20 21:07:56','2026-09-20 21:09:13'),(5,'SEPLAG-PRO-2026/16646','2026-09-16','Solicitação de informações para defesa do Estado autos nº 1027451-47.2026.8.11.0015 - FGTS e Férias Temporário – SES.','SIGADOC',NULL,NULL,'2026-09-21 06:46:14','2026-09-20 21:10:33','2026-09-21 06:46:22');
/*!40000 ALTER TABLE `processos_administrativos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `processos_judiciais`
--

DROP TABLE IF EXISTS `processos_judiciais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processos_judiciais` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero_cnj` varchar(25) NOT NULL COMMENT 'Ex.: 1008695-17.2026.8.11.0006',
  `tribunal` varchar(120) DEFAULT NULL,
  `orgao_julgador` varchar(180) DEFAULT NULL,
  `vara` varchar(180) DEFAULT NULL,
  `comarca` varchar(180) DEFAULT NULL,
  `jurisdicao` varchar(180) DEFAULT NULL,
  `classe_judicial` varchar(180) DEFAULT NULL,
  `assunto_principal` varchar(255) DEFAULT NULL,
  `data_distribuicao` date DEFAULT NULL,
  `valor_causa` decimal(15,2) DEFAULT NULL,
  `situacao` varchar(120) DEFAULT NULL,
  `segredo_justica` tinyint(1) DEFAULT NULL COMMENT 'NULL = informacao nao disponivel',
  `resumo` text DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `origem` enum('MANUAL','PJE') NOT NULL DEFAULT 'MANUAL',
  `url_origem` varchar(500) DEFAULT NULL,
  `extrator_versao` varchar(20) DEFAULT NULL,
  `data_extracao` datetime DEFAULT NULL,
  `data_ultima_atualizacao` date DEFAULT NULL COMMENT 'Ultima atualizacao informada pela fonte',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pj_numero_cnj` (`numero_cnj`),
  KEY `ix_pj_tribunal` (`tribunal`),
  KEY `ix_pj_comarca` (`comarca`),
  KEY `ix_pj_situacao` (`situacao`),
  KEY `ix_pj_data_distribuicao` (`data_distribuicao`),
  KEY `ix_pj_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `processos_judiciais`
--

LOCK TABLES `processos_judiciais` WRITE;
/*!40000 ALTER TABLE `processos_judiciais` DISABLE KEYS */;
INSERT INTO `processos_judiciais` VALUES (1,'1008695-17.2026.8.11.0006',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Processo judicial de demonstracao. Metadados a serem completados pelo PJe.',NULL,'MANUAL',NULL,NULL,NULL,NULL,'2026-09-20 19:18:01','2026-09-20 19:18:01'),(3,'1032204-20.2025.8.11.0003',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'MANUAL',NULL,NULL,NULL,NULL,'2026-09-20 21:07:28','2026-09-20 21:07:28'),(4,'1027451-47.2026.8.11.0015',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'MANUAL',NULL,NULL,NULL,NULL,'2026-09-20 21:10:01','2026-09-20 21:10:01');
/*!40000 ALTER TABLE `processos_judiciais` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-21  7:07:56
