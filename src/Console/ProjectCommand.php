<?php

namespace Splicewire\Beam\Synthetic\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ProjectedTenantData;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Roster\ScenarioProjector;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;

/**
 * Run a blueprint or a portfolio FORWARD from an anchor month, read-only.
 *
 * The curve is a function from an envelope to a series, so a projection that writes nothing works for central and
 * tenant data alike: the central/tenant split lives only in the materializer. This command reads the blueprint and
 * portfolio files and the expander, and nothing else: it runs no query of any kind, which
 * `tests/Console/ProjectCommandTest.php` asserts. A projection that reads the database is one refactor away from
 * writing to it.
 */
class ProjectCommand extends Command
{
    use ResolvesRoster;

    protected $signature = 'synthetic:project
        {blueprint? : The blueprint id to project (omit, with no --portfolio, to list blueprints and portfolios)}
        {--portfolio= : Project every tenant this portfolio describes instead}
        {--months= : How many months to project (default: the blueprint\'s window, or the portfolio\'s span)}
        {--from= : Anchor month, YYYY-MM (default: this month; the projection runs FORWARD from it)}
        {--json : Emit JSON instead of a table}';

    protected $description = 'Project a synthetic blueprint or portfolio forward, read-only (never reads or writes rows)';

    public function handle(ScenarioProjector $projector, BlueprintRegistry $blueprints, PortfolioRegistry $portfolios): int
    {
        if ($this->argument('blueprint') === null && $this->option('portfolio') === null) {
            return $this->list($blueprints, $portfolios);
        }

        try {
            $roster = $this->roster($blueprints, $portfolios);
            $anchor = $this->anchor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $months = $this->option('months') !== null ? max(1, (int) $this->option('months')) : null;
        $projection = $projector->project($roster, $anchor, $months);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($projection->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $label = $projection->portfolio !== null ? "portfolio {$projection->portfolio}" : "blueprint {$projection->blueprint}";
        $this->line("Projection — {$label}, {$projection->months} months from {$projection->from}");
        $this->newLine();

        if ($roster->portfolioId === null) {
            $this->tenantTable($projection->tenants[0]);
        } else {
            $this->table(
                ['tenant', 'scenario', 'plan', 'starts', 'usage cost (USD)'],
                array_map(fn (ProjectedTenantData $t): array => [
                    $t->tenant, $t->scenario, $t->plan, $t->startsAt ?? '(after the window)', number_format($t->totalUsageCostUsd, 2),
                ], $projection->tenants),
            );
            $this->table(['month', 'usage cost (USD)', 'seats'], $this->rows($projection->totals));
        }

        $this->line('  total  $'.number_format($projection->totalUsageCostUsd, 2));
        $this->newLine();
        $this->comment('Read-only: this command reads and writes no rows. synthetic:materialize writes a story as history.');

        return self::SUCCESS;
    }

    private function tenantTable(ProjectedTenantData $tenant): void
    {
        $this->line("  {$tenant->tenant}: {$tenant->scenario}, plan {$tenant->plan}, usage on {$tenant->usageMeter}");
        $this->table(['month', 'usage cost (USD)', 'seats'], $this->rows($tenant->projection));
    }

    /**
     * @param  list<ScenarioMonthData>  $months
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function rows(array $months): array
    {
        return array_map(fn (ScenarioMonthData $m): array => [$m->month, number_format($m->usageCostUsd, 2), $m->seatCount], $months);
    }

    private function list(BlueprintRegistry $blueprints, PortfolioRegistry $portfolios): int
    {
        $all = $blueprints->all();

        if ($all === []) {
            // Distinct from "you named one I cannot find": this host declares none.
            $this->warn('This host declares no synthetic blueprints (beam.synthetic.blueprint_paths).');

            return self::SUCCESS;
        }

        $this->line('Blueprints:');
        foreach ($all as $blueprint) {
            $this->line(sprintf('  %-28s %s, plan %s, %d→%d seats, $%s/mo usage target',
                $blueprint->id,
                $blueprint->scenario->value,
                $blueprint->planSlug,
                $blueprint->startingSeats,
                $blueprint->endingSeats,
                number_format($blueprint->targetMonthlyUsageCostUsd, 2),
            ));
        }

        $this->line('Portfolios:');
        foreach ($portfolios->all() as $portfolio) {
            $roster = SyntheticRoster::ofPortfolio($portfolio, $blueprints);
            $this->line(sprintf('  %-28s %s, %d tenants over %d months', $portfolio->id, $portfolio->label, count($roster->members), $roster->length()));
        }

        return self::SUCCESS;
    }

    /** @throws InvalidArgumentException on an anchor that is not YYYY-MM */
    private function anchor(): CarbonImmutable
    {
        $from = $this->option('from');

        if ($from === null) {
            return CarbonImmutable::now()->startOfMonth();
        }

        // `!` zeroes the unparsed fields, so a partial date never inherits today's day 31 and overflows the month.
        $anchor = preg_match('/^\d{4}-\d{2}$/', (string) $from) === 1 ? CarbonImmutable::createFromFormat('!Y-m', (string) $from) : false;

        if (! $anchor instanceof CarbonImmutable) {
            throw new InvalidArgumentException("--from must be a month, YYYY-MM; got '{$from}'.");
        }

        return $anchor->startOfMonth();
    }
}
