<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap\Fixture;

use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface;
use Magento\Sitemap\Model\SitemapItem;

/**
 * A provider as another module would write it: core's interface, registered on core's composite,
 * knowing nothing of this module.
 */
class RegisteredElsewhereProvider implements ItemProviderInterface
{
    public const URL = 'mageos-seo-registered-elsewhere.html';

    /**
     * @inheritdoc
     */
    public function getItems($storeId)
    {
        return [new SitemapItem(self::URL, '0.5', 'weekly')];
    }
}
