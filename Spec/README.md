# Especificações do SESJUR

Especificações funcionais e técnicas do **SESJUR — Gestão Jurídica**, sistema interno do setor jurídico para cadastro e acompanhamento de Processos Administrativos (SIGADOC), Judiciais (PJe) e Extrajudiciais, com vínculo N:N entre eles, extração assistida pelo navegador e controle de acesso por Perfil.

Este conjunto segue a metodologia **Spec-Driven Development** (GitHub Spec Kit): cada documento descreve **o quê** e **por quê** (regras de negócio, requisitos, critérios de aceitação), deixando o **como** para os planos técnicos.

> **Fonte da verdade da linguagem:** `CONTEXT.md` (glossário do domínio) e `docs/adr/` (decisões arquiteturais). Em caso de conflito, prevalecem esses dois; este diretório os detalha e organiza.

## Convenções

| Item | Convenção |
|---|---|
| Identificadores | `RN-xxx` regra de negócio · `RF-xxx` requisito funcional · `RNF-xxx` requisito não funcional · `CA-xxx` critério de aceitação |
| Situação | ✅ implementado · ⚙️ parcial · 🗓️ planejado (não implementado) |
| Idioma | Português (BR). Termos do domínio com inicial maiúscula (ex.: Processo Administrativo, Perfil, Vínculo) |
| Termos a evitar | Os listados como _Avoid_ no `CONTEXT.md` |

## Índice

| Pasta | Conteúdo |
|---|---|
| [00-visao-geral](00-visao-geral/) | Visão do produto, escopo, glossário, perfis e permissões |
| [01-arquitetura](01-arquitetura/) | Arquitetura, segurança, decisões (ADRs) |
| [02-processos](02-processos/) | Processo Administrativo, Judicial, Extrajudicial e Vínculos |
| [03-providencias](03-providencias/) | Tarefas, Solicitações, Anotações, Prazo e Atividade |
| [04-modulos](04-modulos/) | Bloqueios e depósitos, Intimações pessoais, Pagamento indenizatório, Prioridades e Produtos de Saúde, Demanda de Saúde (planejado) |
| [05-extracao](05-extracao/) | Extração assistida e extensão do Chrome |
| [06-expedientes](06-expedientes/) | Gerador de Expedientes e Modelos |
| [07-pessoas-estrutura](07-pessoas-estrutura/) | Agentes Públicos, Usuários, Unidades Administrativas, tabelas de apoio, Central de Recursos |
| [08-transversais](08-transversais/) | Auditoria, pesquisa geral, configurações do sistema, API de Integração |
| [09-dados](09-dados/) | Modelo de dados |
| [10-operacao](10-operacao/) | Instalação, transferência entre computadores, testes |

## Como usar com o Spec Kit

1. Novas funcionalidades nascem como especificação em `specs/<###-nome>/spec.md` (comando `/speckit-specify`), a partir de `.specify/templates/spec-template.md`.
2. Ao serem implementadas e estabilizadas, sua essência é consolidada aqui, na pasta temática correspondente.
3. Princípios inegociáveis do projeto estão em [01-arquitetura/principios.md](01-arquitetura/principios.md) e devem alimentar a constituição (`/speckit-constitution`).

## Pendências conhecidas da documentação

- O `README.md` da raiz ainda descreve o sistema como "Gestão Jurídica" instalado em `gestao-juridica/` no XAMPP; o ambiente atual de referência é **Laragon** (ver [10-operacao](10-operacao/instalacao-e-transferencia.md)).
- ~~A constituição do Spec Kit estava com os marcadores do template~~ — **preenchida em 2026-10-27** (`.specify/memory/constitution.md`, v1.0.0), a partir de [principios.md](01-arquitetura/principios.md).
- Esta documentação descreve o **sistema legado**. A reestruturação está em [11-reestruturacao](11-reestruturacao/plano-de-especificacao.md); o `CONTEXT.md` e os ADRs 0006–0010 já usam o vocabulário do sistema-alvo (trechos marcados _Alvo_).
