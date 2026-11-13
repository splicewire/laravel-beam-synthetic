<?php

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Synthetic\Data\PortfolioMemberData;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioInputData;

/*
 * app-walkthrough APP-09 at the owner: a class this package puts on a particle wire declares its rendered label
 * (#[Title]), so a host's schema and forms show a name rather than the class identifier. This package mounts no
 * operation, so a walk of the booted registries reaches nothing here: the classes are named. PortfolioMemberData
 * travels in beam-exec's scenario operations (ScenarioDraftData, ScenarioSaveData and ScenarioEntryData list it);
 * exec-board ticket 24's host proof found it unlabeled at laravel-beam-commerce-starter's IdentifierLabelTest.
 */
it('labels every class another package\'s operations carry on the wire', function () {
    $unlabeled = array_values(array_filter(
        [PortfolioMemberData::class, ScenarioPortfolioData::class, StoredPortfolioData::class, StoredPortfolioInputData::class],
        fn (string $class): bool => (new ReflectionClass($class))->getAttributes(Title::class) === [],
    ));

    expect($unlabeled)->toBe([]);
});
