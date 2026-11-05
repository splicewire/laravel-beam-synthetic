<?php

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Synthetic\Console\CaptureCurvesCommand;
use Splicewire\Beam\Synthetic\Golden\GoldenCurveCases;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * The regeneration half of the curve guard. These tests regenerate and diff: they write only into a throwaway
 * directory, never the committed fixture, so no test here can rewrite what it asserts against.
 */
beforeEach(function () {
    $this->fixtureHash = hash_file('sha256', GoldenCurveCases::path());
    $this->scratch = sys_get_temp_dir().'/beam-synthetic-capture-'.getmypid().'-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    if (is_file($this->scratch.'/curves.json')) {
        unlink($this->scratch.'/curves.json');
    }
    if (is_dir($this->scratch)) {
        rmdir($this->scratch);
    }

    // Whatever a test did, the committed fixture is untouched.
    expect(hash_file('sha256', GoldenCurveCases::path()))->toBe($this->fixtureHash);
});

it('regenerates the committed fixture byte for byte', function () {
    $this->artisan('synthetic:capture-curves', ['--write' => true, '--path' => $this->scratch.'/curves.json'])->assertSuccessful();

    expect(file_get_contents($this->scratch.'/curves.json'))->toBe(file_get_contents(GoldenCurveCases::path()));
});

it('prints, and writes nothing, without --write', function () {
    Artisan::call('synthetic:capture-curves');

    expect(Artisan::output())->toContain(CaptureCurvesCommand::render(new ScenarioExpander))
        ->toContain('Printed only')
        ->and(is_dir($this->scratch))->toBeFalse();
});

it('says whether it created or overwrote the file', function () {
    $this->artisan('synthetic:capture-curves', ['--write' => true, '--path' => $this->scratch.'/curves.json'])
        ->expectsOutputToContain('Created ');
    $this->artisan('synthetic:capture-curves', ['--write' => true, '--path' => $this->scratch.'/curves.json'])
        ->expectsOutputToContain('Overwrote ');
});
