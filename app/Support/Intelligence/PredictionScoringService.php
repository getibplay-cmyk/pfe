<?php

namespace App\Support\Intelligence;

interface PredictionScoringService
{
    public function score(PredictionInput $input): PredictionResult;
}
