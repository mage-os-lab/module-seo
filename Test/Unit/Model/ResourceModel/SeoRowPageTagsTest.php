<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\Context;
use MageOS\Seo\Model\CmsPageConfig as CmsPageConfigRow;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\PageCache\SeoCacheTags;
use MageOS\Seo\Model\ResourceModel\AbstractSeoRowResource;
use MageOS\Seo\Model\ResourceModel\CategoryConfig;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig;
use MageOS\Seo\Model\ResourceModel\ProductOverride;
use PHPUnit\Framework\TestCase;

/**
 * Which cached pages a write to an SEO row purges. That the purge follows the write, after the
 * commit, and only when a setting changed is tested against a real database by
 * Test/Integration/PageCache/SeoPagePurgeTest.
 */
class SeoRowPageTagsTest extends TestCase
{
    /**
     * @return void
     */
    public function testACategoryRowPurgesItsCategorysPages(): void
    {
        $row = $this->row(['category_id' => '14', 'store_id' => 1]);

        $this->assertSame(['mageos_seo_cat_14'], $this->tags(CategoryConfig::class, $row, false));
        $this->assertSame(['mageos_seo_cat_14'], $this->tags(CategoryConfig::class, $row, true));
    }

    /**
     * @return void
     */
    public function testAProductRowPurgesItsProductsPages(): void
    {
        $this->assertSame(
            ['mageos_seo_prod_10'],
            $this->tags(ProductOverride::class, $this->row(['product_id' => '10', 'store_id' => 0]), false)
        );
    }

    /**
     * A CMS page that stays in its group changes only its own pages: the others list its URL, not
     * its settings.
     *
     * @return void
     */
    public function testACmsRowKeepingItsGroupPurgesItsOwnPagesOnly(): void
    {
        $row = $this->row(['page_id' => 7, 'hreflang_group' => 'about-us', 'robots_meta' => null], true);
        $row->setData('robots_meta', 'NOINDEX,FOLLOW');

        $this->assertSame(['mageos_seo_cms_7'], $this->tags(CmsPageConfig::class, $row, false));
    }

    /**
     * Moving between groups changes the heads of both groups' pages.
     *
     * @return void
     */
    public function testACmsRowMovingBetweenGroupsPurgesBothGroups(): void
    {
        $row = $this->row(['page_id' => 7, 'hreflang_group' => 'old-group'], true);
        $row->setData('hreflang_group', 'new-group');

        $this->assertSame(
            ['mageos_seo_cms_7', $this->groupTag('old-group'), $this->groupTag('new-group')],
            $this->tags(CmsPageConfig::class, $row, false)
        );
    }

    /**
     * A new row joining a group, and a row leaving one.
     *
     * @return void
     */
    public function testACmsRowJoiningOrLeavingAGroupPurgesThatGroup(): void
    {
        $joining = $this->row(['page_id' => 7, 'hreflang_group' => 'about-us']);
        $this->assertSame(
            ['mageos_seo_cms_7', $this->groupTag('about-us')],
            $this->tags(CmsPageConfig::class, $joining, false)
        );

        $leaving = $this->row(['page_id' => 7, 'hreflang_group' => 'about-us'], true);
        $leaving->setData('hreflang_group', null);
        $this->assertSame(
            ['mageos_seo_cms_7', $this->groupTag('about-us')],
            $this->tags(CmsPageConfig::class, $leaving, false)
        );
    }

    /**
     * A deleted row takes its page out of its group, whether it was loaded first or not.
     *
     * @return void
     */
    public function testADeletedCmsRowPurgesItsGroup(): void
    {
        $loaded = $this->row(['page_id' => 7, 'hreflang_group' => 'about-us'], true);
        $this->assertSame(
            ['mageos_seo_cms_7', $this->groupTag('about-us')],
            $this->tags(CmsPageConfig::class, $loaded, true)
        );

        $unloaded = $this->row(['page_id' => 7, 'hreflang_group' => 'about-us']);
        $this->assertSame(
            ['mageos_seo_cms_7', $this->groupTag('about-us')],
            $this->tags(CmsPageConfig::class, $unloaded, true)
        );

        $inNoGroup = $this->row(['page_id' => 7, 'hreflang_group' => null], true);
        $this->assertSame(['mageos_seo_cms_7'], $this->tags(CmsPageConfig::class, $inNoGroup, true));
    }

    /**
     * The tags the resource model names for the write.
     *
     * @param class-string<AbstractSeoRowResource> $resourceClass
     * @param AbstractModel $row
     * @param bool $removed
     * @return string[]
     */
    private function tags(string $resourceClass, AbstractModel $row, bool $removed): array
    {
        $resource = new $resourceClass(
            $this->createStub(Context::class),
            new SeoCacheTags(),
            $this->createStub(Purger::class)
        );

        return (new \ReflectionMethod($resource, 'pageTags'))->invoke($resource, $row, $removed);
    }

    /**
     * A row with the data, as loaded from the table when $loaded.
     *
     * @param array<string,mixed> $data
     * @param bool $loaded
     * @return AbstractModel
     */
    private function row(array $data, bool $loaded = false): AbstractModel
    {
        // Only the row's data and its record of what was loaded are used, so the model's own
        // dependencies are left out; any of the three row models would do.
        $row = (new \ReflectionClass(CmsPageConfigRow::class))->newInstanceWithoutConstructor();
        $row->setData($data);
        if ($loaded) {
            $row->setOrigData();
        }

        return $row;
    }

    /**
     * @param string $group
     * @return string
     */
    private function groupTag(string $group): string
    {
        return (new SeoCacheTags())->hreflangGroup($group);
    }
}
