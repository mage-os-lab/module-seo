<?php

declare(strict_types=1);

namespace MageOS\Seo\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\StructuredData\Compositor;

class JsonLd extends Template
{
    /**
     * @param Context $context
     * @param Compositor $compositor
     * @param ScopeConfigInterface $scopeConfig
     * @param mixed[] $data
     */
    public function __construct(
        Context                           $context,
        private readonly Compositor       $compositor,
        private readonly ScopeConfigInterface $scopeConfig,
        array                             $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return the rendered JSON-LD string, or an empty string if disabled or no schemas.
     *
     * @return string
     */
    public function getJsonLd(): string
    {
        if (!(bool) $this->scopeConfig->getValue(
            'mageos_seo_general/structured_data/enabled',
            ScopeInterface::SCOPE_STORE
        )) {
            return '';
        }

        return $this->compositor->render();
    }

    /**
     * Render nothing if there is no JSON-LD to output.
     *
     * Avoids an empty <script> tag in the page source.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        $json = $this->getJsonLd();
        if ($json === '') {
            return '';
        }
        return parent::_toHtml();
    }
}
