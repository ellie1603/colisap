<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\User;

class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function view(User $user, Claim $claim): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function update(User $user, Claim $claim): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function delete(User $user, Claim $claim): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Claim $claim): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Claim $claim): bool
    {
        return $user->hasRole('admin');
    }
}
