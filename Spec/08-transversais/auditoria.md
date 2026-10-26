# Auditoria

Registro **append-only** de quem fez o quê, quando e em qual entidade. **Nunca é editado nem apagado.**

## Regras

- **RN-AUD-001** Gravada **na mesma transação** da operação auditada (`AuditoriaService`, `AuditoriaRepository`): se a operação falha, não há registro órfão; se há registro, a operação ocorreu.
- **RN-AUD-002** Na **edição**, registra **apenas os campos alterados** (valor anterior/posterior).
- **RN-AUD-003** Autor = `usuario_logado_id()`; `NULL` apenas em operações de sistema/scripts.
- **RN-AUD-004** Toda tabela de negócio tem `criado_por`, `atualizado_por`, `created_at`, `updated_at` (associativas: `criado_por` e `created_at`).
- **RN-AUD-005** Exclusões físicas (produtos, atribuições de prioridade, processos, tarefas…) guardam o **registro completo** na Auditoria.
- **RN-AUD-006** Alterações dos parâmetros da extensão (domínios, HTTPS, template de URL) vão para a Auditoria.

## Eventos (amostra)

`USUARIO_CRIADO/EDITADO/ATIVADO/INATIVADO` · `CARGO_*` · `LOTACAO_*` · `ACAO_CRIADA/EDITADA/EXCLUIDA` · vínculos extrajudiciais · prioridades · produtos · solicitações · anotações · intimações · lançamentos e guias · pagamentos · configurações da extensão.

## Consulta

- Guia **Histórico** do Processo Administrativo: providências, prioridades, produtos e vínculos extrajudiciais (≠ Movimentações, que são Eventos do SIGADOC).
- Histórico nas fichas de Usuário, Agente e tabelas de apoio.
- Ficha do Processo Extrajudicial mostra o vínculo no seu histórico também.

## Critérios de aceitação

- **CA-AUD-01** Alterar dois campos de um cadastro gera um registro com exatamente esses dois campos.
- **CA-AUD-02** Falha na transação não deixa registro de auditoria.
- **CA-AUD-03** Não existe tela nem endpoint para editar ou apagar a Auditoria.
- **CA-AUD-04** Excluir um Produto de Saúde preserva na Auditoria todos os seus dados.
