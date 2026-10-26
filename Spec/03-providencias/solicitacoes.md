# Solicitações

Pedido enviado a **outra Unidade Administrativa** (o *destinatário*) por um **expediente do SIGADOC**, ligado a um Processo Administrativo (N por processo).

## Atributos

| Campo | Regra |
|---|---|
| Data | Data do pedido |
| Assunto | Obrigatório |
| Destinatário | Unidade Administrativa |
| Prazo | Opcional |
| Status | Manual: `Aguardando`, `Atendido`, `Encerrado` |
| Vencida | **Derivada**: segue `Aguardando` depois do Prazo; nunca gravada |

## Regras

- **RN-SOL-001** Ao contrário da Tarefa, a Solicitação **não afeta** o Prazo vigente nem a Atividade do processo.
- **RN-SOL-002** Mesma regra de alteração da Tarefa: qualquer Usuário cria; só Administrador, Gestor ou quem criou altera ou exclui.
- **RN-SOL-003** Auditada no Histórico do processo.
- **RN-SOL-004** Não usar "ofício", "pedido" ou "requisição" como nome da entidade.

## Telas

`solicitacoes.php` (lista + filtros), `nova-solicitacao.php`, `editar-solicitacao.php`, `excluir-solicitacao.php`; guia Providências e modal "Nova Solicitação".

## Critérios de aceitação

- **CA-SOL-01** Solicitação `Aguardando` com prazo passado aparece como Vencida.
- **CA-SOL-02** Registrar/alterar Solicitação não muda Prazo vigente nem Atividade.
- **CA-SOL-03** Unidade inativa não é oferecida como destinatário em novos registros, mas permanece nas existentes.

## Situação

✅ Implementado (`tests/php/solicitacoes.php`).
