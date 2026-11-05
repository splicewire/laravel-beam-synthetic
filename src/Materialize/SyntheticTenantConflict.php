<?php

namespace Splicewire\Beam\Synthetic\Materialize;

use RuntimeException;

/**
 * A synthetic tenant id is already taken by a tenant the materializer must not rewrite: a real tenant, or a
 * synthetic one told from another blueprint or portfolio. Raised before anything is written.
 */
final class SyntheticTenantConflict extends RuntimeException
{
    public static function realTenant(string $id): self
    {
        return new self("A tenant with the id '{$id}' already exists and is not synthetic. The materializer never rewrites a real tenant; rename the blueprint.");
    }

    public static function otherStory(string $id, string $theirs, string $ours): self
    {
        return new self("The synthetic tenant '{$id}' was materialized from {$theirs}, not {$ours}. Materializing it again from {$ours} would rewrite another story's rows.");
    }

    public static function storageNotReady(string $id, string $extra): self
    {
        return new self("The synthetic extra '{$extra}' writes inside the tenant, but the storage of '{$id}' is not ready. Let the host's provisioning pipeline finish (run its queue worker, or use a sync queue), then materialize again.");
    }
}
