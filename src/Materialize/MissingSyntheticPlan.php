<?php

namespace Splicewire\Beam\Synthetic\Materialize;

use RuntimeException;

/**
 * A blueprint names a plan slug this host has no plan for. Raised before anything is written, naming every missing
 * slug, the blueprints that name it, and the slugs the host does have.
 */
final class MissingSyntheticPlan extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $missing  slug => the blueprint ids that name it
     * @param  list<string>  $available  the host's plan slugs
     */
    public static function for(array $missing, array $available): self
    {
        $lines = [];

        foreach ($missing as $slug => $blueprints) {
            $lines[] = "'{$slug}' (named by ".implode(', ', $blueprints).')';
        }

        return new self(sprintf(
            'This host has no plan with the slug %s. Its plans are: %s. Create the plan, or point the blueprint at one of these.',
            implode('; ', $lines),
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }
}
