<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Filament\Resources\Pages\CreateRecord;

class CreateApplication extends CreateRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $member = Member::find($data['member_id']);

        return [
            ...$data,
            'status' => 'pending',
            'previous_status' => $member?->status,
            'fee_amount' => $data['type'] === 'reapplication' ? app(PolicySettings::class)->decimal('reapplication_fee') : 0,
        ];
    }

    protected function getRedirectUrl(): string
    {
        return ApplicationResource::getUrl('edit', ['record' => $this->record]);
    }
}
