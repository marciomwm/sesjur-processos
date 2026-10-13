<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\Database;
use App\Services\ConfiguracaoService;

$conexao = Database::testar();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();

    if (!$conexao['ok']) {
        flash('erro', 'Não é possível salvar: banco de dados indisponível.');
        redirecionar('configuracoes.php');
    }

    try {
        $sigadoc = ConfiguracaoService::normalizarListaHosts((string)($_POST['sigadoc_hosts'] ?? ''));
        $pje     = ConfiguracaoService::normalizarListaHosts((string)($_POST['pje_hosts'] ?? ''));

        ConfiguracaoService::salvar('sigadoc_hosts', implode(',', $sigadoc));
        ConfiguracaoService::salvar('pje_hosts', implode(',', $pje));
        ConfiguracaoService::salvar('exigir_https', isset($_POST['exigir_https']) ? '1' : '0');
        ConfiguracaoService::salvar('sigadoc_url_template', trim((string)($_POST['sigadoc_url_template'] ?? '')));

        flash('sucesso', 'Configurações salvas. Sincronize a allowlist com a extensão para que os novos domínios sejam aceitos.');
    } catch (Throwable $e) {
        flash('erro', 'Não foi possível salvar: ' . $e->getMessage());
    }
    redirecionar('configuracoes.php');
}

$diagnostico = ConfiguracaoService::diagnostico();

$titulo      = 'Configurações';
$menuAtivo   = 'config';
$breadcrumbs = ['Sistema' => null, 'Configurações' => null];
$scripts     = ['configuracoes.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Domínios institucionais autorizados</h2>
            <p class="card__subtitulo">Princípio do menor privilégio: a extensão só acessa os domínios listados aqui e o localhost da aplicação</p>
        </div>
    </div>
    <form method="post">
        <?= Csrf::campo() ?>
        <div class="card__corpo">
            <div class="alert alert--info">
                <span class="alert__icone">ℹ</span>
                <div>
                    Informe apenas os domínios (sem <code>https://</code> e sem caminho). Um por linha ou separados por vírgula.
                    Subdomínios de um domínio autorizado também são aceitos.<br>
                    <strong>Estes valores não vêm preenchidos:</strong> nenhum domínio do SIGADOC ou do PJe é presumido pelo sistema.
                </div>
            </div>

            <div class="form-grid">
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="sigadoc_hosts">Domínios do SIGADOC</label>
                    <textarea class="textarea mono" id="sigadoc_hosts" name="sigadoc_hosts" rows="4"
                              placeholder="sigadoc.exemplo.gov.br"><?= e(implode("\n", $diagnostico['sigadoc_hosts'])) ?></textarea>
                    <span class="campo__ajuda">
                        <?= $diagnostico['sigadoc_pronto']
                            ? '<span class="badge badge--verde">Configurado</span>'
                            : '<span class="badge badge--ambar">Pendente</span>' ?>
                    </span>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="pje_hosts">Domínios do PJe</label>
                    <textarea class="textarea mono" id="pje_hosts" name="pje_hosts" rows="4"
                              placeholder="pje.exemplo.jus.br"><?= e(implode("\n", $diagnostico['pje_hosts'])) ?></textarea>
                    <span class="campo__ajuda">
                        <?= $diagnostico['pje_pronto']
                            ? '<span class="badge badge--verde">Configurado</span>'
                            : '<span class="badge badge--ambar">Pendente</span>' ?>
                    </span>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="sigadoc_url_template">Consulta direta no SIGADOC (opcional)</label>
                    <input class="input mono" type="text" id="sigadoc_url_template" name="sigadoc_url_template"
                           value="<?= e($diagnostico['sigadoc_url_template']) ?>"
                           placeholder="https://{host}/sigaex/app/expediente/doc/exibir?sigla={protocolo}">
                    <span class="campo__ajuda">
                        Permite que a extensão abra a página do processo diretamente, sem depender de uma aba já aberta.
                        Substituições: <code>{host}</code> e <code>{protocolo}</code>.
                        Em branco, a extensão usa o padrão observado no SIGA-DOC.
                    </span>
                </div>
                <div class="campo">
                    <label class="checkbox-linha">
                        <input type="checkbox" name="exigir_https" value="1" <?= $diagnostico['exigir_https'] ? 'checked' : '' ?>>
                        Exigir HTTPS nas URLs externas (recomendado)
                    </label>
                </div>
            </div>
        </div>
        <div class="card__rodape">
            <button type="submit" class="btn btn--primario" <?= $conexao['ok'] ? '' : 'disabled' ?>>💾 Salvar configurações</button>
            <button type="button" class="btn btn--secundario" id="btnSincronizarExtensao">⟳ Sincronizar com a extensão</button>
            <span id="statusSincronizacao" class="texto-secundario"></span>
        </div>
    </form>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:var(--densidade-secao)">
    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Banco de dados</h2></div>
        <div class="card__corpo">
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Status</span>
                    <span class="definicao__valor">
                        <?= $conexao['ok']
                            ? '<span class="badge badge--verde">Conectado</span>'
                            : '<span class="badge badge--vermelho">Indisponível</span>' ?>
                    </span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Servidor</span>
                    <span class="definicao__valor"><?= e((string)config('db.host')) ?>:<?= e((string)config('db.port')) ?></span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Banco</span>
                    <span class="definicao__valor mono"><?= e((string)config('db.database')) ?></span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Versão</span>
                    <span class="definicao__valor"><?= e($conexao['ok'] ? $conexao['versao'] : ($conexao['erro'] ?? '—')) ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Extensão do Chrome</h2></div>
        <div class="card__corpo">
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Detecção</span>
                    <span class="definicao__valor" id="statusExtensaoConfig">verificando…</span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Versão</span>
                    <span class="definicao__valor" id="versaoExtensao">—</span>
                </div>
                <div class="definicao definicao--larga"><span class="definicao__rotulo">Instalação</span>
                    <span class="definicao__valor">
                        Abra <code>chrome://extensions</code>, ative o <strong>Modo do desenvolvedor</strong> e use
                        <strong>Carregar sem compactação</strong> apontando para a pasta <code>chrome-extension/</code> do projeto.
                    </span>
                </div>
                <div class="definicao definicao--larga"><span class="definicao__rotulo">Permissões de domínio</span>
                    <span class="definicao__valor">
                        A extensão pede autorização para cada domínio institucional na própria página de opções
                        (<em>Detalhes → Opções da extensão</em>). Nenhum acesso global é solicitado.
                    </span>
                </div>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Estado do mapeamento de DOM</h2></div>
    <div class="card__corpo">
        <p class="texto-secundario">
            Os extratores só funcionam depois que os seletores reais das páginas do SIGADOC e do PJe forem mapeados.
            Os seletores ficam centralizados em <code>chrome-extension/config/selectors.js</code> e são versionados.
            Enquanto não estiverem preenchidos, o extrator tenta a estratégia por rótulo (label/dt-dd/tabela) e
            retorna <code>SELECTORS_NOT_CONFIGURED</code> quando nada é localizado — nunca fabrica valores.
        </p>
        <p class="texto-secundario mb-0">
            Para concluir o mapeamento, salve o HTML de uma página real (Ctrl+S ou “Copiar outerHTML”), anonimize os dados
            pessoais e coloque o arquivo em <code>tests/fixtures/sigadoc/</code> ou <code>tests/fixtures/pje/</code>.
            Consulte a seção “Mapeamento do DOM” no README.
        </p>
    </div>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
