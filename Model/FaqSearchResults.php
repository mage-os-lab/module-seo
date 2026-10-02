<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\Api\SearchResults;
use MageOS\Seo\Api\Data\FaqSearchResultsInterface;

/**
 * A page of FAQ entries from FaqRepositoryInterface::getList().
 *
 * Core's SearchResults does the work; this class exists so the object is a
 * FaqSearchResultsInterface, as core's CMS and MSI search results do.
 */
class FaqSearchResults extends SearchResults implements FaqSearchResultsInterface
{
}
