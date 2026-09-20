<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Intercepts /hreflang-sitemap.xml (and its /hreflang-sitemap-N.xml chunk files) and
 * forwards them to the sitemap controller without a URL rewrite.
 *
 * Registered before the default router. Mirrors the llms.txt router pattern.
 */
class HreflangSitemapRouter implements RouterInterface
{
    private const PATH_PATTERN = PublicPaths::HREFLANG_PATTERN;

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * Forward hreflang sitemap paths to the sitemap controller.
     *
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $path = trim($request->getPathInfo(), '/');
        if (!preg_match(self::PATH_PATTERN, $path, $matches)) {
            return null;
        }

        // Already forwarded by a previous iteration — avoid an infinite loop.
        if ($request->getModuleName() === 'mageos-seo') {
            return null;
        }

        $request->setModuleName('mageos-seo')
                ->setControllerName('hreflangsitemap')
                ->setActionName('index')
                ->setParam('chunk', (int) ($matches[1] ?? 0))
                ->setAlias(\Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS, $path);

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
