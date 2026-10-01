<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Hides records whose member has been (soft) deleted from every query — lists, widgets,
 * reports and totals — and brings them back automatically when the member is restored.
 */
trait BelongsToActiveMember
{
    public static function bootBelongsToActiveMember(): void
    {
        static::addGlobalScope('activeMember', fn (Builder $query) => $query->whereHas('member'));
    }
}
