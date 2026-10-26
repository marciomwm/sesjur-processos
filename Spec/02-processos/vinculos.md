# Vínculos (N:N)

Relação entre um **Processo Administrativo** e um **Processo Judicial** ou **Extrajudicial**.

```
processos_administrativos 1 ──< processo_administrativo_judicial >── 1 processos_judiciais
processos_administrativos 1 ──< processo_administrativo_extrajudicial >── 1 processos_extrajudiciais
                                 UNIQUE (adm_id, outro_id)
```

## Regras

- **RN-VIN-001** Cardinalidade N:N: um Administrativo pode ter vários Judiciais/Extrajudiciais e vice-versa (ex.: 2 Administrativos para 1 Judicial).
- **RN-VIN-002** O vínculo é por **chave estrangeira numérica**, nunca por texto (Protocolo/CNJ).
- **RN-VIN-003** `UNIQUE (administrativo, outro)` impede duplicidade.
- **RN-VIN-004** **Remover um vínculo nunca exclui processo algum.** O cascade só age quando um dos processos é de fato excluído.
- **RN-VIN-005** Não há vínculo Judicial↔Judicial nem Administrativo↔Administrativo.
- **RN-VIN-006** Vínculos com Extrajudicial ficam no **Histórico** dos dois processos (Auditoria).
- **RN-VIN-007** A sincronização de vínculos no formulário (adicionar/remover vários de uma vez) é transacional.

## Interface

- **Widget de vínculos** (`partials/vinculos-widget.php`, `vinculos.js`) nos formulários: autocomplete por `api/pesquisar-processos-*.php`.
- **Navegação bidirecional** nas fichas: Administrativo → guias Judiciais/Extrajudiciais; Judicial/Extrajudicial → Administrativos vinculados.
- **Remoção** por `remover-vinculo.php` (POST + CSRF; parâmetro `retorno` validado).
- **API JSON** `api/vinculos.php`: criar/remover.

## Critérios de aceitação

- **CA-VIN-01** Vincular o mesmo par duas vezes não cria duplicata.
- **CA-VIN-02** Remover o vínculo preserva os dois processos e é restabelecível.
- **CA-VIN-03** Excluir um processo remove seus vínculos e preserva o outro lado.
- **CA-VIN-04** O vínculo é navegável a partir dos dois lados em um clique.

## Situação

✅ Implementado e verificado de ponta a ponta (README §10, "§60 Relacionamento").
