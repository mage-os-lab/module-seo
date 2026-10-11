<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Model\Product\OfferSummary;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Test\Integration\Fixture\ProductAttribute as AttributeFixture;
use PHPUnit\Framework\TestCase;

/**
 * PLAN-G, "G2.3 follow-up": a page of configurables' variants read at once is what core's own path
 * gives one configurable at a time, and costs a handful of queries in place of ten a configurable.
 *
 * Core's path is ConfigurableOptionsProvider, which product pages keep. Parity is what matters: the
 * same variants (a disabled one filtered out as the storefront filters it, in the cron area too), and
 * the same offer summary (prices, a special price, availability).
 *
 * Open (PLAN-G): here the out-of-stock variant is kept by both paths alike; whether the storefront
 * filter leaves it out of a feed is not yet shown.
 *
 * @magentoAppArea crontab
 * @magentoDbIsolation enabled
 */
#[
    // An option for each of a configurable's three variants.
    DataFixture(AttributeFixture::class, ['options' => ['One', 'Two', 'Three']], 'attribute'),
    DataFixture(ProductFixture::class, ['price' => 10], 'a1'),
    DataFixture(ProductFixture::class, ['price' => 20, 'special_price' => 15], 'a2'),
    DataFixture(ProductFixture::class, ['price' => 30, 'status' => Status::STATUS_DISABLED], 'a3'),
    DataFixture(
        ConfigurableProductFixture::class,
        ['_options' => ['$attribute$'], '_links' => ['$a1$', '$a2$', '$a3$']],
        'a'
    ),
    DataFixture(
        ProductFixture::class,
        ['price' => 25, 'extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'b1'
    ),
    DataFixture(ProductFixture::class, ['price' => 35], 'b2'),
    DataFixture(ProductFixture::class, ['price' => 45], 'b3'),
    DataFixture(
        ConfigurableProductFixture::class,
        ['_options' => ['$attribute$'], '_links' => ['$b1$', '$b2$', '$b3$']],
        'b'
    ),
]
class ChildProductsPrefetchTest extends TestCase
{
    /**
     * @return void
     */
    public function testAPrefetchedPageGivesTheVariantsAndOfferOfCoresPath(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $parents       = [$this->product('a'), $this->product('b')];

        $coreChildren = $objectManager->create(ChildProducts::class);
        $coreSummary  = $objectManager->create(OfferSummary::class, ['childProducts' => $coreChildren]);
        $expected     = [];
        foreach ($parents as $parent) {
            $expected[$parent->getSku()] = [$this->ids($coreChildren->get($parent)), $coreSummary->forProduct($parent)];
        }

        $children = $objectManager->create(ChildProducts::class);
        $summary  = $objectManager->create(OfferSummary::class, ['childProducts' => $children]);
        $summary->prefetch($parents);
        $actual = [];
        foreach ($parents as $parent) {
            $actual[$parent->getSku()] = [$this->ids($children->get($parent)), $summary->forProduct($parent)];
        }

        $this->assertSame($expected, $actual);
        $this->assertSame(
            [$this->id('a1'), $this->id('a2')],
            $actual[$this->sku('a')][0],
            'The storefront filter leaves the disabled variant out.'
        );
    }

    /**
     * The page costs a few queries; each configurable's variants, their final prices and their stock
     * then cost none.
     *
     * @return void
     */
    public function testAPageCostsAFewQueriesAndItsVariantsNone(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $parents       = [$this->product('a'), $this->product('b')];
        $children      = $objectManager->create(ChildProducts::class);
        $summary       = $objectManager->create(OfferSummary::class, ['childProducts' => $children]);
        $finalPrice    = $objectManager->get(FinalPrice::class);
        $availability  = $objectManager->get(AvailabilityResolver::class);
        $profiler      = $objectManager->get(ResourceConnection::class)->getConnection()->getProfiler();

        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $summary->prefetch($parents);
            $pageQueries = (int) $profiler->getTotalNumQueries();

            $profiler->clear();
            foreach ($parents as $parent) {
                $variants = $children->get($parent);
                $availability->best($variants);
                foreach ($variants as $variant) {
                    $finalPrice->get($variant);
                }
            }
            $variantQueries = (int) $profiler->getTotalNumQueries();
        } finally {
            $profiler->setEnabled(false);
        }

        $this->assertLessThanOrEqual(6, $pageQueries, 'The page\'s variants and their stock.');
        $this->assertSame(0, $variantQueries, 'The variants were read again.');
    }

    /**
     * A fixture product as the store view loads it.
     *
     * @param string $fixture
     * @return ProductInterface
     */
    private function product(string $fixture): ProductInterface
    {
        return Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)
            ->get($this->sku($fixture), false, 1, true);
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function sku(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getSku();
    }

    /**
     * @param string $fixture
     * @return int
     */
    private function id(string $fixture): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
    }

    /**
     * The products' IDs, sorted.
     *
     * @param ProductInterface[] $products
     * @return int[]
     */
    private function ids(array $products): array
    {
        $ids = array_map(static fn (ProductInterface $product): int => (int) $product->getId(), $products);
        sort($ids);

        return $ids;
    }
}
