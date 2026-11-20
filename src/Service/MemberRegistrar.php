<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les adhérents d'un club à partir des lignes d'un fichier (voir MemberFileReader)
 * et prépare les données de leur carte (voir LicenseService).
 *
 * Ne fait PAS de flush : l'appelant décide du moment de l'enregistrement (une seule transaction).
 */
class MemberRegistrar
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, string> $row ligne normalisée par MemberFileReader
     */
    public function createClubMember(Order $order, Club $club, array $row, string $licenceNumber, string $season): Member
    {
        $member = new Member();
        $member->setFirstName($row["first_name"]);
        $member->setLastName($row["last_name"]);
        $member->setSex($row["sex"]);
        $member->setBirthDate($row["birth_date"]);
        $member->setAddress($row["address"] ?: null);
        $member->setPostalCode($row["postal_code"] ?: null);
        $member->setCity($row["city"] ?: null);
        $member->setEmail($row["email"] ?: null);
        $member->setPhoneNumber($row["phone"] ?: null);
        $member->setCountry("France");
        $member->setCommande($order);
        $member->setClub($club);
        $member->setSportSeason($season);
        $member->setLicenceNumber($licenceNumber);

        $this->entityManager->persist($member);

        return $member;
    }

    /**
     * Clé d'identité d'un adhérent (insensible à la casse et aux espaces) : sert à ne pas recréer
     * un adhérent déjà enregistré lors d'une relance.
     */
    public static function identityKey(?string $firstName, ?string $lastName, ?string $birthDate): string
    {
        $normalize = static fn(?string $value): string => mb_strtolower(trim(preg_replace("/\s+/u", " ", (string)$value) ?? ""));

        return $normalize($firstName) . "|" . $normalize($lastName) . "|" . $normalize($birthDate);
    }

    /**
     * Données de carte au format attendu par LicenseService::deliver().
     *
     * @param string|null $replaces ancien numéro de licence, si cette carte en remplace une autre
     * @return array{type: string, email: ?string, data: array<string, mixed>, replaces: ?string}
     */
    public function cardFor(Member $member, Club $club, string $season, ?string $replaces = null): array
    {
        return [
            "type" => "club-membre",
            "email" => $member->getEmail(),
            "replaces" => $replaces,
            "data" => [
                "membre_prenom" => NameFormatter::firstName($member->getFirstName()),
                "membre_nom" => $member->getLastName(),
                "membre_date" => $member->getBirthDate(),
                "membre_sexe" => $member->getSex(),
                "licence" => $member->getLicenceNumber(),
                "logo" => $club->getLogo(),
                "club_name" => $club->getName(),
                "numero" => $club->getClubNumber(),
                "season" => $season,
            ],
        ];
    }

    /**
     * Carte d'un adhérent déjà enregistré : carte de club s'il appartient à un club, carte individuelle sinon.
     *
     * @return array{type: string, email: ?string, data: array<string, mixed>, replaces: ?string}
     */
    public function cardForMember(Member $member): array
    {
        $club = $member->getClub();
        $season = $member->getSportSeason() ?? $club?->getSportSeason() ?? "";

        if ($club !== null) {
            return $this->cardFor($member, $club, $season, $member->getCardReplaces());
        }

        return [
            "type" => "membre-individuel",
            "email" => $member->getEmail(),
            "replaces" => null,
            "data" => [
                "first_name" => NameFormatter::firstName($member->getFirstName()),
                "last_name" => $member->getLastName(),
                "birth_date" => $member->getBirthDate(),
                "sex" => $member->getSex(),
                "email" => $member->getEmail(),
                "licence" => $member->getLicenceNumber(),
                "season" => $season,
            ],
        ];
    }

    /**
     * @return array{type: string, email: ?string, data: array<string, mixed>, replaces: ?string}
     */
    public function clubCardFor(Club $club): array
    {
        return [
            "type" => "club",
            "email" => $club->getEmail(),
            "replaces" => null,
            "data" => [
                "logo" => $club->getLogo(),
                "club_name" => $club->getName(),
                "email" => $club->getEmail(),
                "president_name" => $club->getPresidentName(),
                "treasurer_name" => $club->getTreasurerName(),
                "address" => $club->getAddress(),
                "address2" => "",
                "zip" => $club->getPostalCode(),
                "city" => $club->getCity(),
                "country" => $club->getCountry(),
                "numero" => $club->getClubNumber(),
                "season" => $club->getSportSeason(),
            ],
        ];
    }
}
