<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The popcorn `Registry` and `Gated` contract for a registry whose storage is a composed
 * {@see BasicRegistry} field, built from the owner's own `#[IsRegistry]` declaration.
 */
trait DelegatesToStore
{
    private ?BasicRegistry $store = null;

    private function store(): BasicRegistry
    {
        return $this->store ??= BasicRegistry::for($this);
    }

    /** Attach a registrar: the seam the host's declared directories arrive through. */
    public function attach(Registrar $registrar): void
    {
        $this->store()->attach($registrar);
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->store()->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    /** The registrant that wrote `$key`, such as `config:beam.synthetic.blueprint_paths`. */
    public function registrantOf(RegistryKey|string $key): ?string
    {
        return $this->store()->registrantOf($key);
    }

    public function unfiltered(): Registry
    {
        return $this->store()->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store()->authorizeWith($authorizer);

        return $this;
    }

    /** @return list<string> the keys as their registrants spelled them, in registration order */
    private function ids(): array
    {
        return $this->store()->relativeKeys();
    }
}
