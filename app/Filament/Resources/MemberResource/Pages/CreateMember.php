<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\Application;
use App\Models\SavingsTransaction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    private float $openingBalance = 0.0;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->openingBalance = (float) ($this->data['savings_balance'] ?? 0);

        return [...$data, 'status' => 'waiting'];
    }

    protected function afterCreate(): void
    {
        $member = $this->record;

        Application::create([
            'member_id' => $member->id,
            'type' => 'new',
            'category' => $member->category,
            'application_date' => $member->application_date ?? $member->approval_date ?? Carbon::today(),
            'status' => $member->approval_date ? 'approved' : 'pending',
            'approved_at' => $member->approval_date,
            'approved_by' => $member->approval_date ? auth()->id() : null,
        ]);

        if ($this->openingBalance > 0) {
            SavingsTransaction::create([
                'member_id' => $member->id,
                'type' => 'deposit',
                'amount' => $this->openingBalance,
                'transaction_date' => $member->approval_date ?? Carbon::today(),
                'remarks' => 'Opening balance',
            ]);
        }

        $member->refresh()->refreshStatus();
    }

    protected function getRedirectUrl(): string
    {
        return MemberResource::getUrl('view', ['record' => $this->record]);
    }
}
