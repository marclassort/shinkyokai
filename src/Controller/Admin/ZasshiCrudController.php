<?php

namespace App\Controller\Admin;

use App\Entity\Zasshi;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Exception;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    /**
     * @throws Exception
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * @throws Exception
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * @throws Exception
     */
    private function handleFileUpload($entityInstance): void
    {
        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $this->getContext()->getRequest()->files->get('Zasshi')["pdf"];

        if ($uploadedFile) {
            $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads';
            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $slugifiedFilename = $this->generateSlug($originalFilename);
            $newFilename = sprintf(
                '%s-%s.%s',
                $slugifiedFilename,
                uniqid(),
                $uploadedFile->guessExtension()
            );
            $uploadedFile->move($uploadsDirectory, $newFilename);
            $entityInstance->setPdf('/uploads/' . $newFilename);
        } elseif (!$entityInstance->getPdf()) {
            throw new Exception('Le champ PDF est obligatoire.');
        }
    }

    private function generateSlug(string $string): string
    {
        if (function_exists('transliterator_transliterate')) {
            $string = transliterator_transliterate('Any-Latin; Latin-ASCII', $string);
        } else {
            $string = str_replace(
                ['à', 'á', 'â', 'ã', 'ä', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ'],
                ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y'],
                $string
            );
        }
        $slug = strtolower($string);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
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
                        'maxSize' => '20M',
                        'mimeTypes' => ['application/pdf'],
                        'mimeTypesMessage' => 'Veuillez télécharger un fichier PDF valide.',
                    ]),
                ],
            ])
            ->setHelp('Téléchargez un fichier PDF (max. 20 Mo).');

        // Champ pour sélectionner mois et année
        $monthYearField = ChoiceField::new('date', 'Mois et Année')
            ->setChoices($this->generateMonthYearChoices())
            ->renderAsNativeWidget();

        return [
            FormField::addPanel('Fichier')->setIcon('fa fa-file'),
            $pdfField,
            FormField::addPanel('Informations supplémentaires'),
            $monthYearField,
            TextField::new("name", "Nom ")
        ];
    }

    private function generateMonthYearChoices(): array
    {
        $startYear = 2023;
        $years = range($startYear, $startYear + 5);
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
