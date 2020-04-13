<?php

declare(strict_types=1);

namespace PoP\API\Facades;

use PoP\API\PersistedQueries\PersistedFragmentManagerInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class PersistedFragmentManagerFacade
{
    public static function getInstance(): PersistedFragmentManagerInterface
    {
        return ContainerBuilderFactory::getInstance()->get('persisted_fragment_manager');
    }
}
