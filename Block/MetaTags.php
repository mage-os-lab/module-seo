<?php

declare(strict_types=1);

namespace MageOS\Seo\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\MetaTag\Compositor;

class MetaTags extends Template
{
    /**
     * @param Context $context
     * @param Compositor $metaCompositor
     * @param ScopeConfigInterface $scopeConfig
     * @param Escaper $escaper
     * @param mixed[] $data
     */
    public function __construct(
        Context                               $context,
        private readonly Compositor           $metaCompositor,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Escaper              $escaper,
        array                                 $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return all meta tag definitions from the compositor.
     *
     * @return mixed[]
     */
    public function getMetaTags(): array
    {
        if (!(bool) $this->scopeConfig->getValue(
            'mageos_seo_general/og_tags/enabled',
            ScopeInterface::SCOPE_STORE
        )) {
            return [];
        }

        return $this->metaCompositor->getMetaTags();
    }

    /**
     * Escape a meta tag attribute value using escapeHtml (not escapeHtmlAttr).
     *
     * @param string $value
     * @return string
     */
    public function escapeMetaContent(string $value): string
    {
        return $this->escaper->escapeHtml($value);
    }

    /**
     * Render nothing if there are no meta tags to output.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (empty($this->getMetaTags())) {
            return '';
        }
        return parent::_toHtml();
    }
}
