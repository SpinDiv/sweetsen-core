<?php

declare(strict_types=1);

namespace SweetSen\Domain;

/** Name normalization for duplicate matching (case, accents, punctuation, honorifics, suffixes). */
final class Names
{
    private const IGNORE = ['jr', 'sr', 'ii', 'iii', 'iv', 'ma', 'maria', 'mr', 'mrs', 'ms', 'dr', 'dra', 'gng', 'g'];

    /** @return list<string> */
    public static function tokens(?string $name): array
    {
        $s = strtolower(remove_accents((string) $name));
        $s = (string) preg_replace('/[^a-z]+/', ' ', $s);
        $tokens = array_values(array_filter(
            explode(' ', trim($s)),
            static fn(string $t): bool => $t !== '' && !in_array($t, self::IGNORE, true),
        ));
        return $tokens;
    }

    public static function key(?string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /** First names match when they share any meaningful token ("Ma. Teresa" ~ "Teresa", "ROLANDO" ~ "Rolando"). */
    public static function firstNamesMatch(?string $a, ?string $b): bool
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        return $ta !== [] && $tb !== [] && array_intersect($ta, $tb) !== [];
    }
}
