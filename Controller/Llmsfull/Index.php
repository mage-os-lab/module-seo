<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Llmsfull;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\CanonicalPathRedirect;
use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Feed\RegenerationRequester;

class Index implements HttpGetActionInterface
{
    private const FILE = 'llms-full.txt';

    /**
     * @param RawFactory $rawFactory
     * @param Config $seoConfig
     * @param FeedStorage $feedStorage
     * @param CanonicalPathRedirect $canonicalPathRedirect
     * @param RegenerationRequester $regenerationRequester
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RawFactory            $rawFactory,
        private readonly Config                $seoConfig,
        private readonly FeedStorage           $feedStorage,
        private readonly CanonicalPathRedirect $canonicalPathRedirect,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Serve /llms-full.txt from the pre-generated feed file.
     *
     * Web requests never build the document: a missing file queues a rebuild and
     * answers 503 Retry-After, so anonymous traffic cannot trigger catalog builds.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->canonicalPathRedirect->check(self::FILE);
        if ($redirect !== null) {
            return $redirect;
        }

        $result = $this->rawFactory->create();

        if (!$this->seoConfig->isLlmsFullTxtEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $content = $this->feedStorage->read(self::FILE, $storeId);
        if ($content === null) {
            $this->regenerationRequester->request(FeedRegenerator::GROUP_LLMS);
            $result->setHttpResponseCode(503);
            $result->setHeader('Retry-After', '120', true);
            $result->setContents('');
            return $result;
        }

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Cache-Control', FeedCache::CACHE_CONTROL, true);
        $result->setHeader('X-Magento-Tags', FeedCache::TAG_LLMS_FULL, true);
        $result->setContents($content);

        return $result;
    }
}
