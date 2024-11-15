<?php

namespace App\Service;

use App\Repository\CulturalArtsRepository;

class GlobalVariablesService
{
    private CulturalArtsRepository $culturalArtsRepository;

    public function __construct(CulturalArtsRepository $culturalArtsRepository)
    {
        $this->culturalArtsRepository = $culturalArtsRepository;
    }

    public function getWorkshops(): array
    {
        return $this->culturalArtsRepository->findAll();
    }
}
