<?php
declare(strict_types=1);

/**
 * Gerador de Expedientes, dentro do layout da aplicacao. A ferramenta ocupa a
 * area de conteudo no conteiner .ge, com CSS proprio e escopado
 * (assets/expediente/styles.css) que usa os tokens visuais do app.css.
 *
 * O expediente e vinculado a um Processo Administrativo: as propriedades do
 * catalogo sao os atributos dele e dos Processos Judiciais e Extrajudiciais
 * vinculados (App\Services\ExpedienteService). ?processo_id= abre o gerador
 * ja com o processo da ficha.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Services\ExpedienteService;
use App\Services\ModeloExpedienteService;

$configuracao = [
    'catalogo'   => ExpedienteService::catalogo(),
    'grupos'     => ExpedienteService::GRUPOS,
    'baseUrl'    => url(''),
    'apiUrl'     => rtrim((string)config('app.api_url'), '/'),
    'processoId' => entrada_int('processo_id'),
    // Modelos visiveis ao usuario (os dele e os compartilhados); as escritas
    // vao por api/expediente-modelos.php.
    'modelos'          => (new ModeloExpedienteService())->visiveis(),
    'podeCompartilhar' => ModeloExpedienteService::podeCompartilhar(),
    'csrf'             => Csrf::token(),
];

$scriptsExpediente = ['js/config.js', 'templates.js', 'engine.js', 'js/ui.js', 'js/properties.js', 'js/models.js',
    'js/expediente.js', 'js/storage.js', 'js/export.js', 'js/documento.js', 'js/sigadoc.js', 'js/sesjur.js',
    'js/email.js', 'js/editor.js', 'js/planilha.js', 'js/lotes.js', 'js/app.js', 'js/biblioteca.js',
    'js/lote-ui.js', 'js/navegacao.js'];

$titulo      = 'Gerador de Expedientes';
$menuAtivo   = 'expediente';
$breadcrumbs = ['Processos' => null, 'Gerador de Expedientes' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>
<link rel="stylesheet" href="<?= e(asset('assets/expediente/styles.css')) ?>">

<div class="ge" id="gerador">

<div class="topo nao-imprimir">
  <button type="button" id="btnPainel" class="btn btn-mini so-estreito"
          aria-controls="painelLateral" aria-expanded="false">☰ Processo e propriedades</button>
  <nav class="nav-principal" aria-label="Seções do gerador">
    <a href="#/criador" data-nav="criador">Criador</a>
    <a href="#/biblioteca" data-nav="biblioteca">Biblioteca de Modelos</a>
    <a href="#/lotes" data-nav="lotes">Lotes</a>
  </nav>
  <div id="modoProducao" class="segmentado" role="group" aria-label="Modo de produção">
    <a href="#/criador" data-modo="criador" title="Um documento por vez">Individual</a>
    <a href="#/lote" data-modo="lote" title="Vários documentos a partir de uma planilha">Em lote</a>
  </div>
  <span class="espaco"></span>
  <a id="chipProtocolo" class="chip-protocolo" title="Abrir a ficha do processo" target="_blank" rel="noopener" hidden></a>
  <span id="indicadorSalvo" class="indicador-salvo" role="status" aria-live="polite"></span>
</div>

<div id="visaoCriador" class="app">

  <!-- ============================================ PREENCHER (coluna esquerda) -->
  <aside id="painelLateral" class="lateral nao-imprimir" aria-label="Dados do expediente">

    <section class="secao" aria-labelledby="tituloProcesso">
      <h2 id="tituloProcesso">Processo Administrativo</h2>

      <div id="processoBusca" class="campo">
        <label for="buscaProcesso">Vincular a um processo</label>
        <div class="menu-wrap">
          <input type="search" id="buscaProcesso" placeholder="Protocolo ou objeto…"
                 autocomplete="off" spellcheck="false" role="combobox"
                 aria-controls="resultadosProcesso" aria-expanded="false" aria-describedby="ajudaProcesso">
          <div id="resultadosProcesso" class="menu menu-esq" role="listbox" hidden></div>
        </div>
        <div id="ajudaProcesso" class="ajuda">
          As propriedades do catálogo recebem os dados do processo e dos processos judiciais e
          extrajudiciais vinculados a ele. No texto, o protocolo é <code>{{PROCESSO_SIGADOC}}</code>.
        </div>
      </div>

      <div id="processoAtual" class="processo-atual" hidden>
        <div class="linha-campo">
          <a id="linkProcesso" class="processo-protocolo" title="Abrir a ficha do processo" target="_blank" rel="noopener"></a>
          <button type="button" id="btnDesvincular" class="btn-prop btn-prop-perigo"
                  title="Desvincular o processo" aria-label="Desvincular o processo">✕</button>
        </div>
        <p id="processoResumo" class="modelo-desc"></p>

        <div id="processoRelacionados" class="processo-relacionados">
          <div class="campo">
            <label for="selJudicial">Processo judicial</label>
            <select id="selJudicial" aria-describedby="ajudaRelacionados"></select>
          </div>
          <div class="campo">
            <label for="selExtrajudicial">Processo extrajudicial</label>
            <select id="selExtrajudicial" aria-describedby="ajudaRelacionados"></select>
          </div>
          <div id="ajudaRelacionados" class="ajuda">
            Com mais de um vinculado, escolha qual alimenta as propriedades do grupo.
          </div>
        </div>

        <button type="button" id="btnAtualizarProcesso" class="btn btn-mini"
                title="Substituir os valores das propriedades do catálogo pelos dados atuais do SESJUR">↻ Atualizar dados do SESJUR</button>
      </div>
    </section>

    <section class="secao" aria-labelledby="tituloModelo">
      <h2 id="tituloModelo">Modelo</h2>
      <div class="campo">
        <label for="selModelo" class="so-leitor">Modelo de expediente</label>
        <select id="selModelo"></select>
        <p id="modeloDesc" class="modelo-desc"></p>
      </div>
      <div class="botoes">
        <button type="button" id="btnSalvarModelo" class="btn btn-mini">Salvar como modelo</button>
        <button type="button" id="btnAtualizarModelo" class="btn btn-mini" hidden
                title="Regravar este modelo com o texto e as propriedades atuais">Atualizar</button>
        <button type="button" id="btnExcluirModelo" class="btn btn-mini btn-fantasma btn-perigo" hidden>Excluir modelo</button>
      </div>
    </section>

    <section class="secao secao-props" aria-labelledby="tituloProps">
      <h2 id="tituloProps">Propriedades <span id="contaSel" class="conta">0</span></h2>

      <div class="botoes">
        <button type="button" id="btnNovaProp" class="btn btn-mini btn-principal">+ Nova propriedade</button>
        <div class="menu-wrap">
          <button type="button" id="btnCatalogo" class="btn btn-mini"
                  title="Acrescentar um atributo do processo administrativo ou dos processos vinculados">Do catálogo ▾</button>
          <div id="menuCatalogo" class="menu menu-props menu-esq" hidden></div>
        </div>
        <button type="button" id="btnSelPadrao" class="btn btn-mini btn-fantasma"
                title="Voltar às propriedades sugeridas por este modelo">Restaurar</button>
      </div>

      <input type="search" id="buscaProps" class="busca" placeholder="Pesquisar propriedades..."
             aria-label="Pesquisar propriedades" hidden>

      <div id="campos" class="campos"></div>

      <div id="vazioProps" class="vazio-props" hidden>
        <p class="vazio-titulo">Nenhuma propriedade</p>
        <p class="vazio-texto">
          Propriedades são variáveis do documento e entram no texto como
          <code>{{PROPRIEDADE}}</code>. As do catálogo vêm do processo vinculado;
          as demais você cria e preenche aqui.
        </p>
        <button type="button" id="btnVazioAdd" class="btn btn-mini">+ Nova propriedade</button>
      </div>
    </section>
  </aside>

  <!-- ============================================== PRODUZIR (área principal) -->
  <div class="trabalho">

    <div class="barras nao-imprimir">
      <div id="faixaModelo" class="faixa-modelo" hidden>
        <span>Editando o modelo <strong id="faixaModeloNome"></strong>. As alterações só entram no modelo ao salvar.</span>
        <span class="espaco"></span>
        <button type="button" id="btnFaixaSalvar" class="btn btn-mini btn-principal">Salvar modelo</button>
        <a href="#/biblioteca" class="btn btn-mini">Voltar à Biblioteca</a>
      </div>
      <div class="barra-acoes" role="toolbar" aria-label="Ações do expediente">
        <div class="segmentado" role="group" aria-label="Modo do documento">
          <button type="button" id="btnModoEditar" class="ativo" aria-pressed="true">Editar</button>
          <button type="button" id="btnModoVisualizar" aria-pressed="false"
                  title="Visualizar o documento final (Ctrl+Enter)">Visualizar</button>
        </div>
        <span id="seloStatus" class="selo"></span>

        <div class="menu-wrap">
          <button type="button" id="btnPendentes" class="btn btn-mini btn-alerta" hidden></button>
          <div id="menuPendentes" class="menu menu-props menu-esq" hidden></div>
        </div>

        <span class="espaco"></span>
        <span id="contaTokens" class="dica"></span>

        <div class="menu-wrap">
          <button type="button" id="btnBaixar" class="btn">Baixar ▾</button>
          <div id="menuBaixar" class="menu" hidden>
            <button type="button" data-acao="md">Markdown (.md)<small>Texto com marcação simples</small></button>
            <button type="button" data-acao="pdf">PDF (.pdf)<small>Pela impressão do navegador, em A4 (Ctrl+P)</small></button>
            <button type="button" data-acao="docx">Word (.docx)<small>Abre no Word e no LibreOffice</small></button>
            <div class="menu-sep" role="separator"></div>
            <button type="button" data-acao="copiar-texto">Copiar texto simples<small>Sem formatação</small></button>
          </div>
        </div>
        <div class="menu-wrap">
          <button type="button" id="btnEnviar" class="btn btn-principal">Enviar ▾</button>
          <div id="menuEnviar" class="menu" hidden>
            <button type="button" data-acao="email">Enviar por e-mail<small>Com PDF e/ou DOCX em anexo</small></button>
            <button type="button" data-acao="sigadoc">Enviar para SIGADOC<small>Integração direta</small></button>
            <div class="menu-sep" role="separator"></div>
            <button type="button" data-acao="copiar-sigadoc">Copiar para SIGADOC<small>Cola formatado no editor do SIGADOC</small></button>
          </div>
        </div>
      </div>

      <div id="ferramentas" class="ferramentas" role="toolbar" aria-label="Formatação do texto">
        <div class="grupo-fer" role="group" aria-label="Histórico">
          <button type="button" class="btn-fer" data-cmd="undo" title="Desfazer (Ctrl+Z)" aria-label="Desfazer">↶</button>
          <button type="button" class="btn-fer" data-cmd="redo" title="Refazer (Ctrl+Shift+Z)" aria-label="Refazer">↷</button>
        </div>

        <select id="selBloco" class="sel-fer" title="Estilo do parágrafo" aria-label="Estilo do parágrafo">
          <option value="p">Texto normal</option>
          <option value="h1">Título</option>
          <option value="h2">Subtítulo</option>
          <option value="blockquote">Citação</option>
        </select>

        <div class="grupo-fer" role="group" aria-label="Formatação">
          <button type="button" class="btn-fer" data-cmd="bold" title="Negrito (Ctrl+B)" aria-label="Negrito"><b>N</b></button>
          <button type="button" class="btn-fer" data-cmd="italic" title="Itálico (Ctrl+I)" aria-label="Itálico"><i>I</i></button>
          <button type="button" class="btn-fer" data-cmd="underline" title="Sublinhado (Ctrl+U)" aria-label="Sublinhado"><u>S</u></button>
          <button type="button" class="btn-fer" data-cmd="strikeThrough" title="Tachado" aria-label="Tachado"><s>T</s></button>
          <button type="button" class="btn-fer" data-cmd="removeFormat" title="Limpar formatação" aria-label="Limpar formatação">T<sub>×</sub></button>
        </div>

        <div class="grupo-fer" role="group" aria-label="Alinhamento">
          <button type="button" class="btn-fer" data-cmd="justifyLeft" title="Alinhar à esquerda" aria-label="Alinhar à esquerda">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M2 6.5h8M2 10h12M2 13.5h8"/></svg></button>
          <button type="button" class="btn-fer" data-cmd="justifyCenter" title="Centralizar" aria-label="Centralizar">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M4 6.5h8M2 10h12M4 13.5h8"/></svg></button>
          <button type="button" class="btn-fer" data-cmd="justifyRight" title="Alinhar à direita" aria-label="Alinhar à direita">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M6 6.5h8M2 10h12M6 13.5h8"/></svg></button>
          <button type="button" class="btn-fer" data-cmd="justifyFull" title="Justificar" aria-label="Justificar">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M2 6.5h12M2 10h12M2 13.5h12"/></svg></button>
        </div>

        <div class="grupo-fer" role="group" aria-label="Listas e recuos">
          <button type="button" class="btn-fer" data-cmd="insertUnorderedList" title="Lista com marcadores" aria-label="Lista com marcadores">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 4h8M6 8h8M6 12h8"/><path class="ic-ponto" d="M2.5 4h.01M2.5 8h.01M2.5 12h.01"/></svg></button>
          <button type="button" class="btn-fer" data-cmd="insertOrderedList" title="Lista numerada" aria-label="Lista numerada">1.</button>
          <button type="button" class="btn-fer" data-acao="recuar-menos" title="Diminuir recuo" aria-label="Diminuir recuo">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M8 6.5h6M8 10h6M2 13.5h12M5 6.5L2.5 8.25 5 10"/></svg></button>
          <button type="button" class="btn-fer" data-acao="recuar" title="Aumentar recuo" aria-label="Aumentar recuo">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3h12M8 6.5h6M8 10h6M2 13.5h12M2.5 6.5L5 8.25 2.5 10"/></svg></button>
          <button type="button" class="btn-fer" data-acao="primeira-linha" title="Recuo da primeira linha" aria-label="Recuo da primeira linha">
            <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M7 3h7M2 6.5h12M2 10h12M2 13.5h12M2 3h2.5"/></svg></button>
        </div>

        <label class="rotulo-fer" for="selEntrelinha" title="Espaçamento entre linhas">
          <svg class="ic" viewBox="0 0 16 16" aria-hidden="true"><path d="M7 3h7M7 8h7M7 13h7M3 3v10M1.5 4.5L3 3l1.5 1.5M1.5 11.5L3 13l1.5-1.5"/></svg>
          <span class="so-leitor">Espaçamento entre linhas</span>
        </label>
        <select id="selEntrelinha" class="sel-fer sel-curto" title="Espaçamento entre linhas"></select>

        <div class="menu-wrap">
          <button type="button" id="btnPagina" class="btn btn-mini">Página ▾</button>
          <div id="menuPagina" class="menu menu-form menu-esq" hidden>
            <div class="campo">
              <label for="selMargens">Margens do documento</label>
              <select id="selMargens"></select>
            </div>
            <div class="campo">
              <label for="selEspaco">Espaçamento depois do parágrafo</label>
              <select id="selEspaco"></select>
            </div>
          </div>
        </div>

        <div class="menu-wrap">
          <button type="button" id="btnInserir" class="btn btn-mini">Inserir ▾</button>
          <div id="menuInserir" class="menu menu-esq" hidden>
            <button type="button" data-acao="tabela">Tabela</button>
            <button type="button" data-acao="link">Link</button>
            <button type="button" data-acao="linha">Linha horizontal</button>
            <button type="button" data-acao="quebra">Quebra de página</button>
          </div>
        </div>

        <div class="menu-wrap">
          <button type="button" id="btnInserirProp" class="btn btn-mini"
                  title="Inserir uma propriedade na posição do cursor">+ Propriedade ▾</button>
          <div id="menuProps" class="menu menu-props menu-esq" hidden></div>
        </div>

        <div class="grupo-fer" role="group" aria-label="Ordem dos parágrafos">
          <button type="button" class="btn-fer" data-acao="sobe" title="Mover parágrafo para cima (Alt+↑)" aria-label="Mover parágrafo para cima">↑</button>
          <button type="button" class="btn-fer" data-acao="desce" title="Mover parágrafo para baixo (Alt+↓)" aria-label="Mover parágrafo para baixo">↓</button>
        </div>

        <span class="espaco"></span>
        <button type="button" id="btnRestaurar" class="btn btn-mini btn-fantasma"
                title="Voltar ao texto original do modelo">Restaurar texto</button>
        <button type="button" id="btnLimpar" class="btn btn-mini btn-fantasma btn-perigo">Limpar documento</button>
      </div>

      <div id="faixaPreview" class="faixa-preview" hidden>
        <div id="avisos" class="faixa-avisos" aria-live="polite"></div>
        <button type="button" id="btnDestacar" class="btn btn-mini" aria-pressed="true"
                title="Realçar na tela os valores vindos das propriedades (não sai na exportação)">Destacar variáveis</button>
      </div>
    </div>

    <div id="mesa" class="mesa">
      <div id="editor" class="editor folha" contenteditable="true" spellcheck="true" lang="pt-BR"
           role="textbox" aria-multiline="true" aria-label="Texto do expediente"
           data-placeholder="Comece digitando o texto do expediente ou selecione um modelo."></div>

      <article id="folha" class="folha" aria-label="Visualização do expediente" hidden></article>

      <details id="painelAud" class="conferencia nao-imprimir" hidden>
        <summary>Conferência das propriedades</summary>
        <div id="auditoria" class="auditoria"></div>
      </details>

      <p class="rodape-dica nao-imprimir">
        <strong>Ctrl+Enter</strong> alterna Editar/Visualizar · <strong>Ctrl+S</strong> salva o rascunho ·
        <strong>Ctrl+P</strong> exporta em PDF · <strong>Alt+↑/↓</strong> move o parágrafo
      </p>
    </div>
  </div>
</div>

<section id="visaoLote" class="visao nao-imprimir" aria-label="Documentos em lote" hidden></section>
<section id="visaoBiblioteca" class="visao nao-imprimir" aria-label="Biblioteca de Modelos" hidden></section>
<section id="visaoLotes" class="visao nao-imprimir" aria-label="Lotes" hidden></section>

<!-- área usada só na impressão do "PDF único" do lote -->
<div id="impressaoLote" class="folha" aria-hidden="true"></div>

<div id="toasts" class="toast-area" aria-live="polite"></div>

<script type="application/json" id="sesjurExpediente"><?= json_encode($configuracao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php foreach ($scriptsExpediente as $script): ?>
<script charset="utf-8" src="<?= e(asset('assets/expediente/' . $script)) ?>"></script>
<?php endforeach; ?>
</div>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
