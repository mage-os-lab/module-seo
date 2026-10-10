<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageCache;

use MageOS\Seo\Model\PageCache\SeoCacheTags;
use PHPUnit\Framework\TestCase;

/**
 * The page cache tags of the SEO settings, one per entity.
 */
class SeoCacheTagsTest extends TestCase
{
    /**
     * @return void
     */
    public function testEachEntityHasATagOfItsOwn(): void
    {
        $tags = new SeoCacheTags();

        $this->assertSame('mageos_seo_cat_12', $tags->category(12));
        $this->assertSame('mageos_seo_prod_12', $tags->product(12));
        $this->assertSame('mageos_seo_cms_12', $tags->cmsPage(12));
    }

    /**
     * A translation group is free text, so its tag is a hash: the same for the same group, another
     * for another, and safe in a comma-separated header and a Varnish ban pattern.
     *
     * @return void
     */
    public function testATranslationGroupsTagIsAHashOfIt(): void
    {
        $tags = new SeoCacheTags();

        $tag = $tags->hreflangGroup('about, us (EN)');

        $this->assertSame('mageos_seo_hreflang_' . substr(sha1('about, us (EN)'), 0, 16), $tag);
        $this->assertMatchesRegularExpression('/^mageos_seo_hreflang_[0-9a-f]{16}$/', $tag);
        $this->assertSame($tag, $tags->hreflangGroup('about, us (EN)'));
        $this->assertNotSame($tag, $tags->hreflangGroup('about-us'));
    }
}
