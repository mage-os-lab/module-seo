<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

use Magento\Sitemap\Model\SitemapItemInterface as CoreSitemapItemInterface;

/**
 * A sitemap item that knows what it lists, and can carry more than a URL.
 *
 * Still a core sitemap item, so core's own sitemap generator can write it. On top of that it says
 * which entity it is — core's providers know, and used to throw it away — and carries a data bag
 * for whatever extensions add to its row.
 *
 * The entity types match the url_rewrite entity types core uses, so an item can be looked up
 * there directly. An item from a provider that does not know its entity has neither.
 *
 * @api
 */
interface SitemapItemInterface extends CoreSitemapItemInterface, DataBagInterface
{
    public const ENTITY_PRODUCT  = 'product';
    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_CMS_PAGE = 'cms-page';

    /**
     * The store view's home page, which is not an entity of its own.
     */
    public const ENTITY_STORE = 'store';

    /**
     * What the item lists: one of the ENTITY_* constants, or another module's own type.
     *
     * @return string|null Null when the provider does not know
     */
    public function getEntityType(): ?string;

    /**
     * The ID of the entity listed.
     *
     * @return int|null Null for the home page, and when the provider does not know
     */
    public function getEntityId(): ?int;
}
