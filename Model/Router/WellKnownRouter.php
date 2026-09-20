<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use MageOS\Seo\Model\WellKnown\EndpointPool;

/**
 * Intercepts /.well-known/* requests and forwards registered endpoints to the dispatcher
 * controller, without requiring URL rewrites. Unregistered paths fall through to the next router.
 *
 * Mirrors how Magento_Robots intercepts robots.txt. Registered before the default router.
 */
class WellKnownRouter implements RouterInterface
{
    private const PREFIX = PublicPaths::WELL_KNOWN_PREFIX;

    /**
     * @param ActionFactory $actionFactory
     * @param EndpointPool $endpointPool
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly EndpointPool $endpointPool
    ) {
    }

    /**
     * Match /.well-known/{name} against the endpoint registry and forward when known.
     *
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $path = ltrim($request->getPathInfo(), '/');

        if (!str_starts_with($path, self::PREFIX)) {
            return null;
        }

        // Prevent an infinite loop once this router has already forwarded the request.
        if ($request->getModuleName() === 'mageos-seo') {
            return null;
        }

        $name = substr($path, \strlen(self::PREFIX));
        if (!$this->endpointPool->has($name)) {
            return null;
        }

        $request->setModuleName('mageos-seo')
            ->setControllerName('wellknown')
            ->setActionName('index')
            ->setParam('endpoint', $name)
            ->setAlias(\Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS, $path);

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
