<?php


namespace App\Controller\Admin;

use App\Entity\Club;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class ClubCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Club::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new("id")->hideOnForm(),
            TextField::new("name", "Nom du club"),
            ImageField::new("logo", "Logo")
                ->setBasePath("/uploads/")
                ->setUploadDir("public/uploads/")
                //->setUploadedFileNamePattern("[randomhash].[extension]")
                ->setRequired(false),
            TextField::new("address", "Adresse")
                ->hideOnIndex(),
            TextField::new("postalCode", "Code postal")
                ->hideOnIndex(),
            TextField::new("city", "Ville")
                ->hideOnIndex(),
            TextField::new("presidentName", "Nom du président"),
            TextField::new("treasurerName", "Nom du trésorier")
                ->hideOnIndex(),
            TextField::new("email", "Email du club"),
            TextField::new("country", "Pays")
                ->hideOnIndex(),
            TextField::new("clubNumber", "Numéro de club"),
            ChoiceField::new("sportSeason", "Saison sportive")
                ->setChoices($this->sportSeasonChoices())
                ->allowMultipleChoices(false)
                ->setFormTypeOption("placeholder", "— Saison —")
                ->renderAsNativeWidget(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular("Club")
            ->setEntityLabelInPlural("Clubs")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des clubs");
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
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
