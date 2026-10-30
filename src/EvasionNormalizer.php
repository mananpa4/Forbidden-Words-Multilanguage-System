<?php

declare(strict_types=1);

namespace ForbiddenWords;

/**
 * Normalizacion anti-evasion: reconstruye la forma canonica de un token que
 * el usuario intento ocultar con separadores, leet u homoglifos.
 *
 * NO sustituye al token original: se genera como representacion auxiliar para
 * buscar patrones concretos. Quien llama decide si la reconstruccion basta
 * para clasificar una infraccion.
 *
 * Reglas implementadas (matriz de evasion del sistema Trust & Safety):
 *  1. Caracteres de ancho cero y controles invisibles -> eliminados.
 *  2. Separadores artificiales entre letras (., _, -, *, espacios dobles)
 *     -> reducidos a la forma concatenada.
 *  3. Sustituciones leet frecuentes (0=o, 1=i, 3=e, 4=a, 5=s, 7=t, @=a, $=s).
 *  4. Homoglifos de alfabetos distintos usados para evadir (cirilico -> latino).
 *  5. Variantes foneticas comunes de mensajeria (guasap, wazap, wasap).
 *
 * Cada transformacion es reversible en el sentido de que solo se aplica al
 * texto normalizado de trabajo; el mensaje original se conserva para auditoria.
 */
final class EvasionNormalizer
{
    /** Caracteres invisibles que no aportan lectura y se usan para evadir. */
    private const INVISIBLE = [
        "\u{00AD}", // soft hyphen
        "\u{200B}", // zero width space
        "\u{200C}", // zero width non-joiner
        "\u{200D}", // zero width joiner
        "\u{2060}", // word joiner
        "\u{FEFF}", // BOM / zero width no-break space
    ];

    /** Sustituciones leet tipicas -> letra latina. */
    private const LEET = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '@' => 'a',
        '$' => 's',
    ];

    /** Homoglifos cirillicos/griegos usados para evadir filtros latinos. */
    private const HOMOGLYPHS = [
        "\u{0430}" => 'a', // cyrillic a
        "\u{0435}" => 'e', // cyrillic ie
        "\u{043E}" => 'o', // cyrillic o
        "\u{0440}" => 'p', // cyrillic er
        "\u{0441}" => 'c', // cyrillic es
        "\u{0443}" => 'y', // cyrillic u
        "\u{0445}" => 'x', // cyrillic ha
        "\u{0456}" => 'i', // cyrillic byelorussian-ukrainian i
        "\u{04BB}" => 'h', // cyrillic shha
        "\u{03B1}" => 'a', // greek alpha
        "\u{03B5}" => 'e', // greek epsilon
        "\u{03BF}" => 'o', // greek omicron
        "\u{03C1}" => 'p', // greek rho
        "\u{03C3}" => 's', // greek sigma
        "\u{03C5}" => 'u', // greek upsilon
        "\u{03C7}" => 'x', // greek chi
    ];

    /** Variantes foneticas ya canonicas: la forma evadida -> la forma real. */
    private const PHONETIC = [
        'guasap' => 'whatsapp',
        'wazap' => 'whatsapp',
        'wasap' => 'whatsapp',
        'watsap' => 'whatsapp',
        'watsApp' => 'whatsapp',
        'wsp' => 'whatsapp',
        'wpp' => 'whatsapp',
        'tb' => 'telegram',
        'tgram' => 'telegram',
        'tg' => 'telegram',
        'igm' => 'instagram',
        'insta' => 'instagram',
    ];

    /**
     * Devuelve la forma canonica de $token para busqueda de patrones.
     * El resultado esta en minusculas, sin invisibles, sin leet ni homoglifos.
     */
    public static function normalizeToken(string $token): string
    {
        $t = self::stripInvisible($token);
        $t = mb_strtolower($t);
        $t = self::replaceHomoglyphs($t);
        $t = self::replaceLeet($t);

        // Separadores artificiales solo entre letras (no rompe "hola.mundo"
        // como palabra, pero si reconstruye "w.h.a.t.s.a.p.p").
        $t = preg_replace('/(?<=\p{L})[._\-*·•](?=\p{L})/u', '', $t) ?? $t;

        if (isset(self::PHONETIC[$t])) {
            $t = self::PHONETIC[$t];
        }

        return $t;
    }

    /**
     * Normaliza un texto completo: minusculas, sin invisibles, sin homoglifos
     * ni leet. Conserva espacios y puntuacion basica para no fusionar numeros
     * de pedido ni URLs legitimas.
     */
    public static function normalizeText(string $text): string
    {
        $t = self::stripInvisible($text);
        $t = mb_strtolower($t);
        $t = self::replaceHomoglyphs($t);
        $t = self::replaceLeet($t);

        // Colapsa SOLO secuencias de letras sueltas separadas por espacios:
        // "p a g a   p o r   f u e r a" -> "paga por fuera".
        // No toca palabras normales: exige que cada "letra" tenga un espacio
        // detras y que el grupo completo este delimitado.
        $t = preg_replace_callback(
            '/(?<![\p{L}\p{N}])(?:\p{L} )+\p{L}(?![\p{L}\p{N}])/u',
            static fn (array $m): string => str_replace(' ', '', $m[0]),
            $t,
        ) ?? $t;
        $t = preg_replace('/\s{2,}/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Detecta indicios deliberados de ocultamiento en el texto original.
     * Devuelve las tecnicas observadas, para registrar EVASION_BYPASS.
     *
     * @return list<string>
     */
    public static function detectEvasion(string $text): array
    {
        $found = [];

        if (preg_match('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', $text)) {
            $found[] = 'ZERO_WIDTH';
        }
        if (preg_match('/\p{L}[._\-*·•]\p{L}/u', $text)) {
            $found[] = 'SEPARATORS';
        }
        if (preg_match('/\p{L}\s+\p{L}\s+\p{L}/u', $text) && preg_match('/\b\w\s\w\s\w\s\w\b/u', $text)) {
            $found[] = 'SPACED_LETTERS';
        }
        if (preg_match('/[0-9@$]/', $text) && preg_match('/\b[4a][5s][3e]/i', $text)) {
            // heuristica suave: presencia de digitos pegados a letras
            if (preg_match('/\p{L}[0-9@$]|[0-9@$]\p{L}/u', $text)) {
                $found[] = 'LEET';
            }
        }
        if (preg_match('/[\x{0400}-\x{04FF}\x{0370}-\x{03FF}]/u', $text)) {
            $found[] = 'HOMOGLYPHS';
        }

        return $found;
    }

    private static function stripInvisible(string $text): string
    {
        return str_replace(self::INVISIBLE, '', $text);
    }

    private static function replaceLeet(string $text): string
    {
        return strtr($text, self::LEET);
    }

    private static function replaceHomoglyphs(string $text): string
    {
        return strtr($text, self::HOMOGLYPHS);
    }
}
