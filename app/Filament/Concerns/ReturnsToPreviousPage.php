<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\Locked;

/**
 * After a form is saved, send the user back to the page they opened it from (the list, a member's
 * profile, Monitoring…) where the "Saved" message confirms it — instead of leaving them on the form.
 */
trait ReturnsToPreviousPage
{
    #[Locked]
    public ?string $previousUrl = null;

    public function mountReturnsToPreviousPage(): void
    {
        $previous = url()->previous();
        $path = (string) parse_url($previous, PHP_URL_PATH);
        $panelPath = '/'.trim((string) filament()->getCurrentPanel()?->getPath(), '/');

        $isAnotherPanelPage = parse_url($previous, PHP_URL_HOST) === request()->getHost()
            && ($path === $panelPath || str_starts_with($path, $panelPath.'/'))
            && $path !== '/'.trim(request()->path(), '/')
            && ! str_ends_with($path, '/login');

        $this->previousUrl = $isAnotherPanelPage ? $previous : null;
    }

    /**
     * The page the form was opened from, or the given page when it was opened directly.
     */
    protected function previousPageOr(string $fallback): string
    {
        return $this->previousUrl ?? $fallback;
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousPageOr(static::getResource()::getUrl('index'));
    }
}
