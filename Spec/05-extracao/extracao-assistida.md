# Extração assistida

Leitura automática dos campos de um processo a partir do **DOM da página** do SIGADOC ou do PJe, executada pela extensão do navegador **na aba já autenticada do próprio Usuário**. Termos a evitar: importação, scraping, raspagem, sincronização.

## Fluxo

```
EXTRAIR → VALIDAR → PREENCHER → USUÁRIO REVISA → CONFIRMA → SALVA
```

A Extração **preenche o formulário**, destacando em verde os campos vindos da fonte; **nunca grava**. Em qualquer falha, os dados já digitados são preservados.

## Requisitos

- **RF-EXT-001** O Usuário informa o identificador (Protocolo; ou Número CNJ + URL) e aciona "Completar cadastro pelo SIGADOC/PJe".
- **RF-EXT-002** O PHP **autoriza** o pedido (`api/extracao-preparar.php`: formato + allowlist) e **revalida** o retorno (`api/extracao-confirmar.php`), conferindo o identificador.
- **RF-EXT-003** SIGADOC: a extensão procura entre as abas abertas em domínios autorizados a que corresponde ao Protocolo; sem aba, usa o **atalho de navegação direta** (`…/doc/exibir?sigla=<PROTOCOLO>`, template editável em Configurações — o sufixo de volume `-V01` é opcional); se falhar, volta à busca entre abas.
- **RF-EXT-004** PJe: a URL é validada (esquema, HTTPS, allowlist) **antes** de qualquer navegação; a extensão reaproveita aba aberta na mesma URL ou abre uma em segundo plano e a fecha depois.
- **RF-EXT-005** O **Número CNJ é o primeiro campo extraído**; divergência interrompe e nada é preenchido.
- **RF-EXT-006** **Criação pela extensão** (menu de contexto/popup): na página de um processo, ou com um número selecionado, abre o cadastro já com o identificador e dispara a Extração normal. Texto selecionado que não é número vira sugestão de Objeto (Administrativo) ou Observações internas (Judicial). Processo já cadastrado ⇒ link da ficha em vez de importar.

## Regras invioláveis

- **RN-EXT-001** Escalar não encontrado ⇒ `null`; coleção vazia ⇒ `[]`; ausência ⇒ `warning` + campo em branco ([ADR 0003](../../docs/adr/0003-extracao-nunca-fabrica-valor-ausente.md)).
- **RN-EXT-002** O servidor nunca acessa as fontes ([ADR 0001](../../docs/adr/0001-extracao-no-navegador-nunca-no-servidor.md)).
- **RN-EXT-003** Sem Domínio Autorizado (concedido pelo Usuário), nenhuma extração é tentada no host.
- **RN-EXT-004** O coletor aguarda a estabilização do DOM (`MutationObserver`, 500 ms de silêncio, teto de 5 s); **nenhum laço infinito**.
- **RN-EXT-005** SIGADOC: o corpo é HTML gerado do modelo, sem id/classe — a busca por **rótulo** é restrita ao container do documento (`#page .card-sidebar.border-alert .card-body`) para não contaminar com a barra lateral (documentos apensados).
- **RN-EXT-006** SIGADOC: a visão padrão lista só juntadas/anotações; o coletor faz GET de **mesma origem** na visão "Ver Mais" (`exibirAntigo`), com a sessão do usuário (timeout 10 s) e confere o protocolo do `<h2>`; se falhar, cai na tabela resumida com aviso de **histórico parcial**.
- **RN-EXT-007** PJe: dados cadastrais por **rótulo `dt→dd`** em `#maisDetalhes` (3 `<dl>` irmãs); ids JSF mudam a cada renderização e `ca=` carrega token de sessão — **nada é lido desses atributos**.
- **RN-EXT-008** SIGADOC: com vários marcadores de situação, prevalece o mais restritivo (Sobrestado > Arquivado > Aguardando Andamento > Em trânsito).

## Cascata de estratégias do motor

1. **CSS** (`css`) — seletores mapeados, do mais específico ao mais genérico;
2. **Rótulo visível** (`labels`) — `label[for]`, `label` envolvente, `aria-label`, `dt→dd`, `th→td`, `th` de coluna, irmão seguinte, `"Rótulo: valor"`;
3. **Padrão textual** (`padrao`) — só para identificadores, sempre confirmados contra o valor solicitado.

Nada encontrado ⇒ `SELECTORS_MISSING` (nunca valor inventado).

## Contrato de dados

Envelope de sucesso: `{ success, source, extractedAt, extractorVersion, data, warnings[] }`.
Falha: `{ success:false, source, error:{ code, message } }`.

- **SIGADOC** `data`: `protocolo`, `dataAbertura`, `objeto`, `situacao{codigo,descricao,marcadores[],textoOriginal}`, `responsavel{nome,matricula,lotacao,lotacaoNome}`, `eventos[]`, `historicoCompleto`.
- **PJe** `data`: `numeroCNJ`, `tribunal`, `orgaoJulgador`, `jurisdicao`, `classeJudicial`, `assuntoPrincipal`, `assuntosSecundarios[]`, `dataDistribuicao`, `valorCausa`, `situacao`, sinalizadores, `partes[]`, cronologia, documentos.

## Códigos de erro e mensagens

| Código | Mensagem |
|---|---|
| `EXTENSION_MISSING` | Extensão não detectada. Instale a extensão em `chrome://extensions`. |
| `EXTENSION_TIMEOUT` | A extensão não respondeu. |
| `ALLOWLIST_EMPTY` | Nenhum domínio autorizado configurado. |
| `DOMAIN_NOT_ALLOWED` | O domínio informado não está na lista de domínios autorizados. |
| `PERMISSION_DENIED` | Permissão de acesso ao domínio não concedida à extensão. |
| `NO_TAB` | Nenhuma aba do sistema de origem está aberta. |
| `SESSION_REQUIRED` | Não foi possível acessar o processo. Verifique se você está autenticado. |
| `PROCESS_NOT_FOUND` | O processo informado não foi localizado. |
| `SELECTORS_MISSING` | A página foi localizada, mas não foi possível identificar os campos. |
| `SELECTORS_NOT_CONFIGURED` | Os seletores do extrator ainda não foram mapeados. |
| `IDENTIFIER_MISMATCH` | O identificador encontrado na página não corresponde ao processo informado. |
| `URL_NOT_HTTPS` / `URL_SCHEME_BLOCKED` / `URL_INVALID` | Problemas na URL informada. |

## Critérios de aceitação

- **CA-EXT-01** Informar Protocolo/CNJ e extrair preenche o formulário sem gravar nada.
- **CA-EXT-02** Identificador Divergente interrompe sem preencher campo algum.
- **CA-EXT-03** Domínio fora da allowlist ou sem permissão do Chrome é recusado com a mensagem do código correspondente.
- **CA-EXT-04** Campo ausente na página resulta em `null` + aviso, não em valor deduzido.
- **CA-EXT-05** Falha de extração preserva o que o Usuário já digitou.

## Situação

✅ SIGADOC mapeado e validado (SIGA-DOC v11.2.0, 20–21/09/2026). ✅ PJe mapeado e validado (1º Grau TJMT, 27/09/2026; detalhes em [docs/pje-selectors.md](../../docs/pje-selectors.md)). Versão atual do extrator: ver `VERSAO_EXTRATOR` em `chrome-extension/config/selectors.js`.
