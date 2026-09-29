<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Pool;

use MageOS\Seo\Model\Pool\HandleMatcher;
use PHPUnit\Framework\TestCase;

class HandleMatcherTest extends TestCase
{
    /**
     * @var HandleMatcher
     */
    private HandleMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new HandleMatcher();
    }

    public function testWildcardMatchesAnyActiveHandles(): void
    {
        $this->assertTrue($this->matcher->matches(['*'], ['cms_page_view']));
    }

    public function testWildcardMatchesEvenWithNoActiveHandles(): void
    {
        $this->assertTrue($this->matcher->matches(['*'], []));
    }

    public function testExactHandleMatch(): void
    {
        $this->assertTrue(
            $this->matcher->matches(['catalog_product_view'], ['default', 'catalog_product_view'])
        );
    }

    public function testPartialOverlapMatches(): void
    {
        $this->assertTrue(
            $this->matcher->matches(
                ['catalog_product_view', 'catalog_category_view'],
                ['default', 'catalog_category_view', 'catalog_category_view_id_3']
            )
        );
    }

    public function testNoOverlapDoesNotMatch(): void
    {
        $this->assertFalse(
            $this->matcher->matches(['catalog_product_view'], ['default', 'cms_index_index'])
        );
    }

    public function testEmptyProviderHandlesDoNotMatch(): void
    {
        $this->assertFalse($this->matcher->matches([], ['catalog_product_view']));
    }

    public function testEmptyActiveHandlesDoNotMatchSpecificHandle(): void
    {
        $this->assertFalse($this->matcher->matches(['catalog_product_view'], []));
    }

    public function testWildcardIsKeptOffADeniedHandle(): void
    {
        $matcher = new HandleMatcher(['checkout_cart_index']);

        $this->assertFalse($matcher->matches(['*'], ['default', 'checkout_cart_index']));
    }

    public function testWildcardStillRunsOnPagesThatAreNotDenied(): void
    {
        $matcher = new HandleMatcher(['checkout_cart_index']);

        $this->assertTrue($matcher->matches(['*'], ['default', 'cms_index_index']));
    }

    public function testATrailingStarDeniesTheWholeFamily(): void
    {
        $matcher = new HandleMatcher(['checkout_*']);

        $this->assertFalse($matcher->matches(['*'], ['default', 'checkout_onepage_success']));
        $this->assertFalse($matcher->matches(['*'], ['default', 'checkout_index_index']));
    }

    public function testATrailingStarDoesNotDenyAnUnrelatedHandleSharingNoPrefix(): void
    {
        $matcher = new HandleMatcher(['customer_*']);

        $this->assertTrue($matcher->matches(['*'], ['default', 'catalog_product_view']));
    }

    public function testAProviderThatNamesADeniedHandleStillRuns(): void
    {
        // Naming the handle is asking to be there; only wildcards are suppressed.
        $matcher = new HandleMatcher(['checkout_cart_index']);

        $this->assertTrue(
            $matcher->matches(['checkout_cart_index'], ['default', 'checkout_cart_index'])
        );
    }

    public function testAnEmptyDenyListLeavesWildcardsAlone(): void
    {
        $this->assertTrue($this->matcher->matches(['*'], ['default', 'checkout_cart_index']));
    }
}
