<?php

namespace Splicewire\Beam\Synthetic\Extras;

/**
 * Where a {@see MaterializesSyntheticExtras} writes. A `Central` extra runs as the materializer does, against the
 * central connection. A `Tenant` extra runs inside `$tenant->run()`, against the synthetic tenant's own storage, so
 * the materializer refuses it, naming the tenant, when that storage is not ready.
 */
enum SyntheticPlane: string
{
    case Central = 'central';
    case Tenant = 'tenant';
}
