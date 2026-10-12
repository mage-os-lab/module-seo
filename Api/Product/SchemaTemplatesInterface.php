<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Product;

/**
 * The product schema templates this store can use.
 *
 * @api
 */
interface SchemaTemplatesInterface
{
    /**
     * Every registered template: its code and its label.
     *
     * @return array<string, string> code => label
     */
    public function getAvailableTemplates(): array;
}
