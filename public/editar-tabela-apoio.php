<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Administradores (Pastas e Areas de Recursos: tambem gestores).
App\Services\TabelaApoioService::exigirAcesso(entrada('tipo', $_GET));

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Services\AuditoriaService;
use App\Services\TabelaApoioService;

$tipo = entrada('tipo', $_GET);
$id   = entrada_int('id');
// Lotações viraram Unidades Administrativas, com tela própria.
if ($tipo === 'lotacoes') {
    redirecionar($id !== null ? 'editar-unidade-administrativa.php?id=' . $id : 'unidades-administrativas.php');
}
if (!TabelaApoioService::tipoValido($tipo) || $id === null) {
    flash('erro', 'Registro não informado.');
    redirecionar('usuarios.php');
}

$servico  = new TabelaApoioService($tipo);
$meta     = $servico->meta();
$singular = $meta['singular'];
$artigo   = $meta['artigo'];
$voltar   = 'tabela-apoio.php?tipo=' . $tipo;
$paiMeta  = $meta['pai'] ?? null;
$pais     = $paiMeta !== null ? (new TabelaApoioService($paiMeta['tipo']))->repositorio()->todos() : [];

$registro = $servico->repositorio()->buscarPorId($id);
if ($registro === null) {
    flash('erro', ucfirst($singular) . ' não encontrad' . $artigo . '.');
    redirecionar($voltar);
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['nome' => entrada('nome'), 'sigla' => entrada('sigla')];
    if ($paiMeta !== null) {
        $dados[$paiMeta['campo']] = entrada($paiMeta['campo']);
    }
    try {
        $servico->atualizar($id, $dados);
        flash('sucesso', ucfirst($singular) . ' atualizad' . $artigo . ' com sucesso.');
        redirecionar($voltar);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, $tipo . '.atualizar');
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

$usuariosAtivos = $servico->repositorio()->usuariosAtivos($id);
$historico = (new AuditoriaRepository())->listarPorEntidade($tipo, $id);
$rotulosCampo = ['nome' => 'Nome', 'sigla' => 'Sigla', 'ativo' => 'Status', 'instituicao_externa_id' => 'Instituição externa'];
$nomesPai = array_column($pais, 'nome', 'id');

$titulo      = 'Editar ' . $singular;
$menuAtivo   = $meta['menu'];
$breadcrumbs = [($meta['menu'] === 'recursos' ? 'Recursos' : 'Sistema') => ($meta['menu'] === 'recursos' ? 'recursos.php' : null), $meta['titulo'] => $voltar, $registro['nome'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<form method="post" class="form-usuario" novalidate>
    <?= Csrf::campo() ?>
    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Editar <?= e($singular) ?></h2>
                <p class="card__subtitulo">
                    <?php if ($usuariosAtivos > 0): ?>
                        A alteração vale para <?= e((string)$usuariosAtivos) ?> <?= e($meta['vinculo']['texto']) ?> e para todo o histórico.
                    <?php else: ?>
                        Nenhum registro ativo vinculado.
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge <?= (int)$registro['ativo'] === 1 ? 'badge--verde' : 'badge--neutro' ?>">
                <?= (int)$registro['ativo'] === 1 ? ($artigo === 'a' ? 'Ativa' : 'Ativo') : ($artigo === 'a' ? 'Inativa' : 'Inativo') ?>
            </span>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <?php if ($paiMeta !== null): ?>
                    <div class="campo">
                        <label class="campo__rotulo" for="pai"><?= e($paiMeta['rotulo']) ?> <span class="obrigatorio">*</span></label>
                        <select class="select <?= isset($erros[$paiMeta['campo']]) ? 'is-invalido' : '' ?>" id="pai" name="<?= e($paiMeta['campo']) ?>" required>
                            <?php foreach ($pais as $p): ?>
                                <?php if ((int)$p['ativo'] === 1 || (string)$p['id'] === (string)$registro[$paiMeta['campo']]): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (string)($dados[$paiMeta['campo']] ?? '') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?><?= (int)$p['ativo'] === 1 ? '' : ' (inativa)' ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($erros[$paiMeta['campo']])): ?><span class="campo__erro"><?= e($erros[$paiMeta['campo']]) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="campo <?= $meta['sigla'] ? 'campo--8' : '' ?>">
                    <label class="campo__rotulo" for="nome">Nome <span class="obrigatorio">*</span></label>
                    <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome"
                           required maxlength="<?= (int)$meta['nome_max'] ?>" value="<?= e((string)$dados['nome']) ?>">
                    <?php if (isset($erros['nome'])): ?>
                        <span class="campo__erro"><?= e($erros['nome']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Use para corrigir a grafia ou refletir a nova denominação oficial.</span>
                    <?php endif; ?>
                </div>
                <?php if ($meta['sigla']): ?>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="sigla">Sigla</label>
                        <input class="input mono <?= isset($erros['sigla']) ? 'is-invalido' : '' ?>" type="text" id="sigla" name="sigla"
                               maxlength="<?= TabelaApoioService::SIGLA_MAX ?>" value="<?= e((string)($dados['sigla'] ?? '')) ?>">
                        <?php if (isset($erros['sigla'])): ?><span class="campo__erro"><?= e($erros['sigla']) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e(url($voltar)) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario">Salvar alterações</button>
        </div>
    </section>
</form>

<section class="card form-usuario">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php if ($historico === []): ?>
        <div class="card__corpo">
            <p class="texto-secundario mb-0">Nenhum evento registrado. Itens criados pela instalação do banco não têm evento de cadastro.</p>
        </div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--compacta">
                <thead><tr><th>Data/hora</th><th>Evento</th><th>Alterações</th><th>Responsável</th></tr></thead>
                <tbody>
                <?php foreach ($historico as $h): ?>
                    <?php
                    $antes  = json_decode((string)($h['dados_anteriores'] ?? ''), true) ?: [];
                    $depois = json_decode((string)($h['dados_novos'] ?? ''), true) ?: [];
                    ?>
                    <tr>
                        <td class="nowrap"><?= e(datahora_br($h['created_at'])) ?></td>
                        <td class="nowrap"><span class="badge"><?= e(AuditoriaService::rotulo($h['acao'])) ?></span></td>
                        <td>
                            <?php if (str_contains($h['acao'], '_EDITAD')): ?>
                                <ul class="lista-alteracoes">
                                    <?php foreach ($depois as $campo => $novo): ?>
                                        <li><strong><?= e($rotulosCampo[$campo] ?? $campo) ?>:</strong>
                                            <span class="texto-secundario"><?= e((string)($campo === 'instituicao_externa_id' ? ($nomesPai[$antes[$campo] ?? 0] ?? '—') : ($antes[$campo] ?? '—'))) ?></span> → <?= e((string)($campo === 'instituicao_externa_id' ? ($nomesPai[$novo] ?? '—') : ($novo ?? '—'))) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $h['autor_nome'] !== null ? e($h['autor_nome']) : '<span class="texto-secundario">Não identificado (sem autenticação)</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
