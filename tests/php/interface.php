<?php
declare(strict_types=1);

/**
 * Testes de preferências da interface (não dependem do banco).
 * @var \Tests\Runner $t
 */

$t->grupo('Interface — densidade');

$t->teste('densidade padrão é compacta e só aceita os dois modos', function ($t) {
    $t->igual(densidade_interface('comfortable'), 'comfortable', 'modo confortável');
    $t->igual(densidade_interface('compact'), 'compact', 'modo compacto');
    $t->igual(densidade_interface(''), 'compact', 'vazio vira compacto');
    $t->igual(densidade_interface('"><script>'), 'compact', 'valor inválido vira compacto');
});
