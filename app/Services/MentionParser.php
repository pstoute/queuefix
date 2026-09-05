<?php

namespace App\Services;

class MentionParser
{
    /** @return list<string> */
    public function handles(string $text): array
    {
        preg_match_all(
            '/(?<![A-Za-z0-9._%+\-])@([A-Za-z0-9][A-Za-z0-9_-]{0,47})(?![A-Za-z0-9_-])/u',
            $text,
            $matches,
        );

        return array_values(array_unique(array_map(
            static fn (string $handle): string => strtolower($handle),
            $matches[1],
        )));
    }
}
