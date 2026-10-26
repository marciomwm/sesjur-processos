# Unidades Administrativas

Unidade da estrutura de uma **Instituição** (gabinete, superintendência, coordenadoria…). É também a **lotação** do Usuário/Agente e o **destinatário** da Solicitação. Evitar "setor" e "órgão"; "lotação" só como "a unidade em que o usuário está lotado".

## Atributos

| Campo | Regra |
|---|---|
| Instituição | Órgão/entidade (ex.: SES-MT, PGE-MT); tabela de apoio |
| Nível | Posição na estrutura (ex.: "Nível I - Decisão Colegiada"); lista comum a todas as Instituições; **pode ser criado direto no cadastro da unidade** |
| Unidade Superior | Imediatamente acima; **sempre da mesma Instituição**; **sem ciclos**; vazia ⇒ topo da estrutura |
| Nome | **Único dentro da Instituição** |
| Sigla do SIGADOC | Opcional, única |
| Gestor da Unidade | Nome e matrícula **digitados** na própria unidade (não precisa ser Usuário) |
| Lotacionograma | Relação de servidores lotados (nome, matrícula, cargo/função), digitada na unidade; independente do cadastro de Usuários |
| Competências | Atribuições conforme regimento interno, texto livre |

## Regras

- **RN-UNI-001** **Nunca é excluída**, só inativada. Itens inativados continuam válidos onde já usados.
- **RN-UNI-002** A hierarquia nunca forma ciclo; mudar a Unidade Superior para uma descendente é recusado.
- **RN-UNI-003** Lotacionograma **≠** Usuários lotados na unidade.
- **RN-UNI-004** Eventos auditados (`LOTACAO_*`/unidade).

## Visualizações da estrutura

Três formas de ver os mesmos dados:

- **Lista** — árvore recolhível;
- **Hierarquia** — organograma;
- **Nível** — uma faixa por Nível organizacional, agrupada pela Unidade Superior, com conectores.

**Focar** uma unidade mostra só ela e o que está abaixo.

## Telas

`unidades-administrativas.php`, `unidade-administrativa.php` (ficha), `nova-/editar-unidade-administrativa.php`, `alterar-status-unidade-administrativa.php`. Serviço: `UnidadeAdministrativaService`; repositórios de Instituição e Nível.

## Critérios de aceitação

- **CA-UNI-01** Definir como superior uma unidade de outra Instituição é recusado.
- **CA-UNI-02** Criar ciclo na hierarquia é recusado.
- **CA-UNI-03** Nome repetido na mesma Instituição é recusado (sem diferenciar caixa/acentos); em Instituições diferentes é permitido.
- **CA-UNI-04** Inativar uma unidade não altera quem já está lotado nela.
- **CA-UNI-05** Focar uma unidade nas três visões exibe apenas ela e seus descendentes.

## Situação

✅ Implementado (`tests/php/unidades.php`; migração `2026-09-29_unidades_administrativas`).
