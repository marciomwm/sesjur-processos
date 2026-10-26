# Bloqueios e depósitos judiciais

Controle financeiro, por **Processo Judicial**, de valores bloqueados nas contas do Estado por ordem judicial e de guias emitidas para depósito em juízo.

## Lançamento de bloqueio

| Campo | Regra |
|---|---|
| Tipo | **Bloqueio** (valor constrito) ou **Desbloqueio** (valor liberado) |
| Data | Obrigatória |
| Valor | Monetário positivo |
| Identificador | Opcional (protocolo SISBAJUD, ordem, conta) |
| Observação | Opcional |

- **RN-BLQ-001** **Saldo retido** = Σ bloqueios − Σ desbloqueios numa data. É **calculado, nunca gravado**.
- **RN-BLQ-002** O saldo **nunca fica negativo em ordem cronológica**: não se desbloqueia o que ainda não foi bloqueado. No mesmo dia, o bloqueio conta antes do desbloqueio.
- **RN-BLQ-003** Qualquer Usuário lança; só Administrador, Gestor ou **quem lançou** altera ou exclui.
- **RN-BLQ-004** Evitar os termos "movimentação" (reservado à cronologia do PJe), "constrição" e "penhora".

## Guia de Depósito Judicial

| Campo | Regra |
|---|---|
| Número, Emissão, Vencimento (opcional), Valor, Finalidade | Obrigatórios exceto vencimento |
| Status | Manual: `Emitida`, `Paga` (exige data do pagamento), `Cancelada` |
| Vencida | **Derivada**: segue `Emitida` depois do vencimento |

- **RN-GUI-001** Mesma regra de alteração do Lançamento.
- **RN-GUI-002** Evitar "boleto" e "DJE" como nome da entidade.

## Regra de exclusão

- **RN-FIN-001** Um Processo Judicial com lançamentos ou guias **não pode ser excluído**: registros financeiros nunca somem em cascata.

## Telas

`bloqueios-judiciais.php` (visão consolidada), `salvar-bloqueio-judicial.php`, `salvar-guia-deposito.php` (POST) e seções na ficha do Processo Judicial. Serviços: `BloqueioJudicialService`, `GuiaDepositoService`.

## Critérios de aceitação

- **CA-BLQ-01** Registrar desbloqueio maior que o saldo retido naquela data é recusado.
- **CA-BLQ-02** O saldo exibido bate com Σ bloqueios − Σ desbloqueios em qualquer data consultada.
- **CA-BLQ-03** Guia `Paga` sem data de pagamento é recusada.
- **CA-BLQ-04** Guia `Emitida` com vencimento passado aparece como Vencida.
- **CA-BLQ-05** Tentativa de excluir processo com lançamentos/guias é bloqueada com explicação.

## Situação

✅ Implementado (`tests/php/bloqueios.php`; migração `2026-10-13_bloqueios_depositos`).
