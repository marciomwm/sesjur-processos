<?php
/**
 * Configuracao da aplicacao.
 * Copie este arquivo para config.php e ajuste as credenciais locais.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'gestao_juridica',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'app' => [
        // Nome exibido na interface.
        'nome'     => 'SESJUR',
        'unidade'  => 'Gestão Jurídica da Saúde',
        // URL base da aplicacao dentro do htdocs do XAMPP.
        'base_url' => '/gestao-juridica/public',
        // URL dos endpoints JSON (pasta api/ na raiz do projeto).
        'api_url'  => '/gestao-juridica/api',
        // Exibe detalhes de erro na tela (use false em producao).
        'debug'    => true,
        'timezone' => 'America/Cuiaba',
        'itens_por_pagina' => 20,
    ],

    'usuarios' => [
        // Torna a data de nascimento obrigatoria no cadastro e na edicao.
        // Antes de tornar a coluna NOT NULL no banco, preencha os registros antigos.
        'data_nascimento_obrigatoria' => false,
    ],

    'auth' => [
        // Encerra a sessao apos este tempo sem uso (minimo 5).
        'inatividade_minutos' => 120,
        // Tentativas de senha incorreta antes do bloqueio temporario.
        'max_tentativas'      => 5,
        // Duracao do bloqueio temporario.
        'bloqueio_minutos'    => 15,
    ],

    'integracao' => [
        /**
         * Allowlist de hosts externos autorizados.
         * IMPORTANTE: preencher com os dominios institucionais REAIS do SIGADOC
         * e do PJe. Nenhum dominio e presumido pela aplicacao.
         * Estes valores tambem podem ser mantidos na tabela `configuracoes`
         * (tela Sistema > Configuracoes), que tem precedencia sobre este arquivo.
         */
        'sigadoc_hosts' => [],
        'pje_hosts'     => [],
        // Exige HTTPS nas URLs externas informadas pelo usuario.
        'exigir_https'  => true,
        /**
         * Template opcional para consulta direta de um protocolo no SIGADOC.
         * Vazio = usa o padrao embutido na extensao, observado no sistema real.
         * Substituicoes: {host} e {protocolo}.
         */
        'sigadoc_url_template' => '',
        // Tempo maximo (ms) que a aplicacao aguarda a resposta da extensao.
        'timeout_ms'    => 45000,
    ],
];
