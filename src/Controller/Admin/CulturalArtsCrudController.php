<?php

namespace App\Controller\Admin;

use App\Entity\CulturalArts;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FileUploadType;

class CulturalArtsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CulturalArts::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular("Atelier arts culturels")
            ->setEntityLabelInPlural("Ateliers arts culturels")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des ateliers")
            ->setDefaultSort(["date" => "DESC"]);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new("name",
                "Nom de l'atelier (privilégier la date car le type d'atelier existe déjà)"
            ),
            SlugField::new("slug", "Slug")
                ->setTargetFieldName(["workshopType", "name"])
                ->hideOnIndex(),
            ChoiceField::new("workshopType", "Type d\"atelier")
                ->setChoices([
                    "Sumi-e" => "Sumi-e",
                    "Kintsugi" => "Kintsugi",
                    "Gyotaku" => "Gyotaku",
                    "Origami" => "Origami",
                    "Calligraphie" => "Calligraphie",
                ]),
            TextareaField::new("description", "Description")->hideOnIndex(),
            DateTimeField::new("date", "Date et Heure"),
            NumberField::new("price", "Prix")
                ->setNumDecimals(2)
                ->setStoredAsString(),
            NumberField::new("nonMemberPrice", "Prix non membre")
                ->setNumDecimals(2)
                ->setStoredAsString(),
            ImageField::new("image", "Image")
                ->setBasePath("/uploads/")
                ->setUploadDir("public/uploads/")
                ->setUploadedFileNamePattern("[slug]-[timestamp].[extension]")
                ->setFormType(FileUploadType::class)
                ->setFormTypeOption("required", false)
                ->setFormTypeOption("allow_delete", false),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
