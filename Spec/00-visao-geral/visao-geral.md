# Visão geral do produto

## Propósito

Dar ao setor jurídico um registro único, confiável e navegável dos processos que acompanha, conectando o que nasce no **SIGADOC** (administrativo) ao que tramita no **PJe** (judicial) e em instituições externas (extrajudicial), sem digitação repetitiva e sem comprometer a segurança das sessões do usuário.

```
PROCESSO ADMINISTRATIVO (SIGADOC)
          ↕  Vínculo N:N
PROCESSO JUDICIAL (PJe)  ·  PROCESSO EXTRAJUDICIAL (MP, Defensoria, TCE…)
```

## Problemas que resolve

1. Processos administrativos e judiciais relacionados vivem em sistemas separados, sem ligação navegável.
2. O cadastro manual é lento e sujeito a erro; os dados já existem nas páginas oficiais.
3. A equipe precisa acompanhar prazos, providências, bloqueios/depósitos e intimações pessoais num só lugar.
4. Documentos (ofícios, despachos) são redigidos repetidamente com os mesmos dados do processo.

## Usuários e perfis

| Perfil | Papel resumido |
|---|---|
| Administrador | Tudo, inclusive usuários, perfis, configurações e tabelas mestras |
| Gestor | Processos, Designação, tabelas de apoio operacionais, modelos compartilhados |
| Usuário | Acompanha e registra no processo; não designa |

Detalhes em [perfis-e-permissoes.md](perfis-e-permissoes.md).

## Capacidades (mapa de módulos)

| Módulo | Situação | Especificação |
|---|---|---|
| Processo Administrativo (cadastro, ficha, listagem) | ✅ | [../02-processos/processo-administrativo.md](../02-processos/processo-administrativo.md) |
| Processo Judicial (partes, assuntos, movimentações, documentos) | ✅ | [../02-processos/processo-judicial.md](../02-processos/processo-judicial.md) |
| Processo Extrajudicial | ✅ | [../02-processos/processo-extrajudicial.md](../02-processos/processo-extrajudicial.md) |
| Vínculos N:N | ✅ | [../02-processos/vinculos.md](../02-processos/vinculos.md) |
| Tarefas, Solicitações, Anotações | ✅ | [../03-providencias/](../03-providencias/) |
| Prazo vigente e Atividade | ✅ | [../03-providencias/prazo-e-atividade.md](../03-providencias/prazo-e-atividade.md) |
| Bloqueios e depósitos judiciais | ✅ | [../04-modulos/bloqueios-e-depositos-judiciais.md](../04-modulos/bloqueios-e-depositos-judiciais.md) |
| Intimações pessoais | ✅ | [../04-modulos/intimacoes-pessoais.md](../04-modulos/intimacoes-pessoais.md) |
| Pagamento indenizatório e Entregadores | ✅ | [../04-modulos/pagamento-indenizatorio.md](../04-modulos/pagamento-indenizatorio.md) |
| Prioridades e Produtos de Saúde | ✅ | [../04-modulos/prioridades-e-produtos-saude.md](../04-modulos/prioridades-e-produtos-saude.md) |
| Demanda de Saúde | 🗓️ | [../04-modulos/demanda-saude.md](../04-modulos/demanda-saude.md) |
| Extração assistida + extensão Chrome | ✅ | [../05-extracao/](../05-extracao/) |
| Gerador de Expedientes e Modelos | ✅ | [../06-expedientes/](../06-expedientes/) |
| Agentes Públicos, Usuários, Unidades, Cargos | ✅ | [../07-pessoas-estrutura/](../07-pessoas-estrutura/) |
| Central de Recursos | ✅ | [../07-pessoas-estrutura/central-de-recursos.md](../07-pessoas-estrutura/central-de-recursos.md) |
| Auditoria, pesquisa geral, configurações | ✅ | [../08-transversais/](../08-transversais/) |

## Escopo

**Dentro:** cadastro, consulta, vínculo, acompanhamento, auditoria, geração de texto de expedientes, extração assistida de SIGADOC e PJe pelo navegador do usuário.

**Fora (decisões firmes):**

- O servidor **nunca** acessa SIGADOC/PJe nem reproduz sessão do usuário (ADR 0001).
- Expedientes **não** são gravados no sistema: são exportados/copiados para o SIGADOC.
- O sistema **não calcula prazos processuais**: o prazo é data-limite informada.
- Sem uso de IA para interpretar páginas: a extração é local e determinística.
- Sem dependências externas em tempo de execução (ADR 0004).

## Critérios de sucesso do produto

- **SC-001** Cadastrar um processo a partir da página oficial exige apenas informar o identificador, revisar e salvar.
- **SC-002** Nenhum dado extraído é gravado sem confirmação humana.
- **SC-003** Navegar de um Processo Administrativo para seus Judiciais/Extrajudiciais (e o inverso) em um clique.
- **SC-004** Toda alteração relevante tem autor, data e valores alterados na Auditoria.
- **SC-005** Nenhum campo ausente na fonte é preenchido com valor inventado.
