<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Locked;

/**
 * Two staff editing the same record: whoever saves second is told the record changed while they were
 * editing, and nothing is overwritten, instead of silently replacing the other person's changes.
 */
trait DetectsConcurrentEdits
{
    /**
     * The record's last-changed time when this form was opened (or last saved from here).
     */
    #[Locked]
    public ?string $loadedVersion = null;

    public function mountDetectsConcurrentEdits(): void
    {
        $this->rememberCurrentVersion();
    }

    /**
     * Call after this page changed the record itself (e.g. an approve or reopen button), so the next save is not mistaken for a conflict.
     */
    public function rememberCurrentVersion(): void
    {
        $this->loadedVersion = $this->recordVersion($this->record->fresh());
    }

    protected function beforeValidate(): void
    {
        if ($this->recordVersion($this->record->fresh()) === $this->loadedVersion) {
            return;
        }

        Notification::make()
            ->title('Changed by someone else')
            ->body('Someone saved this record while you were editing it, so your changes were not saved. Reload to see the latest version, then make your changes again.')
            ->warning()
            ->persistent()
            ->actions([
                NotificationAction::make('reload')
                    ->label('Reload')
                    ->button()
                    ->url(static::getResource()::getUrl('edit', ['record' => $this->record])),
            ])
            ->send();

        throw new Halt;
    }

    protected function afterSave(): void
    {
        $this->rememberCurrentVersion();
    }

    private function recordVersion(?object $record): ?string
    {
        return $record?->updated_at?->format('Y-m-d H:i:s.u');
    }
}
