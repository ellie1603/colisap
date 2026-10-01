<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->label('Archive')
                ->modalDescription('The member, with their beneficiaries, claims and contributions, will be hidden from lists, dashboards and reports but kept for history. You can restore them from the "Archived members" filter.'),
            Actions\RestoreAction::make(),
        ];
    }

    /**
     * Manual terminal statuses get their dates recorded.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $status = $data['status'] ?? $this->record->status;

        if ($status === 'terminated' && ! $this->record->terminated_at) {
            $data['terminated_at'] = Carbon::today();
        }

        if ($status === 'withdrawn' && ! $this->record->withdrawn_at) {
            $data['withdrawn_at'] = Carbon::today();
        }

        if (blank($data['account_name'] ?? null)) {
            $data['account_name'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (blank($this->record->account_name)) {
            $this->record->updateQuietly(['account_name' => $this->record->composeAccountName()]);
        }

        $this->record->refreshStatus();
        $this->refreshFormData(['status', 'activated_at', 'account_name']);
    }

    /**
     * Savings or beneficiaries changed in a relation manager can change status; show it right away.
     */
    #[On('member-ledger-updated')]
    public function refreshStatusField(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['status', 'activated_at']);
    }

    protected function getRedirectUrl(): string
    {
        return MemberResource::getUrl('view', ['record' => $this->record]);
    }
}
