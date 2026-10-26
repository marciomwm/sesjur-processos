# Intimações pessoais

Intimação dirigida a um **Agente Público** (a *pessoa intimada*) num processo já cadastrado.

## Acesso

**Restritas aos Perfis Administrador e Gestor.** Só eles veem o painel e as intimações (inclusive nas fichas dos processos), registram, alteram, registram cumprimento ou excluem. Usuário comum não vê o módulo.

## Atributos

| Campo | Regra |
|---|---|
| Processo | **Exatamente um** entre Judicial, Extrajudicial ou Administrativo, por chave estrangeira |
| Pessoa intimada | Agente Público |
| Quem recebeu | Agente Público (pode ser a própria pessoa intimada) |
| Data do recebimento | Obrigatória |
| Data do prazo | Data-limite **informada** (o sistema não calcula prazo processual) |
| Data do cumprimento | A da providência (não a do registro); obrigatória se `Cumprida` |
| Status | `Pendente`, `Em andamento`, `Cumprida`, `Cancelada` (informado) |
| Autoria | Quem registrou no sistema, à parte |

## Situação do prazo (calculada, nunca gravada)

| Situação | Condição |
|---|---|
| Dentro do prazo | prazo futuro além de 3 dias |
| Próxima do vencimento | prazo em **até 3 dias**, inclusive hoje |
| Vencida | prazo passado, ainda `Pendente` ou `Em andamento` |
| Cumprida no prazo | cumprimento até o prazo |
| Cumprida fora do prazo | cumprimento depois do prazo |
| — | `Cancelada` não tem situação |

## Regras

- **RN-INT-001** Um processo e um agente podem ter **várias** intimações.
- **RN-INT-002** A mesma combinação (processo, intimado, recebimento, prazo) só é gravada com **confirmação** (possível duplicidade).
- **RN-INT-003** **Retificar o prazo exige justificativa.**
- **RN-INT-004** **Exclusão lógica, com motivo**, preservando o histórico.
- **RN-INT-005** Um processo com intimações (**mesmo excluídas**) **não pode ser excluído**.
- **RN-INT-006** Evitar "notificação", "citação" e "comunicação" como nome da entidade.

## Telas

`intimacoes-pessoais.php` (painel), `nova-intimacao.php`, `editar-intimacao.php`, `intimacao.php` (ficha), `registrar-cumprimento-intimacao.php`; blocos nas fichas dos processos. Serviço: `IntimacaoPessoalService`.

## Critérios de aceitação

- **CA-INT-01** Usuário sem Perfil adequado não vê painel, bloco na ficha nem acessa a URL.
- **CA-INT-02** Marcar `Cumprida` sem data de cumprimento é recusado.
- **CA-INT-03** Alterar o prazo sem justificativa é recusado; com justificativa, fica na Auditoria.
- **CA-INT-04** Intimação duplicada pede confirmação explícita.
- **CA-INT-05** Excluir uma intimação mantém o registro (lógico) e continua impedindo a exclusão do processo.
- **CA-INT-06** Prazo daqui a 3 dias aparece como "Próxima do vencimento"; daqui a 4, "Dentro do prazo".

## Situação

✅ Implementado (`tests/php/intimacoes.php`; migração `2026-10-15_intimacoes_pessoais`).
