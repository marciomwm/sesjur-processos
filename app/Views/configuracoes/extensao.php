<?php
/**
 * Guia Extensao de Configuracoes: parametros da integracao com a extensao
 * do navegador (allowlist de dominios, HTTPS, consulta direta), estado da
 * integracao e historico de alteracoes. A integracao nao usa token nem
 * segredo: a ponte postMessage aceita apenas a origem da aplicacao (ADR 0002)
 * e cada requisicao segue a sessao e o perfil do proprio usuario.
 * Variaveis: $diagnostico, $conexao, $versaoDistribuida, $historico, $erros
 */
use App\Core\Csrf;

$rotulosCampo = [
    'sigadoc_hosts'        => 'Domínios do SIGADOC',
    'pje_hosts'            => 'Domínios do PJe',
    'exigir_https'         => 'Exigir HTTPS',
    'sigadoc_url_template' => 'Consulta direta no SIGADOC',
];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === '' => '—',
    $campo === 'exigir_https'        => $valor === '1' ? 'Sim' : 'Não',
    default                          => (string)$valor,
};
$historicoVazio = 'Nenhuma alteração registrada desde a criação desta trilha.';
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Domínios institucionais autorizados</h2>
            <p class="card__subtitulo">Princípio do menor privilégio: a extensão só acessa os domínios listados aqui e o localhost da aplicação</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('configuracoes.php?aba=extensao')) ?>">
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
                        <?= $diagnostico['sigadoc_hosts'] !== []
                            ? '<span class="badge badge--verde">Configurado</span>'
                            : '<span class="badge badge--ambar">Pendente</span>' ?>
                    </span>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="pje_hosts">Domínios do PJe</label>
                    <textarea class="textarea mono" id="pje_hosts" name="pje_hosts" rows="4"
                              placeholder="pje.exemplo.jus.br"><?= e(implode("\n", $diagnostico['pje_hosts'])) ?></textarea>
                    <span class="campo__ajuda">
                        <?= $diagnostico['pje_hosts'] !== []
                            ? '<span class="badge badge--verde">Configurado</span>'
                            : '<span class="badge badge--ambar">Pendente</span>' ?>
                    </span>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="sigadoc_url_template">Consulta direta no SIGADOC (opcional)</label>
                    <input class="input mono <?= isset($erros['sigadoc_url_template']) ? 'is-invalido' : '' ?>" type="text" id="sigadoc_url_template" name="sigadoc_url_template"
                           value="<?= e($diagnostico['sigadoc_url_template']) ?>" maxlength="500"
                           placeholder="https://{host}/sigaex/app/expediente/doc/exibir?sigla={protocolo}">
                    <?php if (isset($erros['sigadoc_url_template'])): ?>
                        <span class="campo__erro"><?= e($erros['sigadoc_url_template']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">
                            Permite que a extensão abra a página do processo diretamente, sem depender de uma aba já aberta.
                            Substituições: <code>{host}</code> e <code>{protocolo}</code>.
                            Em branco, a extensão usa o padrão observado no SIGA-DOC.
                        </span>
                    <?php endif; ?>
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
            <span id="statusSincronizacao" class="texto-secundario" role="status" aria-live="polite"></span>
        </div>
    </form>
</section>

<div class="config-grade">
    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Estado da integração</h2></div>
        <div class="card__corpo">
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Neste navegador</span>
                    <span class="definicao__valor" id="statusExtensaoConfig" role="status" aria-live="polite">verificando…</span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Versão instalada</span>
                    <span class="definicao__valor" id="versaoExtensao">—</span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Versão distribuída</span>
                    <span class="definicao__valor mono" id="versaoDistribuida" data-versao="<?= e((string)$versaoDistribuida) ?>"><?= e($versaoDistribuida ?? '—') ?></span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Atualização</span>
                    <span class="definicao__valor" id="situacaoVersao">—</span>
                </div>
                <div class="definicao definicao--larga"><span class="definicao__rotulo">Autenticação</span>
                    <span class="definicao__valor">
                        Sem token ou chave: a extensão só conversa com a página aberta da aplicação (origem verificada) e
                        cada consulta usa a sessão e o perfil do próprio usuário. Estar conectada não concede permissão adicional.
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Instalação e uso</h2></div>
        <div class="card__corpo">
            <ol class="lista-passos">
                <li>Abra <code>chrome://extensions</code> e ative o <strong>Modo do desenvolvedor</strong>.</li>
                <li>Use <strong>Carregar sem compactação</strong> e aponte para a pasta <code>chrome-extension/</code> do projeto.</li>
                <li>Em <em>Detalhes → Opções da extensão</em>, conceda a permissão para cada domínio institucional (nenhum acesso global é solicitado).</li>
                <li>Volte aqui e clique em <strong>Sincronizar com a extensão</strong>.</li>
                <li>Nos cadastros de processo, use <strong>Extrair</strong>: os dados preenchem o formulário e só são gravados quando o usuário salva.</li>
            </ol>
        </div>
    </section>

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
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Estado do mapeamento de DOM</h2></div>
    <div class="card__corpo">
        <p class="texto-secundario">
            Os extratores dependem dos seletores das páginas do SIGADOC e do PJe, centralizados e versionados em
            <code>chrome-extension/config/selectors.js</code>. Sem seletor configurado, o extrator tenta a estratégia por rótulo
            (label/dt-dd/tabela) e retorna <code>SELECTORS_NOT_CONFIGURED</code> quando nada é localizado — nunca fabrica valores.
        </p>
        <p class="texto-secundario mb-0">
            Para atualizar o mapeamento, salve o HTML de uma página real, anonimize os dados pessoais e coloque o arquivo em
            <code>tests/fixtures/sigadoc/</code> ou <code>tests/fixtures/pje/</code> (seção “Mapeamento do DOM” do README).
        </p>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Histórico de alterações</h2>
            <p class="card__subtitulo">Quem alterou os parâmetros da integração, quando, e os valores anteriores e novos.</p>
        </div>
    </div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>
