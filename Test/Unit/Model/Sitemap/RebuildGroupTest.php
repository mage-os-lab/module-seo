<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\TestCase;

class RebuildGroupTest extends TestCase
{
    public function testATypeTravelsAsItsGroupAndBack(): void
    {
        $group = new RebuildGroup();

        $this->assertSame('sitemap-products', $group->forType('products'));
        $this->assertSame('blog-posts', $group->typeOf($group->forType('blog-posts')));
    }

    public function testAFeedGroupIsNoSitemapType(): void
    {
        $this->assertNull((new RebuildGroup())->typeOf('llms'));
        $this->assertNull((new RebuildGroup())->typeOf('hreflang'));
    }
}
