<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

/**
 * Where this package's Demo blueprints and portfolio live. Generic and fictional, with no vertical:
 * enough for a fresh host to show synthesize mode before it declares its own.
 *
 * Named through this class, not a path in the config file, so a published config still points into the
 * package rather than into the host.
 */
final class DemoBlueprints
{
    public static function blueprints(): string
    {
        return dirname(__DIR__, 2).'/resources/synthetic/demo/blueprints';
    }

    public static function portfolios(): string
    {
        return dirname(__DIR__, 2).'/resources/synthetic/demo/portfolios';
    }
}
