# Prazo, Prazo vigente e Atividade

Lógica pura em `app/Core/Prazo.php`; recálculo orquestrado por `ProcessoAdministrativoService::recalcularSituacaoDasAcoes()`.

## Definições

| Conceito | Regra |
|---|---|
| **Prazo** (do processo) | Data com hora opcional (sem hora vale até o fim do dia). Vazio ⇒ abertura + 30 dias; sem abertura ⇒ hoje + 30 |
| **Prazo vigente** | O **mais próximo do vencimento** entre o Prazo do processo e os Prazos das Tarefas **não finalizadas** |
| **Atividade** | `ativo` enquanto houver ao menos uma Tarefa não finalizada; `inativo` sem Tarefas ou com todas finalizadas |

## Regras

- **RN-PRZ-001** Prazo vigente e Atividade são **calculados**, nunca editados à mão.
- **RN-PRZ-002** São recalculados **na mesma transação** de qualquer alteração do processo ou de suas Tarefas.
- **RN-PRZ-003** Processos existentes receberam, na migração `2026-09-28`, abertura + 30 dias como Prazo.
- **RN-PRZ-004** Solicitações, Anotações e Intimações **não** entram no cálculo.
- **RN-PRZ-005** O sistema **não calcula prazos processuais** (contagem em dias úteis/uteis, suspensões etc.): prazo é data-limite informada.
- **RN-PRZ-006** A ficha informa **qual Tarefa** definiu o Prazo vigente, quando for o caso.

## Uso na interface

- Listagem de Administrativos: filtro por Atividade e por prazo (**vencidos** / **próximos 7 dias**); ordenação pelo Prazo vigente.
- Formulário: o campo Prazo acompanha a Data de abertura enquanto ainda for o padrão.

## Critérios de aceitação

- **CA-PRZ-01** Sem Tarefas, Prazo vigente = Prazo do processo e Atividade = inativo.
- **CA-PRZ-02** Tarefa pendente com prazo menor que o do processo torna-se o Prazo vigente.
- **CA-PRZ-03** Prazo sem hora é considerado vencido apenas após o fim do dia.
- **CA-PRZ-04** Cancelar a Tarefa que definia o Prazo vigente devolve o vigente ao próximo mais próximo.

## Situação

✅ Implementado (`tests/php/acoes.php`).
