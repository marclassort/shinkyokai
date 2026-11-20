<?php

namespace App\Tests\Service;

use App\Entity\Member;
use App\Service\NameFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Règle métier : un prénom commence toujours par une majuscule.
 */
class NameFormatterTest extends TestCase
{
    /**
     * @dataProvider firstNames
     */
    public function testFirstNameAlwaysStartsWithACapital(string $input, string $expected): void
    {
        self::assertSame($expected, NameFormatter::firstName($input));
    }

    public static function firstNames(): iterable
    {
        yield "minuscule" => ["karim", "Karim"];
        yield "minuscule, autre exemple" => ["sarah", "Sarah"];
        yield "déjà correct" => ["Nina", "Nina"];
        yield "prénom composé avec trait d'union" => ["anne-sophie", "Anne-Sophie"];
        yield "prénom composé avec espace" => ["marie claire", "Marie Claire"];
        yield "apostrophe" => ["d'artagnan", "D'Artagnan"];
        yield "accent en tête" => ["éloïse", "Éloïse"];
        yield "espaces superflus" => ["  marie   claire ", "Marie Claire"];
        yield "tout en capitales" => ["LUCAS", "Lucas"];
        yield "tout en capitales, autre exemple" => ["SACHA", "Sacha"];
        yield "capitales avec accent" => ["ÉLOÏSE", "Éloïse"];
        yield "capitales composées" => ["ANNE-SOPHIE", "Anne-Sophie"];
        yield "capitales avec espace" => ["MARIE CLAIRE", "Marie Claire"];
        yield "capitales avec apostrophe" => ["D'ARTAGNAN", "D'Artagnan"];
        yield "initiale seule conservée" => ["Jean A", "Jean A"];
        yield "casse mixte conservée" => ["mcKenzie", "McKenzie"];
        yield "vide" => ["", ""];
    }

    public function testItIsIdempotent(): void
    {
        foreach (["LUCAS", "anne-sophie", "Éloïse", "d'artagnan"] as $name) {
            self::assertSame(NameFormatter::firstName($name), NameFormatter::firstName(NameFormatter::firstName($name)));
        }
    }

    public function testNullIsAnEmptyString(): void
    {
        self::assertSame("", NameFormatter::firstName(null));
    }

    public function testEveryEntryPointOfTheMemberEntityAppliesTheRule(): void
    {
        $member = (new Member())->setFirstName("sarah");

        self::assertSame("Sarah", $member->getFirstName(), "Enregistré avec la majuscule, quelle que soit l'origine (formulaire, fichier, EasyAdmin)");
    }
}
