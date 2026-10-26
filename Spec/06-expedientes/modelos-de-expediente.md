# Modelos de Expediente

**Modelo**: ponto de partida de um Expediente — texto, formatação e Propriedades —, **gravado no sistema** (`ModeloExpedienteService`, `ModeloExpedienteRepository`, `api/expediente-modelos.php`; migração `2026-10-15_modelos_expediente`). Evitar "template" e "modelo público".

## Visibilidade

| Tipo | Quem vê/usa | Quem cria, altera, exclui, muda visibilidade |
|---|---|---|
| **Individual** (padrão) | Só quem criou | Quem criou |
| **Compartilhado** | Todos os Usuários | **Só Administrador ou Gestor** |

## Regras

- **RN-MOD-001** Qualquer Usuário cria os **seus** Modelos (Individuais).
- **RN-MOD-002** Usuário comum **pode duplicar** um Compartilhado — a **cópia é Individual**.
- **RN-MOD-003** **Favorito** e **contagem de uso** são **de cada Usuário**.
- **RN-MOD-004** Propriedades `{{TOKEN}}` do catálogo são atributos do processo e vinculados; as demais são criadas pelo Usuário.
- **RN-MOD-005** Tokens do catálogo não são renomeados depois de publicados (modelos os citam).
- **RN-MOD-006** Antes da persistência no banco, Modelos/lotes/rascunho ficavam em `localStorage` por navegador; o **rascunho** e os **lotes** continuam no `localStorage` (por usuário/máquina, não compartilhados).
- **RN-MOD-007** Alterações de Modelos Compartilhados são auditadas.

## Critérios de aceitação

- **CA-MOD-01** Modelo Individual não aparece para outro Usuário.
- **CA-MOD-02** Usuário comum não consegue editar/excluir Compartilhado (nem por requisição direta), mas consegue duplicar.
- **CA-MOD-03** Favoritar um Modelo não o favorita para outros Usuários.
- **CA-MOD-04** Usar um Modelo incrementa apenas a contagem do Usuário que o usou.
- **CA-MOD-05** Modelo aplicado preenche as Propriedades do catálogo com os dados do processo escolhido.

## Situação

✅ Implementado (`tests/php/modelos-expediente.php`).
