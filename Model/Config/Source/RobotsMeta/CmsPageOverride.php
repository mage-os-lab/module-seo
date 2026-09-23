<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source\RobotsMeta;

use MageOS\Seo\Model\Config\Source\RobotsMeta;

/**
 * The robots directives for a single CMS page's override.
 *
 * A page with no override follows the store's CMS Pages default.
 */
class CmsPageOverride extends RobotsMeta
{
    /**
     * @inheritdoc
     */
    protected function emptyLabel(): string
    {
        return (string) __("Use the store's CMS Pages default");
    }
}
