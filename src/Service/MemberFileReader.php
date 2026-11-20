<?php

namespace App\Service;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Lit un fichier CSV / XLSX d'adhérents envoyé par un club.
 *
 * - CSV : détecte le séparateur (, ; tabulation), retire le BOM UTF-8, convertit
 *   Windows-1252 en UTF-8 et accepte les fins de ligne \n, \r\n et \r (Excel Mac).
 * - Les colonnes sont retrouvées par leur nom d'en-tête (insensible à la casse et
 *   aux accents), donc l'ordre des colonnes n'a pas d'importance.
 * - Aucune limite de longueur de ligne ni de nombre de lignes.
 *
 * Retourne une liste de tableaux associatifs (clés : voir COLUMNS).
 */
class MemberFileReader
{
    /** clé interne => intitulés acceptés (normalisés : minuscules, sans accents) */
    private const COLUMNS = [
        "first_name" => ["prenom"],
        "last_name" => ["nom de famille", "nom"],
        "sex" => ["sexe"],
        "birth_date" => ["date de naissance", "naissance"],
        "address" => ["adresse", "adresse postale"],
        "postal_code" => ["code postal", "cp"],
        "city" => ["ville"],
        "email" => ["adresse electronique", "email", "e-mail", "courriel", "mail"],
        "phone" => ["numero de telephone", "telephone", "tel", "portable"],
    ];

    private const REQUIRED = ["first_name", "last_name", "sex", "birth_date", "email"];

    private const LABELS = [
        "first_name" => "Prénom",
        "last_name" => "Nom de famille",
        "sex" => "Sexe",
        "birth_date" => "Date de naissance",
        "email" => "Adresse électronique",
    ];

    private const MAX_ERRORS_SHOWN = 5;

    /**
     * @return list<array<string, string>>
     * @throws InvalidArgumentException message affichable à l'utilisateur
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        $rows = match ($extension) {
            "csv" => $this->readCsvRows($path),
            "xlsx", "xls" => $this->readSpreadsheetRows($path),
            default => throw new InvalidArgumentException("Type de fichier non pris en charge (CSV ou XLSX attendu)."),
        };

        if (count($rows) === 0) {
            throw new InvalidArgumentException("Le fichier est vide.");
        }

        // Première ligne non vide = en-têtes
        // (array_shift renumérote les clés : on retire l'en-tête par sa clé pour conserver les vrais numéros de ligne)
        $headerLine = array_key_first($rows);
        $headers = $rows[$headerLine];
        unset($rows[$headerLine]);
        $map = $this->mapHeaders($headers);

        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (!isset($map[$key])) {
                $missing[] = self::LABELS[$key];
            }
        }
        if ($missing) {
            throw new InvalidArgumentException(
                "Colonne(s) obligatoire(s) manquante(s) : " . implode(", ", $missing) . "."
            );
        }

        $members = [];
        $errors = [];
        foreach ($rows as $lineNumber => $row) {
            $member = [];
            foreach (array_keys(self::COLUMNS) as $key) {
                $member[$key] = isset($map[$key]) ? trim((string)($row[$map[$key]] ?? "")) : "";
            }

            $member["first_name"] = NameFormatter::firstName($member["first_name"]);

            $problems = [];
            foreach (self::REQUIRED as $key) {
                if ($member[$key] === "") {
                    $problems[] = mb_strtolower(self::LABELS[$key]);
                }
            }
            if ($member["email"] !== "" && !filter_var($member["email"], FILTER_VALIDATE_EMAIL)) {
                $problems[] = "adresse électronique invalide (« " . $member["email"] . " »)";
            }

            if ($problems) {
                $errors[] = "ligne $lineNumber : " . implode(", ", $problems);
                continue;
            }

            $members[] = $member;
        }

        if ($errors) {
            $shown = array_slice($errors, 0, self::MAX_ERRORS_SHOWN);
            $more = count($errors) - count($shown);
            throw new InvalidArgumentException(
                "Le fichier contient des lignes incomplètes ou invalides : " . implode(" ; ", $shown)
                . ($more > 0 ? " (et $more autre(s))" : "") . "."
            );
        }

        if (count($members) === 0) {
            throw new InvalidArgumentException("Le fichier ne contient aucun adhérent.");
        }

        return $members;
    }

    /**
     * @return array<int, list<string>> lignes non vides, indexées par leur numéro de ligne dans le fichier
     */
    private function readCsvRows(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Impossible de lire le fichier.");
        }

        // BOM UTF-8
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        // Encodage (exports Excel Windows)
        if (!mb_check_encoding($raw, "UTF-8")) {
            $raw = mb_convert_encoding($raw, "UTF-8", "Windows-1252");
        }
        // Fins de ligne : \r\n et \r (Excel Mac) => \n
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $firstLine = strtok($raw, "\n") ?: "";
        $delimiter = $this->detectDelimiter($firstLine);

        $handle = fopen("php://temp", "r+");
        fwrite($handle, $raw);
        rewind($handle);

        $rows = [];
        $lineNumber = 0;
        while (($data = fgetcsv($handle, 0, $delimiter, "\"", "")) !== false) {
            $lineNumber++;
            $data = array_map(static fn($v) => (string)$v, $data);
            if (count(array_filter($data, static fn($v) => trim($v) !== "")) === 0) {
                continue;
            }
            $rows[$lineNumber] = $data;
        }
        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $line): string
    {
        $best = ",";
        $bestCount = -1;
        foreach ([",", ";", "\t"] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @return array<int, list<string>>
     */
    private function readSpreadsheetRows(string $path): array
    {
        try {
            // Un fichier texte renommé en .xlsx serait sinon lu comme un CSV : on exige un vrai classeur.
            if (!in_array(IOFactory::identify($path), ["Xlsx", "Xls", "Ods"], true)) {
                throw new \RuntimeException("Pas un classeur Excel.");
            }
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Le fichier Excel est illisible ou corrompu.");
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $rows = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            $data = [];
            $hasValue = false;
            for ($c = 1; $c <= $highestColumn; $c++) {
                $cell = $sheet->getCell([$c, $r]);
                $value = $cell->getValue();

                if ($value === null || $value === "") {
                    $data[] = "";
                    continue;
                }

                if (is_numeric($value) && Date::isDateTime($cell)) {
                    $value = Date::excelToDateTimeObject((float)$value)->format("d/m/Y");
                } else {
                    // getFormattedValue conserve les zéros initiaux (téléphone, code postal) et résout les formules
                    $value = (string)$cell->getFormattedValue();
                }

                $data[] = $value;
                $hasValue = $hasValue || trim($value) !== "";
            }
            if ($hasValue) {
                $rows[$r] = $data;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param list<string> $headers
     * @return array<string, int> clé interne => index de colonne
     */
    private function mapHeaders(array $headers): array
    {
        $normalized = array_map(fn($h) => $this->normalize((string)$h), $headers);

        $map = [];
        foreach (self::COLUMNS as $key => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', "", $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', " ", $value) ?? $value);
        $value = mb_strtolower($value);

        return strtr($value, [
            "à" => "a", "â" => "a", "ä" => "a", "é" => "e", "è" => "e", "ê" => "e", "ë" => "e",
            "î" => "i", "ï" => "i", "ô" => "o", "ö" => "o", "ù" => "u", "û" => "u", "ü" => "u",
            "ç" => "c", "’" => "'", "*" => "",
        ]);
    }
}
