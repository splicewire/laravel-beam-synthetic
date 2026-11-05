<?php

namespace Splicewire\Beam\Synthetic\Tests\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\Commerce\CommerceServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\TenancyServiceProvider;

/**
 * A small Beam host for the materializer's tests: one `central` sqlite connection, the central tables the
 * materializer writes, migrated from the real beam-tenancy and beam-commerce stubs (re-stamped in their declared
 * order, as `vendor:publish` would), and the two plans the Demo blueprints name.
 *
 * `database.default` IS `central`, so the models that pin `central` and the test share one PDO (two names over one
 * `:memory:` target would be two databases). Storage provisioning is off: this host has no tenant storage, and the
 * materializer must not create database files in the test run.
 *
 * Used per file, through `uses(SyntheticHost::class)`, so the database-free tests keep running with no database.
 */
trait SyntheticHost
{
    /** The stubs, in each package's declared `hasMigrations()` order. */
    private const STUBS = [
        'laravel-beam-tenancy' => ['create_tenants_table', 'create_tenant_users_table'],
        'laravel-beam-commerce' => [
            'create_plans_table',
            'create_subscriptions_table',
            'add_funding_to_subscriptions_table',
            'add_charge_mandate_to_subscriptions_table',
            'create_central_usage_summaries_table',
            'create_bills_table',
        ],
    ];

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            TenancyServiceProvider::class,
            CommerceServiceProvider::class,
            BeamSyntheticServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'central');
        $app['config']->set('database.connections.central', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('database.connections.testing', $app['config']->get('database.connections.central'));
        $app['config']->set('tenancy.tenant_model', Tenant::class);
        $app['config']->set('tenancy.database.central_connection', 'central');
        $app['config']->set('auth.providers.users.model', SyntheticSeatUser::class);
        $app['config']->set('beam.synthetic.provision_storage', false);

        Relation::morphMap(['tenant' => Tenant::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $dir = sys_get_temp_dir().'/beam-synthetic-migrations-'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $n = 1;
        foreach (self::STUBS as $package => $stubs) {
            foreach ($stubs as $stub) {
                copy(
                    dirname(__DIR__, 2)."/vendor/splicewire/{$package}/database/migrations/{$stub}.php.stub",
                    sprintf('%s/2000_01_01_%06d_%s.php', $dir, $n++, $stub),
                );
            }
        }

        $this->loadMigrationsFrom($dir);
    }

    /** The two plans the Demo blueprints name, with the flagship's components. */
    protected function seedDemoPlans(): void
    {
        Plan::create(['name' => 'Starter', 'slug' => 'starter', 'settings' => ['components' => [
            ['type' => 'SubscriptionFee', 'config' => ['monthly_price' => 99.0, 'annual_monthly_price' => 79.0]],
            ['type' => 'PerTokenFees', 'config' => ['markup' => 1.4]],
        ]]]);

        Plan::create(['name' => 'Professional', 'slug' => 'professional', 'settings' => ['components' => [
            ['type' => 'SubscriptionFee', 'config' => ['monthly_price' => 299.0, 'annual_monthly_price' => 249.0]],
            ['type' => 'PerSeatFees', 'config' => ['included_seats' => 5, 'per_seat_price' => 20.0]],
            ['type' => 'PerTokenFees', 'config' => ['markup' => 1.5]],
        ]]]);
    }
}
