<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * A guard with nobody signed in, ever. The stream swaps it in while it
 * renders a shared region, whose fragment is cached and sent to every
 * device with the same state, so no user may reach it.
 */
class GuestGuard implements Guard
{
    public const NAME = 'asgard_guest';

    public function check(): bool
    {
        return false;
    }

    public function guest(): bool
    {
        return true;
    }

    public function user(): ?Authenticatable
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }
}
