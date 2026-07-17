<?php

declare(strict_types=1);

namespace ForbiddenWords\Language;

use ForbiddenWords\Pluralizer;

/**
 * Plurales del ingles.
 *
 *   cats     -> cat
 *   boxes    -> box        (-es tras s, x, z, ch, sh)
 *   cities   -> city       (-y -> -ies)
 *   knives   -> knife/knif (-f/-fe -> -ves)
 *
 * Los plurales irregulares (children, people, mice) no se derivan: no siguen
 * regla. Si hace falta uno, se anade a la lista como palabra propia.
 */
final class EnglishPluralizer implements Pluralizer
{
    private const MIN_LENGTH = 4;

    public function language(): string
    {
        return 'en';
    }

    public function singularCandidates(string $word): array
    {
        $length = mb_strlen($word);
        if ($length < self::MIN_LENGTH || ! str_ends_with($word, 's')) {
            return [];
        }

        $candidates = [];

        // cities -> city
        if (str_ends_with($word, 'ies')) {
            $candidates[] = mb_substr($word, 0, $length - 3).'y';
        }

        // knives -> knife, knifes
        if (str_ends_with($word, 'ves')) {
            $stem         = mb_substr($word, 0, $length - 3);
            $candidates[] = $stem.'fe';
            $candidates[] = $stem.'f';
        }

        // boxes -> box | churches -> church
        if (str_ends_with($word, 'es')) {
            $candidates[] = mb_substr($word, 0, $length - 2);
        }

        // cats -> cat
        $candidates[] = mb_substr($word, 0, $length - 1);

        return array_values(array_unique(array_filter($candidates)));
    }
}
