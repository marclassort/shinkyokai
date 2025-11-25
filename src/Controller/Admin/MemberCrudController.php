<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\Member;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class MemberCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Member::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular("Membre")
            ->setEntityLabelInPlural("Membres")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des membres");
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new("firstName", "Prénom"),
            TextField::new("lastName", "Nom de famille"),
            TextField::new("sex", "Sexe")
                ->hideOnIndex(),
            TextField::new("birthDate", "Date de naissance")
                ->hideOnIndex(),
            TextField::new("address", "Adresse")->hideOnIndex(),
            TextField::new("postalCode", "Code postal")->hideOnIndex(),
            TextField::new("city", "Ville")
                ->hideOnIndex(),
            AssociationField::new("club", "Club")
                ->setFormTypeOption("choice_label", "name")
                ->formatValue(fn($v, $e) => $e->getClub() ? $e->getClub()->getName() : ""),
            TextField::new("sportSeason", "Saison sportive (club)")
                ->onlyOnIndex(),
            AssociationField::new("commande", "Commande")
                ->hideOnIndex()
                ->setFormTypeOption("choice_label", "id")
                ->formatValue(fn($v, $e) => $e->getCommande() ? ("Commande N° ".$e->getCommande()->getId()) : ""),
            TextField::new("email", "Courriel"),
            TextField::new("licenceNumber", "Licence"),
            TextField::new("sportSeason", "Saison sportive"),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(
                EntityFilter::new("club", "Club")
                    ->setFormTypeOption(
                        "value_type_options.choice_label",
                        function (?Club $club) {
                            if (!$club) {
                                return "";
                            }

                            $season = $club->getSportSeason();
                            if ($season) {
                                return sprintf("%s (%s)", $club->getName(), $season);
                            }

                            return $club->getName();
                        }
                    )
            )
            ->add(
                ChoiceFilter::new("sportSeason", "Saison sportive")
                    ->setChoices($this->sportSeasonChoices())
                    ->setFormTypeOption("data", ["comparison" => "in", "value" => null])
                    ->setFormTypeOption("value_type_options.multiple", false)
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    private function sportSeasonChoices(): array
    {
        $endStartYear = null;
        $y = (int) date("Y");
        $endStartYear ??= $y + 2;
        $out = [];
        for ($start = 2024; $start <= $endStartYear; $start++) {
            $label = sprintf("%d-%d", $start, $start + 1);
            $out[$label] = $label;
        }
        return $out;
    }
}
