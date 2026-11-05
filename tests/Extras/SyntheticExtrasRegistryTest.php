<?php

use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Extras\MaterializesSyntheticExtras;
use Splicewire\Beam\Synthetic\Extras\SyntheticExtrasRegistry;
use Splicewire\Beam\Synthetic\Extras\SyntheticPlane;
use Splicewire\Beam\Synthetic\Extras\SyntheticTenantContext;
use Splicewire\Beam\Tenancy\Tenant;

class RegistryProbeExtraA implements MaterializesSyntheticExtras
{
    public function plane(): SyntheticPlane
    {
        return SyntheticPlane::Central;
    }

    public function appliesTo(SyntheticTenantBlueprintData $blueprint): bool
    {
        return true;
    }

    public function beforeProvisioning(Tenant $tenant, SyntheticTenantBlueprintData $blueprint): void {}

    public function materialize(SyntheticTenantContext $context): void {}
}

class RegistryProbeExtraB extends RegistryProbeExtraA {}

it('is empty by default: this package ships no extras', function () {
    expect(app(SyntheticExtrasRegistry::class)->classes())->toBe([]);
});

it('is one instance, so a provider registration reaches the materializer', function () {
    app(SyntheticExtrasRegistry::class)->register('a', RegistryProbeExtraA::class);

    expect(app(SyntheticExtrasRegistry::class)->has('a'))->toBeTrue();
});

it('fills from config first, then code, in that order, and resolves instances from the container', function () {
    config(['beam.synthetic.materializers' => ['from-config' => RegistryProbeExtraB::class]]);
    app()->forgetInstance(SyntheticExtrasRegistry::class);

    $registry = app(SyntheticExtrasRegistry::class);
    $registry->register('from-code', RegistryProbeExtraA::class);

    expect(array_keys($registry->classes()))->toBe(['from-config', 'from-code'])
        ->and($registry->registrantOf('from-config'))->toBe('config:beam.synthetic.materializers')
        ->and($registry->extras()['from-config'])->toBeInstanceOf(RegistryProbeExtraB::class);
});

it('refuses an entry that is not an extra class, naming it', function () {
    expect(fn () => app(SyntheticExtrasRegistry::class)->register('bad', stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'The synthetic extra `bad` must be the class-string of a '.MaterializesSyntheticExtras::class."; got 'stdClass'.");

    expect(fn () => app(SyntheticExtrasRegistry::class)->register('worse', new RegistryProbeExtraA))
        ->toThrow(InvalidArgumentException::class, 'got '.RegistryProbeExtraA::class);
});

it('rejects a second extra under a taken name', function () {
    $registry = app(SyntheticExtrasRegistry::class);
    $registry->register('dup', RegistryProbeExtraA::class);

    expect(fn () => $registry->register('dup', RegistryProbeExtraB::class))->toThrow(DuplicateRegistryKey::class);
});
