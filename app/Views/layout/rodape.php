        </main>

        <footer class="rodape">
            <span><?= e(config('app.nome')) ?> — <?= e(config('app.unidade')) ?></span>
            <span>Dados extraídos exigem revisão humana antes do salvamento</span>
        </footer>
    </div>
</div>

<div class="overlay" id="overlay" hidden>
    <div class="overlay__caixa">
        <div class="spinner"></div>
        <p class="overlay__texto" id="overlayTexto">Processando…</p>
    </div>
</div>

<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('assets/js/extension-bridge.js')) ?>"></script>
<script src="<?= e(asset('assets/js/pesquisa-geral.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
    <script src="<?= e(asset('assets/js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
