# Anotações

Nota livre e interna da equipe sobre um Processo Administrativo (N por processo), com **autor** e **data**.

## Regras

- **RN-ANO-001** Mesma regra de alteração da Tarefa: qualquer Usuário cria; só Administrador, Gestor ou o autor altera ou exclui.
- **RN-ANO-002** Não afeta Prazo vigente nem Atividade.
- **RN-ANO-003** **Não confundir** com o Evento do tipo "Anotação" importado do SIGADOC: aquele é histórico da fonte, aparece em Movimentações e **não é editado** no sistema.
- **RN-ANO-004** Autor, data/hora e processo nunca são digitados.

## Telas

`anotacoes.php`, `nova-anotacao.php`, `editar-anotacao.php`; guia Providências e modal "Nova Anotação".

## Critérios de aceitação

- **CA-ANO-01** O autor e a data/hora são gravados automaticamente.
- **CA-ANO-02** Usuário que não é autor (e não é Admin/Gestor) não consegue editar.
- **CA-ANO-03** Anotações aparecem no Histórico do processo (Auditoria).

## Situação

✅ Implementado (`tests/php/anotacoes.php`).
