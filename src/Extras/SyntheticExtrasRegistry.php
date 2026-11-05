<?php

namespace Splicewire\Beam\Synthetic\Extras;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Synthetic\Blueprints\DelegatesToStore;

/**
 * The host's own materialization steps, keyed by a name the host chooses: each entry is the class-string of a
 * {@see MaterializesSyntheticExtras}, resolved from the container when the materializer runs.
 *
 * This package registers none: the generic materializer writes only what every Beam host shares (the tenant, its
 * subscription, seats, usage and bills). A host fills it from `config('beam.synthetic.materializers')`
 * (`name => class`), or from its own provider with {@see register()}.
 *
 * Extras run in registration order, config first. Duplicate names are rejected: two steps under one name would leave
 * it ambiguous which one ran, and an extra writes real rows.
 */
#[IsRegistry(
    root: 'beam.synthetic.materializers',
    entryType: 'class-string<'.MaterializesSyntheticExtras::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'host steps run while materializing a synthetic tenant, keyed by name; each is a MaterializesSyntheticExtras class',
)]
class SyntheticExtrasRegistry implements Gated, Registry
{
    use DelegatesToStore {
        register as private storeRegister;
    }

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<MaterializesSyntheticExtras>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (! is_string($entry) || ! is_subclass_of($entry, MaterializesSyntheticExtras::class)) {
            throw new InvalidArgumentException(sprintf(
                'The synthetic extra `%s` must be the class-string of a %s; got %s.',
                (string) $key,
                MaterializesSyntheticExtras::class,
                is_string($entry) ? "'{$entry}'" : get_debug_type($entry),
            ));
        }

        return $this->storeRegister($key, $entry, $by, $ability);
    }

    /** @return array<string, class-string<MaterializesSyntheticExtras>> name => class, in registration order */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->ids() as $name) {
            $classes[$name] = $this->resolve($name);
        }

        return $classes;
    }

    /** @return array<string, MaterializesSyntheticExtras> name => extra, resolved from the container, in registration order */
    public function extras(): array
    {
        return array_map(fn (string $class): MaterializesSyntheticExtras => $this->container->make($class), $this->classes());
    }
}
