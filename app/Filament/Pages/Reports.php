<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Reports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.reports';
}
