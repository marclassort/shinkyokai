<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FileUploadType;

class EventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular("Événement")
            ->setEntityLabelInPlural("Événements")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des événements")
            ->setDefaultSort(["eventDate" => "DESC"]);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new("title", "Titre"),
            SlugField::new("slug", "Slug")
                ->setTargetFieldName("title")
                ->hideOnIndex(),
            TextField::new("category", "Catégorie"),
            BooleanField::new("forPublic", "Pour le public"),
            ImageField::new("image", "Image")
                ->setBasePath("/uploads/")
                ->setUploadDir("public/uploads/")
                ->setUploadedFileNamePattern("[slug]-[timestamp].[extension]")
                ->setFormType(FileUploadType::class)
                ->setFormTypeOption("required", false)
                ->setFormTypeOption("allow_delete", false),
            TextEditorField::new("content", "Contenu"),
            DateTimeField::new("eventDate", "Date de l'événement")
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
