<?php

// app/Helpers/initials_helper.php
// Initials for the round letter avatars (My Profile, the Owner's sidebar footer, Manage Accounts).

if (! function_exists('initials')) {
    /**
     * First letter of the first and the last word, upper case: "Real Owner" → "RO".
     * Middle words (e.g. "D." in "Richelle D. Paras") are never used; one word gives one letter
     * ("J." → "J"). Dots and other marks around a word are ignored. Multibyte-safe ("Ñoño Reyes" → "ÑR").
     * An empty name gives "".
     */
    function initials(?string $name): string
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $core = preg_replace('/[^\p{L}\p{N}]+/u', '', $word);
            if ($core !== null && $core !== '') {
                $words[] = $core;
            }
        }
        if ($words === []) {
            return '';
        }

        $letter = static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1));

        return count($words) === 1 ? $letter($words[0]) : $letter($words[0]) . $letter($words[count($words) - 1]);
    }
}
