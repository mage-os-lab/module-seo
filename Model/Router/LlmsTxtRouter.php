<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Intercepts requests for /llms.txt and /llms-full.txt and forwards them
 * to the appropriate controllers without requiring URL rewrites.
 *
 * Registered at sort order 20 (before the default router at 100).
 */
class LlmsTxtRouter implements RouterInterface
{
    private const ROUTES = [
        'llms.txt'      => ['module' => 'rs-seo', 'controller' => 'llms',      'action' => 'index'],
        'llms-full.txt' => ['module' => 'rs-seo', 'controller' => 'llmsfull',  'action' => 'index'],
    ];

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
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
        if ($request->getModuleName() === 'rs-seo') {
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
