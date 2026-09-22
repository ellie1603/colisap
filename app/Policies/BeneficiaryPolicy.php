<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;

class BeneficiaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function view(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function update(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function delete(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasRole('admin');
    }
}
