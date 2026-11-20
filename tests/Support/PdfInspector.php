<?php

namespace App\Tests\Support;

/**
 * Examine un PDF produit par l'application : validité, nombre de pages, format, et TEXTE réellement présent
 * (via pdftotext / pdfinfo de poppler). Sert à vérifier qu'une carte contient bien les données du bon adhérent.
 */
final class PdfInspector
{
    public static function available(): bool
    {
        return self::binary("pdftotext") !== null && self::binary("pdfinfo") !== null;
    }

    /**
     * @return array{valid: bool, bytes: int, pages: ?int, pageSize: ?string, images: ?int, text: string, error: ?string}
     */
    public static function inspect(string $pdfContent): array
    {
        $bytes = strlen($pdfContent);
        $valid = str_starts_with($pdfContent, "%PDF-") && str_contains(substr($pdfContent, -1024), "%%EOF");
        $result = ["valid" => $valid, "bytes" => $bytes, "pages" => null, "pageSize" => null, "images" => null, "text" => "", "error" => null];

        if (!$valid) {
            $result["error"] = "Le contenu n'est pas un PDF complet (en-tête ou %%EOF manquant)";
            return $result;
        }
        if (!self::available()) {
            $result["error"] = "pdftotext/pdfinfo indisponibles : contenu du PDF non lu";
            return $result;
        }

        $file = tempnam(sys_get_temp_dir(), "skk-pdf-");
        file_put_contents($file, $pdfContent);
        try {
            $info = (string)shell_exec(escapeshellarg(self::binary("pdfinfo")) . " " . escapeshellarg($file) . " 2>/dev/null");
            if (preg_match("/^Pages:\s+(\d+)/m", $info, $m)) {
                $result["pages"] = (int)$m[1];
            }
            if (preg_match("/^Page size:\s+(.+)$/m", $info, $m)) {
                $result["pageSize"] = trim($m[1]);
            }
            if (self::binary("pdfimages") !== null) {
                $list = (string)shell_exec(escapeshellarg(self::binary("pdfimages")) . " -list " . escapeshellarg($file) . " 2>/dev/null");
                $result["images"] = max(0, count(array_filter(explode("\n", trim($list)))) - 2);
            }
            $result["text"] = trim((string)shell_exec(escapeshellarg(self::binary("pdftotext")) . " -layout " . escapeshellarg($file) . " - 2>/dev/null"));
        } finally {
            @unlink($file);
        }

        return $result;
    }

    private static function binary(string $name): ?string
    {
        foreach (["/opt/homebrew/bin/", "/usr/local/bin/", "/usr/bin/"] as $dir) {
            if (is_executable($dir . $name)) {
                return $dir . $name;
            }
        }

        return null;
    }
}
