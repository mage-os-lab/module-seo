<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Model\AbstractModel;

/**
 * What a save changed: the questions every invalidation policy asks of the entity an event carries.
 *
 * Shared by the sitemap's policy (Model\Sitemap\InvalidationPolicy) and those of the groups other
 * modules rebuild through the queue, so each answers "can this change what I show?" from the same
 * reading of a save.
 */
class ChangeInspector
{
    /**
     * Whether the saved entity was created by this save.
     *
     * EAV resources flag new entities with isObjectNew(); other models are treated as new
     * when they were never loaded (no original data).
     *
     * @param AbstractModel $entity
     * @return bool
     */
    public function isNew(AbstractModel $entity): bool
    {
        return $entity->isObjectNew() || $entity->getOrigData() === null;
    }

    /**
     * Whether any of the fields differs from its loaded value.
     *
     * @param AbstractModel $entity
     * @param string[] $fields
     * @return bool
     */
    public function anyChanged(AbstractModel $entity, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($entity->dataHasChangedFor($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the product's website assignments changed (same comparison as core's URL rewrite observer).
     *
     * @param Product $product
     * @return bool
     */
    public function websitesChanged(Product $product): bool
    {
        // Set by the product resource when it saves website links.
        if ($product->getData('is_changed_websites')) {
            return true;
        }

        $old = $product->getOrigData('website_ids');
        $new = $product->getWebsiteIds();
        if (!\is_array($old) || !\is_array($new)) {
            return false;
        }

        return array_diff($old, $new) !== [] || array_diff($new, $old) !== [];
    }

    /**
     * Whether a configuration save or delete changed a value under one of the path prefixes.
     *
     * The admin saves every field of a section whether it changed or not, so a save counts only
     * when the value differs from the stored one; a deletion always counts.
     *
     * @param string $eventName
     * @param ConfigValue $value
     * @param string[] $prefixes
     * @return bool
     */
    public function isChangedConfigUnder(string $eventName, ConfigValue $value, array $prefixes): bool
    {
        $path = (string) $value->getData('path');
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return str_ends_with($eventName, '_delete_after') || $value->isValueChanged();
            }
        }

        return false;
    }
}
