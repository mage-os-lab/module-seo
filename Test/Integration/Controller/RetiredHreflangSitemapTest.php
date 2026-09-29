<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\TestFramework\TestCase\AbstractController;

/**
 * `/hreflang-sitemap.xml` is retired: the alternates are in `sitemap.xml`. Nothing answers the old
 * path any more — a plain 404, no redirect.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class RetiredHreflangSitemapTest extends AbstractController
{
    /**
     * @return void
     */
    public function testTheIndexIsNotFound(): void
    {
        $this->dispatch('/hreflang-sitemap.xml');

        $this->assert404NotFound();
    }

    /**
     * @return void
     */
    public function testAChunkIsNotFound(): void
    {
        $this->dispatch('/hreflang-sitemap-1.xml');

        $this->assert404NotFound();
    }
}
