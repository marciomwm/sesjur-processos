# Seletores DOM — PJe TJMT (página de detalhe do processo)

Mapeamento feito em 2026-09-27 a partir da página:
`https://pje.tjmt.jus.br/pje/Processo/ConsultaProcesso/Detalhe/listProcessoCompletoAdvogado.seam?id=...&ca=...&aba=`

Versão da interface: "CumSen" (nova UI do PJe 1º Grau TJMT), stack JSF/RichFaces + Bootstrap.
Objetivo: servir de referência para extração DOM detalhada no SESJUR e na extensão Gestão Jurídica.

## 1. Cabeçalho — número do processo (sempre visível, fora de qualquer aba)

```css
.nav-topo .mais-detalhes .titulo-topo
```
Contém o texto combinado "CumSen 1001857-48.2023.8.11.0108" + nome resumido das partes. Não usar para extrair o número puro.

```css
.nav-topo .mais-detalhes .titulo-topo .fa-clipboard
```
Ícone "copiar número". O número do processo em texto puro está no atributo `onclick` (`copyToClipboard(event, 'NUMERO')`). Extrair via regex do atributo, não do texto visível.

## 2. Dropdown "Mais detalhes"

Abre ao clicar em `.mais-detalhes` (elemento clicável, `button`/`a` com `data-toggle="dropdown"`). O painel abre como `<ul class="dropdown-menu">` dentro do mesmo `<li class="mais-detalhes">`.

```css
#maisDetalhes
```

**Atenção — escopar na DIV, não na primeira `<dl>`**: `#maisDetalhes` contém **três** `<dl class="dl-horizontal">` irmãs. A primeira traz o grosso dos campos; "Cargo judicial" e "Competência" vêm cada uma em sua própria `<dl>`. Usar `#maisDetalhes dl.dl-horizontal` como escopo perde esses dois campos.

O bloco está sempre presente no DOM (fica apenas oculto pelo dropdown), então **a extração não precisa clicar em nada** — a leitura `dt → dd` funciona com o menu fechado.

Estrutura: pares `<dt>`/`<dd>` sequenciais, **sem id ou classe individual por campo**. A extração deve iterar os pares na ordem e casar pelo texto do `<dt>`:

| Ordem | `<dt>` (label) | Observação do `<dd>` |
|---|---|---|
| 1 | Classe judicial | texto simples, ex: `CUMPRIMENTO DE SENTENÇA (156)` |
| 2 | Assunto | dentro de `<ul><li>`, pode ter múltiplos `<li>` |
| 3 | Jurisdição | texto simples |
| 4 | Autuação | data `dd mmm aaaa` |
| 5 | Última distribuição | data `dd mmm aaaa` |
| 6 | Valor da causa | `R$ 0.000,00` |
| 7 | Segredo de justiça? | `SIM`/`NÃO` |
| 8 | Justiça gratuita? | `SIM`/`NÃO` |
| 9 | Tutela/liminar? | `SIM`/`NÃO` |
| 10 | Prioridade? | `SIM`/`NÃO` |
| 11 | Órgão julgador | texto simples, ex: `2ª VARA DE TAPURAH` |
| 12 | Cargo judicial | texto simples, ex: `Juiz Titular` |
| 13 | Competência | texto simples |

### Partes — Polo ativo / Polo passivo

```css
#poloAtivo table.table tbody tr td      /* cada <tr> = uma parte do polo ativo */
#poloAtivo table.table tbody tr td > span > span   /* nome + documento + papel */
#poloAtivo table.table tbody tr td ul.tree li small.text-muted span[title]  /* representante: Defensoria/Procuradoria/advogado */

#poloPassivo  /* mesma estrutura, id trocado, pode ter múltiplos requeridos */
```

Formato do texto da parte principal:
```
"NOME - CPF: 000.000.000-00 (REQUERENTE)"
"NOME - CNPJ: 00.000.000/0001-00 (REQUERIDO)"
```
Precisa de regex para separar `nome`, `tipo de documento (CPF/CNPJ)`, `número do documento` e `papel processual` (texto entre parênteses).

O representante (subitem em `ul.tree > li`) usa o atributo `title` do `<i class="fa fa-users">` para indicar o tipo (`Defensoria`, `Procuradoria`, etc.) e o `<span title="...">` traz o nome do órgão/escritório.

## 3. Timeline de movimentações/documentos (painel esquerdo)

```css
#divTimeLine\:divEventosTimeLine        /* container com scroll, wrapper externo */
#divTimeLine\:eventosTimeLineElement    /* lista de eventos, ordem cronológica decrescente (mais recente primeiro) */
```

Paginação: existem inputs hidden auxiliares dentro do wrapper:
```css
#totalPaginas   /* value = total de páginas da timeline */
#paginaAtual    /* value = página atual carregada */
```

### Separador de data (agrupa os itens seguintes até o próximo separador)

```css
#divTimeLine\:eventosTimeLineElement > .media.data > .media-body > span.text-muted.data-interna
```
Texto: `"18 set 2026"`. O primeiro filho da lista (`.media.data.div-data-rolagem`) é um placeholder vazio/sticky — ignorar.

### Itens da cronologia (`.media.interno`)

Existem duas classes de item, mas **elas não separam "movimento" de "documento"** como o nome sugere:

| Classe | Significado real |
|---|---|
| `.tipo-M` | item **sem** documento anexo — só o texto do movimento |
| `.tipo-D` | item **com** documento anexo — que pode trazer **também** o texto do movimento |

Ou seja, `.tipo-D` tem duas formas: só documento, ou documento + movimento. Modelar "um item = uma movimentação, com 0..1 documento" cobre os dois casos.

```css
.media.interno .media-left .icon-auto            /* natureza — ler o atributo alt */
.media.interno .texto-movimento                  /* texto do movimento, quando houver */
.media.interno small.text-muted.pull-right       /* horário, ex: "15:14" */
.media.interno .anexos > a span[title]           /* "249562011 - Sentença" → id + espécie, separados por " - " */
```

**O `>` em `.anexos > a` é obrigatório**: `.anexos a` capturaria também o botão "Adicionar lembretes", que mora em `.anexos > .btn-group > a`. Verificado no processo real: com o filho direto, no máximo 1 documento por item; com o descendente, sempre 2 links.

Valores observados de `alt` no ícone de natureza (`.media-left .icon-auto`), que indicam quem originou o item:

| `alt` | classe do ícone |
|---|---|
| `Movimento processual lançado` | `icon-auto sistema` |
| `Interno` | `icon-auto I` |
| `Externo` | `icon-auto E` |
| `Polo passivo` | `icon-auto PP` |

Observação: o Bootstrap move `title` para `data-original-title` ao inicializar os tooltips, e deixa `title=""`. Por isso a natureza deve ser lida de **`alt`**, que permanece intacto.

**Atenção**: o `id`/`name` do `<a>` de documento (ex.: `divTimeLine:j_id424:0:j_id426:4:j_id437`) e o atributo `onclick`/`href` desses links **carregam o token de sessão (`ca=`)** e são gerados dinamicamente a cada carregamento da página — não usar como chave estável nem persistir/enviar esse valor para fora do navegador do usuário. Usar o texto do `span[title]` (contém o número do documento) como identificador estável.

Só foram confirmados os tipos `tipo-M` e `tipo-D` neste processo. Processos com audiência, petição, etc. podem ter outras classes `tipo-X` — checar quando disponível.

### Paginação da cronologia (importante)

```css
#totalPaginas    /* input hidden: total de páginas da timeline */
#paginaAtual     /* input hidden: página já carregada */
```

A timeline é carregada sob demanda conforme o usuário rola. No processo de referência, a primeira carga trouxe **46 de ~104** itens, com `#totalPaginas = 4`. Uma extração feita sem rolar a timeline até o fim captura **histórico parcial** — por isso o extrator devolve `historicoCompleto: false` quando `#totalPaginas > 1`, e o SESJUR sinaliza isso na tela em vez de apresentar a lista como integral.

## 4. Painel de visualização do documento (direita)

```css
#detalheDocumento                                         /* container geral do visualizador */
#detalheDocumento .titulo-documento h3.media-heading a span[title]   /* "249562011 - Sentença" */
#detalheDocumento .titulo-documento > div                 /* "Juntado por PATRICIA BEDIN em 18/09/2026 15:00:16" */
```
O segundo seletor precisa de regex para separar `autor da juntada` e `data/hora`.

O conteúdo do documento é renderizado dentro de:
```css
iframe#frameHtml
```
com `src` no formato `https://pje.tjmt.jus.br/pje/seam/resource/rest/pje-legacy/documento/download/{ID_DOCUMENTO}`. Esse `{ID_DOCUMENTO}` no `src` do iframe é a forma mais confiável de capturar o ID do documento atualmente aberto no visualizador.

Controles de paginação do documento (setas "N de M" no topo do visualizador) foram vistos visualmente mas não tiveram seus seletores inspecionados — mapear em uma próxima sessão se for necessário navegar entre páginas do PDF/documento programaticamente.

## Onde este mapeamento está implementado

| Arquivo | Papel |
|---|---|
| `chrome-extension/config/selectors.js` → `PJE_SELECTORS` | Único lugar que conhece o DOM do PJe |
| `chrome-extension/extractors/pje-extractor.js` | Aplica os seletores e monta o contrato |
| `tests/fixtures/pje/real-pje-tjmt-processo.html` | Estrutura real anonimizada |
| `tests/extractors/casos.js` → "PJe · DOM real do TJMT" | Testes contra a fixture |

Ao remapear após uma mudança do PJe, atualize os quatro e incremente `VERSAO_EXTRATOR`.

## Formato dos valores (normalização necessária)

| Campo | Formato na página | Normalização |
|---|---|---|
| Datas (`Autuação`, separadores da timeline) | `14 dez 2023`, `18 set 2026` | mês por extenso abreviado → ISO (`dataIsoExtensa`) |
| Classe e assuntos | `CUMPRIMENTO DE SENTENÇA (156)` | separar descrição e código CNJ (`descricaoComCodigo`) |
| Partes | `NOME - CPF: 000.000.000-00 (REQUERENTE)` | separar nome, documento e papel (`identificacaoParte`) |
| Sinalizadores | `SIM` / `NÃO` (com acento) | booleano tri-estado; ausência = `null`, **não** `false` |
| Hora da movimentação | `15:14` | `HH:MM` |

## Observações gerais para implementação na extensão

- **Tokens de sessão em atributos**: vários controles (abrir documento, copiar número, etc.) carregam o parâmetro `ca=` da URL/token de sessão dentro de `onclick`/`href`. Trate esses valores como sensíveis — não logar nem enviar para fora do contexto local da extensão.
- **IDs dinâmicos JSF**: muitos elementos têm ids do padrão `algumId:j_id123:N:...` que mudam a cada renderização/postback. Prefira seletores por classe + estrutura relativa (como documentado acima) a ids diretos, exceto para containers estáveis como `#divTimeLine\:eventosTimeLineElement`, `#detalheDocumento`, `#poloAtivo`, `#poloPassivo`, `#maisDetalhes`.
- **`dt`/`dd` sem chave própria**: o bloco "Mais detalhes" exige parsing posicional/por-label, não há seletor direto por campo — se o PJe reordenar os campos em atualização futura, a extração por label continua funcionando; por índice, não.
- Página testada: processo `1001857-48.2023.8.11.0108` (Cumprimento de Sentença, 2ª Vara de Tapurah), em 2026-09-27.

---

## 5. Revisão de 28/09/2026 (extrator 1.3.0)

Fonte: `Recursos/pje-elementos-dom.json` (inspeção ao vivo + validação sobre o HTML completo salvo).
Fixture: `tests/fixtures/pje/real-pje-tjmt-2026-09-28.html`.

| Item | Seletor / origem | Observação |
|---|---|---|
| Número CNJ (preferido) | `a.titulo-topo-desktop i.fa-clipboard` → `onclick` | `copyToClipboard(event, '<CNJ>')`; só o trecho que casa com o padrão CNJ é usado |
| Sigla da classe | 1º nó de texto de `a.titulo-topo-desktop` | ex.: `ProceComCiv` → `classe_abreviada` |
| Resumo das partes | `a.titulo-topo-desktop small` | `resumo_partes` |
| Prioridade com motivo | `dt` "Prioridade?" | texto que não é SIM/NÃO (ex.: `Idoso`) vale como SIM e vai para `prioridade_descricao` |
| Datas da `<dl>` | — | aceitas em `dd mmm aaaa` e `dd/mm/aaaa` |
| Outros interessados | `#outrosInteressados table tr` | mesmo template dos polos; gravado no polo `TERCEIRO` |
| Linha de cabeçalho dos polos | 1ª `<tr>` com `<th>` | sem `<td>` → descartada |
| Tipo do representante | `ul.tree li i[title]` ou `i[data-original-title]` | o tooltip do Bootstrap move o `title`; gravado como `Tipo: nome` |
| Origem do evento | 2º token de `.media-left .icon-auto` | `sistema`, `I`, `PA`, `PP`, `E`, `OU` → `SISTEMA`, `INTERNO`, `POLO_ATIVO`, `POLO_PASSIVO`, `EXTERNO`, `OUTROS` |
| N documentos por evento | cada `.anexos > a` | número pelo rótulo ou pelo `onclick` do `i.copiar-clipboard` seguinte |
| Marcador de rolagem | `.media.data.div-data-rolagem` | ignorado |
| Paginação | `#paginaAtual` ≥ `#totalPaginas` | define `historicoCompleto` |
| Painel do documento | `#detalheDocumento:tituloDocumento`, `:toolbarDocumento .contador-paginas`, `:quadroDocumento iframe#frameHtml` | juntado por / data da juntada / posição |

### Etapas interativas (opcionais, `extractors/pje-documentos.js`)

1. **Cronologia completa** — rola `#divTimeLine:divEventosTimeLine` (e despacha `scroll`,
   necessário em aba de segundo plano) até `#paginaAtual = #totalPaginas`; desiste após
   3 tentativas sem progresso. Mecanismo de carga ainda **não confirmado ao vivo** —
   se falhar, o histórico continua marcado como parcial.
2. **Íntegra das peças decisórias** — para cada documento classificado (Sentença, Decisão,
   Acórdão, Despacho, Embargos de Declaração; até 40), clica no link da cronologia, espera o
   painel mostrar o número e o `iframe#frameHtml` carregar a URL nova, e lê o texto
   (mesma origem). Sem acesso ao iframe, lê a mesma URL por GET de mesma origem. PDF sem
   texto → `PDF_SEM_TEXTO`, sem íntegra. **Processos em segredo de justiça não têm a
   íntegra extraída** (só metadados).

Pendências herdadas do JSON: confirmar o conteúdo do iframe para uma Sentença real
(a amostra era um comprovante gerado pelo sistema) e o gatilho exato da paginação.

### Persistência

`processo_judicial_documentos` (migração `database/migracao_2026-09-28_pje_documentos.sql`):
registro **acumulativo** por (processo, número do documento). Uma nova importação acrescenta
e atualiza, nunca apaga documento nem íntegra já obtida. A íntegra é lida em
`public/documento-judicial.php`.
