<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Llmsfull;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;

class Index implements HttpGetActionInterface
{
    /**
     * @param LlmsTxtBuilder $builder
     * @param RawFactory $rawFactory
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly LlmsTxtBuilder $builder,
        private readonly RawFactory     $rawFactory,
        private readonly Config         $seoConfig
    ) {
    }

    /**
     * Serve /llms-full.txt as plain text with cache headers.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();

        if (!$this->seoConfig->isLlmsFullTxtEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $content = $this->builder->buildFull();

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'public, max-age=3600, s-maxage=86400', true);
        $result->setHeader('X-Magento-Tags', 'RS_LLMS_FULL', true);
        $result->setContents($content);

        return $result;
    }
}
