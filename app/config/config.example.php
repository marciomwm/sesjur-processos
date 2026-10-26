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

    'api' => [
        /**
         * API de Integracao (api/v1/): exportacao somente leitura para sistemas
         * externos, autenticada por token. Os tokens sao criados por
         * linha de comando: php database/api-clientes.php ajuda
         */
        // Endereco publico da API, informado ao consumidor (servidor na VPN da SES-MT).
        'url_publica'       => 'https://sesjur.saude.mt.gov.br/sesjur-processos/api/v1/',
        // Recusa chamadas sem HTTPS. Padrao true; use false so no ambiente local (Laragon, http://localhost).
        'exigir_https'      => true,
        /**
         * IPs dos proxies reversos que publicam o SESJUR. Somente deles sao
         * aceitos X-Forwarded-Proto (HTTPS encerrado no proxy) e
         * X-Forwarded-For (IP real do cliente, usado nos IPs permitidos e nos
         * limites). Vazio = acesso direto ao Apache, sem proxy.
         * VPN da SES-MT: nginx em 10.15.1.11 (sesjur.saude.mt.gov.br) na frente
         * da VM 10.15.0.115 (Apache/PHP). Confira o IP que o Apache ve em
         * api_requisicoes.ip apos a primeira chamada e ajuste se for outro.
         */
        'proxies_confiaveis' => ['10.15.1.11'],
        // Chamadas por minuto permitidas a cada cliente.
        'limite_por_minuto' => 120,
        // Chamadas com token invalido por IP, em 10 minutos, antes do bloqueio temporario.
        'max_falhas_por_ip' => 20,
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
