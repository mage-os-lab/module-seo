<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source\RobotsMeta;

use MageOS\Seo\Model\Config\Source\RobotsMeta;

/**
 * The robots directives for a single category's override.
 *
 * A category with no override inherits from the nearest parent category that has one, and failing
 * that follows the store's Category Pages default (see Model\Category\InheritanceResolver).
 */
class CategoryOverride extends RobotsMeta
{
    /**
     * @inheritdoc
     */
    protected function emptyLabel(): string
    {
        return (string) __("Inherit (parent category, then the store's Category Pages default)");
    }
}
