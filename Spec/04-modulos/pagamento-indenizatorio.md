# Pagamento indenizatório e Entregadores

Módulo específico para Processos Administrativos cujo **Procedimento** é "Pagamento Indenizatório": registra quem entregou o objeto (bem ou serviço) à Administração e o valor devido.

## Entregador

Pessoa física ou jurídica que entrega o objeto (presta serviço ou fornece bem).

| Campo | Regra |
|---|---|
| Documento | CPF (11 dígitos) ou CNPJ (14 caracteres, **inclusive CNPJ alfanumérico**), sem máscara; **UNIQUE** |
| Tipo de pessoa | **Derivado** do documento: CPF ⇒ Física; CNPJ ⇒ Jurídica |
| Nome | Nome ou razão social |

Validadores: `CpfValidator`, `CnpjValidator`. Telas: `entregadores.php`, `editar-entregador.php`. Serviço: `EntregadorService`.

## Dados do Pagamento

**Um registro por Processo Administrativo** (`processo_administrativo_pagamentos`, PK = processo).

| Campo | Regra |
|---|---|
| Demandante | Unidade Administrativa **da SES-MT** que demandou |
| Credor | Entregador |
| Objeto | Bem fornecido ou serviço prestado (texto) |
| Data de execução | Data da entrega do objeto |
| Competência | `AAAA-MM`, **derivada** da data de execução (CHECK no banco) |
| Valor | Decimal(15,2), **> 0** |

## Regras

- **RN-PAG-001** O módulo é exigido/exibido quando o Procedimento do processo é o de Pagamento Indenizatório (`PagamentoIndenizatorioService::exigidoNoProcesso`).
- **RN-PAG-002** Hoje o painel se ativa pela **comparação do nome** do procedimento; migrar para `procedimentos.modulo` (chave estável) é passo planejado, com backup e conciliação ([ADR 0005](../../docs/adr/0005-modulo-por-procedimento.md)).
- **RN-PAG-003** Competência nunca é digitada: é calculada da data de execução.
- **RN-PAG-004** Excluir o processo exclui os Dados do Pagamento (cascata); Entregador e Unidade nunca são excluídos em cascata (`RESTRICT`).
- **RN-PAG-005** Salvar valida e audita (`salvar-dados-pagamento.php`).

## Critérios de aceitação

- **CA-PAG-01** CPF e CNPJ inválidos (dígitos verificadores) são recusados; CNPJ alfanumérico válido é aceito.
- **CA-PAG-02** Mesmo documento não gera dois Entregadores.
- **CA-PAG-03** Valor zero ou negativo é recusado.
- **CA-PAG-04** Competência bate com o mês da data de execução.
- **CA-PAG-05** Um processo não pode ter dois registros de pagamento.

## Situação

✅ Implementado (`tests/php/pagamentos.php`; migração `2026-10-19_entregadores_pagamentos`, idempotente; reversão por `DROP TABLE`).
