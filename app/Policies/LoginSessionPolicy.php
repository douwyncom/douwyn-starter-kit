<?php

namespace App\Policies;

use App\Models\LoginSession;
use App\Models\User;

class LoginSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('login_sessions.view');
    }

    public function view(User $user, LoginSession $loginSession): bool
    {
        return $user->hasPermissionTo('login_sessions.view');
    }

    public function revoke(User $user, LoginSession $loginSession): bool
    {
        return $user->hasPermissionTo('login_sessions.revoke');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LoginSession $loginSession): bool
    {
        return false;
    }

    public function delete(User $user, LoginSession $loginSession): bool
    {
        return false;
    }

    public function restore(User $user, LoginSession $loginSession): bool
    {
        return false;
    }

    public function forceDelete(User $user, LoginSession $loginSession): bool
    {
        return false;
    }
}
