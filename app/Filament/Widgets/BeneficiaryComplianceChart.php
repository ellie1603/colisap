<?php

namespace App\Filament\Widgets;

class BeneficiaryComplianceChart extends ColisapChart
{
    protected static ?string $heading = 'Beneficiary compliance';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $values = $this->monitoring()->beneficiaryCompliance();

        return $this->single('Participants', $values, array_slice(['#E11D48', '#10B981', '#0EA5E9', '#3B3FA6', '#F59E0B'], 0, count($values)));
    }
}
