<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Service\CurrencyService;

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
     * @param Config $seoConfig
     * @param DateTime $dateTime
     * @param OfferEnricherPool $offerEnricherPool
     * @return OfferBuilder
     */
    private function offerBuilder(
        StoreManagerInterface $storeManager,
        CurrencyService $currencyService,
        AvailabilityResolver $availabilityResolver,
        Config $seoConfig,
        DateTime $dateTime,
        OfferEnricherPool $offerEnricherPool
    ): OfferBuilder {
        return new OfferBuilder(
            $storeManager,
            $currencyService,
            $availabilityResolver,
            $seoConfig,
            $dateTime,
            $offerEnricherPool,
            new ChildProducts($this->createStub(ConfigurableOptionsProviderInterface::class))
        );
    }
}
