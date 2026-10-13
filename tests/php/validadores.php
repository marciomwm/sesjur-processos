<?php
declare(strict_types=1);

/**
 * Testes de validação e normalização (não dependem do banco).
 * @var \Tests\Runner $t
 */

use App\Validators\CnjValidator;
use App\Validators\Normalizador;
use App\Validators\ProtocoloValidator;
use App\Validators\UrlExternaValidator;

$t->grupo('Validação do protocolo administrativo');

$t->teste('normaliza caixa, espaços e separadores', function ($t) {
    $t->igual(ProtocoloValidator::normalizar(' seplag-pro-2026/16696 '), 'SEPLAG-PRO-2026/16696', 'normalização');
    $t->igual(ProtocoloValidator::normalizar('SEPLAG - PRO - 2026 / 16696'), 'SEPLAG-PRO-2026/16696', 'espaços ao redor dos separadores');
    $t->igual(ProtocoloValidator::normalizar('SEPLAG–PRO–2026/16696'), 'SEPLAG-PRO-2026/16696', 'travessão vira hífen');
    $t->igual(ProtocoloValidator::normalizar('   '), null, 'entrada vazia vira null');
});

$t->teste('aceita o formato válido e rejeita os inválidos', function ($t) {
    $t->afirmar(ProtocoloValidator::valido('SEPLAG-PRO-2026/16696'), 'exemplo da especificação é válido');
    $t->afirmar(!ProtocoloValidator::valido('SEPLAG/2026'), 'formato incompleto é rejeitado');
    $t->afirmar(!ProtocoloValidator::valido('12345'), 'apenas números é rejeitado');
    $t->afirmar(!ProtocoloValidator::valido(null), 'null é rejeitado');
});

$t->teste('compara protocolos após normalização', function ($t) {
    $t->afirmar(ProtocoloValidator::equivalentes('seplag-pro-2026/16696', 'SEPLAG-PRO-2026/16696'), 'equivalentes');
    $t->afirmar(!ProtocoloValidator::equivalentes('SEPLAG-PRO-2026/16696', 'SEPLAG-PRO-2026/99999'), 'divergentes');
    $t->afirmar(!ProtocoloValidator::equivalentes(null, 'SEPLAG-PRO-2026/16696'), 'null nunca é equivalente');
});

$t->grupo('Validação do número CNJ');

$t->teste('normaliza para o formato com máscara', function ($t) {
    $t->igual(CnjValidator::normalizar('10086951720268110006'), '1008695-17.2026.8.11.0006', 'sem máscara');
    $t->igual(CnjValidator::normalizar('1008695-17.2026.8.11.0006'), '1008695-17.2026.8.11.0006', 'com máscara');
    $t->igual(CnjValidator::normalizar('123'), null, 'quantidade errada de dígitos');
});

$t->teste('confere o dígito verificador (módulo 97)', function ($t) {
    $t->afirmar(CnjValidator::valido('1008695-17.2026.8.11.0006'), 'exemplo real da especificação');
    $t->afirmar(!CnjValidator::valido('1008695-18.2026.8.11.0006'), 'dígito verificador adulterado');
    $t->afirmar(!CnjValidator::valido('0000000-00.0000.0.00.0000'), 'número nulo é rejeitado');
});

$t->teste('extrai números CNJ de texto livre', function ($t) {
    $objeto = 'Solicitação de informações para defesa do Estado autos nº 1008695-17.2026.8.11.0006 - FGTS - SES';
    $t->igual(CnjValidator::extrairDeTexto($objeto), ['1008695-17.2026.8.11.0006'], 'CNJ citado no objeto');
    $t->igual(CnjValidator::extrairDeTexto('sem processo'), [], 'nenhum CNJ');
});

$t->grupo('Normalização de valores');

$t->teste('datas em ISO', function ($t) {
    $t->igual(Normalizador::dataIso('17/09/2026'), '2026-09-17', 'dd/mm/aaaa');
    $t->igual(Normalizador::dataIso('2026-09-17'), '2026-09-17', 'já em ISO');
    $t->igual(Normalizador::dataIso('31/02/2026'), null, 'data impossível');
    $t->igual(Normalizador::dataIso('qualquer coisa'), null, 'texto irreconhecível');
    $t->igual(Normalizador::dataIso(null), null, 'null');
});

$t->teste('valores monetários', function ($t) {
    $t->igual(Normalizador::valorMonetario('R$ 45.320,75'), 45320.75, 'formato pt-BR');
    $t->igual(Normalizador::valorMonetario('45320.75'), 45320.75, 'ponto decimal');
    $t->igual(Normalizador::valorMonetario('0,00'), 0.0, 'zero');
    $t->igual(Normalizador::valorMonetario(''), null, 'vazio');
    $t->igual(Normalizador::valorMonetario('abc'), null, 'texto');
});

$t->teste('booleano tri-estado e polo', function ($t) {
    $t->igual(Normalizador::booleanoOuNulo('Sim'), true, 'Sim');
    $t->igual(Normalizador::booleanoOuNulo('Não'), false, 'Não');
    $t->igual(Normalizador::booleanoOuNulo(''), null, 'não informado');
    $t->igual(Normalizador::polo('Polo Ativo'), 'ATIVO', 'polo ativo');
    $t->igual(Normalizador::polo('polo passivo'), 'PASSIVO', 'polo passivo');
    $t->igual(Normalizador::polo('Terceiro interessado'), 'TERCEIRO', 'terceiro');
    $t->igual(Normalizador::polo(null), 'OUTRO', 'ausente vira OUTRO');
});

$t->teste('texto colapsa espaços e devolve null quando vazio', function ($t) {
    $t->igual(Normalizador::texto("  a   b \n c "), 'a b c', 'colapso de espaços');
    $t->igual(Normalizador::texto("\u{00A0}"), null, 'apenas NBSP vira null');
    $t->igual(Normalizador::texto(''), null, 'string vazia');
});

$t->grupo('Validação de URL externa e allowlist');

$t->teste('rejeita esquemas perigosos', function ($t) {
    $hosts = ['pje.exemplo.jus.br'];
    foreach (['javascript:alert(1)', 'data:text/html,<h1>x', 'file:///etc/passwd'] as $url) {
        $r = UrlExternaValidator::validar($url, $hosts);
        $t->afirmar(!$r['ok'], 'rejeita ' . $url);
        $t->igual($r['code'], 'URL_SCHEME_BLOCKED', 'código para ' . $url);
    }
});

$t->teste('exige HTTPS quando configurado', function ($t) {
    $hosts = ['pje.exemplo.jus.br'];
    $r = UrlExternaValidator::validar('http://pje.exemplo.jus.br/processo/1', $hosts, true);
    $t->afirmar(!$r['ok'], 'http é rejeitado');
    $t->igual($r['code'], 'URL_NOT_HTTPS', 'código do erro');

    $r = UrlExternaValidator::validar('http://pje.exemplo.jus.br/processo/1', $hosts, false);
    $t->afirmar($r['ok'], 'http é aceito quando a exigência está desligada');
});

$t->teste('aplica a allowlist de domínios', function ($t) {
    $hosts = ['pje.exemplo.jus.br'];
    $r = UrlExternaValidator::validar('https://pje.exemplo.jus.br/processo/1', $hosts);
    $t->afirmar($r['ok'], 'domínio autorizado é aceito');
    $t->igual($r['host'], 'pje.exemplo.jus.br', 'host extraído');

    $r = UrlExternaValidator::validar('https://sub.pje.exemplo.jus.br/processo/1', $hosts);
    $t->afirmar($r['ok'], 'subdomínio de domínio autorizado é aceito');

    $r = UrlExternaValidator::validar('https://malicioso.example.com/processo/1', $hosts);
    $t->afirmar(!$r['ok'], 'domínio não autorizado é rejeitado');
    $t->igual($r['code'], 'DOMAIN_NOT_ALLOWED', 'código do erro');

    $r = UrlExternaValidator::validar('https://pje.exemplo.jus.br.malicioso.com/x', $hosts);
    $t->afirmar(!$r['ok'], 'domínio com sufixo enganoso é rejeitado');
});

$t->teste('allowlist vazia bloqueia a integração', function ($t) {
    $r = UrlExternaValidator::validar('https://pje.exemplo.jus.br/p/1', []);
    $t->afirmar(!$r['ok'], 'sem allowlist nada é autorizado');
    $t->igual($r['code'], 'ALLOWLIST_EMPTY', 'código do erro');
});
