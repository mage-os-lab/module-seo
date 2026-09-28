<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * An offer's priceValidUntil on a real product page: a special price's end date, and nothing made up.
 *
 * The date is published when the special price has a value and its end date is today or later in
 * the store's time; special_to_date is inclusive, so the price holds for the whole of that day.
 * Without both there is no date at all.
 *
 * The products are created in the test rather than by attribute, because their dates are relative
 * to today; each is removed again in tearDown().
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class PriceValidUntilOutputTest extends AbstractController
{
    use ProductPageOutput;

    /**
     * The product the test created, removed in tearDown().
     *
     * @var DataObject|null
     */
    private ?DataObject $product = null;

    /**
     * Remove the test's product.
     *
     * Deleting is refused outside a secure area, and this test runs in the frontend area; the
     * #[DataFixture] machinery sets the same flag when it reverts its own fixtures.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->product !== null) {
            $registry = Bootstrap::getObjectManager()->get(Registry::class);
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);
            try {
                Bootstrap::getObjectManager()->get(ProductFixture::class)->revert($this->product);
            } finally {
                $registry->unregister('isSecureArea');
                $this->product = null;
            }
        }
        parent::tearDown();
    }

    public function testWithoutASpecialPriceThereIsNoPriceValidUntil(): void
    {
        $this->assertArrayNotHasKey('priceValidUntil', $this->offer([]));
    }

    public function testASpecialPriceWithAFutureEndDateGivesThatDate(): void
    {
        $end   = $this->day('+30 days');
        $offer = $this->offer(['special_price' => '9.99', 'special_to_date' => $end]);

        $this->assertSame($end, $offer['priceValidUntil'] ?? null);
    }

    public function testASpecialPriceEndingTodayStillGivesTheDate(): void
    {
        $today = $this->day('today');
        $offer = $this->offer(['special_price' => '9.99', 'special_to_date' => $today]);

        $this->assertSame($today, $offer['priceValidUntil'] ?? null);
    }

    public function testASpecialPriceWithoutAnEndDateGivesNoDate(): void
    {
        $this->assertArrayNotHasKey('priceValidUntil', $this->offer(['special_price' => '9.99']));
    }

    public function testASpecialPriceWhoseEndDateHasPassedGivesNoDate(): void
    {
        $offer = $this->offer(['special_price' => '9.99', 'special_to_date' => $this->day('-1 day')]);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAnEndDateWithoutASpecialPriceGivesNoDate(): void
    {
        $this->assertArrayNotHasKey('priceValidUntil', $this->offer(['special_to_date' => $this->day('+30 days')]));
    }

    /**
     * Create a simple product with the given special price attributes and return its page's offer.
     *
     * @param array<string,string> $special special_price and/or special_to_date
     * @return array<string,mixed>
     */
    private function offer(array $special): array
    {
        $this->product = Bootstrap::getObjectManager()->get(ProductFixture::class)->apply([
            'price'             => 12.5,
            'custom_attributes' => $special,
        ]);

        $this->dispatch('catalog/product/view/id/' . (int) $this->product->getId());
        $node = $this->productNode((string) $this->getResponse()->getBody());
        $this->assertSame('Product', $node['@type'] ?? null, 'No product JSON-LD on the page.');

        return $node['offers'] ?? [];
    }

    /**
     * A day relative to today in the store's time, as Y-m-d.
     *
     * @param string $modifier
     * @return string
     */
    private function day(string $modifier): string
    {
        return Bootstrap::getObjectManager()->get(TimezoneInterface::class)->date()->modify($modifier)->format('Y-m-d');
    }
}
