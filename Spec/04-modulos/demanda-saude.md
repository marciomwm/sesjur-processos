# Demanda de Saúde

> 🗓️ **PLANEJADO — NÃO IMPLEMENTADO.** Especificação consolidada em 2026-10-20 (Nexus › Entrada › "SESJUR - Especificação - Demanda de Saúde (consolidada)"). Os termos abaixo ainda não existem no código. Este arquivo é o ponto de partida para `/speckit-specify`.

## Definição

**Demanda de Saúde** é um **Processo Administrativo** cujo Procedimento tem o módulo `DEMANDA_SAUDE`: pedido de instituição externa (Judiciário, Ministério Público, Defensoria) para que a SES-MT forneça bens ou serviços de saúde a um **Paciente**. **Não é entidade própria.**

- Está **completa** quando tem **Paciente, Origem e ao menos um Item**.
- Seu **status é derivado dos itens**.
- Evitar "demanda jurídica" (nome da nota original) e "procedimento" (reservado ao trâmite).

## Módulo por Procedimento

`procedimentos.modulo` (chave estável, ex.: `DEMANDA_SAUDE`) seleciona painel e campos próprios; o código mapeia a chave para tabela, formulário e painel ([ADR 0005](../../docs/adr/0005-modulo-por-procedimento.md)).

## Entidades

### Paciente
Pessoa que receberá o produto: nome, CPF **ou** CNS, nascimento, município.

- **RN-DS-001** Entidade **reutilizável** entre Demandas; uma Demanda tem **um** Paciente.
- **RN-DS-002** CPF e CNS **únicos quando informados**.
- **RN-DS-003** Sem documento, **alerta de possível duplicidade** por nome + nascimento, com confirmação.
- **RN-DS-004** Todos os Perfis veem e editam; alterações vão para a Auditoria.

### Origem (da Demanda)
Instituição Externa + Órgão Externo que enviaram (ex.: TJMT, 1ª Vara Cível de Cuiabá).

- **RN-DS-005** Campo **obrigatório**, **uma** origem principal.
- **RN-DS-006** **Independe** dos vínculos judiciais e extrajudiciais.
- Reutiliza as tabelas de apoio de [processo-extrajudicial.md](../02-processos/processo-extrajudicial.md).

### Catálogo de Produtos de Saúde
Cadastro de Produtos: nome **único sem distinção de caixa e acento**, Categoria, apresentação e SIGTAP opcionais, ativo.

- **RN-DS-007** Produto novo digitado na Demanda fica **"pendente de revisão"** até Gestor ou Administrador aprovar.
- **RN-DS-008** No ranking aparece como **"não revisado"**.
- Categoria passa a ser tabela de apoio.

### Item da Demanda
Produto do Catálogo na Demanda, com observação e Status: `Em andamento`, `Atendido`, `Encerrado`.

- **RN-DS-009** Mudanças de status vão para a Auditoria.
- **RN-DS-010** Status da Demanda é derivado dos status dos Itens.

## Perguntas em aberto (a esclarecer em `/speckit-clarify`)

- Regra exata de derivação do status da Demanda a partir dos Itens (ex.: todos Atendidos ⇒ Atendida?).
- Migração dos atuais Produtos de Saúde (texto/categoria livre) para o Catálogo.
- Escopo do "ranking" (por produto? por origem? por período?) e quem o consulta.
- Convivência com o módulo Pagamento Indenizatório durante a migração para a chave `modulo`.
- Campos de CNS: validação de dígito verificador?

## Critérios de aceitação (propostos)

- **CA-DS-01** Salvar Demanda sem Paciente, Origem ou Item deixa-a "incompleta", sinalizada na ficha.
- **CA-DS-02** CPF/CNS repetido é recusado; Paciente sem documento com mesmo nome + nascimento dispara alerta.
- **CA-DS-03** Produto novo aparece como "pendente de revisão" e só entra no catálogo oficial após aprovação.
- **CA-DS-04** Renomear o Procedimento no catálogo não desativa o painel da Demanda.
