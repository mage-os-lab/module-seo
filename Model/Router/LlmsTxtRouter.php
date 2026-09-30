<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use MageOS\Seo\Model\Aeo\Config;

/**
 * Intercepts requests for /llms.txt and /llms-full.txt and forwards them
 * to the appropriate controllers without requiring URL rewrites.
 *
 * Registered at sort order 20 (before the default router at 100).
 */
class LlmsTxtRouter implements RouterInterface
{
    /**
     * Request path => the controller that answers it. The same paths are registered as public in
     * di.xml (Router\PublicPaths), so no session is started for them.
     */
    private const ROUTES = [
        'llms.txt'      => ['module' => 'mageos-aeo', 'controller' => 'llms',      'action' => 'index'],
        'llms-full.txt' => ['module' => 'mageos-aeo', 'controller' => 'llmsfull',  'action' => 'index'],
        'llms.jsonl'    => ['module' => 'mageos-aeo', 'controller' => 'llmsjsonl', 'action' => 'index'],
    ];

    /**
     * Config check per path. A disabled file is not claimed, so the request falls
     * through to the other routers (a static file, another module, or the 404 page).
     */
    private const ENABLED_CHECKS = [
        'llms.txt'      => 'isLlmsTxtEnabled',
        'llms-full.txt' => 'isLlmsFullTxtEnabled',
        'llms.jsonl'    => 'isLlmsJsonlEnabled',
    ];

    /**
     * @param ActionFactory $actionFactory
     * @param Config $aeoConfig
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $aeoConfig
    ) {
    }

    /**
     * Match the request path against known llms.txt paths and forward if matched.
     *
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $path = trim($request->getPathInfo(), '/');

        if (!isset(self::ROUTES[$path])) {
            return null;
        }

        // Prevent infinite loop — if the module has already been set to ours
        // by a previous iteration, this router has already matched and forwarded.
        if ($request->getModuleName() === 'mageos-aeo') {
            return null;
        }

        // Claim the path only while the file is enabled for the current store.
        if (!$this->aeoConfig->{self::ENABLED_CHECKS[$path]}()) {
            return null;
        }

        $route = self::ROUTES[$path];

        $request->setModuleName($route['module'])
                ->setControllerName($route['controller'])
                ->setActionName($route['action'])
                ->setAlias(\Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS, $path);

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
