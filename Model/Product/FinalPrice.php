<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;

/**
 * A product's final price in the display currency, or null when it is not known.
 *
 * The one place the structured data (Model\Product\OfferBuilder) and MageOS_Aeo's /llms.jsonl
 * decide whether a price can be published. An unknown price is left out rather than written as 0:
 * schema.org and Google read "price": 0 as free, and Google's merchant listings refuse it.
 *
 * Unknown means:
 * - the lookup threw: logged with the SKU, so the cause is found rather than published as free;
 * - a composite product (configurable, grouped, bundle) priced 0 or less. Core prices such a product
 *   from its options, and casts "no option to price it" to 0: an out-of-stock configurable has no
 *   saleable child, so ConfigurablePriceResolver returns (float) null.
 *
 * A simple product priced 0 is known: it is free. A fixed-price bundle priced 0 reads as unknown,
 * the one case the rule gets wrong; it is rare enough to accept.
 *
 * PriceInfo amounts are already in the current (display) currency: core RegularPrice, SpecialPrice
 * and CatalogRulePrice::getValue() convert with PriceCurrency. They are used as they are.
 */
class FinalPrice
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The product's final price, or null when it is not known.
     *
     * @param ProductInterface $product
     * @return float|null
     */
    public function get(ProductInterface $product): ?float
    {
        /** @var \Magento\Catalog\Model\Product $product */
        try {
            $value = (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
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

        if ($value <= 0.0 && $product->getTypeInstance()->isComposite($product)) {
            return null;
        }

        return $value;
    }
}
