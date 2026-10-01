<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Exports\MemberExporter;
use App\Filament\Resources\MemberResource;
use App\Models\Member;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(MemberResource::getUrl('import'))
                ->visible(Auth::user()?->can('members.import') ?? false),
            Actions\Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(Auth::user()?->can('members.export') ?? false)
                ->modalHeading('Export members')
                ->modalDescription(fn (ListMembers $livewire): string => number_format($livewire->getTableQueryForExport()->count()).' member(s) match the current tab, search and filters.')
                ->modalSubmitActionLabel('Download')
                ->form([
                    Forms\Components\Radio::make('format')
                        ->options(MemberExporter::FORMATS)
                        ->default('xlsx')
                        ->inline()
                        ->required(),
                ])
                ->action(fn (array $data, ListMembers $livewire) => app(MemberExporter::class)
                    ->download($livewire->getTableQueryForExport(), $data['format'])),
            Actions\CreateAction::make()->label('Add member'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Member::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'all' => Tab::make('All')->badge($counts->sum()),
            ...collect(Member::STATUSES)->mapWithKeys(fn (string $label, string $status) => [
                $status => Tab::make($label)
                    ->badge($counts[$status] ?? 0)
                    ->badgeColor(Member::statusColor($status))
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status)),
            ])->all(),
        ];
    }
}
