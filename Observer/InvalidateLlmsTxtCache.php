<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\CacheInvalidate\Model\PurgeCache;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\PageCache\Model\Config;

class InvalidateLlmsTxtCache implements ObserverInterface
{
    /**
     * purge varnish tags for the llms
     *
     * @param Config $config
     * @param PurgeCache $purgeCache
     */
    public function __construct(
        private readonly Config $config,
        private readonly PurgeCache $purgeCache,
    ) {
    }

    /**
     * Invalidate full-page cache when category or vendor data changes so that
     * /llms.txt and /llms-full.txt are regenerated on next request.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // If varnish is enabled purge the tags specifically for the LLM Texts
        // Otherwise let the max age do it's job
        if ((int)$this->config->getType() === Config::VARNISH &&
            $this->config->isEnabled()
        ) {
            $bareTags = [
                'RS_LLMS',
                'RS_LLMS_FULL',
            ];

            $tags = [];
            $pattern = '((^|,)%s(,|$))';
            foreach ($bareTags as $tag) {
                $tags[] = \sprintf($pattern, $tag);
            }
            $this->purgeCache->sendPurgeRequest(array_unique($tags));
        }
    }
}
