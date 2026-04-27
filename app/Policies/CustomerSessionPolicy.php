<?php

namespace App\Policies;

use App\Models\CustomerSession;
use App\Models\User;

class CustomerSessionPolicy
{
    public function view(User $user, CustomerSession $session): bool
    {
        return $user->isAdmin() || $user->isManager() || $session->waiter_id === $user->id;
    }

    public function update(User $user, CustomerSession $session): bool
    {
        return $user->isAdmin() || $user->isManager() || $session->waiter_id === $user->id;
    }

    public function addOrder(User $user, CustomerSession $session): bool
    {
        if ($user->isAdmin() || $user->isManager()) {
            return $session->status !== 'paid';
        }

        return $session->waiter_id === $user->id && ! in_array($session->status, ['paid'], true);
    }

    public function collectPayment(User $user, CustomerSession $session): bool
    {
        if ($user->isAdmin() || $user->isManager()) {
            return $session->status !== 'paid';
        }

        return $session->waiter_id === $user->id && $session->status !== 'paid';
    }
}
