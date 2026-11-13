# Move published portfolio rows to central particles

**PROVISIONAL pending root d62cb04e.** This move accompanies owner-review ticket 18;
Exec and Synthetic land together after the root policy decision. This package no longer
publishes its dedicated table migration. No upgrade automatically drops or rewrites a host table.

The source census inspected `database/migrations` in 51 fleet checkouts. Two have the published
`2026_11_07_175654_create_beam_synthetic_portfolios_table.php`:

| Host | Source head at census |
|---|---|
| `splicewire/laravel-beam-commerce-starter` | `369c9bdd0e908e778ac8670ffb00aa9764b39a63` |
| `splicewire/laravel-beam-market-starter` | `8d610602c07e20bc6052e9a97898b76699d19c91` |

No flagship copy was found. This is a source inventory, not evidence that either host ran the
migration or has rows. No live database was inspected. READY names the census receipt for CONFORM
C-2 and the migration-flatten inbound notice.

## Explicit lossless move

Run this in an authorized host maintenance window with scenario mutations paused. Back up the
central database first. Install the existing Beam particle/schema migrations and register the
new Data declaration before moving rows. Keep the old table and its migration history throughout
verification. Do not republish the retired stub or delete an already executed migration file.

Use the actual authorized host actor, who must pass `synthetic.portfolios.manage`. Exec's default
mapping requires BOTH existing entitlements, `exec.view` and `exec.scenario.manage`. A standalone
host defines the ability explicitly. No guest or system bypass is used.

The following is a host-side Tinker/script recipe, not an automatically run package migration.
Set `$actor` and an unused private `$backupPath` explicitly. The backup retains every old field,
including saver identity, label, raw definition and timestamps. Its exclusive create prevents
accidentally replacing a prior export. The import preflights ALL rows before writing anything;
declared collisions, invalid definitions and differing existing target scenarios stop the move.
Resolve those named rows deliberately while retaining their originals in the backup and old table.

```php
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;

// $actor = the actual authorized host user; $backupPath = unused private absolute path.
Gate::forUser($actor)->authorize(StoredPortfolioData::WRITE_ABILITY, new BeamParticle);
$connection = app(TenancyContext::class)->centralConnection();
$db = DB::connection($connection);
$legacy = $db->table('beam_synthetic_portfolios')->orderBy('id')->get();
$backup = fopen($backupPath, 'x');
if ($backup === false) {
    throw new RuntimeException('Choose an unused backup path.');
}
fchmod($backup, 0600);
$bytes = json_encode($legacy->all(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (fwrite($backup, $bytes) !== strlen($bytes)) {
    fclose($backup);
    throw new RuntimeException('Incomplete export: do not import or retire the old table.');
}
fclose($backup);

$store = app(StoredPortfolios::class);
$portfolios = [];
foreach ($legacy as $row) {
    $definition = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
    $portfolio = ScenarioPortfolioData::from([...$definition, 'id' => $row->id]);
    if (($why = $store->refusal($portfolio)) !== null) {
        throw new RuntimeException($why);
    }
    if (($existing = $store->find($row->id)) !== null
        && $existing->toArray() !== $portfolio->toArray()) {
        throw new RuntimeException('Different target scenario already exists: '.$row->id);
    }
    $portfolios[$row->id] = $portfolio;
}

$map = $db->transaction(function () use ($portfolios, $store, $actor, $connection) {
    $map = [];
    foreach ($portfolios as $key => $portfolio) {
        // Identical targets make retry safe without rewriting their audit/metadata.
        if ($store->find($key) === null) {
            $store->save($portfolio, $actor);
        }
        $particle = BeamParticle::on($connection)
            ->where('schema_ref', StoredPortfolioData::class)->where('source_tier', 'local')
            ->where('meta->owner->type', 'central')->where('meta->owner->id', 'host')
            ->where('payload->scenarioKey', $key)->sole();
        $map[$key] = (string) $particle->getKey();
    }
    return $map;
});
// Persist $map, backup SHA256 and operator identity in an exclusive private move receipt.
```

Verify every original key and definition against a FRESH store scope, and save the old-key to
new-particle-UUID map beside the backup. The new envelope UUID must differ from the scenario key.
Check list, preview, replace, delete and lens selection through the existing scenario operations
on an isolated host before adoption. Existing target conflicts never get silently overwritten.
The import audit correctly names the moving actor; original saver/timestamp facts stay in the
unaltered export, not fabricated as new envelope timestamps. Preserve historical activity records;
if a host rewrites their subject links, review that separate step against the saved id map first.

Only after counts, definitions and operation effects match should the host retire the dedicated
table through its own reviewed migration procedure. Keep the database backup, export, id map and
move receipt. A failed preflight or validation leaves the old table untouched and gives a named
row to resolve. The package candidate performs none of these live actions.
