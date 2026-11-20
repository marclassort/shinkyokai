<?php

namespace App\Tests\Service;

use App\Service\MemberFileReader;
use App\Tests\Support\MemberFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des fichiers d'adhérents envoyés par les clubs.
 */
class MemberFileReaderTest extends TestCase
{
    private MemberFileReader $reader;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->reader = new MemberFileReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function temp(string $path): string
    {
        return $this->tempFiles[] = $path;
    }

    public function testReadsAFullClubLotFromXlsxInFileOrder(): void
    {
        $path = $this->temp(MemberFile::xlsx(MemberFile::rows(107)));

        $members = $this->reader->read($path, "xlsx");

        self::assertCount(107, $members);
        self::assertSame("NOM001", $members[0]["last_name"]);
        self::assertSame("NOM107", $members[106]["last_name"]);
        self::assertSame("0600000001", $members[0]["phone"], "Le zéro initial du téléphone doit être conservé");
    }

    public function testXlsxWithThousandsOfEmptyDeclaredRowsIsNotCountedAsMembers(): void
    {
        // Le fichier réel du 6 novembre déclarait 1000 lignes pour 107 adhérents.
        $path = $this->temp(MemberFile::xlsx(MemberFile::rows(5)));
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $spreadsheet->getActiveSheet()->getStyle("A1:I1000")->getFont()->setBold(false);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        self::assertCount(5, $this->reader->read($path, "xlsx"));
    }

    /**
     * @dataProvider csvDelimiters
     */
    public function testReadsCsvWhateverTheSeparator(string $delimiter): void
    {
        $path = $this->temp(MemberFile::csv(MemberFile::rows(12), $delimiter));

        self::assertCount(12, $this->reader->read($path, "csv"));
    }

    public static function csvDelimiters(): iterable
    {
        yield "point-virgule (Excel français)" => [";"];
        yield "virgule" => [","];
        yield "tabulation" => ["\t"];
    }

    public function testReadsCsvWithBomWindows1252AndMacLineEndings(): void
    {
        $rows = MemberFile::rows(3);
        $rows[0]["first_name"] = "Hélène";
        $path = $this->temp(MemberFile::csv($rows));
        $csv = file_get_contents($path);
        $csv = str_replace("\n", "\r", $csv);                                  // fins de ligne Excel Mac
        $csv = mb_convert_encoding($csv, "Windows-1252", "UTF-8");             // export Excel Windows
        file_put_contents($path, "\xEF\xBB\xBF" . $csv);                      // BOM UTF-8 (rare mais possible)

        $members = $this->reader->read($path, "csv");

        self::assertCount(3, $members);
        self::assertSame("Hélène", $members[0]["first_name"]);
    }

    public function testColumnsAreFoundByHeaderNameWhateverTheirOrder(): void
    {
        $path = $this->temp(MemberFile::tempPath("csv"));
        file_put_contents($path, "Adresse électronique;Prénom;Sexe;Nom de famille;Date de naissance\n"
            . "a@example.test;Jean;Masculin;DUPONT;12/03/2011\n");

        $members = $this->reader->read($path, "csv");

        self::assertSame("DUPONT", $members[0]["last_name"]);
        self::assertSame("Jean", $members[0]["first_name"]);
        self::assertSame("a@example.test", $members[0]["email"]);
    }

    public function testMissingRequiredColumnIsReportedByName(): void
    {
        $path = $this->temp(MemberFile::tempPath("csv"));
        file_put_contents($path, "Prénom;Nom de famille;Sexe\nJean;DUPONT;Masculin\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Date de naissance");
        $this->reader->read($path, "csv");
    }

    public function testIncompleteOrInvalidRowsAreRefusedWithTheirLineNumber(): void
    {
        $rows = MemberFile::rows(4);
        $rows[1]["email"] = "pas-un-email";
        $rows[2]["last_name"] = "";
        $path = $this->temp(MemberFile::csv($rows));

        try {
            $this->reader->read($path, "csv");
            self::fail("Le fichier aurait dû être refusé.");
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("ligne 3", $e->getMessage());
            self::assertStringContainsString("ligne 4", $e->getMessage());
            self::assertStringContainsString("pas-un-email", $e->getMessage());
        }
    }

    public function testEmptyFileAndUnsupportedTypeAreRefused(): void
    {
        $empty = $this->temp(MemberFile::tempPath("csv"));
        file_put_contents($empty, "");

        try {
            $this->reader->read($empty, "csv");
            self::fail();
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("vide", $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->reader->read($empty, "pdf");
    }

    public function testCorruptedExcelFileGivesAFriendlyMessage(): void
    {
        $path = $this->temp(MemberFile::tempPath("xlsx"));
        file_put_contents($path, "ceci n'est pas un fichier Excel");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("illisible");
        $this->reader->read($path, "xlsx");
    }

    public function testFirstNamesAreReadWithAnInitialCapitalAndNeverAllInCapitals(): void
    {
        $rows = MemberFile::rows(3);
        $rows[0]["first_name"] = "karim";
        $rows[1]["first_name"] = "anne-sophie";
        $rows[2]["first_name"] = "SACHA";
        $path = $this->temp(MemberFile::xlsx($rows));

        $members = $this->reader->read($path, "xlsx");

        self::assertSame(["Karim", "Anne-Sophie", "Sacha"], array_column($members, "first_name"));
        self::assertSame("NOM001", $members[0]["last_name"], "Le nom n'est pas modifié");
    }
}
