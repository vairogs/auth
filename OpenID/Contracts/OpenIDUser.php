<?php declare(strict_types = 1);

namespace Vairogs\Auth\OpenID\Contracts;

interface OpenIDUser
{
    public function getOpenID(): string;

    public function returnsEmail(): bool;

    public function getEmail(): ?string;
}
