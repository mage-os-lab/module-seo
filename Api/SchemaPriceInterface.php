<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * A price as structured data and the feeds publish it.
 *
 * schema.org, Google's merchant listings and the llms.jsonl feed all read a price as a plain
 * number: two decimals, a point, no grouping, whatever the store's locale shows. The amounts are
 * already in the display currency (Product\FinalPriceInterface gives them so); this converts nothing.
 *
 * @api
 */
interface SchemaPriceInterface
{
    /**
     * The amount as published: two decimals, a point, no grouping (1234.5 is "1234.50").
     *
     * @param float $amount
     * @return string
     */
    public function amount(float $amount): string;

    /**
     * The ISO code of the currency the current store view shows prices in.
     *
     * @return string
     */
    public function currencyCode(): string;
}
