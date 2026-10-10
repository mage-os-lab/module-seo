<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Migration;

use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Migration\MetaRobotsTagDirectives;
use MageOS\Seo\Model\Migration\MetaRobotsTagImporter;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\Product\OverrideRepository;
use PHPUnit\Framework\TestCase;

/**
 * Which rows an import writes from the directives MageOS_MetaRobotsTag served, and what it keeps
 * (issue #26, review finding H6). Test/Integration/Model/Migration covers it against the real tables.
 */
class MetaRobotsTagImporterTest extends TestCase
{
    /**
     * This module's robots, by type, entity ID and store view (0: global), as each repository holds them.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private array $stored = ['product' => [], 'category' => [], 'cms_page' => []];

    /**
     * Rows written, as [type, entity ID, store view ID, directive].
     *
     * @var array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private array $saved = [];

    /**
     * The largest number of IDs read at once.
     *
     * @var int
     */
    private int $largestRead = 0;

    /**
     * Reads of products' or CMS pages' robots, by store view.
     *
     * @var array<int, int>
     */
    private array $reads = [];

    /**
     * @return void
     */
    public function testOneGlobalRowWhereEveryStoreViewWasServedTheSame(): void
    {
        $report = $this->importer(products: [10 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,FOLLOW']])->import();

        $this->assertSame([['product', 10, 0, 'NOINDEX,FOLLOW']], $this->saved);
        $this->assertSame(['written' => ['product' => 1, 'category' => 0, 'cms_page' => 0], 'notes' => []], $report);
    }

    /**
     * @return void
     */
    public function testARowPerStoreViewWhereTheyDiffer(): void
    {
        $this->importer(categories: [
            20 => [1 => 'INDEX,NOFOLLOW', 2 => null, 3 => 'NOINDEX,NOFOLLOW'],
            21 => [1 => null, 2 => 'INDEX,FOLLOW,noarchive'],
        ])->import();

        $this->assertSame(
            [
                ['category', 20, 1, 'INDEX,NOFOLLOW'],
                ['category', 20, 3, 'NOINDEX,NOFOLLOW'],
                ['category', 21, 2, 'INDEX,FOLLOW,noarchive'],
            ],
            $this->saved,
            'A store view with no flag gets no row, and none is global.'
        );
    }

    /**
     * @return void
     */
    public function testAnEntityWithNoFlagOnAnyOfItsStoreViewsIsLeftAlone(): void
    {
        $report = $this->importer(products: [10 => [1 => null, 2 => null]])->import();

        $this->assertSame([], $this->saved);
        $this->assertSame(0, $report['written']['product']);
    }

    /**
     * @return void
     */
    public function testAGlobalValueSetHereIsKeptAndReported(): void
    {
        $this->stored['product'][10][0] = 'INDEX,NOFOLLOW';
        $this->stored['product'][11][0] = 'NOINDEX,FOLLOW';

        $report = $this->importer(products: [
            10 => [1 => 'NOINDEX,FOLLOW'],
            11 => [1 => 'NOINDEX,FOLLOW'],
        ])->import();

        $this->assertSame([], $this->saved);
        $this->assertSame(0, $report['written']['product']);
        $this->assertSame(
            [
                'Product 10, all store views: kept "INDEX,NOFOLLOW"; MageOS_MetaRobotsTag\'s flags give'
                . ' "NOINDEX,FOLLOW".',
            ],
            $report['notes'],
            'The same value already here is no conflict.'
        );
    }

    /**
     * A global row is written, but a store view with its own, different row keeps it.
     *
     * @return void
     */
    public function testAStoreViewsOwnRowIsKeptUnderAnImportedGlobalRow(): void
    {
        $this->stored['product'][10][1] = 'INDEX,FOLLOW';
        $this->stored['product'][11][1] = 'NOINDEX,FOLLOW';

        $report = $this->importer(products: [
            10 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,FOLLOW'],
            11 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,FOLLOW'],
        ])->import();

        $this->assertSame([['product', 10, 0, 'NOINDEX,FOLLOW'], ['product', 11, 0, 'NOINDEX,FOLLOW']], $this->saved);
        $this->assertSame(
            ['Product 10, store view 1: kept "INDEX,FOLLOW"; MageOS_MetaRobotsTag\'s flags give "NOINDEX,FOLLOW".'],
            $report['notes']
        );
    }

    /**
     * Each store view's robots are read once for a chunk of entities, the global row's included.
     *
     * @return void
     */
    public function testEachStoreViewsRobotsAreReadOnce(): void
    {
        $this->importer(products: [
            10 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,NOFOLLOW'],
            11 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,NOFOLLOW'],
        ])->import();

        $this->assertSame([0 => 1, 1 => 1, 2 => 1], $this->reads);
    }

    /**
     * Every category's own robots are read, not only the first's.
     *
     * @return void
     */
    public function testEveryCategorysOwnRobotsAreRead(): void
    {
        $this->stored['category'][21][0] = 'INDEX,NOFOLLOW';

        $report = $this->importer(categories: [
            20 => [1 => 'NOINDEX,FOLLOW'],
            21 => [1 => 'NOINDEX,FOLLOW'],
        ])->import();

        $this->assertSame([['category', 20, 0, 'NOINDEX,FOLLOW']], $this->saved);
        $this->assertCount(1, $report['notes']);
    }

    /**
     * A conflicting global row is reported once, not again for each store view inheriting it.
     *
     * @return void
     */
    public function testAKeptGlobalRowIsReportedOnce(): void
    {
        $this->stored['product'][10][0] = 'INDEX,NOFOLLOW';
        $this->stored['product'][10][2] = 'INDEX,NOFOLLOW';

        $report = $this->importer(products: [10 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,FOLLOW']])->import();

        $this->assertCount(1, $report['notes']);
    }

    /**
     * @return void
     */
    public function testAGlobalRowAStoreViewInheritsIsKeptForEachStoreView(): void
    {
        $this->stored['product'][10][0] = 'INDEX,FOLLOW';

        $report = $this->importer(products: [10 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,NOFOLLOW']])->import();

        $this->assertSame([], $this->saved);
        $this->assertSame(
            [
                'Product 10, store view 1: kept "INDEX,FOLLOW" (set for all store views); MageOS_MetaRobotsTag\'s'
                . ' flags give "NOINDEX,FOLLOW".',
                'Product 10, store view 2: kept "INDEX,FOLLOW" (set for all store views); MageOS_MetaRobotsTag\'s'
                . ' flags give "NOINDEX,NOFOLLOW".',
            ],
            $report['notes']
        );
    }

    /**
     * @return void
     */
    public function testAStoreViewsOwnRowIsKeptWhereARowPerStoreViewIsImported(): void
    {
        $this->stored['cms_page'][30][2] = 'NOINDEX,FOLLOW';

        $report = $this->importer(cmsPages: [30 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,NOFOLLOW']])->import();

        $this->assertSame([['cms_page', 30, 1, 'NOINDEX,FOLLOW']], $this->saved);
        $this->assertSame(1, $report['written']['cms_page']);
        $this->assertSame(
            [
                'CMS page 30, store view 2: kept "NOINDEX,FOLLOW"; MageOS_MetaRobotsTag\'s flags give'
                . ' "NOINDEX,NOFOLLOW".',
                'CMS page 30: the directives differ by store view, so they are set per store view, which its admin'
                . ' form does not show: store view 1 "NOINDEX,FOLLOW".',
            ],
            $report['notes']
        );
    }

    /**
     * Only a CMS page's store view rows are reported as hidden: the other forms have a store switcher.
     *
     * @return void
     */
    public function testOnlyACmsPagesStoreViewRowsAreReportedAsNotShown(): void
    {
        $report = $this->importer(
            products: [10 => [1 => 'NOINDEX,FOLLOW', 2 => null]],
            categories: [20 => [1 => 'NOINDEX,FOLLOW', 2 => null]],
            cmsPages: [30 => [1 => 'NOINDEX,FOLLOW', 2 => 'NOINDEX,FOLLOW'], 31 => [1 => 'NOINDEX,FOLLOW', 2 => null]]
        )->import();

        $this->assertSame(
            [
                'CMS page 31: the directives differ by store view, so they are set per store view, which its admin'
                . ' form does not show: store view 1 "NOINDEX,FOLLOW".',
            ],
            $report['notes']
        );
        $this->assertSame(['product' => 1, 'category' => 1, 'cms_page' => 2], $report['written']);
    }

    /**
     * @return void
     */
    public function testADryRunCountsAndReportsButWritesNothing(): void
    {
        $this->stored['category'][20][0] = 'INDEX,FOLLOW';

        $report = $this->importer(
            products: [10 => [1 => 'NOINDEX,FOLLOW']],
            categories: [20 => [1 => 'NOINDEX,FOLLOW']]
        )->import(true);

        $this->assertSame([], $this->saved);
        $this->assertSame(['product' => 1, 'category' => 0, 'cms_page' => 0], $report['written']);
        $this->assertCount(1, $report['notes']);
    }

    /**
     * The whole import is one page cache purge.
     *
     * @return void
     */
    public function testTheImportIsOnePurgeBatch(): void
    {
        $purger = $this->createMock(Purger::class);
        $purger->expects($this->once())->method('batch')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        $this->importer(products: [10 => [1 => 'NOINDEX,FOLLOW']], purger: $purger)->import();

        $this->assertCount(1, $this->saved);
    }

    /**
     * @return void
     */
    public function testRobotsAreReadAChunkOfEntitiesAtATime(): void
    {
        $products = array_fill_keys(range(1, 1001), [1 => 'NOINDEX,FOLLOW']);

        $report = $this->importer(products: $products)->import();

        $this->assertSame(500, $this->largestRead);
        $this->assertSame(1001, $report['written']['product']);
    }

    /**
     * @return void
     */
    public function testTheReportIsWordedWhenSomethingWasWritten(): void
    {
        $importer = $this->importer();

        $nothing = ['written' => ['product' => 0, 'category' => 0, 'cms_page' => 0], 'notes' => ['Kept one.']];
        $this->assertSame([], $importer->describe($nothing, false));
        $this->assertSame(
            ['Imported robots settings for 2 product(s), 1 category and 0 CMS page(s).'],
            $importer->describe(['written' => ['product' => 2, 'category' => 1, 'cms_page' => 0], 'notes' => []], false)
        );
        $this->assertSame(
            ['Would import robots settings for 0 product(s), 3 categories and 1 CMS page(s).'],
            $importer->describe(['written' => ['product' => 0, 'category' => 3, 'cms_page' => 1], 'notes' => []], true)
        );
    }

    /**
     * @return void
     */
    public function testLegacyDataIsWhatTheDirectivesFind(): void
    {
        $directives = $this->createStub(MetaRobotsTagDirectives::class);
        $directives->method('exist')->willReturn(true);

        $this->assertTrue($this->importer(directives: $directives)->hasLegacyData());
    }

    /**
     * The importer over in-memory repositories.
     *
     * @param array<int, array<int, string|null>> $products
     * @param array<int, array<int, string|null>> $categories
     * @param array<int, array<int, string|null>> $cmsPages
     * @param Purger|null $purger
     * @param MetaRobotsTagDirectives|null $directives
     * @return MetaRobotsTagImporter
     */
    private function importer(
        array $products = [],
        array $categories = [],
        array $cmsPages = [],
        ?Purger $purger = null,
        ?MetaRobotsTagDirectives $directives = null
    ): MetaRobotsTagImporter {
        if ($directives === null) {
            $directives = $this->createStub(MetaRobotsTagDirectives::class);
            $directives->method('products')->willReturn($products);
            $directives->method('categories')->willReturn($categories);
            $directives->method('cmsPages')->willReturn($cmsPages);
        }

        $productRepository = $this->createStub(OverrideRepository::class);
        $productRepository->method('getForProducts')->willReturnCallback(
            function (array $ids, int $storeId): array {
                $this->reads[$storeId] = ($this->reads[$storeId] ?? 0) + 1;

                return $this->rows('product', $ids, $storeId);
            }
        );
        $productRepository->method('save')->willReturnCallback(
            function (int $id, int $storeId, array $data): void {
                $this->saved[] = ['product', $id, $storeId, $data['robots_meta']];
            }
        );

        $categoryRepository = $this->createStub(CategoryConfigRepository::class);
        $categoryRepository->method('getForCategory')->willReturnCallback(
            fn (int $id, array $path, int $storeId): array => $path === []
                ? $this->rows('category', [$id], $storeId)[$id]
                : ['robots_meta' => 'an ancestor\'s']
        );
        $categoryRepository->method('save')->willReturnCallback(
            function (int $id, array $data, int $storeId): void {
                $this->saved[] = ['category', $id, $storeId, $data['robots_meta']];
            }
        );

        $cmsPageRepository = $this->createStub(CmsPageConfigRepository::class);
        $cmsPageRepository->method('getForPages')->willReturnCallback(
            fn (array $ids, int $storeId): array => $this->rows('cms_page', $ids, $storeId)
        );
        $cmsPageRepository->method('save')->willReturnCallback(
            function (int $id, array $data, int $storeId): void {
                $this->saved[] = ['cms_page', $id, $storeId, $data['robots_meta']];
            }
        );

        if ($purger === null) {
            $purger = $this->createStub(Purger::class);
            $purger->method('batch')->willReturnCallback(static fn (callable $work): mixed => $work());
        }

        return new MetaRobotsTagImporter(
            $directives,
            $productRepository,
            $categoryRepository,
            $cmsPageRepository,
            $purger
        );
    }

    /**
     * Rows as the repositories return them: the store view's robots, else the global row's.
     *
     * @param string $type
     * @param int[] $ids
     * @param int $storeId
     * @return array<int, array{robots_meta: string|null}>
     */
    private function rows(string $type, array $ids, int $storeId): array
    {
        $this->largestRead = max($this->largestRead, \count($ids));

        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = [
                'robots_meta' => $this->stored[$type][$id][$storeId] ?? $this->stored[$type][$id][0] ?? null,
            ];
        }

        return $rows;
    }
}
