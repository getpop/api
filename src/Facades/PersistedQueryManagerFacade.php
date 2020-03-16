<?php
namespace PoP\API\Facades;

use PoP\API\PersistedQueries\PersistedQueryManagerInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class PersistedQueryManagerFacade
{
    public static function getInstance(): PersistedQueryManagerInterface
    {
        return ContainerBuilderFactory::getInstance()->get('persisted_query_manager');
    }
}