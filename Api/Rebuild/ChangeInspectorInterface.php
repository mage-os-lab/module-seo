<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

use Magento\Catalog\Model\Product;
use Magento\Framework\Model\AbstractModel;

/**
 * Answers what a save changed, for a rebuild policy deciding whether the change can affect its
 * documents.
 *
 * @api
 */
interface ChangeInspectorInterface
{
    /**
     * Whether the saved entity was created by this save.
     *
     * @param AbstractModel $entity
     * @return bool
     */
    public function isNew(AbstractModel $entity): bool;

    /**
     * Whether the product's website assignments changed with this save.
     *
     * @param Product $product
     * @return bool
     */
    public function websitesChanged(Product $product): bool;
}
