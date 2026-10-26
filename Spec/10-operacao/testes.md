# Testes

## Suíte PHP

```bash
php tests/run-tests.php
```

- Runner próprio (`tests/Runner.php`); cria e **destrói** o banco isolado `gestao_juridica_teste` a partir de `database/database.sql`. **O banco de produção não é tocado.**
- Sem MariaDB, os testes de integração são ignorados e os unitários continuam.
- Arquivos em `tests/php/`:

| Arquivo | Cobre |
|---|---|
| `validadores.php` | Protocolo, CNJ (formato + dígito), datas, moeda, tri-estado, polo, URL/allowlist |
| `extracao.php` | Contrato SIGADOC/PJe, ausentes, coleções vazias, divergência, falhas |
| `integracao.php` | CRUD, transações, duplicidade, N:N, filtros, ordenação, paginação, SQL injection como dado |
| `usuarios.php`, `agentes.php`, `acesso.php` | Usuários, agentes, perfis e autorização |
| `acoes.php` | Tarefas, prazo vigente, atividade |
| `solicitacoes.php`, `anotacoes.php` | Providências |
| `processo-relacionados.php` | Prioridades, produtos/SIGTAP, extrajudiciais |
| `bloqueios.php`, `pagamentos.php`, `intimacoes.php` | Módulos financeiros e intimações |
| `unidades.php`, `recursos.php`, `configuracoes.php` | Estrutura, recursos, configurações |
| `expediente.php`, `modelos-expediente.php` | Gerador e Modelos |
| `interface.php` | Interface |

## Testes dos extratores (JavaScript)

```bash
php -S localhost:8080 -t .
```

Abrir `http://localhost:8080/tests/extractors/runner.html` (precisa ser por HTTP; `fetch` é bloqueado em `file://`; o `.htaccess` bloqueia `tests/` no Apache, por isso o servidor de desenvolvimento).

Cobre: página válida, variações de marcação (`dl/dt/dd`, `th/td`, tabela vertical, `"Rótulo: valor"`), campo ausente, DOM alterado, processo inexistente, sessão expirada, identificador divergente — nos dois sistemas — e **fixtures reais anonimizadas** (incluindo o teste de que o extrator não confunde o processo exibido com siglas da barra lateral).

Fixtures: `tests/fixtures/sigadoc/*.html`, `tests/fixtures/pje/*.html`; guia em `tests/fixtures/LEIA-ME.md`.

## Política de testes

- **RNF-TST-001** Toda regra de negócio nova (`RN-*`) tem ao menos um teste no arquivo do seu módulo.
- **RNF-TST-002** Mudança de seletor ⇒ novo caso em `tests/extractors/casos.js` + `VERSAO_EXTRATOR` incrementada.
- **RNF-TST-003** Fixtures **sempre anonimizadas**, preservando estrutura.
- **RNF-TST-004** Testes nunca usam o banco de produção.
- **RNF-TST-005** Antes de aplicar migração em dados reais: backup + execução da suíte em banco novo.

## Verificação manual de referência

Cadastro com vínculo; navegação bidirecional; N:N (2 Administrativos para 1 Judicial); partes por polo; assuntos principal + secundários; normalização do valor da causa; remoção de vínculo preservando processos; exclusão com cascata correta; mensagens de falha segura da extração.

> Contagens de testes variam entre versões da documentação (36, 82, 93 e 271 asserções aparecem em seções diferentes do README, em momentos distintos). Rodar a suíte para o número atual.
