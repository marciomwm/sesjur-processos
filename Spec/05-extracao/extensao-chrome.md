# Extensão do Chrome (Manifest V3)

Pasta `chrome-extension/` — *Gestão Jurídica — Integração SIGADOC/PJe*. Empacotada também em `chrome-extension.zip` na raiz.

## Componentes

| Arquivo | Papel |
|---|---|
| `manifest.json` | Declara permissões; estática só para `http://localhost/*` e `http://127.0.0.1/*` + `contextMenus`; domínios institucionais como **permissões opcionais** |
| `background/service-worker.js` | Orquestração, abas, permissões, menu de contexto; só atende `sender.id === chrome.runtime.id` |
| `content/app-bridge.js` | Ponte, injetada **só em localhost/127.0.0.1** |
| `content/collector.js` | Coletor **único**, injetado **sob demanda** (`chrome.scripting.executeScript`) na aba certa; despacha por `source` |
| `extractors/dom-utils.js` | Motor genérico (css → rótulo → padrão), `esperarElemento()` com timeout |
| `extractors/normalizers.js` | Normalização para o contrato interno |
| `extractors/sigadoc-extractor.js` / `pje-extractor.js` | Extratores por sistema |
| `config/selectors.js` | ⛔ **Único** lugar com seletores de DOM; define `VERSAO_EXTRATOR` |
| `options/` | Página de opções: concessão de permissão por domínio |
| `icons/` | Ícones |

Lado aplicação: `public/assets/js/extension-bridge.js` (ponte `postMessage`), `extracao-ui.js` (estados EXTRAIR→VALIDAR→PREENCHER→REVISAR), `form-processo-*.js`.

## Instalação

1. `chrome://extensions` → **Modo do desenvolvedor** → **Carregar sem compactação** → pasta `chrome-extension/` do projeto.
2. Recarregar a aplicação: indicador do cabeçalho muda de `Extensão não detectada` para **`Extensão conectada`**.
3. *Sistema › Configurações › Extensão*: preencher **Domínios do SIGADOC** e **do PJe** (só o host, um por linha ou separados por vírgula; subdomínios são aceitos) → **Salvar** → **Sincronizar com a extensão**.
4. Se aparecer *"Conceda a permissão para: …"*: `chrome://extensions` → Detalhes → **Opções da extensão** → **Conceder** por domínio (o Chrome pede confirmação).

A extensão nasce **sem nenhum domínio autorizado**; nenhum endereço institucional é presumido.

## Comunicação aplicação ↔ extensão

`window.postMessage` ↔ content script (localhost) ↔ `chrome.runtime.sendMessage` ↔ service worker. Mensagens aceitas: `GJ_PING`, `GJ_SYNC_CONFIG`, `GJ_EXTRACT_SIGADOC`, `GJ_EXTRACT_PJE`, `GJ_OBTER_PENDENTE`. Origem e fonte da mensagem são verificadas. Decisão em [ADR 0002](../../docs/adr/0002-ponte-postmessage-restrita-a-localhost.md).

## Criação pela extensão

Menu de contexto — **Criar processo no SESJUR a partir desta página** (ou com texto selecionado: **…com "…"**) e botão do ícone. A página do SESJUR informa à extensão o próprio endereço na primeira abertura. Dados de passagem em `chrome.storage.session`, vinculados à aba, entrega única, validade de 5 minutos.

## Processo de remapeamento de seletores

1. Capturar o HTML real autenticado (Ctrl+S somente HTML, ou DevTools → *Copy outerHTML*).
2. **Anonimizar** (nomes → `FULANO DE TAL`, documentos → `000.000.000-00`, remover tokens/sessionId/matrículas/e-mails), **preservando a estrutura**.
3. Salvar em `tests/fixtures/sigadoc/real-*.html` ou `tests/fixtures/pje/real-*.html`.
4. Preferir âncoras estáveis nesta ordem: `id` → `name` → `data-*` → `aria-*` → rótulo → relação estrutural. Evitar `nth-child`, classes dinâmicas e caminhos longos.
5. Preencher `css` com candidatos do mais específico ao mais genérico.
6. **Incrementar `VERSAO_EXTRATOR`**.
7. Registrar caso em `tests/extractors/casos.js` e rodar o runner.
8. Recarregar a extensão.

## Critérios de aceitação

- **CA-EXC-01** Sem permissão do Chrome para o domínio, a extração falha com `PERMISSION_DENIED`, sem tentar navegar.
- **CA-EXC-02** A extensão não declara permissão global de navegação.
- **CA-EXC-03** Alterar um seletor exige alterar apenas `config/selectors.js` (+ versão + teste).
- **CA-EXC-04** Fora de localhost/127.0.0.1 a ponte não é injetada.

## Situação

✅ Completa. Limitação conhecida: só funciona com a aplicação em `localhost`/`127.0.0.1`.
