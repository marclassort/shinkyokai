<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular("Commande")
            ->setEntityLabelInPlural("Commandes")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des commandes")
            ->setFormOptions(["validation_groups" => false])
            ->showEntityActionsInlined(false)
            ->setPageTitle(Crud::PAGE_DETAIL, "Détails de la commande")
            ->setPageTitle(Crud::PAGE_INDEX, "Gestion des commandes")
            ->setPageTitle(Crud::PAGE_EDIT, "Modification interdite")
            ->setDefaultSort(["createdAt" => "DESC"])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::EDIT, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new("id")->hideOnForm(),
            TextField::new("orderType", "Type de commande"),
            ChoiceField::new("status", "Statut")
                ->setChoices([
                    "En cours" => "en-cours",
                    "Payée" => "payee",
                    "Annulée" => "annulee"
                ]),
            MoneyField::new("totalAmount", "Montant total")
                ->setCurrency("EUR")
                ->setStoredAsCents(true)
                ->formatValue(function ($value) {
                    return $value . " €";
                }),
            DateField::new("createdAt", "Commande passée le")
                ->formatValue(function ($value) {
                    return $value->format("d/m/Y à H:i");
                }),
            CollectionField::new("product", "Produits")
                ->hideOnIndex()
        ];
    }
}
