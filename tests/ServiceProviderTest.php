<?php

use Rushing\Popcorn\Concerns\Chained;
use Rushing\Popcorn\Registries\Registrant;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;

it('merges its config under the nested beam.synthetic root', function () {
    expect(config('beam.synthetic'))->toBeArray()
        ->and(config()->has('beam-synthetic'))->toBeFalse();
});

it('runs a chained concern in each phase inside the package registrant scope', function () {
    $provider = new class($this->app) extends BeamSyntheticServiceProvider
    {
        /** @var array<string, string|null> */
        public array $seen = [];

        #[Chained('register')]
        protected function recordRegister(): void
        {
            $this->seen['register'] = Registrant::current();
        }

        #[Chained('boot')]
        protected function recordBoot(): void
        {
            $this->seen['boot'] = Registrant::current();
        }
    };

    $provider->register();
    $provider->boot();

    expect($provider->seen)->toBe([
        'register' => 'splicewire/laravel-beam-synthetic',
        'boot' => 'splicewire/laravel-beam-synthetic',
    ])->and(Registrant::current())->toBeNull();
});
