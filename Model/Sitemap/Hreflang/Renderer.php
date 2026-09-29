<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Hreflang;

use Magento\Framework\Escaper;
use MageOS\Seo\Api\Sitemap\RowRendererInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;

/**
 * Writes the alternates Enricher filed as `<xhtml:link rel="alternate">` elements in the row.
 *
 * Inline, beside each URL, the way Google prefers them in a sitemap.
 */
class Renderer implements RowRendererInterface
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getNamespaces(): array
    {
        return ['xhtml' => 'http://www.w3.org/1999/xhtml'];
    }

    /**
     * @inheritdoc
     */
    public function render(SitemapItemInterface $item, int $storeId): string
    {
        $alternates = $item->getDataBag()[Enricher::BAG_KEY] ?? null;
        if (!$alternates instanceof Alternates) {
            return '';
        }

        $row = '';
        foreach ($alternates->getAlternates() as $alternate) {
            $hreflang = (string) ($alternate['hreflang'] ?? '');
            $url      = (string) ($alternate['url'] ?? '');
            if ($hreflang === '' || $url === '') {
                continue;
            }

            $row .= '<xhtml:link rel="alternate" hreflang="' . $this->escaper->escapeHtmlAttr($hreflang)
                . '" href="' . $this->escaper->escapeUrl($url) . '"/>';
        }

        return $row;
    }
}
