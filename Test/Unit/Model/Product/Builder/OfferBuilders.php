<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Service\CurrencyService;
use Psr\Log\LoggerInterface;

/**
 * A real OfferBuilder over a builder test's own collaborators, so the offer a template builder
 * emits is asserted end to end. The products in these tests are not configurable, so no children
 * are ever asked for.
 */
trait OfferBuilders
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param CurrencyService $currencyService
     * @param AvailabilityResolver $availabilityResolver
     * @param OfferEnricherPool $offerEnricherPool
     * @return OfferBuilder
     */
    private function offerBuilder(
        StoreManagerInterface $storeManager,
        CurrencyService $currencyService,
        AvailabilityResolver $availabilityResolver,
        OfferEnricherPool $offerEnricherPool
    ): OfferBuilder {
        return new OfferBuilder(
            $storeManager,
            $currencyService,
            $availabilityResolver,
            $offerEnricherPool,
            new ChildProducts($this->createStub(ConfigurableOptionsProviderInterface::class)),
            new FinalPrice($this->createStub(LoggerInterface::class), $this->createStub(Config::class))
        );
    }

    /**
     * A final price's amount as the store displays it: what FinalPrice publishes.
     *
     * @param float $value
     * @return AmountInterface
     */
    private function amount(float $value): AmountInterface
    {
        $amount = $this->createStub(AmountInterface::class);
        $amount->method('getValue')->willReturn($value);

        return $amount;
    }
}
