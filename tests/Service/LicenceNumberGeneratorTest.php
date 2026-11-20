<?php

namespace App\Tests\Service;

use App\Service\LicenceNumberGenerator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Attribution des numéros de licence : jamais de doublon, jamais de retour en arrière.
 */
class LicenceNumberGeneratorTest extends DatabaseTestCase
{
    private function generator(int $firstAvailable = 1): LicenceNumberGenerator
    {
        return new LicenceNumberGenerator($this->entityManager, $firstAvailable);
    }

    public function testStartsAtOneWhenThereIsNoMember(): void
    {
        self::assertSame(1, $this->generator()->nextNumber());
        self::assertSame(["SKK0001", "SKK0002"], $this->generator()->reserve(2));
    }

    public function testContinuesAfterTheHighestNumberWhateverTheCreationOrder(): void
    {
        // Le membre créé en dernier n'a pas le plus grand numéro : l'ancien code se basait sur lui.
        $this->makeMember("A", "A", "SKK0194");
        $this->makeMember("B", "B", "SKK0141");
        $this->entityManager->flush();

        self::assertSame(195, $this->generator()->nextNumber());
    }

    public function testNumbersAreNeverReusedAfterAGapOrANonStandardLicence(): void
    {
        $this->makeMember("A", "A", "SKK0010");
        $this->makeMember("B", "B", "HISTORIQUE-1");   // licence d'un autre format : ignorée
        $this->makeMember("C", "C", "SKK0012");
        $this->entityManager->flush();

        self::assertSame(["SKK0013", "SKK0014", "SKK0015"], $this->generator()->reserve(3));
    }

    public function testNumbersBeyondFourDigitsAreSupported(): void
    {
        $this->makeMember("A", "A", "SKK9999");
        $this->entityManager->flush();

        self::assertSame(["SKK10000"], $this->generator()->reserve(1));
    }

    public function testFirstAvailableFloorProtectsNumbersAlreadyPrintedOnCards(): void
    {
        // Cartes déjà envoyées jusqu'à SKK0250 mais pas encore en base (cas du 6 novembre).
        $this->makeMember("A", "A", "SKK0196");
        $this->entityManager->flush();

        self::assertSame(251, $this->generator(251)->nextNumber(), "Le plancher l'emporte sur la base");
        self::assertSame(300, $this->generator(251)->nextNumber() + 49, "contrôle d'arithmétique");

        $this->makeMember("B", "B", "SKK0400");
        $this->entityManager->flush();
        self::assertSame(401, $this->generator(251)->nextNumber(), "La base l'emporte sur un plancher dépassé");
    }

    public function testDatabaseRefusesTwoMembersWithTheSameLicenceNumber(): void
    {
        $this->makeMember("A", "A", "SKK0195");
        $this->entityManager->flush();

        $this->makeMember("B", "B", "SKK0195");

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testFormatAndParse(): void
    {
        self::assertSame("SKK0007", LicenceNumberGenerator::format(7));
        self::assertSame(7, LicenceNumberGenerator::parse("SKK0007"));
        self::assertNull(LicenceNumberGenerator::parse("XYZ0007"));
        self::assertNull(LicenceNumberGenerator::parse(null));
    }
}
