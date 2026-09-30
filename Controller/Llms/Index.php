<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;

class Index implements HttpGetActionInterface
{
    private const FILE = 'llms.txt';

    /**
     * @param RawFactory $rawFactory
     * @param Config $aeoConfig
     * @param FeedStorage $feedStorage
     * @param CanonicalPathRedirect $canonicalPathRedirect
     * @param RegenerationRequester $regenerationRequester
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RawFactory            $rawFactory,
        private readonly Config                $aeoConfig,
        private readonly FeedStorage           $feedStorage,
        private readonly CanonicalPathRedirect $canonicalPathRedirect,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Serve /llms.txt from the pre-generated feed file.
     *
     * Web requests never build the document: a missing file queues a rebuild, so
     * anonymous traffic cannot trigger catalog builds.
     *
     * A missing file answers 404, not 503. Lighthouse's llms-txt audit scores a 5xx
     * as a failure (0) and any 4xx as "not applicable"; a store should not fail the
     * audit because the first rebuild has not run yet. Retry-After still tells
     * clients when to come back, and no-store keeps the 404 out of Varnish/FPC.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        // Query-string variants and the standard-router URL collapse to the canonical
        // path so they cannot be used to force cache-missing requests.
        $redirect = $this->canonicalPathRedirect->check(self::FILE);
        if ($redirect !== null) {
            return $redirect;
        }

        $result = $this->rawFactory->create();

        if (!$this->aeoConfig->isLlmsTxtEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $content = $this->feedStorage->read(self::FILE, $storeId);
        if ($content === null) {
            $this->regenerationRequester->request(FeedRegenerator::GROUP_LLMS);
            $result->setHttpResponseCode(404);
            $result->setHeader('Retry-After', '120', true);
            $result->setHeader('Cache-Control', 'no-store', true);
            $result->setContents('');
            return $result;
        }

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Cache-Control', FeedCache::CACHE_CONTROL, true);
        $result->setHeader('X-Magento-Tags', FeedCache::TAG_LLMS, true);
        $result->setContents($content);

        return $result;
    }
}
