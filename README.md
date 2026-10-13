# Gestão Jurídica — Processos Administrativos (SIGADOC) e Judiciais (PJe)

Sistema interno para cadastro, consulta e **relacionamento N:N** entre processos
administrativos provenientes do SIGADOC e processos judiciais provenientes do PJe,
com preenchimento automático por extração do DOM feita no navegador do usuário
(extensão do Chrome, Manifest V3) e **confirmação humana obrigatória** antes de
qualquer gravação.

```
PROCESSO ADMINISTRATIVO — SIGADOC
              ↕
      RELACIONAMENTO N:N
              ↕
PROCESSO JUDICIAL — PJe
```

---

## 1. Estado da entrega

| Camada | Situação |
|---|---|
| Banco de dados (`database/database.sql`) | ✅ completo, com FK, UNIQUE, índices e dados de demonstração |
| Backend PHP (repositories, services, validators, transações) | ✅ completo |
| Frontend (dashboard, CRUD, fichas, listagens, filtros, paginação) | ✅ completo |
| Relacionamento N:N navegável nos dois sentidos | ✅ completo |
| API JSON (autocomplete, autorização/confirmação de extração, vínculos) | ✅ completo |
| Extensão Chrome MV3 (service worker, ponte, injeção, permissões) | ✅ completa |
| Motor de extração do DOM + normalizadores + contrato de dados | ✅ completo |
| **Seletores reais do SIGADOC** | ✅ **mapeados e validados na página real** (SIGA-DOC v11.2.0, 20/09/2026) |
| **Seletores reais do PJe** | ✅ **mapeados e validados na página real** (PJe 1º Grau TJMT, 27/09/2026) |
| Testes PHP (93) e testes de extração (271 asserções) | ✅ passando |

> **Situação da integração**
>
> **SIGADOC — concluído.** O DOM real de `www.sigadoc.mt.gov.br` (SIGA-DOC
> v11.2.0) foi inspecionado em 20/09/2026, os seletores foram mapeados e o
> extrator foi **executado com sucesso contra a página ao vivo**, devolvendo
> protocolo, data de abertura e objeto. O domínio já está cadastrado.
>
> **PJe — concluído.** O DOM real de `pje.tjmt.jus.br` (1º Grau, interface
> nova) foi inspecionado em 27/09/2026 e os seletores foram **validados campo a
> campo contra a página ao vivo**: dados cadastrais, partes por polo e
> cronologia. O mapeamento está documentado em `docs/pje-selectors.md`.
> Detalhes em §8.

---

## 2. Instalação no XAMPP

### 2.1 Onde copiar o projeto

Copie a pasta `gestao-juridica/` inteira para dentro do `htdocs` do XAMPP:

| Sistema | Caminho |
|---|---|
| macOS | `/Applications/XAMPP/htdocs/gestao-juridica` |
| Windows | `C:\xampp\htdocs\gestao-juridica` |
| Linux | `/opt/lampp/htdocs/gestao-juridica` |

### 2.2 Apache

1. Abra o **XAMPP Control Panel** e inicie **Apache** e **MySQL**.
2. Nenhum VirtualHost é necessário: a aplicação roda em subdiretório.
3. Requisitos do Apache (já habilitados no XAMPP padrão):
   - `mod_rewrite` — opcional, apenas para o redirecionamento da raiz;
   - `mod_headers` — opcional, para os cabeçalhos de segurança;
   - `AllowOverride All` na pasta `htdocs` para que os `.htaccess` do projeto
     tenham efeito. Se estiver como `AllowOverride None`, os arquivos
     `.htaccess` são ignorados — a aplicação continua funcionando, mas as pastas
     `app/`, `database/` e `tests/` ficam acessíveis por HTTP.

### 2.3 MariaDB/MySQL e criação do banco

**Opção A — phpMyAdmin**

1. Acesse `http://localhost/phpmyadmin`.
2. Aba **Importar** → **Escolher arquivo** → `database/database.sql` → **Executar**.
   O script já cria o banco `gestao_juridica`, as tabelas e os dados de demonstração.

**Opção B — linha de comando**

```bash
# macOS
/Applications/XAMPP/xamppfiles/bin/mysql -u root -h 127.0.0.1 < database/database.sql
```

```bash
# Windows
C:\xampp\mysql\bin\mysql.exe -u root < database\database.sql
```

> ⚠️ O script começa com `DROP TABLE IF EXISTS`. Ao reimportá-lo, **todos os
> dados existentes do banco `gestao_juridica` são perdidos**. Faça backup antes.

### 2.4 Credenciais da aplicação

1. Copie o arquivo de exemplo:

```bash
cp app/config/config.example.php app/config/config.php
```

2. Edite `app/config/config.php`:

```php
'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'gestao_juridica',
    'username' => 'root',
    'password' => '',          // defina a senha do seu MariaDB
],

'app' => [
    'base_url' => '/gestao-juridica/public',   // ajuste se renomear a pasta
    'api_url'  => '/gestao-juridica/api',
    'debug'    => true,                        // false em produção
],
```

`app/config/config.php` não é versionado (ver `app/config/.gitignore`).

### 2.5 URL local

```
http://localhost/gestao-juridica/public/
```

### 2.6 Como verificar a conexão

Abra o **Dashboard**. O cartão **Banco de dados** mostra:

- `Conectado` + a versão do MariaDB → tudo certo;
- `Indisponível` + a mensagem do erro → confira credenciais e se o MySQL está iniciado.

A tela *Sistema › Configurações* repete o diagnóstico com host, porta e banco em uso.

---

## 3. Instalação da extensão do Chrome

1. Abra `chrome://extensions`.
2. Ative **Modo do desenvolvedor** (canto superior direito).
3. Clique em **Carregar sem compactação**.
4. Selecione a pasta `chrome-extension/` **do projeto no htdocs**.
5. A extensão aparece como *Gestão Jurídica — Integração SIGADOC/PJe*.

Recarregue a aplicação: o indicador no cabeçalho deve mudar de
`Extensão não detectada` para **`Extensão conectada`**.

### 3.1 Configurar os domínios permitidos

A extensão nasce **sem nenhum domínio autorizado** — nenhum endereço
institucional é presumido pelo sistema.

1. Na aplicação, vá em **Sistema › Configurações**.
2. Preencha **Domínios do SIGADOC** e **Domínios do PJe** (apenas o host, sem
   `https://` e sem caminho; um por linha ou separados por vírgula).
   Subdomínios de um domínio autorizado também são aceitos.
3. Clique em **Salvar configurações**.
4. Clique em **Sincronizar com a extensão**.
5. Se aparecer *"Conceda a permissão para: …"*, abra
   `chrome://extensions` → *Detalhes* da extensão → **Opções da extensão** e
   clique em **Conceder** para cada domínio. O Chrome pedirá sua confirmação.

O acesso é concedido **um domínio por vez**, por gesto explícito seu. A extensão
nunca pede permissão global de navegação.

### 3.2 Como testar o SIGADOC

1. Autentique-se normalmente no SIGADOC, no mesmo Chrome.
2. Abra a página do processo administrativo desejado e **deixe a aba aberta**.
3. Na aplicação: **Novo Processo Administrativo**.
4. Informe o protocolo (ex.: `SEPLAG-PRO-2026/16696`).
5. Clique em **Completar cadastro pelo SIGADOC**.
6. A extensão procura, entre as abas abertas em domínios autorizados, aquela cujo
   protocolo corresponde ao informado; lê o DOM; devolve os dados.
7. **Revise** os campos preenchidos e clique em **Salvar Processo**.

### 3.3 Como testar o PJe

1. Autentique-se normalmente no PJe.
2. Localize o processo, abra sua página e **copie a URL**.
3. Na aplicação: **Novo Processo Judicial**.
4. Informe o número CNJ (ex.: `1008695-17.2026.8.11.0006`) e cole a URL.
5. Clique em **Completar cadastro pelo PJe**.
6. A URL é validada (esquema, HTTPS e allowlist) **antes** de qualquer navegação.
   A extensão reaproveita uma aba já aberta na mesma URL ou abre uma aba em
   segundo plano, lê o DOM e a fecha em seguida.
7. O **número CNJ é o primeiro campo extraído**. Se divergir do informado, a
   importação é interrompida e **nenhum campo é preenchido**.
8. **Revise** e clique em **Salvar Processo**.

---

## 4. Árvore do projeto

```text
gestao-juridica/
│
├── app/
│   ├── bootstrap.php                     autoloader, config, sessão, erros
│   ├── config/
│   │   ├── config.example.php            modelo versionado
│   │   ├── config.php                    credenciais locais (não versionado)
│   │   └── .gitignore
│   ├── Core/
│   │   ├── Database.php                  PDO singleton + helper de transação
│   │   ├── Csrf.php                      token sincronizador
│   │   ├── Paginacao.php
│   │   ├── ValidationException.php
│   │   └── helpers.php                   e(), url(), data_br(), responder_json()…
│   ├── Repositories/
│   │   ├── ProcessoAdministrativoRepository.php
│   │   ├── ProcessoJudicialRepository.php
│   │   ├── ParteProcessualRepository.php
│   │   ├── AssuntoProcessualRepository.php
│   │   ├── VinculoRepository.php
│   │   └── ConfiguracaoRepository.php
│   ├── Services/
│   │   ├── ProcessoAdministrativoService.php   regras + transação
│   │   ├── ProcessoJudicialService.php         processo + partes + assuntos + vínculos
│   │   ├── VinculoService.php
│   │   ├── ExtracaoService.php                 contrato de dados e revalidação
│   │   └── ConfiguracaoService.php             allowlist de domínios
│   ├── Validators/
│   │   ├── ProtocoloValidator.php
│   │   ├── CnjValidator.php                    formato + dígito verificador
│   │   ├── UrlExternaValidator.php             esquema, HTTPS e allowlist
│   │   └── Normalizador.php
│   ├── Views/
│   │   ├── layout/{cabecalho,rodape}.php
│   │   └── partials/{paginacao,vinculos-widget,form-processo-judicial}.php
│   └── .htaccess                         nega acesso HTTP
│
├── public/                               raiz web
│   ├── index.php                         dashboard
│   ├── processos-administrativos.php     listagem + filtros + paginação
│   ├── processo-administrativo.php       ficha
│   ├── novo-processo-administrativo.php
│   ├── editar-processo-administrativo.php
│   ├── excluir-processo-administrativo.php
│   ├── processos-judiciais.php
│   ├── processo-judicial.php
│   ├── novo-processo-judicial.php
│   ├── editar-processo-judicial.php
│   ├── excluir-processo-judicial.php
│   ├── remover-vinculo.php
│   ├── configuracoes.php
│   ├── .htaccess
│   └── assets/
│       ├── css/app.css
│       └── js/
│           ├── app.js                    utilitários, fetch com CSRF, máscaras
│           ├── extension-bridge.js       ponte postMessage ↔ extensão
│           ├── extracao-ui.js            estados EXTRAIR→VALIDAR→PREENCHER→REVISAR
│           ├── form-processo-administrativo.js
│           ├── form-processo-judicial.js
│           ├── vinculos.js               autocomplete N:N
│           ├── partes.js                 linhas de partes
│           └── configuracoes.js
│
├── api/                                  endpoints JSON
│   ├── _bootstrap.php                    método, CSRF, cabeçalhos
│   ├── extracao-preparar.php             AUTORIZA o pedido de extração
│   ├── extracao-confirmar.php            REVALIDA o retorno da extensão
│   ├── pesquisar-processos-judiciais.php
│   ├── pesquisar-processos-administrativos.php
│   ├── vinculos.php                      criar/remover vínculo (JSON)
│   ├── config-integracao.php             allowlist para a extensão
│   └── .htaccess
│
├── database/
│   ├── database.sql
│   └── .htaccess
│
├── chrome-extension/                     Manifest V3
│   ├── manifest.json
│   ├── background/service-worker.js      orquestração, abas, permissões
│   ├── content/
│   │   ├── app-bridge.js                 ponte, só em localhost
│   │   └── collector.js                  injetado sob demanda nas páginas oficiais
│   ├── extractors/
│   │   ├── dom-utils.js                  motor genérico (css → rótulo → padrão)
│   │   ├── normalizers.js                contrato interno
│   │   ├── sigadoc-extractor.js
│   │   └── pje-extractor.js
│   ├── config/selectors.js               ⛔ ÚNICO lugar com seletores de DOM
│   ├── options/{options.html,options.js} concessão de permissão por domínio
│   └── icons/
│
├── tests/
│   ├── run-tests.php                     suíte PHP (banco isolado)
│   ├── Runner.php
│   ├── php/{validadores,extracao,integracao}.php
│   ├── extractors/{runner.html,runner.js,casos.js}
│   ├── fixtures/
│   │   ├── LEIA-ME.md                    como produzir fixtures reais
│   │   ├── sigadoc/*.html
│   │   └── pje/*.html
│   └── .htaccess
│
├── .htaccess
└── README.md
```

### 4.1 Desvios em relação à estrutura sugerida

Três ajustes conscientes, permitidos pela orientação de "aperfeiçoar a
arquitetura se tecnicamente necessário":

| Sugerido | Adotado | Motivo |
|---|---|---|
| `content/sigadoc.js` + `content/pje.js` | `content/collector.js` único | Os content scripts não são registrados estaticamente (os domínios não são conhecidos de antemão): são **injetados sob demanda** por `chrome.scripting.executeScript` na aba correta. Um coletor único que despacha por `source` evita injetar código de um sistema na página do outro. A separação por sistema permanece onde importa — nos **extratores**. |
| `api/` como pasta irmã de `public/` | mantido | A pasta é servida em `/gestao-juridica/api`, configurada em `app.api_url`. |
| Bootstrap 5 | CSS próprio | Ver §11. |

---

## 5. Modelo de dados

```
processos_administrativos  1 ──< processo_administrativo_judicial >── 1  processos_judiciais
                                  UNIQUE(adm_id, jud_id)                      │
                                                                              ├──< partes_processuais
                                                                              └──< assuntos_processuais
```

| Tabela | Chaves e restrições |
|---|---|
| `processos_administrativos` | PK `id`; **UNIQUE `protocolo`**; índices em `data_abertura`, `created_at` |
| `processos_judiciais` | PK `id`; **UNIQUE `numero_cnj`**; índices em `tribunal`, `situacao`, `data_distribuicao` |
| `partes_processuais` | FK → `processos_judiciais` `ON DELETE CASCADE` |
| `assuntos_processuais` | FK → `processos_judiciais` `ON DELETE CASCADE`; flag `principal` |
| `processo_administrativo_judicial` | FKs para as duas pontas, `ON DELETE CASCADE`; **UNIQUE (`processo_administrativo_id`, `processo_judicial_id`)** |
| `configuracoes` | PK `chave` — allowlist de domínios e exigência de HTTPS |

Decisões relevantes:

- **Relacionamento por ID, nunca por texto.** Protocolo e CNJ são identificadores
  de negócio (com UNIQUE), mas os vínculos usam chaves estrangeiras numéricas.
- **Remover um vínculo não remove processo algum** — a exclusão em cascata atua
  apenas quando um dos processos é efetivamente excluído.
- **`segredo_justica` é tri-estado** (`NULL` = não informado pela fonte), para
  não afirmar "não" quando o dado simplesmente não estava na página.
- **Assuntos são normalizados** em tabela própria (principal + secundários), e
  não concatenados em um campo texto.
- `origem`, `url_origem`, `data_extracao` e `extrator_versao` registram a
  **proveniência** de cada registro.

---

## 6. Arquitetura da integração

```
┌──────────────────────── NAVEGADOR DO USUÁRIO ────────────────────────┐
│                                                                      │
│   SIGADOC (aba autenticada)        PJe (aba autenticada)             │
│            │ DOM                            │ DOM                    │
│            └──────────────┬─────────────────┘                        │
│                           ▼                                          │
│            collector.js (injetado sob demanda)                       │
│                           │                                          │
│              extractors/ → normalizers/ → contrato                   │
│                           ▼                                          │
│                   service-worker.js                                  │
│                           ▲                                          │
│                 chrome.runtime.sendMessage                           │
│                           │                                          │
│              content/app-bridge.js (só em localhost)                 │
│                           ▲                                          │
│                   window.postMessage                                 │
│                           │                                          │
│              extension-bridge.js (página da aplicação)               │
└───────────────────────────┼──────────────────────────────────────────┘
                            ▼
            ┌───────────────────────────────┐
            │  APLICAÇÃO PHP (localhost)    │
            │  api/extracao-preparar.php    │  autoriza (formato + allowlist)
            │  api/extracao-confirmar.php   │  revalida e confere identificador
            │  formulário → revisão humana  │
            └───────────────┬───────────────┘
                            ▼
                    ┌───────────────┐
                    │    MariaDB    │
                    └───────────────┘
```

### 6.1 Comunicação aplicação ↔ extensão (decisão documentada)

**Mecanismo adotado:** `window.postMessage` entre a página da aplicação e um
*content script* da extensão injetado **apenas** em `http://localhost/*` e
`http://127.0.0.1/*`, que repassa a mensagem ao service worker por
`chrome.runtime.sendMessage` e devolve a resposta pelo mesmo caminho.

Por que não as alternativas:

| Alternativa | Motivo da recusa |
|---|---|
| Área de transferência | A especificação exige fluxo programático. O clipboard depende de gesto do usuário, não carrega estrutura e não garante origem. |
| `externally_connectable` | Exigiria fixar o **ID da extensão** no código PHP. Em instalação "sem compactação" o ID muda por máquina, o que quebraria a implantação. |
| Servidor HTTP dentro da extensão | MV3 não permite; ampliaria desnecessariamente a superfície de ataque. |

Garantias da ponte: a mensagem só é aceita quando `event.source === window` **e**
`event.origin === location.origin`, e apenas para uma lista fechada de tipos
(`GJ_PING`, `GJ_SYNC_CONFIG`, `GJ_EXTRACT_SIGADOC`, `GJ_EXTRACT_PJE`, `GJ_OBTER_PENDENTE`). O service
worker, por sua vez, só atende mensagens cujo `sender.id === chrome.runtime.id`.

### 6.2 Contrato de dados

Sucesso (SIGADOC):

```json
{
  "success": true,
  "source": "SIGADOC",
  "extractedAt": "2026-09-20T18:00:00-04:00",
  "extractorVersion": "1.1.0",
  "data": {
    "protocolo": "SEPLAG-PRO-2026/16696",
    "dataAbertura": "2026-09-17",
    "objeto": "Solicitação de informações para defesa do Estado autos nº 1008695-17.2026.8.11.0006 - FGTS - SES",
    "situacao": { "codigo": "AGUARDANDO_ANDAMENTO", "descricao": "Aguardando Andamento", "marcadores": ["Aguardando Andamento"], "textoOriginal": "1º Volume - …" },
    "responsavel": { "nome": "SERVIDOR 01", "matricula": "SES000001", "lotacao": "UNIJUR", "lotacaoNome": "UNIDADE JURIDICA" },
    "eventos": [
      { "data": "2026-08-28", "tipo": "JUNTADA", "tipoDescricao": "Juntada", "lotacao": "SESCOFDE", "responsavel": "SERVIDOR 02",
        "atendenteLotacao": "SESCOFDE", "atendente": "SERVIDOR 10", "documento": "SES-CIN-2026/161332-A", "descricao": null }
    ],
    "historicoCompleto": true
  },
  "warnings": []
}
```

Sucesso (PJe): mesmo envelope, com `data` contendo `numeroCNJ`, `tribunal`,
`orgaoJulgador`, `jurisdicao`, `classeJudicial`,
`assuntoPrincipal`, `assuntosSecundarios[]`, `dataDistribuicao`, `valorCausa`,
`situacao`, `segredoJustica` e `partes[]`.

Falha:

```json
{
  "success": false,
  "source": "PJE",
  "error": { "code": "IDENTIFIER_MISMATCH", "message": "O identificador encontrado na página não corresponde ao processo informado." }
}
```

Regras invioláveis:

- escalar não encontrado → `null`; coleção vazia → `[]`;
- **nunca** fabricar valor ausente — a ausência vira `warning` e campo em branco
  para preenchimento manual.

### 6.3 Códigos de erro e mensagens

| Código | Mensagem exibida |
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
| `SELECTORS_NOT_CONFIGURED` | Os seletores do extrator ainda não foram mapeados (ver §8). |
| `IDENTIFIER_MISMATCH` | O identificador encontrado na página não corresponde ao processo informado. |
| `URL_NOT_HTTPS` / `URL_SCHEME_BLOCKED` / `URL_INVALID` | Problemas na URL informada. |

---

## 7. Segurança

### Aplicação

- **PDO com prepared statements** em 100% das consultas; nenhum dado do usuário
  é concatenado em SQL. Campos de ordenação passam por *allowlist* de colunas.
- **Escape de saída** com `e()` (`htmlspecialchars`) em todas as views.
- **CSRF** por token de sessão em todo `POST` (formulários e endpoints JSON).
- **Validação no servidor** é a autoridade; a validação no cliente é conveniência.
- **Confirmação antes de excluir**, com aviso explícito do que será e do que
  **não** será removido.
- **Integridade referencial** garantida por FKs e `UNIQUE` no banco, além das
  regras de serviço.
- `remover-vinculo.php` valida o parâmetro `retorno` por expressão regular,
  impedindo *open redirect*.

### Integração

- **O PHP nunca acessa SIGADOC ou PJe.** A URL informada pelo usuário jamais
  vira uma requisição do servidor — ela é validada e repassada à extensão, e a
  navegação ocorre no navegador autenticado. Isso **elimina a superfície de SSRF**.
- **Allowlist de domínios** aplicada em três pontos: no PHP (ao autorizar), no
  service worker (antes de navegar) e no Chrome (permissão de host concedida
  pelo usuário).
- Esquemas `javascript:`, `data:`, `file:`, `blob:` e afins são **bloqueados**.
- A extensão declara apenas `http://localhost/*` e `http://127.0.0.1/*` como
  permissão estática. Os domínios institucionais são **permissões opcionais**,
  solicitadas uma a uma na página de opções.
- **Nenhuma credencial, cookie ou token** é lido, armazenado ou transmitido.
  Não há tentativa de reproduzir a sessão do usuário, contornar CAPTCHA ou
  burlar controle de acesso.
- **Nenhum conteúdo de página sai do navegador.** Não há chamada a serviço
  externo nem uso de IA para interpretar o DOM: a extração é local e determinística.

### Confirmação humana

```
EXTRAIR → VALIDAR → PREENCHER → USUÁRIO REVISAR → CONFIRMAR → SALVAR
```

A extração **nunca** grava. Ela apenas preenche o formulário, destacando em verde
os campos vindos da fonte externa. Em qualquer falha (seletor ausente, sessão
expirada, identificador divergente, domínio não autorizado), a operação é
interrompida e **os dados já digitados pelo usuário são preservados**.

---

## 8. Mapeamento do DOM — o que falta e como concluir

`chrome-extension/config/selectors.js` é o **único** arquivo do projeto que
conhece a estrutura das páginas externas.

### 8.1 SIGADOC — MAPEADO ✅

Inspecionado em **20/09/2026** contra `www.sigadoc.mt.gov.br`
(**SIGA-DOC v11.2.0**), página `/sigaex/app/expediente/doc/exibir?sigla=<SIGLA>`.

**Achado estrutural decisivo:** o corpo do processo é HTML gerado a partir do
modelo do documento. As tabelas **não têm id nem classe**, e o par rótulo/valor
alterna entre duas formas:

```html
<!-- horizontal: rótulo e valor na mesma linha -->
<tr><td><b>Data de abertura</b></td><td>07/04/2026</td></tr>

<!-- vertical: rótulo em uma linha, valor na seguinte -->
<tr><td><b>OBJETO</b></td></tr>
<tr><td>texto do objeto…</td></tr>
```

Por isso **não existe seletor CSS estável para data e objeto**. Ancorá-los em
algo como `table:nth-of-type(5) td` violaria a regra de resiliência (§28) e
quebraria a cada ajuste do modelo do documento. A solução adotada:

| Campo | Estratégia | Âncora |
|---|---|---|
| `protocolo` | CSS + regex | `#page h2` (cabeçalho da página), filtrado pelo padrão do protocolo para descartar o link "Voltar" |
| `dataAbertura` | rótulo horizontal | `td` rótulo → `td` seguinte |
| `objeto` | rótulo vertical | `tr` rótulo → `tr` seguinte da mesma tabela |

A busca por rótulo é **restrita ao container do documento**
(`#page .card-sidebar.border-alert .card-body`), que é único e identificável.
Sem esse escopo, a barra lateral — que lista *Documentos Apensados* e *Veja
também* com outras siglas — poderia contaminar a extração. Há teste cobrindo
exatamente esse risco.

**Verificação executada na página real:**

```
escopoEncontrado: true
protocolo    → "SES-PRO-2026/23509"  (estratégia: css)
dataAbertura → "07/04/2026"          (estratégia: rótulo)  → ISO 2026-04-07
objeto       → 205 caracteres        (estratégia: rótulo)  → sem vazar o rótulo
```

**Navegação direta.** Confirmou-se que o sufixo de volume (`-V01`) é opcional:
`…/doc/exibir?sigla=SES-PRO-2026/23509` devolve o processo. A extensão passou a
usar esse padrão como **atalho** quando nenhuma aba aberta corresponde ao
protocolo — e continua caindo para a busca entre as abas se o atalho falhar.
O template é editável em *Sistema › Configurações*.

#### Situação, responsável e movimentações (extrator 1.1.0 — 21/09/2026)

| Campo | Âncora no DOM real |
|---|---|
| `situacao` | `#collapseSituacaoDoc p` (fallback `#page h3`): `1º Volume - Aguardando Andamento [SES…], …`. Os marcadores são separados respeitando colchetes aninhados; entre vários, prevalece o mais restritivo (Sobrestado > Arquivado > Aguardando Andamento > Em trânsito). |
| `responsavel` | `#collapseOutrosMob table` — última linha `Vnn`: `<span title="NOME - MATRÍCULA">` e `<span title="UNIDADE">SIGLA</span>`. Fallbacks: spans da situação → atendente da movimentação mais recente. |
| `eventos[]` | Visão completa **"Ver Mais"** (`a#ver-mais` → `/doc/exibirAntigo`): `table.mov` com Data · Evento · Cadastrante (lotação, pessoa) · Atendente (lotação, pessoa) · Descrição. A data só aparece na 1ª linha de cada grupo (as demais herdam); a tabela "Geral" é ignorada. |

A visão padrão (`/doc/exibir`) lista **apenas juntadas e anotações**. Por isso o
collector faz um GET de **mesma origem** na página "Ver Mais" (a mesma
requisição do clique do usuário, com a sessão dele, timeout de 10 s) e o
extrator confere o protocolo do `<h2>` dessa página antes de usá-la. Se ela
falhar, recai na tabela resumida, com aviso de "Histórico parcial".

Rótulos de situação confirmados no filtro oficial do sistema
(`ultMovIdEstadoDoc`): *Aguardando Andamento*, *Sobrestado*, *Arquivo
Corrente*, *Caixa de Entrada (Digital)*, *A Receber (Físico)*, *Transferido*…
Códigos gravados: `AGUARDANDO_ANDAMENTO`, `SOBRESTADO`, `ARQUIVADO`,
`EM_TRANSITO`, `OUTRA` (o rótulo original fica em `situacao_descricao`).

Verificado na página real: 63 movimentações (recebimento, transferência,
juntada, anotação, apensação, sobrestar, dessobrestar, desentranhamento,
criação), situação e servidor responsável extraídos sem avisos.

Ainda em `_futuros`: **documentos relacionados** (`#outputRelacaoDocs`),
**classificação documental** e o card **Documento Interno Produzido**.

Fixtures reais anonimizadas: `tests/fixtures/sigadoc/real-sigadoc-mt-processo.html`
e `real-sigadoc-mt-processo-ver-mais.html`.

**Banco existente:** aplique `database/migracao_2026-09-21_situacao_eventos.sql`
(apenas adiciona colunas e a tabela `processo_administrativo_eventos`).

### 8.2 PJe — MAPEADO ✅

Inspecionado em **27/09/2026** contra `pje.tjmt.jus.br` (**PJe 1º Grau, interface
nova**), página
`/pje/Processo/ConsultaProcesso/Detalhe/listProcessoCompletoAdvogado.seam`.
Mapeamento completo, com justificativa campo a campo, em **`docs/pje-selectors.md`**.

**Achados estruturais decisivos:**

1. **Os dados cadastrais não têm seletor por campo.** Ficam em pares `<dt>/<dd>`
   dentro de `#maisDetalhes`, sem id nem classe própria — a leitura correta é por
   **rótulo** (`dt→dd`), que sobrevive à reordenação dos campos. Por isso vários
   campos do PJe têm `css: []` de propósito: *não* significa "não mapeado".
   O bloco está sempre no DOM (só oculto pelo dropdown), então a extração **não
   precisa clicar em nada**.
2. **`#maisDetalhes` contém três `<dl>` irmãs.** "Cargo judicial" e "Competência"
   vêm em `<dl>` próprias; escopar na primeira perderia os dois campos.
3. **O polo das partes vem do container** (`#poloAtivo` / `#poloPassivo`), não de
   heurística de cabeçalho — elimina o risco de atribuir a parte ao polo errado.
4. **A cronologia é paginada.** O primeiro carregamento trouxe 46 de ~104 itens
   (`#totalPaginas = 4`). O extrator devolve `historicoCompleto: false` e a
   aplicação **sinaliza histórico parcial** em vez de apresentá-lo como integral.
5. **Os ids JSF mudam a cada renderização** (`divTimeLine:j_id424:0:…`) e vários
   `href`/`onclick` carregam o **token de sessão (`ca=`)**. Nada é lido desses
   atributos: os documentos são identificados pelo texto visível.

Campos extraídos: número CNJ, tribunal (do `<title>`), órgão julgador, cargo
judicial, competência, jurisdição, classe + código CNJ, assuntos + códigos,
data de autuação, última distribuição, valor da causa, os quatro sinalizadores
(segredo, gratuidade, tutela/liminar, prioridade), partes por polo com documento
e representação, e a cronologia (movimentos e documentos juntados).

Fixture real anonimizada: `tests/fixtures/pje/real-pje-tjmt-processo.html`.

**Banco existente:** aplique `database/migracao_2026-09-27_pje_detalhado.sql`
(apenas adiciona colunas e a tabela `processo_judicial_movimentacoes`).

### 8.3 Cascata de estratégias do motor

Quando um campo não tem `css` — por não existir seletor estável, como na `<dl>`
do PJe e nas tabelas do SIGADOC — o motor usa uma cascata:

1. **seletores CSS** (`css`) — precisos, quando mapeados;
2. **rótulo visível** (`labels`) — `label[for]`, `label` envolvente, `aria-label`,
   `dt→dd`, `th→td` na linha, `th` de coluna, irmão seguinte e `"Rótulo: valor"`;
3. **padrão textual** (`padrao`) — apenas para os identificadores (protocolo/CNJ),
   sempre confirmados por comparação com o valor solicitado.

Se nada for encontrado, o extrator devolve `SELECTORS_MISSING` — **nunca um
valor inventado**. Campos ausentes viram `null` + `warning`; coleções ausentes
viram `[]`.

### Passo a passo para remapear

1. **Capture o HTML real**, já autenticado, da página do processo:
   `Ctrl+S` (somente HTML) ou DevTools → `Elements` → botão direito em `<html>`
   → *Copy* → *Copy outerHTML*.
2. **Anonimize**: troque nomes por `FULANO DE TAL`, documentos por
   `000.000.000-00`, e remova qualquer token, `sessionId`, matrícula ou e-mail.
   **Preserve a estrutura** (ids, classes, `data-*`, hierarquia).
3. Salve em `tests/fixtures/sigadoc/real-*.html` ou `tests/fixtures/pje/real-*.html`.
4. **Identifique elementos estáveis** — nesta ordem de preferência:
   `id` → `name` → `data-*` → `aria-*` → rótulo → relação estrutural.
   Evite `nth-child`, posições fixas, classes geradas dinamicamente e caminhos longos.
5. **Preencha `css`** com uma lista de candidatos, do mais específico ao mais genérico:

```js
dataAbertura: {
    css: ['#dataAbertura', '[data-campo="dataAbertura"]', '.ficha .data-abertura'],
    labels: ['Data de Abertura', 'Abertura'],
    tipo: 'data'
}
```

6. **Incremente `VERSAO_EXTRATOR`** (registrada na proveniência de cada registro).
7. **Registre o caso** em `tests/extractors/casos.js` e rode o runner (§9.2).
8. Recarregue a extensão em `chrome://extensions`.

Vara e comarca não fazem parte do cadastro do processo judicial (retiradas em
28/09/2026, extrator 1.4.0): no TJMT a jurisdição faz as vezes de comarca e o
órgão julgador já traz a vara.

### Páginas dinâmicas

O `collector.js` aguarda a estabilização do DOM com `MutationObserver`
(500 ms de silêncio, teto de 5 s) antes de extrair, e `dom-utils.js` oferece
`esperarElemento()` com timeout. **Não há laço infinito em nenhum ponto.**

---

## 9. Testes

### 9.1 Suíte PHP (validação, contrato, CRUD, transações, N:N, filtros)

```bash
/Applications/XAMPP/bin/php tests/run-tests.php
```

Cria e destrói automaticamente o banco isolado `gestao_juridica_teste` a partir
de `database/database.sql`. **O banco de produção não é tocado.** Sem MariaDB, os
testes de integração são ignorados e os unitários continuam rodando.

Resultado atual: **36/36 testes aprovados**, cobrindo

- normalização e validação de protocolo, CNJ (formato **e dígito verificador**),
  datas, moeda, booleano tri-estado e polo;
- allowlist de domínios, esquemas bloqueados e exigência de HTTPS;
- contrato SIGADOC/PJe: normalização, campos ausentes, coleções vazias,
  identificador divergente e propagação de falhas;
- CRUD completo das duas entidades, com partes e assuntos;
- **transação**: falha em partes não deixa processo parcialmente gravado;
- duplicidade de protocolo e de CNJ;
- N:N nos dois sentidos, sincronização, remoção de vínculo sem excluir processos;
- filtros, ordenação (com allowlist de colunas) e paginação;
- tentativa de SQL injection tratada como dado.

### 9.2 Testes dos extratores (JavaScript)

```bash
php -S localhost:8080 -t .
```

Abra `http://localhost:8080/tests/extractors/runner.html`.

> Precisa ser por HTTP: `fetch` de fixtures é bloqueado em `file://`.

Resultado atual: **82 asserções aprovadas**, cobrindo página válida, variação de
marcação (`dl/dt/dd`, `th/td`, tabela vertical, `"Rótulo: valor"`), campo
ausente, DOM alterado, processo inexistente, sessão expirada e identificador
divergente — para os dois sistemas — mais dois casos sobre a **fixture real
anonimizada do SIGADOC**, um deles verificando que o extrator não confunde o
processo em exibição com as siglas da barra lateral.

> Os testes rodam pelo servidor de desenvolvimento (`php -S`), não pelo Apache:
> o `.htaccess` do projeto bloqueia `tests/` por HTTP, como deve ser.

### 9.3 Fluxos verificados manualmente na aplicação

Cadastro com vínculo, navegação bidirecional administrativo ↔ judicial,
cardinalidade N:N (2 administrativos para 1 judicial), partes agrupadas por polo,
assuntos principal + secundários, normalização do valor da causa, remoção de
vínculo preservando os processos, exclusão com cascata correta, e as mensagens
de falha segura da extração.

---

## 10. Critérios de aceitação

| Fluxo | Situação |
|---|---|
| **§58 SIGADOC** — informar protocolo, extrair, validar, revisar e salvar | ✅ seletores mapeados e **extrator validado na página real**; falta apenas instalar a extensão no Chrome para o teste de ponta a ponta |
| **§59 PJe** — informar CNJ + URL, validar URL, extrair, conferir CNJ, revisar e salvar | ⚙️ pronto na aplicação e na extensão; executa de ponta a ponta **assim que o domínio e os seletores reais forem informados** (§8.2) |
| **§60 Relacionamento** — vincular, navegar nos dois sentidos, remover só o vínculo, restabelecer | ✅ **verificado de ponta a ponta** |
| CRUD, transações, filtros, paginação, validações, duplicidade | ✅ **verificado por testes automatizados** |

Nos fluxos ⚙️, tudo que não depende dos sistemas externos está implementado e
testado: o pedido é autorizado, a ausência de configuração é reportada com
mensagem específica, e a falha é sempre segura.

---

## 11. Evolução prevista

A arquitetura já acomoda, sem alterar o núcleo:

- **PJe**: advogados, procuradores, representantes, movimentações, documentos,
  decisões, liminares, sentenças, recursos, audiências, perícias, cumprimento,
  obrigações e prazos — bastam novas tabelas com FK para `processos_judiciais` e
  novos campos em `PJE_SELECTORS`;
- **Aplicação**: demandas, prazos, responsáveis, especialidades, procedimentos,
  documentos, órgãos, relatórios e indicadores (já reservados no menu);
- **Auditoria**: `created_at`/`updated_at` existem em todas as tabelas; o próximo
  passo é uma tabela `auditoria` (usuário, operação, valor anterior/posterior);
- **Autenticação de usuários**: a aplicação roda hoje em ambiente local
  controlado; a camada de sessão já está inicializada e o CSRF ativo.

### Observação sobre o Bootstrap 5

A especificação o admitia "se conveniente". Optou-se por **CSS próprio**
(`public/assets/css/app.css`, ~440 linhas) porque a aplicação roda em rede
interna, possivelmente sem acesso à internet — o que tornaria um CDN um ponto de
falha — e porque o conjunto de componentes necessário é pequeno e específico.
Não há nenhuma dependência externa em tempo de execução.

---

## 12. Módulo de Usuários (cadastro de agentes públicos)

Cadastro, consulta, edição e ativação/inativação dos agentes públicos do setor
jurídico. **Não há exclusão física**: desligamento = `ativo = 0`, preservando a
autoria de registros e o histórico.

| Camada | Arquivos |
|---|---|
| Banco | `cargos`, `lotacoes`, `usuarios`, `auditoria` em `database/database.sql`; migração não destrutiva em `database/migracao_2026-09-25_usuarios.sql` |
| Regras | `app/Validators/UsuarioValidator.php` (formato, sem banco) · `app/Services/UsuarioService.php` (unicidade, transação, auditoria) · `app/Services/AuditoriaService.php` |
| Dados | `app/Repositories/{Usuario,Cargo,Lotacao,Auditoria}Repository.php` |
| Telas | `public/usuarios.php` (lista, pesquisa e filtros) · `novo-usuario.php` · `editar-usuario.php` · `usuario.php` (ficha + histórico) · `alterar-status-usuario.php` (somente POST) |
| Cargos e lotações | `public/tabela-apoio.php?tipo=cargos` ou `?tipo=lotacoes` (lista + cadastro rápido) · `editar-tabela-apoio.php` (edição + histórico) · `alterar-status-tabela-apoio.php` · `app/Services/TabelaApoioService.php` · `app/Repositories/TabelaApoioRepository.php` (base de `Cargo`/`LotacaoRepository`) |
| Interface | `app/Views/partials/form-usuario.php` · `public/assets/js/form-usuario.js` · seção "Usuários" de `app.css` |
| Testes | `tests/php/usuarios.php` (19 testes) |

Decisões:

- **Cargo e lotação por FK** (tabelas de apoio), não texto livre: evita grafias
  divergentes e permite renomear uma unidade sem reescrever o histórico. Itens
  obsoletos são inativados; o usuário que já os possui continua editável.
- **Unicidade garantida pelo banco** (`UNIQUE` em e-mail e matrícula); a checagem
  prévia só melhora a mensagem, e o erro 1062 de uma corrida é traduzido na mesma
  mensagem amigável.
- **Auditoria já ativa** para `USUARIO_CRIADO/EDITADO/ATIVADO/INATIVADO`, gravada
  na mesma transação; na edição, registra apenas os campos alterados. O autor fica
  `NULL` até existir login — `usuario_logado_id()` em `app/Core/helpers.php` é o
  único ponto a ligar.
- **Autenticação/RBAC**: planejada em `database/planejamento_autenticacao_rbac.sql`
  (não executar): credenciais em tabela 1:1 própria e perfis N:N.
- **Data de nascimento** opcional; torna-se obrigatória com
  `'usuarios' => ['data_nascimento_obrigatoria' => true]` em `config.php`.
- **Erros**: telas mostram mensagem genérica com um código; o detalhe vai para o
  log do PHP (`C:\laragon\tmp\php_errors.log`), pesquisável pelo código.
- **Cargos e lotações** são mantidos pelo menu *Sistema › Cargos / Lotações*.
  Nome único (sem diferenciar maiúsculas nem acentos), sigla da lotação opcional
  e única. Inativar não altera quem já possui o item; ele só deixa de aparecer em
  novos cadastros. Eventos `CARGO_*` e `LOTACAO_*` vão para a auditoria.
- **Cache de CSS/JS**: `asset()` acrescenta `?v=<data de modificação>` às URLs,
  então alterações em `app.css`/`*.js` chegam aos navegadores sem Ctrl+F5.

---

## 13. Ações, prazo e atividade · pesquisa geral · criação pela extensão (28/09/2026)

**Banco existente:** faça backup e aplique `database/migracao_2026-09-28_acoes_prazo.sql`.
Ela cria `processo_administrativo_acoes`, acrescenta prazo e atividade aos
processos administrativos (os existentes recebem abertura + 30 dias) e **apaga**
as colunas `vara`/`comarca` e a tabela `processo_judicial_designacoes`.

### Processo Judicial

- Vara, Comarca e Designação saíram do cadastro, da ficha, da listagem, dos
  filtros e do contrato do extrator do PJe (extrator 1.4.0).
- A listagem tem duas visões — **cards** (padrão) e **tabela** —, alternadas no
  topo da lista. A escolha fica guardada na sessão.

### Ações (gerenciador de tarefas)

| Camada | Arquivos |
|---|---|
| Regras | `app/Core/Prazo.php` (funções puras de prazo) · `app/Services/AcaoService.php` (validação, permissão, auditoria) · `ProcessoAdministrativoService::recalcularSituacaoDasAcoes()` |
| Dados | `app/Repositories/AcaoRepository.php` |
| Telas | `public/acoes.php` (quadro por status + filtros) · `nova-acao.php` · `editar-acao.php` · `alterar-status-acao.php` e `excluir-acao.php` (somente POST) · seção "Ações" da ficha do processo |
| Testes | `tests/php/acoes.php` |

- Campos: Descrição, Prazo (data + hora opcional), Status (Pendente, Em
  andamento, Concluída, Cancelada) e Observação. Concluída e Cancelada são
  **finalizadas**.
- Qualquer usuário cria; **alterar, mudar status ou excluir** só o
  Administrador, o Gestor ou quem criou a ação (regra no serviço, não só na tela).
- Auditoria: `ACAO_CRIADA`, `ACAO_EDITADA`, `ACAO_EXCLUIDA`, registradas no
  processo administrativo.

### Prazo e atividade do Processo Administrativo

- **Prazo** (data + hora opcional): vazio ⇒ abertura + 30 dias (sem abertura,
  hoje + 30). O formulário acompanha a data de abertura enquanto o prazo ainda
  for o padrão.
- **Prazo vigente** (calculado): o mais próximo entre o prazo do processo e os
  das ações não finalizadas. A ficha informa qual ação o definiu.
- **Atividade** (calculada): ativo enquanto houver ação não finalizada; sem
  ações, ou com todas finalizadas, inativo.
- Os dois campos são recalculados na mesma transação de qualquer alteração do
  processo ou das suas ações. A listagem filtra por atividade e por prazo
  (vencidos / 7 dias) e ordena pelo prazo vigente.

### Pesquisa geral

Campo na barra superior (atalho `/`): sugere resultados enquanto se digita e,
com Enter, abre `pesquisa.php`. Procura em processos administrativos (protocolo,
objeto, procedimento, responsável), processos judiciais (CNJ com ou sem
máscara, classe, assunto, partes do cabeçalho, órgão julgador), partes, ações e
— só para administradores — usuários. Protocolo ou CNJ exato abre direto a ficha.
Código: `app/Services/PesquisaService.php`, `api/pesquisa-geral.php`,
`public/assets/js/pesquisa-geral.js`.

### Criar processo no SESJUR a partir do SIGADOC/PJe (extensão 1.4.0)

Recarregue a extensão em `chrome://extensions` (ela pede a nova permissão
`contextMenus`) e abra o SESJUR uma vez: a página informa à extensão o próprio
endereço.

1. Na página de um processo do SIGADOC ou do PJe (domínio autorizado), clique
   com o botão direito → **Criar processo no SESJUR a partir desta página** — ou
   use o botão do ícone da extensão.
2. Com um número de processo **selecionado** na página, o menu vira **Criar
   processo no SESJUR com “…”**: vale para processos apenas citados na página.
3. A extensão identifica o processo (lendo a página, sem identificador
   esperado) e abre o cadastro correspondente com o número preenchido. O
   cadastro dispara a importação normal — autorização, extração, revalidação —
   e aguarda a revisão. **Nada é salvo sem clicar em Salvar.**
4. Um texto selecionado que não é número aparece como sugestão para o Objeto
   (administrativo) ou as Observações internas (judicial).
5. Se o processo já estiver cadastrado, o cadastro mostra o link da ficha em
   vez de importar.

Os dados entre a página de origem e o cadastro ficam em `chrome.storage.session`
(memória da extensão), vinculados à aba aberta e entregues uma única vez, com
validade de 5 minutos.

---

## 14. Providências rápidas, prioridades, produtos de saúde, extrajudiciais e agentes públicos (30/09/2026)

**Banco existente:** faça backup e aplique
`database/migracao_2026-09-30_agentes_prioridades_produtos_extrajudiciais.sql`
(uma única vez). Ela cria as tabelas novas, acrescenta `usuarios.agente_publico_id`
e cria um **Agente Público para cada usuário existente**, ligando os dois. O tipo
de vínculo só é deduzido do cargo (estagiário, residente); os demais ficam
"Outro" e devem ser revisados em *Sistema › Agentes Públicos*.

### Ficha do Processo Administrativo

Guias: Informações · Providências · Judiciais · Extrajudiciais · Produtos de
Saúde · Prioridades · Movimentações · Histórico. A barra **Registrar** (canto
inferior; no celular, rodapé da tela) fica visível em qualquer guia e abre os
modais **Nova Ação**, **Nova Solicitação** e **Nova Anotação** — os mesmos campos
das páginas de cadastro (`app/Views/partials/campos-*.php`). O processo, o autor,
a data/hora e o tipo não são digitados. Sem JavaScript, os botões levam às
páginas de cadastro comuns. O modal envia para `public/providencia-rapida.php`,
que responde JSON (erros por campo) ou, sem JS, volta para a ficha.

O modal é um componente reutilizável (`public/assets/js/modal.js` +
`<dialog class="modal">`): também serve à inclusão/edição de Produto de Saúde.

**Histórico** mostra a trilha de auditoria do processo: providências,
prioridades, produtos e vínculos extrajudiciais (quem, quando, o quê).

### Prioridades (*Sistema › Prioridades*, administrador)

Nome, nível de 1 (baixa) a 5 (máxima), descrição e status. Sempre exibidas com
número e rótulo ("Nível 5 · Máxima"), não só pela cor. Atribuídas ao processo
N:N (`processo_administrativo_prioridades`, sem repetição) na guia Prioridades,
e mostradas como chips no cabeçalho da ficha. Inativar não retira de quem já tem.

### Produtos de Saúde e SIGTAP

Categoria (medicamento, procedimento, exame…), descrição e classificação SIGTAP
opcional. `sigtap_procedimentos` já tem a estrutura da tabela oficial (código de
10 dígitos, grupo, subgrupo, forma de organização, competência, origem): hoje um
código novo é cadastrado com origem `MANUAL` e reaproveitado; a importação
oficial poderá preencher a mesma tabela com origem `SIGTAP`.

### Processos Extrajudiciais

Entidade própria (`processos_extrajudiciais`) para procedimentos de instituições
externas, com protocolo único **por instituição**, data, instituição, órgão,
assunto, descrição, situação e observações. Instituição Externa e Órgão Externo
são tabelas de apoio (*Sistema › Instituições Externas*); o órgão também pode
ser criado no próprio formulário. O vínculo com o Processo Administrativo é N:N
(`processo_administrativo_extrajudicial`), gerido como o judicial (widget de
vínculos nos dois formulários) e auditado nas duas fichas. Filtros: protocolo ou
assunto, instituição, órgão, período e situação.

### Agentes Públicos e Usuários

`agentes_publicos` é a pessoa (dados pessoais e funcionais, lotação em Unidade
Administrativa — o órgão é a instituição da unidade, não é gravado à parte);
`usuarios` é a credencial (login, perfil, status). **Criar usuário** parte da
ficha do agente (`novo-usuario.php?agente_id=…`), que exige matrícula e cargo e
recusa agente desligado ou que já tem conta. Todo usuário pertence a um agente:
o cadastro direto pelo serviço (scripts, testes) liga a conta ao agente sem
conta de mesma matrícula ou cria o agente.

Por compatibilidade, `usuarios` ainda guarda uma **cópia sincronizada** de nome,
matrícula, cargo, unidade e nascimento (usada na sessão e nas consultas de
autoria): alterar o agente atualiza a conta e vice-versa, com auditoria dos dois
lados. Desligar o agente inativa o acesso. Remover essas colunas de `usuarios`
é o próximo passo, depois de trocar as consultas que as leem.

### Auditoria e exclusão

Todas as tabelas novas têm `criado_por`, `atualizado_por`, `created_at` e
`updated_at` (as associativas, `criado_por` e `created_at`). Prioridades,
instituições/órgãos externos e agentes nunca são excluídos (inativação/situação).
Produtos, atribuições de prioridade e processos extrajudiciais seguem a regra dos
processos: exclusão física com o registro completo na auditoria.

### Testes

`tests/php/processo-relacionados.php` (prioridades, produtos/SIGTAP,
extrajudiciais) e `tests/php/agentes.php` (agentes e contas de usuário).

---

## 15. Gerador de Expedientes (02/10/2026)

A ferramenta de `gerador-Expediente/` passou a ser uma página do sistema:
`public/expediente.php` (menu *Processos › Gerador de Expedientes* e botão
**📝 Gerar expediente** na ficha do Processo Administrativo, que abre o gerador
já com o processo). Não há migração de banco.

A página usa o layout da aplicação (menu lateral, cabeçalho, pesquisa geral) e
a mesma identidade visual. A ferramenta fica no contêiner `.ge`, e
`public/assets/expediente/styles.css` tem **todos os seletores sob `.ge`**:
as classes do gerador (`.btn`, `.campo`, `.chip`…) têm homônimas no `app.css`
e não podem vazar para o resto da página, nem o contrário. As cores, a fonte,
os raios e as sombras vêm dos tokens do `app.css`; o bloco final do arquivo
("Identidade visual do SESJUR") veste a ferramenta com os componentes da
aplicação. Sem dependência externa (as fontes do Google da versão avulsa foram
retiradas; ADR 0004). A página ocupa a altura da janela — rolam o painel de
propriedades e a mesa do documento — e, na impressão (PDF), sai só a folha.

### Vínculo com o Processo Administrativo

O campo livre "Protocolo" deu lugar à seção **Processo Administrativo**: o
expediente é vinculado a um processo gravado no SESJUR, escolhido por
protocolo ou objeto. Quando há mais de um Processo Judicial ou Extrajudicial
vinculado, o usuário escolhe qual deles alimenta as propriedades do grupo.

### Catálogo de propriedades

O menu **Do catálogo ▾** lista atributos do processo selecionado e dos
processos vinculados, com o valor atual ao lado de cada um:

| Grupo | Propriedades |
|---|---|
| Processo Administrativo | `{{PROCESSO_SIGADOC}}` e `{{PA_…}}`: data de abertura, objeto, procedimento, situação, responsável, lotação, prazos, designados, prioridades, produtos de saúde |
| Processo Judicial relacionado | `{{PROCESSO_JUDICIAL}}` e `{{PJ_…}}`: tribunal, órgão julgador, competência, jurisdição, classe, assuntos, polos ativo e passivo, datas, valor da causa, situação; `{{PJ_TODOS}}` enumera todos os vinculados |
| Processo Extrajudicial relacionado | `{{PROCESSO_EXTRAJUDICIAL}}` e `{{PE_…}}`: data, instituição, órgão, assunto, descrição, situação; `{{PE_TODOS}}` enumera todos os vinculados |

A lista é definida num só lugar, `App\Services\ExpedienteService::CATALOGO`
(a página a entrega ao JavaScript), e os valores vêm de
`api/expediente-dados.php?id=<processo>`. Valor ausente no banco chega vazio
e aparece no texto como pendência — nunca é fabricado (ADR 0003). Os valores
podem ser ajustados à mão no painel; **↻ Atualizar dados do SESJUR** os
substitui pelos atuais. Um token publicado não deve ser renomeado: os modelos
salvos pelos usuários o citam no texto.

### O que continua como na versão avulsa

Modelos, lotes e o rascunho ficam no `localStorage` do navegador (por
usuário/máquina, não compartilhados); o expediente gerado não é gravado no
banco. Na produção em lote, as propriedades do catálogo são colunas
preenchidas na planilha. A suíte `teste.html` e os modelos de `legado/` ficaram
só em `gerador-Expediente/`, que não foi alterado.

| Parte | Arquivos |
|---|---|
| Página e dados | `public/expediente.php` · `api/expediente-dados.php` · `app/Services/ExpedienteService.php` |
| Interface | `public/assets/expediente/` — `js/config.js` e `js/sesjur.js` (novos), `templates.js`, `js/app.js` e `js/expediente.js` (adaptados); os demais são cópia da versão avulsa |
| Testes | `tests/php/expediente.php` |
