<?php

namespace Splicewire\Beam\Synthetic\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Materialize\MaterializedTenant;
use Splicewire\Beam\Synthetic\Materialize\SyntheticTenantMaterializer;
use Throwable;

/**
 * Materialize a blueprint, or every tenant a portfolio describes, into this host as synthetic tenants. Safe to re-run:
 * a second run in the same month rewrites the same rows to the same values. See {@see SyntheticTenantMaterializer}.
 */
class MaterializeCommand extends Command
{
    use ResolvesRoster;

    protected $signature = 'synthetic:materialize
        {blueprint? : The blueprint id to materialize}
        {--portfolio= : Materialize every tenant this portfolio describes instead}
        {--bills : Also generate the bills of every closed period the window covers}';

    protected $description = 'Materialize synthetic tenants from a blueprint or a portfolio (idempotent)';

    public function handle(SyntheticTenantMaterializer $materializer, BlueprintRegistry $blueprints, PortfolioRegistry $portfolios): int
    {
        if ($this->argument('blueprint') === null && $this->option('portfolio') === null) {
            $this->error('Name a blueprint, or a --portfolio. Run synthetic:project with no argument to list them.');

            return self::FAILURE;
        }

        try {
            $results = $materializer->materialize($this->roster($blueprints, $portfolios), bills: (bool) $this->option('bills'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['tenant', 'blueprint', 'plan', 'months', 'seats', 'usage', 'bills', 'storage', 'extras', ''],
            array_map(fn (MaterializedTenant $r): array => [
                $r->tenantId,
                $r->blueprintId,
                $r->planSlug,
                $r->months[0].'..'.$r->months[array_key_last($r->months)],
                $r->seats,
                $r->usageMonths,
                $this->option('bills') ? count($r->billsGenerated).' new, '.count($r->billsSkipped).' kept' : '-',
                $r->storageReady ? 'ready' : 'not ready',
                $r->extras === [] ? '-' : implode(', ', $r->extras),
                $r->created ? 'created' : 'updated',
            ], $results),
        );

        $this->info(sprintf('%d synthetic tenant(s) materialized.', count($results)));

        return self::SUCCESS;
    }
}
