<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Attribue les numéros de licence (SKK0001, SKK0002, ...).
 *
 * Le prochain numéro est « le plus grand numéro existant + 1 », calculé sur TOUS les membres
 * (et non sur « le dernier membre créé » : celui-ci pouvait ne pas avoir de licence, ce qui
 * faisait repartir la numérotation de zéro et créait des doublons).
 *
 * `app.licence_first_available` (variable d'environnement LICENCE_FIRST_AVAILABLE) fixe un plancher :
 * aucun numéro inférieur ne sera attribué, même si la base contient moins. Utile pour « réserver »
 * des numéros déjà imprimés sur des cartes envoyées mais pas encore enregistrés en base.
 */
class LicenceNumberGenerator
{
    public const PREFIX = "SKK";

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire("%app.licence_first_available%")] private readonly int $firstAvailable = 1,
    ) {
    }

    public static function format(int $number): string
    {
        return sprintf("%s%04d", self::PREFIX, $number);
    }

    public static function parse(?string $licence): ?int
    {
        if ($licence !== null && preg_match("/^" . self::PREFIX . "(\d+)$/", trim($licence), $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Numéros de licence en base, indexés par identifiant de membre (les licences non conformes sont ignorées).
     *
     * @return array<int, int>
     */
    public function numbersInUse(): array
    {
        $query = $this->entityManager->createQuery(
            "SELECT m.id AS id, m.licenceNumber AS licence FROM App\Entity\Member m WHERE m.licenceNumber LIKE 'SKK%'"
        );

        $numbers = [];
        foreach ($query->toIterable() as $row) {
            $number = self::parse($row["licence"]);
            if ($number !== null) {
                $numbers[(int)$row["id"]] = $number;
            }
        }

        return $numbers;
    }

    public function highest(): int
    {
        return $this->numbersInUse() ? max($this->numbersInUse()) : 0;
    }

    /**
     * Premier numéro à attribuer (entier). Les suivants s'obtiennent en incrémentant.
     */
    public function nextNumber(): int
    {
        return max($this->highest() + 1, $this->firstAvailable);
    }

    /**
     * @return list<string> $count numéros consécutifs, ex. ["SKK0304", "SKK0305"]
     */
    public function reserve(int $count): array
    {
        $first = $this->nextNumber();

        return array_map(self::format(...), range($first, $first + max($count, 1) - 1));
    }
}
