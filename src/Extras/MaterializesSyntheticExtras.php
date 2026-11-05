<?php

namespace Splicewire\Beam\Synthetic\Extras;

use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * A host's own step in materializing a synthetic tenant: the part of a story only the host knows how to tell, such as
 * a vertical's records inside the tenant. Registered in `beam.synthetic.materializers` ({@see SyntheticExtrasRegistry}).
 *
 * For each tenant it materializes, the materializer asks every registered extra {@see appliesTo()}, then:
 *
 * 1. calls {@see beforeProvisioning()} on the unsaved (or reloaded) tenant, before the save that fires the host's
 *    provisioning pipeline, so an extra can set attributes the pipeline reads;
 * 2. saves the tenant, writes its subscription, seats, usage and (with `--bills`) bills;
 * 3. calls {@see materialize()}: directly for a {@see SyntheticPlane::Central} extra, and inside `$tenant->run()` for a
 *    {@see SyntheticPlane::Tenant} one.
 *
 * Both calls run on every materialize, including a re-run, so each must be idempotent.
 */
interface MaterializesSyntheticExtras
{
    /** Where {@see materialize()} writes. */
    public function plane(): SyntheticPlane;

    /** Whether this extra has anything to do for a tenant told from `$blueprint`. */
    public function appliesTo(SyntheticTenantBlueprintData $blueprint): bool;

    /** Set attributes on the tenant before the save that provisions it. The materializer saves; do not save here. */
    public function beforeProvisioning(Tenant $tenant, SyntheticTenantBlueprintData $blueprint): void;

    /** Write this extra's part of the story. */
    public function materialize(SyntheticTenantContext $context): void;
}
