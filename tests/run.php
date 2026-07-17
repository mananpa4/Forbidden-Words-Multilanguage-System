<?php

declare(strict_types=1);

/**
 * Banco de pruebas sin dependencias: `php tests/run.php`.
 *
 * No usa PHPUnit a proposito. La libreria no tiene dependencias de ejecucion y
 * se copia dentro de sitios heredados (WoWonder) donde no siempre hay composer
 * disponible; las pruebas deben poder ejecutarse igual.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'ForbiddenWords\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use ForbiddenWords\ForbiddenWords;
use ForbiddenWords\WordSet;

$passed = 0;
$failed = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;

        return;
    }
    $failed++;
    printf(
        "  FALLO  %s\n         esperaba: %s\n         obtuvo:   %s\n",
        $name,
        var_export($expected, true),
        var_export($actual, true),
    );
}

$filter = new ForbiddenWords(new WordSet(
    base: ['casino', 'apuesta', 'droga', 'papel', 'luz', 'ciudad'],
    extra: ['city', 'knife', 'box', 'cat', '赌场'],
    removed: ['papel'],
));

// --- Lo que motivo todo esto: el plural debe detectarse ---
check('singular exacto', $filter->isForbidden('casino'), true);
check('plural regular', $filter->isForbidden('casinos'), true);
check('plural en mayusculas', $filter->isForbidden('CASINOS'), true);
check('plural con acento tipografico', $filter->isForbidden('Apuestas'), true);
check('plural es -ces -> -z', $filter->isForbidden('luces'), true);
check('plural es -es', $filter->isForbidden('ciudades'), true);
check('plural en -ies -> -y', $filter->isForbidden('cities'), true);
check('plural en -ves -> -fe', $filter->isForbidden('knives'), true);
check('plural en -es', $filter->isForbidden('boxes'), true);
check('plural en -s', $filter->isForbidden('cats'), true);

// --- Falsos positivos: lo que NO debe censurarse ---
check('palabra legitima', $filter->isForbidden('casa'), false);
check('plural legitimo', $filter->isForbidden('casas'), false);
check('plural legitimo 2', $filter->isForbidden('flores'), false);
check('subcadena no cuenta', $filter->isForbidden('clase'), false);
check('palabra corta intacta', $filter->isForbidden('dos'), false);
check('capa removed excluye', $filter->isForbidden('papel'), false);
check('capa removed excluye plural', $filter->isForbidden('papeles'), false);

// --- Enmascarado, identico a chismy/dowdes/shomex ---
check('mask 7 letras', ForbiddenWords::mask('pendejo'), 'pen****');
check('mask 1 letra', ForbiddenWords::mask('a'), '*');
check('mask 2 letras', ForbiddenWords::mask('ab'), 'a*');

// --- Censura sobre frases ---
check(
    'censura plural en frase',
    $filter->censor('Vamos a los casinos de la ciudad'),
    'Vamos a los cas**** de la ciu***',
);
check(
    'frase limpia intacta',
    $filter->censor('Tengo dos casas con flores'),
    'Tengo dos casas con flores',
);
check('texto nulo', $filter->censor(null), '');
check('contiene prohibida', $filter->containsForbidden('hay apuestas aqui'), true);
check('no contiene prohibida', $filter->containsForbidden('todo en orden'), false);

// --- CJK: sin espacios, se busca subcadena ---
check('cjk subcadena', $filter->censor('我们去赌场吧'), '我们去赌*吧');
check('cjk contiene', $filter->containsForbidden('去赌场'), true);

// --- Lista vacia: no se toca nada ---
$empty = new ForbiddenWords(new WordSet());
check('lista vacia no censura', $empty->censor('casinos y apuestas'), 'casinos y apuestas');

// --- Capas ---
check('conteo respeta removed', $filter->words()->count(), 10);
check('parse separa comas y saltos', WordSet::parse("uno, dos\ntres,\n\ncuatro"), ['uno', 'dos', 'tres', 'cuatro']);

printf("\n%d/%d correctos", $passed, $passed + $failed);
if ($failed > 0) {
    printf(", %d FALLOS\n", $failed);
    exit(1);
}
echo "\n";
