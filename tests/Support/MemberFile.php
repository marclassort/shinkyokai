<?php

namespace App\Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fabrique des fichiers d'adhérents SYNTHÉTIQUES (aucune donnée réelle) au format du modèle fourni aux clubs.
 */
final class MemberFile
{
    public const HEADERS = [
        "Prénom", "Nom de famille", "Sexe", "Date de naissance", "Adresse", "Code postal", "Ville",
        "Adresse électronique", "Numéro de téléphone",
    ];

    /**
     * @return list<array<string, string>> lignes triées par nom, comme le fichier réel du 6 novembre
     */
    public static function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                "first_name" => sprintf("Prenom%03d", $i),
                "last_name" => sprintf("NOM%03d", $i),
                "sex" => $i % 3 === 0 ? "Féminin" : "Masculin",
                "birth_date" => sprintf("%02d/%02d/%d", 1 + $i % 28, 1 + $i % 12, 2000 + $i % 20),
                "address" => $i . " rue des Tests",
                "postal_code" => "64000",
                "city" => "Pau",
                // Plusieurs adhérents partagent la même adresse (familles), comme dans le fichier réel
                "email" => sprintf("famille%03d@example.test", intdiv($i + 1, 2)),
                "phone" => sprintf("06%08d", $i),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public static function xlsx(array $rows, ?string $path = null): string
    {
        $path ??= self::tempPath("xlsx");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADERS, null, "A1");
        foreach ($rows as $index => $row) {
            // Explicitement en texte : on teste la lecture, pas la conversion de types d'Excel
            $sheet->fromArray(array_values($row), null, "A" . ($index + 2), true);
            $sheet->setCellValueExplicit("I" . ($index + 2), $row["phone"], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public static function csv(array $rows, string $delimiter = ";", ?string $path = null): string
    {
        $path ??= self::tempPath("csv");

        $handle = fopen($path, "w");
        fputcsv($handle, self::HEADERS, $delimiter, "\"", "");
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), $delimiter, "\"", "");
        }
        fclose($handle);

        return $path;
    }

    public static function tempPath(string $extension): string
    {
        return sys_get_temp_dir() . "/skk-test-" . uniqid() . "." . $extension;
    }
}
