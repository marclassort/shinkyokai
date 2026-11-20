<?php

namespace App\Tests\Support;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;

/**
 * Fabriques d'entités pour les tests (nécessite $this->entityManager).
 */
trait DatabaseFixtures
{
    protected function makeClub(string $number = "SHIN0011", string $name = "Club de test", string $season = "2026-2027"): Club
    {
        $club = new Club();
        $club->setName($name);
        $club->setLogo("logo-test.png");
        $club->setEmail("president@example.test");
        $club->setPresidentName("Président Test");
        $club->setTreasurerName("Trésorier Test");
        $club->setAddress("1 rue du Club");
        $club->setPostalCode("64000");
        $club->setCity("Pau");
        $club->setCountry("France");
        $club->setClubNumber($number);
        $club->setSportSeason($season);
        $this->entityManager->persist($club);

        return $club;
    }

    protected function makeOrder(string $type = "complement-affiliation", string $status = "en-cours", float $amount = 0.0, ?\DateTimeImmutable $createdAt = null): Order
    {
        $order = new Order();
        $order->setOrderType($type);
        $order->setStatus($status);
        $order->setTotalAmount($amount);
        $order->setCreatedAt($createdAt ?? new \DateTimeImmutable("2026-11-06 21:57:00"));
        $this->entityManager->persist($order);

        return $order;
    }

    protected function makeMember(string $first, string $last, string $licence, ?Club $club = null, ?Order $order = null, string $season = "2026-2027", string $birth = "01/01/2010"): Member
    {
        $member = new Member();
        $member->setFirstName($first);
        $member->setLastName($last);
        $member->setSex("Masculin");
        $member->setBirthDate($birth);
        $member->setEmail(strtolower($first) . "@example.test");
        $member->setLicenceNumber($licence);
        $member->setSportSeason($season);
        $member->setClub($club);
        $member->setCommande($order);
        $this->entityManager->persist($member);

        return $member;
    }

    /**
     * @return list<string> numéros de licence en base, triés
     */
    protected function allLicenceNumbers(): array
    {
        $numbers = array_map(
            static fn(array $row) => $row["licenceNumber"],
            $this->entityManager->createQuery("SELECT m.licenceNumber FROM App\Entity\Member m")->getArrayResult()
        );
        sort($numbers);

        return $numbers;
    }
}
