# Tarefas

Providência interna a executar, ligada a um Processo Administrativo (N por processo). Nome antigo: **Ação** — código, rotas e banco ainda usam `acao` (`AcaoService`, `acoes.php`, `processo_administrativo_acoes`).

## Atributos

| Campo | Regra |
|---|---|
| Descrição | Obrigatória |
| Prazo | Data + hora opcional (sem hora vale até o fim do dia) |
| Status | `Pendente`, `Em andamento`, `Concluída`, `Cancelada` — as duas últimas são **finalizadas** |
| Observação | Texto livre |
| Autoria | Criador, data/hora; nunca digitados |

## Regras

- **RN-TAR-001** Qualquer Usuário cria; **só Administrador, Gestor ou quem criou** altera, muda status ou exclui (regra no serviço).
- **RN-TAR-002** Tarefas **não finalizadas** entram no cálculo do Prazo vigente e da Atividade ([prazo-e-atividade.md](prazo-e-atividade.md)).
- **RN-TAR-003** Toda criação/edição/mudança de status/exclusão recalcula Prazo vigente e Atividade **na mesma transação**.
- **RN-TAR-004** Auditoria: `ACAO_CRIADA`, `ACAO_EDITADA`, `ACAO_EXCLUIDA`, registradas no Processo Administrativo.

## Telas

- **Quadro** (`acoes.php`): colunas por status + filtros.
- **Nova/Editar** (`nova-acao.php`, `editar-acao.php`); mudança de status e exclusão somente por POST.
- **Guia Providências** da ficha e **modal** "Nova Tarefa" da barra Registrar (mesmos campos via `partials/campos-acao.php`).

## Critérios de aceitação

- **CA-TAR-01** Criar Tarefa com prazo anterior ao do processo torna o Prazo vigente o da Tarefa; a ficha informa qual Tarefa o definiu.
- **CA-TAR-02** Concluir/Cancelar a última Tarefa pendente torna o processo **inativo**.
- **CA-TAR-03** Usuário sem autoria não consegue alterar a Tarefa nem por requisição direta.
- **CA-TAR-04** Descrição vazia é recusada.

## Situação

✅ Implementado (`tests/php/acoes.php`).
