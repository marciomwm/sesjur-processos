# Prioridades e Produtos de Saúde

## Prioridades

Critério que pede **atenção preferencial** ao Processo Administrativo (ex.: Idoso, Determinação Judicial).

| Campo | Regra |
|---|---|
| Nome, descrição, status | Cadastro em *Sistema › Prioridades* (**Administrador**) |
| Nível | **1 (baixa) a 5 (máxima)** |

- **RN-PRI-001** Um processo tem **nenhuma, uma ou várias** Prioridades, sem repetir (`processo_administrativo_prioridades`, N:N).
- **RN-PRI-002** Sempre exibida com **número e rótulo** ("Nível 5 · Máxima"), nunca só pela cor.
- **RN-PRI-003** Tabela de apoio: **inativada, nunca excluída**; inativar não retira de quem já tem.
- **RN-PRI-004** Mostrada como **chips** no cabeçalho da ficha; atribuída na guia Prioridades.
- **RN-PRI-005** Atribuições e remoções são auditadas (Histórico do processo).
- **RN-PRI-006** Não confundir com o sinalizador tri-estado "Prioridade" do Processo Judicial (lido do PJe).

Serviço: `PrioridadeService`; telas `prioridades.php`, `editar-prioridade.php`, `processo-prioridade.php`.

## Produtos de Saúde

Objeto material da demanda do Processo Administrativo: medicamento, procedimento, exame, órtese, transporte sanitário…

| Campo | Regra |
|---|---|
| Categoria | Medicamento, procedimento, exame, órtese… (Insumo é **uma** das categorias) |
| Descrição | Obrigatória |
| Classificação SIGTAP | Opcional |

- **RN-PRO-001** Um processo tem **N produtos, sem repetir o item**.
- **RN-PRO-002** Exclusão física com registro completo na Auditoria.
- **RN-PRO-003** Inclusão/edição em **modal** (`salvar-produto-saude.php`, `excluir-produto-saude.php`).
- **RN-PRO-004** Evitar "item" e "insumo" como nome da entidade.

### Classificação SIGTAP

Procedimento da Tabela SIGTAP do SUS: código de **10 dígitos**, grupo, subgrupo, forma de organização, competência, origem.

- **RN-SIG-001** Enquanto a tabela oficial não é importada, o código é cadastrado **manualmente** na primeira vez (`origem = MANUAL`) e **reaproveitado** depois.
- **RN-SIG-002** A importação oficial poderá preencher a mesma tabela com `origem = SIGTAP`.
- Pesquisa: `api/pesquisar-sigtap.php`.

> 🗓️ **Evolução planejada:** o Produto de Saúde passa a vir de um **Catálogo**, e a Categoria, de tabela de apoio — ver [demanda-saude.md](demanda-saude.md).

## Critérios de aceitação

- **CA-PRI-01** A mesma Prioridade não pode ser atribuída duas vezes ao processo.
- **CA-PRI-02** Prioridade inativa continua exibida em quem já a possui e não aparece para novas atribuições.
- **CA-PRO-01** Incluir o mesmo Produto duas vezes no processo é recusado.
- **CA-SIG-01** Código SIGTAP fora de 10 dígitos é recusado.

## Situação

✅ Implementado (`tests/php/processo-relacionados.php`; migração `2026-09-30_agentes_prioridades_produtos_extrajudiciais`).
