<?php

namespace Splicewire\Beam\Synthetic\Store;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Backing\CentralParticleBacking;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioInputData;
use Splicewire\Beam\Write\GateWriteGate;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;
use Throwable;

/**
 * The portfolios a host saved at runtime: central Data-declared particles, read by
 * {@see PortfolioRegistry} beside the portfolio files and written by the exec board's scenario builder (or any
 * caller of {@see save()}).
 *
 * ## A file id wins, and saving one is refused
 *
 * A portfolio a host DECLARES (a file under `beam.synthetic.portfolio_paths`, or one registered in code) is part of
 * the host's code and review; a stored one is runtime data. So {@see save()} refuses an id the registry already
 * declares, and a stored row whose id a file later comes to declare is shadowed: the registry reads the file, and
 * {@see shadowed()} names the row so an operator can delete it. Nothing stored can replace a declared portfolio.
 *
 * ## A row is validated whenever it is read
 *
 * Every row goes through {@see ScenarioPortfolioData::from()} and {@see BlueprintRegistry::missingFrom()} on read,
 * as it did on save. A row that no longer validates (say, a blueprint file it names was removed) is left out of
 * {@see all()} and reported by {@see invalid()}, so it cannot break a board that reads it.
 *
 * ## Cost
 *
 * Bound scoped: the first read in a request (or command) issues two central queries, whether the table exists and
 * its rows, and every later read in that scope is answered from memory. A host without the shared particle table or
 * a storage-capable Beam context reads no stored portfolios rather than failing; `beam.synthetic.portfolio_store` false turns the store off entirely
 * (no query at all). Every save and delete is written to the central activity trail (`log_name`
 * `synthetic-portfolios`).
 */
final class StoredPortfolios
{
    public const LOG_NAME = 'synthetic-portfolios';

    /** A stored id is the lens cookie's portfolio segment, so it is kebab-case (`BoardLens::parse()`'s rule). */
    public const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const ID_MAX = 100;

    private ?bool $available = null;

    /** @var array<string, ScenarioPortfolioData|string>|null id => portfolio, or why the row does not validate */
    private ?array $rows = null;

    public function __construct(
        private readonly Config $config,
        private readonly BlueprintRegistry $blueprints,
        private readonly PortfolioRegistry $portfolios,
    ) {}

    /** Whether this host keeps stored portfolios at all (`beam.synthetic.portfolio_store`, default true). */
    public function enabled(): bool
    {
        return (bool) $this->config->get('beam.synthetic.portfolio_store', true);
    }

    /** Whether the store can be read and written here: enabled, and its table migrated. */
    public function available(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->available === null) {
            if (! app()->bound(TenancyContext::class)) {
                return $this->available = false;
            }
            $model = $this->centralParticle();
            $connection = $model->getConnectionName() ?? $this->config->get('database.default');

            // A host with no `central` connection (no database at all, as in a projection-only run) keeps none.
            $this->available = is_array($this->config->get("database.connections.{$connection}"))
                && Schema::connection($connection)->hasTable($model->getTable());
        }

        return $this->available;
    }

    /**
     * Every stored portfolio that validates and is not shadowed by a declared one, in the order they were saved.
     *
     * @return array<string, ScenarioPortfolioData>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->rows() as $id => $row) {
            if ($row instanceof ScenarioPortfolioData && ! $this->portfolios->declares($id)) {
                $all[$id] = $row;
            }
        }

        return $all;
    }

    public function find(string $id): ?ScenarioPortfolioData
    {
        return $this->all()[$id] ?? null;
    }

    /** @return array<string, string> id => why, for every stored row that no longer validates */
    public function invalid(): array
    {
        return array_filter($this->rows(), 'is_string');
    }

    /** @return list<string> the stored ids a declared portfolio shadows (the declared one is what is read) */
    public function shadowed(): array
    {
        return array_values(array_filter(array_keys($this->rows()), fn (string $id): bool => $this->portfolios->declares($id)));
    }

    /**
     * Why `$portfolio` cannot be stored, or null when it can: an id that is not kebab-case or is too long, an id a
     * declared portfolio already holds, or a member naming a blueprint this host does not declare. The shape itself
     * (members, counts, offsets, rates) was checked when the Data was built.
     */
    public function refusal(ScenarioPortfolioData $portfolio): ?string
    {
        if (strlen($portfolio->id) > self::ID_MAX || preg_match(self::ID_PATTERN, $portfolio->id) !== 1) {
            return "Portfolio id '{$portfolio->id}' must be kebab-case (a-z, 0-9 and single hyphens), at most ".self::ID_MAX.' characters.';
        }

        if ($this->portfolios->declares($portfolio->id)) {
            return "Portfolio id '{$portfolio->id}' is declared by the host ({$this->portfolios->registrantOf($portfolio->id)}); a stored portfolio cannot replace it. Choose another id.";
        }

        $missing = $this->blueprints->missingFrom($portfolio);

        return $missing === [] ? null : sprintf("Portfolio '%s' names blueprints this host does not declare: %s.", $portfolio->id, implode(', ', $missing));
    }

    /**
     * Store `$portfolio` under its id, replacing a stored one with that id. Logged centrally.
     *
     * @throws AuthorizationException when no authenticated Model actor or declared ability admits the write.
     * @throws InvalidArgumentException when {@see refusal()} names a reason, or the store is not available
     */
    public function save(ScenarioPortfolioData $portfolio, ?Model $causer = null): ScenarioPortfolioData
    {
        $this->guard();

        $portfolio = ScenarioPortfolioData::from($portfolio->toArray());
        if (($why = $this->refusal($portfolio)) !== null) {
            throw new InvalidArgumentException($why);
        }

        $actor = $this->actor($causer);
        $backing = $this->backing($actor);
        $this->lock($portfolio->id)->block(10, function () use ($backing, $portfolio, $actor): void {
            $this->centralParticle()->getConnection()->transaction(function () use ($backing, $portfolio, $actor): void {
                $matches = $backing->query([])->where('payload->scenarioKey', $portfolio->id)->orderBy('created_at')->orderBy('id')->limit(2)->get();
                if ($matches->count() > 1) {
                    throw new InvalidArgumentException("Scenario '{$portfolio->id}' has multiple stored particles; reconcile or delete the key before saving.");
                }
                $existing = $matches->first();
                $before = $existing?->payload['portfolio'] ?? null;
                $model = $existing ?? $backing->newRecord()->model();
                $meta = $model->meta ?? [];
                $meta['saved_by'] = ['type' => $actor->getMorphClass(), 'id' => (string) $actor->getKey()];
                $model->meta = $meta;
                $gate = app(ParticleWriter::class)->gate();
                if (get_class($gate) === GateWriteGate::class) {
                    $gate = new PolicyWriteGate(Gate::getFacadeRoot(), StoredPortfolioData::WRITE_ABILITY);
                }
                $written = $backing->writer($gate)->write($model, (new StoredPortfolioInputData($portfolio->id, $portfolio))->toArray(), $actor);
                $this->log($existing === null ? 'created' : 'updated', $portfolio->id, $actor, [
                    'before' => $before, 'after' => $portfolio->toArray(),
                ], (string) $written->getKey());
            });
        });
        $this->rows = null;

        return $portfolio;
    }

    /**
     * Delete the stored portfolio `$id`. False when there is none (a declared portfolio is never deleted here).
     * Logged centrally when a row went.
     *
     * @throws AuthorizationException when no authenticated Model actor or declared ability admits deletion.
     */
    public function delete(string $id, ?Model $causer = null): bool
    {
        $this->guard();

        $actor = $this->actor($causer);
        $backing = $this->backing($actor);

        return $this->lock($id)->block(10, function () use ($backing, $id, $actor): bool {
            return $this->centralParticle()->getConnection()->transaction(function () use ($backing, $id, $actor): bool {
                $matches = $backing->query([])->where('payload->scenarioKey', $id)->orderBy('created_at')->orderBy('id')->get();
                if ($matches->isEmpty()) {
                    return false;
                }
                foreach ($matches as $row) {
                    // Same ability-checked deletion seam as the existing owned-resource controller.
                    Gate::forUser($actor)->authorize(StoredPortfolioData::WRITE_ABILITY, $row);
                    $before = $row->payload['portfolio'] ?? null;
                    $particleId = (string) $row->getKey();
                    $row->delete();
                    $this->log('deleted', $id, $actor, ['before' => $before], $particleId);
                }
                $this->rows = null;

                return true;
            });
        });
    }

    /** PROVISIONAL pending d62cb04e: internal registry reads preserve viewer and CLI access. */
    private function trustedQuery(): Builder
    {
        return $this->centralParticle()->newQuery()
            ->where('schema_ref', StoredPortfolioData::class)->where('source_tier', 'local')
            ->where('meta->owner->type', 'central')->where('meta->owner->id', 'host');
    }

    private function centralParticle(): BeamParticle
    {
        $context = app(TenancyContext::class);
        if ($context->isActive() && $context->centralConnection() === null) {
            throw new \LogicException('Central portfolio storage requires a central connection while a tenant is initialized.');
        }

        return (new BeamParticle)->setConnection($context->centralConnection());
    }

    private function actor(?Model $causer): Model&Authenticatable
    {
        $actor = $causer ?? auth()->user();
        if (! $actor instanceof Model || ! $actor instanceof Authenticatable) {
            throw new AuthorizationException('Stored portfolio mutations require an authenticated actor.');
        }

        return $actor;
    }

    private function backing(Authenticatable $actor): CentralParticleBacking
    {
        $resource = AttributedParticleDiscovery::resourceFromAttribute(StoredPortfolioData::class);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);

        return $resource->backing($request);
    }

    private function lock(string $key): Lock
    {
        $store = Cache::store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new \LogicException('Stored portfolio mutations require a shared lock-capable cache.');
        }
        if ($store instanceof DatabaseStore) {
            // The central key must not acquire a tenant-local database lock. Leave other cache users alone.
            $store = clone $store;
            $store->setLockConnection($this->centralParticle()->getConnection());
        }

        return $store->lock($this->lockKey($key), 30);
    }

    private function lockKey(string $key): string
    {
        return 'beam.synthetic.portfolio:'.hash('sha256', ($this->centralParticle()->getConnectionName() ?? $this->config->get('database.default')).'|'.$key);
    }

    /** @return array<string, ScenarioPortfolioData|string> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (! $this->available()) {
            return $this->rows = [];
        }

        $rows = [];

        foreach ($this->trustedQuery()->orderBy('created_at')->orderBy('id')->get() as $row) {
            $id = (string) ($row->payload['scenarioKey'] ?? '');
            if (array_key_exists($id, $rows)) {
                $rows[$id] = 'has multiple stored particles; reconcile or delete the key';

                continue;
            }
            try {
                if (strlen($id) > self::ID_MAX || preg_match(self::ID_PATTERN, $id) !== 1) {
                    throw new InvalidArgumentException('The stored scenario key must be kebab-case.');
                }
                $portfolio = ScenarioPortfolioData::from([...(array) ($row->payload['portfolio'] ?? []), 'id' => $id]);
                $missing = $this->blueprints->missingFrom($portfolio);
                $rows[$id] = $missing === [] ? $portfolio : 'names blueprints this host does not declare: '.implode(', ', $missing);
            } catch (Throwable $e) {
                $rows[$id] = $e->getMessage();
            }
        }

        return $this->rows = $rows;
    }

    private function guard(): void
    {
        if (! $this->available()) {
            throw new InvalidArgumentException($this->enabled()
                ? 'Stored portfolios need the central Beam particle table: install the existing Beam migrations and migrate.'
                : 'This host keeps no stored portfolios (beam.synthetic.portfolio_store is false).');
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function log(string $event, string $id, Model $causer, array $properties, string $particleId): void
    {
        CentralActivityLog::on($this->centralParticle()->getConnectionName())->create([
            'log_name' => self::LOG_NAME,
            'description' => match ($event) {
                'created' => "Scenario portfolio '{$id}' saved",
                'updated' => "Scenario portfolio '{$id}' updated",
                default => "Scenario portfolio '{$id}' deleted",
            },
            'event' => $event,
            'subject_type' => (new BeamParticle)->getMorphClass(),
            'subject_id' => $particleId,
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer !== null ? (string) $causer->getKey() : null,
            'properties' => ['scenario_key' => $id, ...$properties],
        ]);
    }
}
