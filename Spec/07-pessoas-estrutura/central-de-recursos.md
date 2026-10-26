# Central de Recursos

Catálogo de **links** (sites, sistemas, documentos, planilhas, painéis) usados pela equipe. Evitar "link", "favorito" e "atalho" como nome da entidade.

## Recurso

| Campo | Regra |
|---|---|
| Título | Obrigatório |
| URL | **Sempre http/https** (outros esquemas recusados) |
| Descrição | Opcional |
| Pasta | **Uma** (tipo do Recurso: Sistemas, Sites, Documentos…) |
| Áreas | **Nenhuma, uma ou várias** (categorias: Judicial, Consultiva…); nenhuma ⇒ "Sem área definida" |

## Regras

- **RN-REC-001** Qualquer Usuário cadastra; só Administrador, Gestor ou **quem cadastrou** altera ou exclui.
- **RN-REC-002** **Pasta** e **Área** são tabelas de apoio mantidas por **Administradores e Gestores** (inativadas, nunca excluídas).
- **RN-REC-003** Auditoria dos eventos do recurso.

## Telas e código

`recursos.php`, `salvar-recurso.php`, `excluir-recurso.php`; `RecursoService`, `RecursoRepository`, `CategoriaRecursoRepository` (Área), `TipoRecursoRepository` (Pasta). Migração `2026-10-07_recursos`.

## Critérios de aceitação

- **CA-REC-01** URL com `javascript:`, `data:` ou `file:` é recusada.
- **CA-REC-02** Recurso sem Área aparece em "Sem área definida".
- **CA-REC-03** Usuário que não cadastrou (e não é Admin/Gestor) não vê ações de alterar/excluir.
- **CA-REC-04** Filtrar por Pasta e por Área combina os critérios.

## Situação

✅ Implementado (`tests/php/recursos.php`).
