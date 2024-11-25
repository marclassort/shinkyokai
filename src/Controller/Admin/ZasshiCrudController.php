<?php

namespace App\Controller\Admin;

use App\Entity\Zasshi;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;

class ZasshiCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Zasshi::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Zasshi')
            ->setEntityLabelInPlural('Zashhis')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gestion des zashhis');
    }

    public function configureFields(string $pageName): iterable
    {
        // Champ pour télécharger uniquement des fichiers PDF
        $pdfField = TextField::new('pdf', 'Fichier PDF')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => $pageName === Crud::PAGE_NEW,
                'constraints' => [
                    new File([
                        'mimeTypes' => ['application/pdf'],
                        'mimeTypesMessage' => 'Veuillez télécharger un fichier PDF valide.',
                    ]),
                ],
            ])
            ->setHelp('Téléchargez un fichier PDF.')
            ->onlyOnForms();

        // Champ pour sélectionner mois et année
        $monthYearField = ChoiceField::new('date', 'Mois et Année')
            ->setChoices($this->generateMonthYearChoices())
            ->renderAsNativeWidget();

        return [
            $pdfField,
            $monthYearField,
        ];
    }

    private function generateMonthYearChoices(): array
    {
        $currentYear = (int) date('Y');
        $years = range($currentYear, $currentYear + 5);
        $months = [
            'Janvier' => 1, 'Février' => 2, 'Mars' => 3, 'Avril' => 4,
            'Mai' => 5, 'Juin' => 6, 'Juillet' => 7, 'Août' => 8,
            'Septembre' => 9, 'Octobre' => 10, 'Novembre' => 11, 'Décembre' => 12,
        ];

        $choices = [];
        foreach ($years as $year) {
            foreach ($months as $monthName => $monthNumber) {
                $choices["$monthName $year"] = sprintf('%d-%02d', $year, $monthNumber);
            }
        }

        return $choices;
    }
}
