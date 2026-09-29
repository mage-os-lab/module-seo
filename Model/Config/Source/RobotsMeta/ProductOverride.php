<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source\RobotsMeta;

use MageOS\Seo\Model\Config\Source\RobotsMeta;

/**
 * The robots directives for a single product's override.
 *
 * A product with no override follows the store's Product Pages default. It does not look at its
 * categories: a category's override applies to category pages only.
 */
class ProductOverride extends RobotsMeta
{
    /**
     * @inheritdoc
     */
    protected function emptyLabel(): string
    {
        return (string) __("Use the store's Product Pages default");
    }
}
