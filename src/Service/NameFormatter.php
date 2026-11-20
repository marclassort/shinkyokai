<?php

namespace App\Service;

/**
 * Mise en forme des noms saisis par les utilisateurs ou lus dans les fichiers des clubs.
 */
final class NameFormatter
{
    /**
     * Un prénom commence toujours par une majuscule, ainsi que chacune de ses parties, et n'est jamais tout en capitales :
     *  - « karim » → « Karim », « anne-sophie » → « Anne-Sophie », « marie claire » → « Marie Claire » ;
     *  - « LUCAS » → « Lucas », « SACHA » → « Sacha », « ANNE-SOPHIE » → « Anne-Sophie ».
     * Une partie qui mélange déjà majuscules et minuscules est conservée telle quelle (« McKenzie » reste « McKenzie »).
     */
    public static function firstName(?string $firstName): string
    {
        $value = trim(preg_replace("/\s+/u", " ", (string)$firstName) ?? "");

        $parts = preg_split("/([\s\-'’])/u", $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        foreach ($parts as $i => $part) {
            if ($part === "" || preg_match("/^[\s\-'’]$/u", $part)) {
                continue;
            }
            $allCapitals = mb_strlen($part) > 1 && mb_strtoupper($part) === $part && mb_strtolower($part) !== $part;
            $parts[$i] = $allCapitals
                ? mb_convert_case($part, MB_CASE_TITLE, "UTF-8")
                : mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
        }

        return implode("", $parts);
    }
}
