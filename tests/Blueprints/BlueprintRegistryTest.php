<?php

use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\DemoBlueprints;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Symfony\Component\Yaml\Yaml;

function scratchDir(): string
{
    $dir = sys_get_temp_dir().'/beam-synthetic-'.bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

function blueprintYaml(string $id, string $scenario = 'steady-ramp'): string
{
    return <<<YAML
    id: {$id}
    name: Fixture {$id}
    ownerName: Pat Doe
    ownerEmail: pat@{$id}.example
    scaffoldPackSlug: null
    planSlug: starter
    scenario: {$scenario}
    windowMonths: 6
    targetMonthlyUsageCostUsd: 10.0
    startingSeats: 1
    endingSeats: 2
    YAML;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/beam-synthetic-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('binds both registries as singletons', function () {
    expect(app(BlueprintRegistry::class))->toBe(app(BlueprintRegistry::class))
        ->and(app(PortfolioRegistry::class))->toBe(app(PortfolioRegistry::class));
});

it('declares both roots, rejecting duplicate ids', function () {
    expect(IsRegistry::of(BlueprintRegistry::class)?->root)->toBe('beam.synthetic.blueprints')
        ->and(IsRegistry::of(PortfolioRegistry::class)?->root)->toBe('beam.synthetic.portfolios')
        ->and(IsRegistry::of(BlueprintRegistry::class)?->onKeyDuplicate)->toBe(OnKeyDuplicate::Reject)
        ->and(IsRegistry::of(PortfolioRegistry::class)?->onKeyDuplicate)->toBe(OnKeyDuplicate::Reject);
});

it('ships the three Demo blueprints, one per scenario, and the Demo portfolio by default', function () {
    $blueprints = app(BlueprintRegistry::class)->all();

    expect(array_keys($blueprints))->toBe(['demo-orrowby-hart', 'demo-quillmere-studio', 'demo-tessaline-works'])
        ->and($blueprints)->each->toBeInstanceOf(SyntheticTenantBlueprintData::class)
        ->and(array_map(fn ($b) => $b->scenario, array_values($blueprints)))->toEqualCanonicalizing(SyntheticScenario::cases())
        ->and(app(BlueprintRegistry::class)->registrantOf('demo-quillmere-studio'))->toBe('config:beam.synthetic.blueprint_paths');

    $portfolios = app(PortfolioRegistry::class)->all();

    expect(array_keys($portfolios))->toBe(['demo-mixed-book'])
        ->and(app(BlueprintRegistry::class)->missingFrom($portfolios['demo-mixed-book']))->toBe([]);
});

it('keeps the Demo set generic: fictional reserved-domain owners, no pack, no vertical', function () {
    foreach (app(BlueprintRegistry::class)->all() as $id => $blueprint) {
        expect($id)->toStartWith('demo-')
            ->and($blueprint->ownerEmail)->toEndWith('.example')
            ->and($blueprint->scaffoldPackSlug)->toBeNull();
    }
});

it('reads a host directory in place of the Demo set, yaml and json, skipping other files', function () {
    $dir = scratchDir();
    file_put_contents($dir.'/b.yaml', blueprintYaml('host-b', 'seasonal'));
    file_put_contents($dir.'/a.json', json_encode(SyntheticTenantBlueprintData::from(Yaml::parse(blueprintYaml('host-a')))->toArray()));
    file_put_contents($dir.'/notes.txt', 'not a blueprint');

    config()->set('beam.synthetic.blueprint_paths', [$dir]);

    expect(array_keys(app(BlueprintRegistry::class)->all()))->toBe(['host-a', 'host-b']);
});

it('reads several directories in order, so a host can keep the Demo set beside its own', function () {
    $dir = scratchDir();
    file_put_contents($dir.'/x.yaml', blueprintYaml('host-x'));

    config()->set('beam.synthetic.blueprint_paths', [DemoBlueprints::blueprints(), $dir]);

    expect(array_keys(app(BlueprintRegistry::class)->all()))->toHaveCount(4)->toContain('host-x', 'demo-quillmere-studio');
});

it('refuses two files with one id, naming both', function () {
    $dir = scratchDir();
    file_put_contents($dir.'/one.yaml', blueprintYaml('same'));
    file_put_contents($dir.'/two.yaml', blueprintYaml('same'));

    config()->set('beam.synthetic.blueprint_paths', [$dir]);

    expect(fn () => app(BlueprintRegistry::class))->toThrow(InvalidArgumentException::class, "declare the id 'same': {$dir}/one.yaml and {$dir}/two.yaml");
});

it('refuses a declared path that is not a directory, rather than registering nothing', function () {
    config()->set('beam.synthetic.blueprint_paths', ['/nonexistent/beam-synthetic-blueprints']);

    expect(fn () => app(BlueprintRegistry::class))->toThrow(InvalidArgumentException::class, 'is not a directory');
});

it('names the file when a blueprint in it cannot replay', function () {
    $dir = scratchDir();
    file_put_contents($dir.'/bad.yaml', blueprintYaml('bad', 'hockey-stick'));

    config()->set('beam.synthetic.blueprint_paths', [$dir]);

    expect(fn () => app(BlueprintRegistry::class))->toThrow(InvalidArgumentException::class, "{$dir}/bad.yaml: Blueprint has an unknown scenario 'hockey-stick'");
});

it('rejects a code registration under an id a file already holds', function () {
    $registry = app(BlueprintRegistry::class);
    $taken = $registry->get('demo-quillmere-studio');

    expect(fn () => $registry->register('demo-quillmere-studio', $taken))->toThrow(DuplicateRegistryKey::class);
});

it('reports the members a portfolio names that the host lacks', function () {
    $portfolio = ScenarioPortfolioData::from([
        'id' => 'p', 'label' => 'P',
        'members' => [['blueprint' => 'demo-orrowby-hart'], ['blueprint' => 'nowhere'], ['blueprint' => 'nowhere']],
    ]);

    expect(app(BlueprintRegistry::class)->missingFrom($portfolio))->toBe(['nowhere'])
        ->and(app(BlueprintRegistry::class)->get('nowhere'))->toBeNull();
});
