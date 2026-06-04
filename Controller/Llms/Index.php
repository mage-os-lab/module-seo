<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;

class Index implements HttpGetActionInterface
{
    /**
     * @param LlmsTxtBuilder $builder
     * @param RawFactory $rawFactory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly LlmsTxtBuilder       $builder,
        private readonly RawFactory           $rawFactory,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Serve /llms.txt as plain text with cache headers.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();

        if (!(bool) $this->scopeConfig->getValue(
            'mageos_seo_general/llms_txt/enabled',
            ScopeInterface::SCOPE_STORE
        )) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $content = $this->builder->buildConcise();

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'public, max-age=3600, s-maxage=86400', true);
        $result->setHeader('X-Magento-Tags', 'RS_LLMS', true);
        $result->setContents($content);

        return $result;
    }
}
