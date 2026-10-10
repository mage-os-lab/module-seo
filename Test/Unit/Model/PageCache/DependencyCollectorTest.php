<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageCache;

use MageOS\Seo\Model\PageCache\DependencyCollector;
use PHPUnit\Framework\TestCase;

/**
 * The SEO settings a page read, as tags: recorded on the storefront only, once each, and forgotten
 * between worker-mode requests.
 */
class DependencyCollectorTest extends TestCase
{
    /**
     * @return void
     */
    public function testWhatThePageReadIsRecordedOnceEach(): void
    {
        $collector = new DependencyCollector(true);

        $collector->record(['mageos_seo_cat_3', 'mageos_seo_cat_2']);
        $collector->record(['mageos_seo_cat_3']);
        $collector->record([]);

        $this->assertSame(['mageos_seo_cat_3', 'mageos_seo_cat_2'], $collector->tags());
    }

    /**
     * Outside the storefront — sitemap and feed builds — nothing is recorded.
     *
     * @return void
     */
    public function testNothingIsRecordedWhenRecordingIsOff(): void
    {
        $collector = new DependencyCollector();

        $collector->record(['mageos_seo_cat_3']);

        $this->assertSame([], $collector->tags());
    }

    /**
     * @return void
     */
    public function testANewRequestStartsWithNothingRecorded(): void
    {
        $collector = new DependencyCollector(true);
        $collector->record(['mageos_seo_prod_9']);

        $collector->_resetState();
        $collector->record(['mageos_seo_cms_4']);

        $this->assertSame(['mageos_seo_cms_4'], $collector->tags(), 'Recording goes on after a reset.');
    }
}
