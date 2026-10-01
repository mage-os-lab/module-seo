<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * A page of FAQ entries from FaqRepositoryInterface::getList(), with the total that matched.
 *
 * @api
 */
interface FaqSearchResultsInterface extends SearchResultsInterface
{
    /**
     * The entries on this page.
     *
     * @return \MageOS\Seo\Api\Data\FaqInterface[]
     */
    public function getItems();

    /**
     * Set the entries on this page.
     *
     * @param \MageOS\Seo\Api\Data\FaqInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
