<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category\Inheritance;

use MageOS\Seo\Api\CategoryConfigSourceOrderInterface;
use MageOS\Seo\Model\Category\Inheritance\CategoryFirstOrder;
use MageOS\Seo\Model\Category\Inheritance\OrderPool;
use MageOS\Seo\Model\Category\Inheritance\StoreFirstOrder;
use MageOS\Seo\Model\Config\Source\InheritanceStrategy;
use PHPUnit\Framework\TestCase;

class OrderPoolTest extends TestCase
{
    public function testAStrategyIsReturnedByItsConfiguredCode(): void
    {
        $pool = new OrderPool([
            'category_first' => new CategoryFirstOrder(),
            'store_first'    => new StoreFirstOrder(),
        ]);

        $this->assertInstanceOf(StoreFirstOrder::class, $pool->get('store_first'));
    }

    public function testAnUnregisteredCodeFailsLoudlyRatherThanFallingBack(): void
    {
        // Silently using the default would resolve every category by a rule the merchant did not
        // choose, and the pages would render — differently, with nothing to say why.
        $pool = new OrderPool(['category_first' => new CategoryFirstOrder()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/store_first/');

        $pool->get('store_first');
    }

    public function testTheFailureNamesWhatIsAvailable(): void
    {
        $pool = new OrderPool(['category_first' => new CategoryFirstOrder()]);

        $this->expectExceptionMessageMatches('/category_first/');

        $pool->get('nope');
    }

    public function testSomethingThatIsNotAStrategyIsNotOffered(): void
    {
        $pool = new OrderPool([
            'category_first' => new CategoryFirstOrder(),
            'broken'         => new \stdClass(),
        ]);

        $this->assertSame(['category_first'], array_keys($pool->getAll()));
    }

    public function testTheAdminDropdownIsBuiltFromTheRegisteredStrategies(): void
    {
        // So a third party registering a strategy in di.xml gets it in the dropdown without
        // touching system.xml or the source model.
        $pool = new OrderPool([
            'category_first' => new CategoryFirstOrder(),
            'store_first'    => new StoreFirstOrder(),
            'bespoke'        => $this->bespokeStrategy(),
        ]);

        $options = (new InheritanceStrategy($pool))->toOptionArray();

        $this->assertSame(
            ['category_first', 'store_first', 'bespoke'],
            array_column($options, 'value')
        );
        $this->assertSame('A shop-specific order', $options[2]['label']);
    }

    /**
     * A strategy of the kind a third party would register.
     *
     * @return CategoryConfigSourceOrderInterface
     */
    private function bespokeStrategy(): CategoryConfigSourceOrderInterface
    {
        return new class implements CategoryConfigSourceOrderInterface {
            /**
             * @inheritdoc
             */
            public function order(array $categoryChain, int $storeId): array
            {
                return [];
            }

            /**
             * @inheritdoc
             */
            public function getLabel(): \Magento\Framework\Phrase
            {
                return __('A shop-specific order');
            }
        };
    }
}
