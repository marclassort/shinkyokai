<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Member;
use App\Service\MemberRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MemberRegistrarTest extends TestCase
{
    public function testCardsShowACapitalisedFirstNameEvenForOldMembersAlreadyInTheDatabase(): void
    {
        // Ancien adhérent enregistré avant la règle : son prénom est en minuscules en base (contournement du setter).
        $member = new Member();
        $member->setFirstName("x")->setLastName("TESTEUR");
        $property = new \ReflectionProperty(Member::class, "firstName");
        $property->setValue($member, "KARIM");
        $club = (new Club())->setName("Club")->setClubNumber("SHIN0011")->setLogo("l.png");
        $registrar = new MemberRegistrar($this->createMock(EntityManagerInterface::class));

        self::assertSame("Karim", $registrar->cardFor($member, $club, "2026-2027")["data"]["membre_prenom"]);
        self::assertSame("Karim", $registrar->cardForMember($member)["data"]["first_name"]);
    }

    public function testIdentityKeyIgnoresCaseAndExtraSpaces(): void
    {
        self::assertSame(
            MemberRegistrar::identityKey("Karim", "TESTEUR", "01/01/2012"),
            MemberRegistrar::identityKey("  karim ", "testeur", "01/01/2012")
        );
    }
}
