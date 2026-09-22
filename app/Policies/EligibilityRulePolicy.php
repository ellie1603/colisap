<?php

namespace App\Policies;

use App\Models\EligibilityRule;
use App\Models\User;

class EligibilityRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function view(User $user, EligibilityRule $eligibilityRule): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, EligibilityRule $eligibilityRule): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, EligibilityRule $eligibilityRule): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, EligibilityRule $eligibilityRule): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, EligibilityRule $eligibilityRule): bool
    {
        return $user->hasRole('admin');
    }
}
