<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category\Inheritance;

use MageOS\Seo\Model\Category\Inheritance\CategoryFirstOrder;
use MageOS\Seo\Model\Category\Inheritance\StoreFirstOrder;
use PHPUnit\Framework\TestCase;

/**
 * The two shipped source orders, tested on ordering alone.
 *
 * Neither reads the database or decides a value, so the whole of each is the sequence it
 * produces — which is also the only thing that distinguishes them.
 */
class OrderStrategyTest extends TestCase
{
    public function testCategoryFirstExhaustsACategoryBeforeMovingUp(): void
    {
        $order = (new CategoryFirstOrder())->order([14, 5, 3], 2);

        $this->assertSame(
            [
                ['category_id' => 14, 'store_id' => 2],
                ['category_id' => 14, 'store_id' => 0],
                ['category_id' => 5, 'store_id' => 2],
                ['category_id' => 5, 'store_id' => 0],
                ['category_id' => 3, 'store_id' => 2],
                ['category_id' => 3, 'store_id' => 0],
            ],
            $order
        );
    }

    public function testStoreFirstExhaustsTheStoreViewBeforeFallingBackToGlobal(): void
    {
        $order = (new StoreFirstOrder())->order([14, 5, 3], 2);

        $this->assertSame(
            [
                ['category_id' => 14, 'store_id' => 2],
                ['category_id' => 5, 'store_id' => 2],
                ['category_id' => 3, 'store_id' => 2],
                ['category_id' => 14, 'store_id' => 0],
                ['category_id' => 5, 'store_id' => 0],
                ['category_id' => 3, 'store_id' => 0],
            ],
            $order
        );
    }

    public function testTheOrdersDisagreeOnlyAboutAnAncestorsStoreValueVersusAChildsGlobalOne(): void
    {
        // The single pair whose relative position differs is what the setting is for: whether a
        // parent's store-view setting outranks the child's own global one.
        $categoryFirst = (new CategoryFirstOrder())->order([14, 5], 2);
        $storeFirst    = (new StoreFirstOrder())->order([14, 5], 2);

        $this->assertSame(['category_id' => 14, 'store_id' => 2], $categoryFirst[0]);
        $this->assertSame(['category_id' => 14, 'store_id' => 2], $storeFirst[0]);

        $this->assertSame(['category_id' => 14, 'store_id' => 0], $categoryFirst[1]);
        $this->assertSame(['category_id' => 5, 'store_id' => 2], $storeFirst[1]);
    }

    public function testNeitherOrderOffersTheStoreScopeWhenThereIsNone(): void
    {
        // Store 0 is the only scope in play, so offering it twice would just be wasted lookups.
        $this->assertSame(
            [['category_id' => 14, 'store_id' => 0], ['category_id' => 5, 'store_id' => 0]],
            (new CategoryFirstOrder())->order([14, 5], 0)
        );
        $this->assertSame(
            [['category_id' => 14, 'store_id' => 0], ['category_id' => 5, 'store_id' => 0]],
            (new StoreFirstOrder())->order([14, 5], 0)
        );
    }

    public function testTheTwoAgreeEntirelyForASingleStoreShop(): void
    {
        $this->assertSame(
            (new CategoryFirstOrder())->order([14, 5, 3], 0),
            (new StoreFirstOrder())->order([14, 5, 3], 0)
        );
    }

    public function testACategoryWithNoAncestorsYieldsOnlyItsOwnScopes(): void
    {
        $this->assertSame(
            [['category_id' => 14, 'store_id' => 1], ['category_id' => 14, 'store_id' => 0]],
            (new CategoryFirstOrder())->order([14], 1)
        );
    }

    public function testBothOrdersDescribeThemselvesForTheAdminDropdown(): void
    {
        $this->assertNotSame('', (string) (new CategoryFirstOrder())->getLabel());
        $this->assertNotSame('', (string) (new StoreFirstOrder())->getLabel());
    }
}
