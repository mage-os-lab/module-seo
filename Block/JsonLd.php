<?php

declare(strict_types=1);

namespace MageOS\Seo\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Organization;
use MageOS\Seo\Model\PageCache\DependencyCollector;
use MageOS\Seo\Model\StructuredData\Compositor;

class JsonLd extends Template implements IdentityInterface
{
    /**
     * @param Context $context
     * @param Compositor $compositor
     * @param Config $seoConfig
     * @param DependencyCollector $dependencies
     * @param mixed[] $data
     */
    public function __construct(
        Context                              $context,
        private readonly Compositor          $compositor,
        private readonly Config              $seoConfig,
        private readonly DependencyCollector $dependencies,
        array                                $data = []
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
        if (!$this->seoConfig->isStructuredDataEnabled()) {
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

    /**
     * @inheritdoc
     *
     * This block sits in layout on every page (default.xml) and renders the
     * Organization-derived schema, so every FPC entry carries the Organization
     * cache tag; saving Organization settings purges those pages automatically.
     * Identities are collected from layout blocks regardless of rendered output.
     *
     * It also carries the tags of every per-entity SEO setting the page read — its robots
     * directive, its JSON-LD, its ItemList, its hreflang links, the categories they inherit from
     * (Model\PageCache\DependencyCollector) — so a change to any of them purges the page. The page
     * cache asks after the page's output is rendered, when every read has been made. Switching the
     * JSON-LD off in the admin keeps the block, and its tags; removing the block from the layout
     * stops changes to SEO settings purging pages.
     */
    public function getIdentities(): array
    {
        return [Organization::CACHE_TAG, ...$this->dependencies->tags()];
    }
}
