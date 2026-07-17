<?php

declare(strict_types=1);

namespace ForbiddenWords\Language;

use ForbiddenWords\Pluralizer;

/**
 * Plurales del espanol.
 *
 *   casinos  -> casino     (vocal + s)
 *   papeles  -> papel      (consonante + es)
 *   luces    -> luz        (z -> ces)
 *   lapices  -> lapiz
 *
 * Limite conocido: el espanol desplaza la tilde al pluralizar
 * ("avion" -> "aviones"), asi que de "aviones" se deriva "avion" sin tilde. Se
 * emite tambien una variante con tilde en la ultima vocal para cubrir el caso
 * mas comun; si la lista trae una forma acentuada rara, hay que anadirla a mano.
 */
final class SpanishPluralizer implements Pluralizer
{
    /** Longitud minima para arriesgarse a quitar la "s": "dos" -> "do" es ruido. */
    private const MIN_LENGTH = 4;

    public function language(): string
    {
        return 'es';
    }

    public function singularCandidates(string $word): array
    {
        $length = mb_strlen($word);
        if ($length < self::MIN_LENGTH || ! str_ends_with($word, 's')) {
            return [];
        }

        $candidates = [];

        // luces -> luz | lapices -> lapiz
        if (str_ends_with($word, 'ces')) {
            $candidates[] = mb_substr($word, 0, $length - 3).'z';
        }

        // papeles -> papel | flores -> flor
        if (str_ends_with($word, 'es')) {
            $stem         = mb_substr($word, 0, $length - 2);
            $candidates[] = $stem;
            // aviones -> avion -> avión: la tilde se pierde al pluralizar.
            $accented = $this->accentLastVowel($stem);
            if ($accented !== null) {
                $candidates[] = $accented;
            }
        }

        // casinos -> casino
        $candidates[] = mb_substr($word, 0, $length - 1);

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Devuelve $stem con tilde en su ultima vocal, o null si no tiene ninguna.
     */
    private function accentLastVowel(string $stem): ?string
    {
        $map = ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú'];
        for ($i = mb_strlen($stem) - 1; $i >= 0; $i--) {
            $char = mb_substr($stem, $i, 1);
            if (isset($map[$char])) {
                return mb_substr($stem, 0, $i).$map[$char].mb_substr($stem, $i + 1);
            }
        }

        return null;
    }
}
