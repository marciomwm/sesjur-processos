<?php
/**
 * Campo "Designado a" dos filtros das listagens de processos.
 * Variavel: $designadoUrl (valor atual: '', 'eu', 'nenhum' ou id)
 * A lista de usuarios aparece para quem designa (administrador/gestor).
 */
use App\Core\Auth;
use App\Repositories\UsuarioRepository;

$usuariosFiltro = Auth::podeDesignar() ? (new UsuarioRepository())->opcoesAtivos() : [];
?>
<div class="campo">
    <label class="campo__rotulo" for="designado">Designado a</label>
    <select class="select" id="designado" name="designado">
        <option value="">— Todos —</option>
        <option value="eu" <?= $designadoUrl === 'eu' ? 'selected' : '' ?>>Designados a mim</option>
        <option value="nenhum" <?= $designadoUrl === 'nenhum' ? 'selected' : '' ?>>Sem designação</option>
        <?php if ($usuariosFiltro !== []): ?>
            <optgroup label="Usuários">
                <?php foreach ($usuariosFiltro as $u): ?>
                    <option value="<?= e((string)$u['id']) ?>" <?= $designadoUrl === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    </select>
</div>
