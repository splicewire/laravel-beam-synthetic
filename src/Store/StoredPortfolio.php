<?php

namespace Splicewire\Beam\Synthetic\Store;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;

/**
 * One portfolio a host saved at runtime: a row of the central `beam_synthetic_portfolios` table, published by this
 * package's migration stub. `definition` is the whole portfolio as {@see ScenarioPortfolioData}
 * would serialize it; {@see StoredPortfolios} validates it again on every read, so a row is never trusted because it
 * was valid when it was written.
 *
 * Read and written only through {@see StoredPortfolios}.
 *
 * @property string $id
 * @property string $label
 * @property array<string, mixed> $definition
 * @property string|null $saved_by_type
 * @property string|null $saved_by_id
 */
class StoredPortfolio extends Model
{
    /**
     * @central-floor registry-runtime — a stored portfolio is a runtime entry of `beam.synthetic.portfolios`, read
     * beside the host's portfolio files by the board's Scenario lens. It belongs to the platform's board, not to any
     * tenant, and must read the same whichever tenant (if any) is initialized.
     */
    protected $connection = 'central';

    protected $table = 'beam_synthetic_portfolios';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'label', 'definition', 'saved_by_type', 'saved_by_id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
