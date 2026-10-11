<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\Product\FinalPriceInterface;
use MageOS\Seo\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * A product's final price as the page shows it, in the display currency, or null when it is not
 * known.
 *
 * The one place the structured data (Model\Product\OfferBuilder), Open Graph
 * (MetaTag\Provider\ProductMetaProvider) and MageOS_Aeo's /llms.jsonl decide whether a price can be
 * published, and which. An unknown price is left out rather than written as 0: schema.org and Google
 * read "price": 0 as free, and Google's merchant listings refuse it.
 *
 * The price is the final price's amount (getAmount()), the one core's price box renders: with the
 * adjustments the store displays, so including tax where prices are shown including tax or both
 * ways, and excluding it where catalog prices include tax but are shown without (issue #40). Google
 * expects the structured data's price to match the visible one. PriceInfo amounts are already in
 * the current (display) currency, and are used as they are.
 *
 * Unknown means:
 * - the lookup threw: logged with the SKU, so the cause is found rather than published as free;
 * - a price below 0;
 * - a composite product (configurable, grouped, bundle) priced 0. Core prices such a product from
 *   its options, and casts "no option to price it" to 0: an out-of-stock configurable has no
 *   saleable child, so ConfigurablePriceResolver returns (float) null;
 * - any other product priced 0, unless Publish a Price of Zero is on (issue #41). A price of 0 is
 *   usually one hidden from the visitor, as on B2B and trade stores, rather than a free product.
 */
class FinalPrice implements FinalPriceInterface
{
    /**
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config          $config
    ) {
    }

    /**
     * The product's final price as the page shows it, or null when it is not known.
     *
     * @param ProductInterface $product
     * @return float|null
     */
    public function get(ProductInterface $product): ?float
    {
        /** @var \Magento\Catalog\Model\Product $product */
        try {
            $value = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        } catch (\Exception $e) {
            $this->logger->error(
                \sprintf(
                    'MageOS_Seo: could not read the final price of product "%s": %s',
                    $product->getSku(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return null;
        }

        if ($value > 0.0) {
            return $value;
        }
        if ($value < 0.0 || $product->getTypeInstance()->isComposite($product)) {
            return null;
        }

        return $this->config->isZeroPricePublished() ? $value : null;
    }
}
