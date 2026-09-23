<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Renderer;

use Magento\Framework\Escaper;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Api\Sitemap\RowRendererInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;

/**
 * `<loc>`, `<lastmod>`, `<changefreq>` and `<priority>`, exactly as core's sitemap writes them.
 *
 * The same escaping, the same URL building and the same last-modified floor as
 * `Magento\Sitemap\Model\Sitemap::_getSitemapRow()`, so a row reads the same whichever
 * generator wrote it.
 */
class CoreFields implements RowRendererInterface
{
    /**
     * @var int|null
     */
    private ?int $lastModFloor = null;

    /**
     * @param CanonicalBaseUrl $canonicalBaseUrl
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly CanonicalBaseUrl $canonicalBaseUrl,
        private readonly Escaper          $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getNamespaces(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function render(SitemapItemInterface $item, int $storeId): string
    {
        $url = $this->canonicalBaseUrl->forStore($storeId) . '/' . ltrim((string) $item->getUrl(), '/');
        $row = '<loc>' . $this->escaper->escapeUrl($url) . '</loc>';

        $updatedAt = $item->getUpdatedAt();
        if ($updatedAt) {
            $row .= '<lastmod>' . $this->lastMod((string) $updatedAt) . '</lastmod>';
        }

        $changeFrequency = $item->getChangeFrequency();
        if ($changeFrequency) {
            $row .= '<changefreq>' . $this->escaper->escapeHtml($changeFrequency) . '</changefreq>';
        }

        $priority = $item->getPriority();
        if ($priority) {
            $row .= \sprintf('<priority>%.1f</priority>', $this->escaper->escapeHtml($priority));
        }

        return $row;
    }

    /**
     * An ISO 8601 date no earlier than core's floor, as core's `_getFormattedLastmodDate()` gives.
     *
     * @param string $date
     * @return string
     */
    private function lastMod(string $date): string
    {
        $this->lastModFloor ??= (int) strtotime(Sitemap::LAST_MOD_MIN_VAL);

        return date('c', max((int) strtotime($date), $this->lastModFloor));
    }
}
