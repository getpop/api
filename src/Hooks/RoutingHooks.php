<?php
namespace PoP\API\Hooks;
use PoP\Engine\Hooks\AbstractHookSet;

class RoutingHooks extends AbstractHookSet
{
    protected function init()
    {
        $this->hooksAPI->addFilter(
            '\PoP\Routing:uri-route',
            array($this, 'getURIRoute')
        );
    }

    public function getURIRoute($route)
    {
        $cmsengineapi = \PoP\Engine\FunctionAPIFactory::getInstance();
        $homeurl = $cmsengineapi->getHomeURL();
        return substr(\PoP\ComponentModel\Utils::getCurrentUrl(), strlen($homeurl));
    }
}
