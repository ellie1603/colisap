<?php

namespace App\Policies;

use App\Models\Contribution;
use App\Models\User;

class ContributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function view(User $user, Contribution $contribution): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function update(User $user, Contribution $contribution): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function delete(User $user, Contribution $contribution): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Contribution $contribution): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Contribution $contribution): bool
    {
        return $user->hasRole('admin');
    }
}
