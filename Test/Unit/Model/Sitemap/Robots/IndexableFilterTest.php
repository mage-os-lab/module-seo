<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\Robots;

use MageOS\Seo\Model\Sitemap\Robots\Directive;
use MageOS\Seo\Model\Sitemap\Robots\Enricher;
use MageOS\Seo\Model\Sitemap\Robots\IndexableFilter;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use PHPUnit\Framework\TestCase;

class IndexableFilterTest extends TestCase
{
    public function testAPageServedNoindexIsLeftOut(): void
    {
        $this->assertFalse((new IndexableFilter())->isIncluded($this->item(new Directive('NOINDEX,FOLLOW')), 1));
    }

    public function testAnIndexablePageIsListed(): void
    {
        $this->assertTrue((new IndexableFilter())->isIncluded($this->item(new Directive('INDEX,FOLLOW')), 1));
    }

    /**
     * With the setting off the enricher files nothing, and nothing is left out.
     */
    public function testAnItemWithoutADirectiveIsListed(): void
    {
        $this->assertTrue((new IndexableFilter())->isIncluded($this->item(null), 1));
    }

    /**
     * @param Directive|null $directive
     * @return SitemapItem
     */
    private function item(?Directive $directive): SitemapItem
    {
        return new SitemapItem(
            'page.html',
            '0.5',
            'daily',
            null,
            null,
            null,
            null,
            $directive === null ? [] : [Enricher::BAG_KEY => $directive]
        );
    }
}
