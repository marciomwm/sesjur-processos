# Gerador de Expedientes

Ferramenta, integrada ao sistema (`public/expediente.php`), para **redigir documentos** (ofícios, comunicações internas, despachos…) a partir de um Modelo, vinculados a um Processo Administrativo, com Propriedades preenchidas a partir dos dados do SESJUR. Origem: ferramenta avulsa `gerador-Expediente/` (mantida intacta).

## Acesso

Menu *Processos › Gerador de Expedientes* e botão **📝 Gerar expediente** na ficha do Processo Administrativo (abre já com o processo).

## Regras

- **RN-EXP-001** O Expediente **não é gravado** no sistema: é exportado ou copiado para o SIGADOC. Modelos, lotes e rascunho: ver [modelos-de-expediente.md](modelos-de-expediente.md).
- **RN-EXP-002** O campo livre "Protocolo" foi substituído pela seção **Processo Administrativo**: o expediente se vincula a um processo gravado, escolhido por protocolo ou objeto.
- **RN-EXP-003** Havendo mais de um Judicial/Extrajudicial vinculado, o Usuário **escolhe qual** alimenta as propriedades do grupo.
- **RN-EXP-004** **Valor ausente no banco chega vazio e aparece no texto como pendência — nunca é fabricado** ([ADR 0003](../../docs/adr/0003-extracao-nunca-fabrica-valor-ausente.md)).
- **RN-EXP-005** Os valores podem ser **ajustados à mão** no painel; **↻ Atualizar dados do SESJUR** os substitui pelos atuais.
- **RN-EXP-006** **Um token publicado não deve ser renomeado**: modelos salvos o citam no texto.
- **RN-EXP-007** Sem dependência externa (fontes do Google da versão avulsa foram retiradas — ADR 0004).
- **RN-EXP-008** Produção em lote: as propriedades do catálogo são colunas preenchidas na planilha.

## Catálogo de propriedades

Definido num só lugar — `App\Services\ExpedienteService::CATALOGO` — e entregue ao JavaScript pela página; valores vêm de `api/expediente-dados.php?id=<processo>`.

| Grupo | Propriedades |
|---|---|
| Processo Administrativo | `{{PROCESSO_SIGADOC}}` e `{{PA_…}}`: data de abertura, objeto, procedimento, situação, responsável, lotação, prazos, designados, prioridades, produtos de saúde |
| Processo Judicial relacionado | `{{PROCESSO_JUDICIAL}}` e `{{PJ_…}}`: tribunal, órgão julgador, competência, jurisdição, classe, assuntos, polos ativo/passivo, datas, valor da causa, situação; `{{PJ_TODOS}}` enumera todos |
| Processo Extrajudicial relacionado | `{{PROCESSO_EXTRAJUDICIAL}}` e `{{PE_…}}`: data, instituição, órgão, assunto, descrição, situação; `{{PE_TODOS}}` enumera todos |

Propriedades **fora do catálogo** são criadas pelo Usuário (variáveis livres `{{TOKEN}}`).

## Interface

- Usa o layout da aplicação (menu lateral, cabeçalho, pesquisa geral) e a identidade visual.
- Ferramenta no contêiner `.ge`; `public/assets/expediente/styles.css` tem **todos os seletores sob `.ge`** para não vazar para o resto (as classes `.btn`, `.campo`, `.chip` têm homônimas no `app.css`).
- Cores, fonte, raios e sombras vêm dos tokens do `app.css`.
- Página ocupa a altura da janela: rolam o painel de propriedades e a mesa do documento.
- Impressão (PDF): sai **só a folha**.
- Menu **Do catálogo ▾** lista atributos do processo e vinculados, com o valor atual ao lado.

## Arquivos

| Parte | Arquivos |
|---|---|
| Página e dados | `public/expediente.php` · `api/expediente-dados.php` · `app/Services/ExpedienteService.php` |
| Interface | `public/assets/expediente/` — `js/config.js` e `js/sesjur.js` (novos), `templates.js`, `js/app.js` e `js/expediente.js` (adaptados) |
| Testes | `tests/php/expediente.php` |

## Critérios de aceitação

- **CA-EXP-01** Abrir pela ficha seleciona o processo e preenche as propriedades do catálogo.
- **CA-EXP-02** Propriedade sem valor no banco aparece como pendência visível, não como texto inventado.
- **CA-EXP-03** Com 2+ Judiciais vinculados, o Usuário escolhe o que alimenta `{{PJ_…}}`; `{{PJ_TODOS}}` lista todos.
- **CA-EXP-04** Imprimir/gerar PDF produz apenas a folha do documento.
- **CA-EXP-05** Estilos do gerador não alteram nenhum outro componente da aplicação.

## Situação

✅ Implementado (02/10/2026). Sem migração de banco.
