<?php

declare(strict_types=1);

namespace ForbiddenWords;

use ForbiddenWords\Language\EnglishPluralizer;
use ForbiddenWords\Language\SpanishPluralizer;

/**
 * Filtro de palabras prohibidas multilenguaje.
 *
 * Tres decisiones que conviene conocer antes de tocar esto:
 *
 * 1. Coincide por palabra completa, no por subcadena. El enfoque ingenuo
 *    (`str_replace`) destroza palabras legitimas: con "as" prohibido, "clase"
 *    se convierte en "cl**e". Se tokeniza con una expresion Unicode y se compara
 *    token a token.
 *
 * 2. Deriva el singular del token en vez de pluralizar la lista. Asi la lista
 *    solo declara "casino" y "casinos" tambien se detecta, sin duplicar el
 *    conjunto ni tener que acertar la pluralizacion de cada entrada.
 *
 * 3. El CJK va aparte. El chino, el japones y el coreano no separan palabras con
 *    espacios, asi que para ellos si hay que buscar subcadenas. Es la excepcion
 *    deliberada al punto 1.
 *
 * No decide que hacer con el texto: solo lo enmascara o responde si contiene
 * algo prohibido. Bloquear, avisar o registrar es de quien llama.
 */
final class ForbiddenWords
{
    /** @var list<Pluralizer> */
    private array $pluralizers;

    /**
     * @param list<Pluralizer>|null $pluralizers Por defecto, espanol e ingles.
     */
    public function __construct(
        private WordSet $words,
        ?array $pluralizers = null,
    ) {
        $this->pluralizers = $pluralizers ?? [
            new SpanishPluralizer(),
            new EnglishPluralizer(),
        ];
    }

    public function words(): WordSet
    {
        return $this->words;
    }

    /**
     * True si $word, o alguno de sus singulares posibles, esta prohibido.
     */
    public function isForbidden(string $word): bool
    {
        $lower = mb_strtolower($word);
        if ($this->words->has($lower)) {
            return true;
        }

        foreach ($this->pluralizers as $pluralizer) {
            foreach ($pluralizer->singularCandidates($lower) as $candidate) {
                if ($candidate !== '' && $this->words->has($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Enmascara parcialmente cada palabra prohibida de $text.
     */
    public function censor(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }
        if ($this->words->count() === 0) {
            return $text;
        }

        $censored = preg_replace_callback(
            '/\p{L}[\p{L}\p{N}\']*+/u',
            fn (array $m): string => $this->isForbidden($m[0]) ? self::mask($m[0]) : $m[0],
            $text,
        ) ?? $text;

        return $this->censorCjk($censored);
    }

    public function containsForbidden(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        return $this->censor($text) !== $text;
    }

    /**
     * Deja visible la primera mitad de la palabra y tapa el resto:
     * "pendejo" (7) -> "pen" + "****" = "pen****".
     *
     * Se conserva este reparto exacto porque chismy.com, dowdes.com y
     * shomex.com.mx ya censuran asi y el resultado debe verse igual en los tres.
     */
    public static function mask(string $word): string
    {
        $length = mb_strlen($word);
        if ($length <= 1) {
            return str_repeat('*', max(1, $length));
        }
        $shown = (int) floor($length / 2);

        return mb_substr($word, 0, $shown).str_repeat('*', $length - $shown);
    }

    private function censorCjk(string $text): string
    {
        foreach ($this->words->cjkWords() as $word) {
            if (mb_strpos($text, $word) !== false) {
                $text = str_replace($word, self::mask($word), $text);
            }
        }

        return $text;
    }
}
