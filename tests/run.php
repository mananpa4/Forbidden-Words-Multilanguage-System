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

use ForbiddenWords\EvasionNormalizer;
use ForbiddenWords\ForbiddenWords;
use ForbiddenWords\MarketplaceDictionary;
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

// --- EvasionNormalizer ---
check('leet c4s1n0 -> casino', EvasionNormalizer::normalizeToken('c4s1n0'), 'casino');
check('separadores w.h.a.t.s -> whatsapp', EvasionNormalizer::normalizeToken('w.h.a.t.s'), 'whats');
check('homoglifo cirillico о -> o', EvasionNormalizer::normalizeToken("c\u{0430}sino"), 'casino');
check('invisible zero-width eliminado', EvasionNormalizer::normalizeToken("cas\u{200B}ino"), 'casino');
check('fonetica guasap -> whatsapp', EvasionNormalizer::normalizeToken('guasap'), 'whatsapp');
check('texto spaced letters', EvasionNormalizer::normalizeText('p a g a   p o r   f u e r a'), 'paga por fuera');
check('evasion detecta zero width', EvasionNormalizer::detectEvasion("hola\u{200B}mundo"), ['ZERO_WIDTH']);
check('evasion detecta separadores', EvasionNormalizer::detectEvasion('w.h.a.t.s'), ['SEPARATORS']);
check('evasion limpia no detecta', EvasionNormalizer::detectEvasion('hola mundo'), []);

// --- MarketplaceDictionary ---
$market = MarketplaceDictionary::fromFile();

// C4 OFF_PLATFORM_PAYMENT: block directo
$r = $market->scan('Cancela el pedido y te pago por fuera con transferencia directa');
check('marketplace block pago por fuera', $r['risk_level'], MarketplaceDictionary::RISK_HIGH);
check('marketplace action block', $r['action'], MarketplaceDictionary::ACTION_BLOCK);
check('marketplace categorias incluye OFF_PLATFORM', in_array('OFF_PLATFORM_PAYMENT', $r['matched_categories'], true), true);

// C1 mensajeria sola: signal, no block
$r = $market->scan('Tienes WhatsApp para dudas?');
check('marketplace whatsapp sola no block', $r['action'] !== MarketplaceDictionary::ACTION_BLOCK, true);
check('marketplace whatsapp sola risk no HIGH', $r['risk_level'] !== MarketplaceDictionary::RISK_HIGH, true);

// Combinacion critica: mensaje + pago por fuera -> HIGH
$r = $market->scan('Escribeme al whatsapp y te pago por fuera');
check('marketplace combinacion contacto+pago HIGH', $r['risk_level'], MarketplaceDictionary::RISK_HIGH);

// C8 insultos: signal (no block automatico por palabra sola)
$r = $market->scan('Eres un pendejo');
check('marketplace insulto signal', in_array('OFFENSES', $r['matched_categories'], true), true);

// Evasion: p a g a p o r f u e r a
$r = $market->scan('P a g a   p o r   f u e r a y te lo mando');
check('marketplace evasion spaced letters block', $r['risk_level'], MarketplaceDictionary::RISK_HIGH);
check('marketplace evasion detectada', $r['obfuscation_detected'], true);

// Mensaje limpio
$r = $market->scan('Hola, cuando llega mi pedido #12345?');
check('marketplace limpio ALLOW', $r['action'], MarketplaceDictionary::ACTION_ALLOW);
check('marketplace limpio score 0', $r['score'], 0);

// C7 credenciales
$r = $market->scan('Envia el codigo OTP que te llego por SMS');
check('marketplace otp HIGH', $r['risk_level'], MarketplaceDictionary::RISK_HIGH);

// C6 disputa: frase block
$r = $market->scan('No abras el reclamo y te devuelvo el dinero');
check('marketplace disputa block', $r['risk_level'], MarketplaceDictionary::RISK_HIGH);

printf("\n%d/%d correctos", $passed, $passed + $failed);
if ($failed > 0) {
    printf(", %d FALLOS\n", $failed);
    exit(1);
}
echo "\n";
