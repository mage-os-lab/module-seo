<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * The address the current store view publishes for automated queries.
 *
 * The Organization's Contact Email, else the store's Customer Support email unless it is still the
 * placeholder Magento ships, else none.
 *
 * @api
 */
interface ContactEmailInterface
{
    /**
     * The address, or '' when the store view has none to publish.
     *
     * @return string
     */
    public function get(): string;
}
