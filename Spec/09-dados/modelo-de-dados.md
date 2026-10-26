# Modelo de dados

Banco `gestao_juridica` (MariaDB/MySQL, InnoDB, `utf8mb4_unicode_ci`). Esquema completo em `database/database.sql`; evolução por migrações datadas em `database/migracao_AAAA-MM-DD_*.sql`.

> Este documento é um **mapa conceitual**. Nomes exatos de tabelas e colunas devem ser confirmados em `database/database.sql` (as migrações mais recentes são a referência quando divergirem).

## Diagrama conceitual

```
                         ┌──────────────────────────┐
 procedimentos ──────────┤ processos_administrativos├───< eventos (espelho SIGADOC)
 (modulo)                │  prazo, prazo vigente,   ├───< tarefas (acoes) · solicitacoes · anotacoes
                         │  atividade, situacao     ├───< designacoes ── usuarios
                         └───────┬───────┬──────────┘───< prioridades (N:N) ── prioridades
                                 │       │              ───< produtos_saude ── sigtap_procedimentos
              ┌──────────────────┘       └─────────────┐ 1:1 pagamento ── entregadores · lotacoes
              ▼ N:N                                     ▼ N:N
   processos_judiciais                        processos_extrajudiciais ── orgaos_externos ── instituicoes_externas
     ├─< partes_processuais                     
     ├─< assuntos_processuais                   intimacoes_pessoais ── (1 processo: jud | extra | adm)
     ├─< movimentacoes · documentos_judiciais        └─ agentes_publicos (intimado, recebedor)
     └─< bloqueios · guias_deposito

 agentes_publicos 1──0..1 usuarios ── credenciais
 agentes_publicos >── lotacoes (Unidade Administrativa) >── instituicoes · niveis (hierarquia: unidade superior)
 cargos · recursos (pasta, áreas N:N) · modelos_expediente · configuracoes · auditoria
```

## Principais tabelas

| Grupo | Tabelas |
|---|---|
| Processos | `processos_administrativos`, `processos_judiciais`, `processos_extrajudiciais` |
| Vínculos | `processo_administrativo_judicial`, `processo_administrativo_extrajudicial` |
| Detalhe judicial | `partes_processuais`, `assuntos_processuais`, `processo_judicial_movimentacoes`, documentos judiciais |
| Detalhe administrativo | `processo_administrativo_eventos`, `processo_administrativo_acoes` (Tarefas), solicitações, anotações, `processo_administrativo_prioridades`, produtos de saúde, `processo_administrativo_pagamentos` |
| Financeiro judicial | lançamentos de bloqueio, guias de depósito |
| Intimações | intimações pessoais |
| Catálogos | `procedimentos`, `prioridades`, `cargos`, `lotacoes` (Unidades), instituições, níveis, instituições/órgãos externos, `sigtap_procedimentos`, pastas/áreas de recursos, `entregadores` |
| Pessoas e acesso | `agentes_publicos`, `usuarios`, credenciais, designações |
| Conteúdo | `recursos`, modelos de expediente |
| Sistema | `configuracoes` (PK `chave`), `auditoria` |

## Convenções

- **PK** numérica; **identificadores de negócio** com UNIQUE (`protocolo`; `numero_cnj`; `(instituicao, protocolo)` no extrajudicial; `documento` do Entregador; e-mail/matrícula do Usuário).
- **Vínculos por FK**, nunca por texto; associativas com `UNIQUE (a, b)`.
- **Cascata** apenas onde é a semântica correta (ex.: partes/assuntos ao excluir o processo); `RESTRICT` para registros financeiros, intimações, autoria e tabelas de apoio.
- **Tri-estado**: `NULL` = não informado (sinalizadores do PJe).
- **Proveniência**: `origem`, `url_origem`, `data_extracao`, `extrator_versao`.
- **Autoria/tempo**: `criado_por`, `atualizado_por`, `created_at`, `updated_at`.
- **CHECK** para invariantes simples (ex.: `valor > 0`; `competencia = LEFT(data_execucao, 7)`; `ativo IN (0,1)`).
- **Estado calculado não é coluna editável** (saldo retido, situação do prazo, vencida).
- **Exclusão lógica** só onde especificado (Intimação Pessoal); tabelas de apoio, Agentes, Usuários e Unidades usam inativação.

## Migrações

Datadas, **idempotentes** (`CREATE TABLE IF NOT EXISTS`, verificação em `information_schema` antes de `ALTER`), com cabeçalho contendo: objetivo, comando de **backup**, comando de aplicação, comando de **reversão**.

| Data | Assunto |
|---|---|
| 2026-09-21 | Situação e eventos do SIGADOC |
| 2026-09-25 | Usuários; perfis e designações; procedimento |
| 2026-09-27 | PJe detalhado (movimentações) |
| 2026-09-28 | Tarefas (ações), prazo, atividade; documentos do PJe |
| 2026-09-29 | Solicitações e anotações; unidades administrativas |
| 2026-09-30 | Agentes públicos, prioridades, produtos de saúde, extrajudiciais |
| 2026-10-07 | Recursos |
| 2026-10-13 | Bloqueios e depósitos |
| 2026-10-15 | Intimações pessoais; modelos de expediente |
| 2026-10-19 | Catálogo de procedimentos; entregadores e pagamentos; `atualizar-banco-2026-10-19.php` |

Backups pontuais ficam em `database/backup_antes_*.sql` (**contêm dados reais** — não versionar/publicar).

## Regra de evolução

1. Backup.
2. Migração idempotente com reversão documentada.
3. Atualizar `database/database.sql` (instalação nova).
4. Atualizar testes (`tests/php/`) e este mapa.
