<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function view(User $user, Member $member): bool
    {
        return $user->hasAnyRole(['admin', 'staff', 'auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function update(User $user, Member $member): bool
    {
        return $user->hasAnyRole(['admin', 'staff']);
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Member $member): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Member $member): bool
    {
        return $user->hasRole('admin');
    }
}
