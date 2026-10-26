# Processo Extrajudicial

Procedimento ou expediente originado em **instituição externa ao Poder Executivo estadual** (Ministério Público, Defensoria, Tribunal de Contas, conselhos…). Distinto do Administrativo (interno) e do Judicial.

## Atributos

| Campo | Regra |
|---|---|
| Protocolo | Da instituição de origem; **único dentro da Instituição Externa** |
| Data | Data do procedimento |
| Instituição Externa | Tabela de apoio própria (≠ Instituição da estrutura interna) |
| Órgão Externo | Unidade da instituição (ex.: 1ª Promotoria de Justiça de Cuiabá); nome único dentro da instituição; **pode ser criado no próprio formulário** |
| Assunto, Descrição | Texto |
| Situação | Situação do procedimento |
| Observações | Texto livre |

## Regras

- **RN-PE-001** Unicidade composta: `(instituição, protocolo)`.
- **RN-PE-002** Instituições e Órgãos Externos são **tabelas de apoio**: inativados, nunca excluídos (Sistema › Instituições Externas).
- **RN-PE-003** O vínculo com o Administrativo é N:N (`processo_administrativo_extrajudicial`), gerido como o judicial (widget de vínculos nos dois formulários) e **auditado nas duas fichas** (aparece no Histórico dos dois processos).
- **RN-PE-004** Exclusão física com registro completo na Auditoria; remove vínculos, não os Administrativos.
- **RN-PE-005** Se o processo tem Intimações Pessoais, não pode ser excluído (ver [intimacoes-pessoais.md](../04-modulos/intimacoes-pessoais.md)).

## Funcionalidades

- Listagem com filtros: protocolo ou assunto, instituição, órgão, período e situação.
- Ficha com dados, vínculos e (para Admin/Gestor) intimações.
- Cadastro/edição manual — **não há extração** para extrajudiciais (fonte externa heterogênea).
- Alimenta o Gerador de Expedientes: `{{PROCESSO_EXTRAJUDICIAL}}`, `{{PE_…}}`, `{{PE_TODOS}}`.
- Na Demanda de Saúde (planejado), a **Origem** (Instituição + Órgão Externo) reutiliza as mesmas tabelas de apoio.

## Critérios de aceitação

- **CA-PE-01** Dois extrajudiciais de instituições diferentes podem ter o mesmo protocolo; na mesma instituição, não.
- **CA-PE-02** Criar um Órgão Externo no formulário o disponibiliza imediatamente na instituição escolhida.
- **CA-PE-03** Vincular/desvincular gera evento de Auditoria visível no Histórico do Administrativo e do Extrajudicial.

## Situação

✅ Implementado (`ProcessoExtrajudicialService`, `ProcessoExtrajudicialRepository`, `VinculoExtrajudicialRepository`, testes em `tests/php/processo-relacionados.php`).
