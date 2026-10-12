<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Api\Rebuild\ChangeInspectorInterface;

/**
 * What a save changed: the questions every invalidation policy asks of the entity an event carries.
 *
 * Shared by the sitemap's policy (Model\Sitemap\InvalidationPolicy) and those of the groups other
 * modules rebuild through the queue, so each answers "can this change what I show?" from the same
 * reading of a save.
 */
class ChangeInspector implements ChangeInspectorInterface
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
     * Whether the product's image gallery changed in what is shown of it.
     *
     * An image added or removed, or for an image kept, its caption, whether it is hidden, or its
     * place. Compared on those alone, each read as the storefront and the sitemap read it — the
     * store view's value, else the default's — because a gallery is saved in another shape than
     * it was loaded in: the admin's product form posts every image back, as strings, with keys of
     * its own and an empty "removed", whether anything changed or not. A save with no gallery data
     * leaves the gallery as it was.
     *
     * @param Product $product
     * @return bool
     */
    public function galleryChanged(Product $product): bool
    {
        $saved = $product->getData('media_gallery');
        if (!\is_array($saved)) {
            return false;
        }

        return $this->shownImages($product->getOrigData('media_gallery')) !== $this->shownImages($saved);
    }

    /**
     * The images of a gallery that are not removed, by file.
     *
     * Each with its caption, hidden flag and position as shown.
     *
     * @param mixed $gallery
     * @return array<string,array{string,int,int}>
     */
    private function shownImages(mixed $gallery): array
    {
        $images = \is_array($gallery) && \is_array($gallery['images'] ?? null) ? $gallery['images'] : [];

        $shown = [];
        foreach ($images as $image) {
            if (!\is_array($image) || !empty($image['removed']) || (string) ($image['file'] ?? '') === '') {
                continue;
            }
            $shown[(string) $image['file']] = [
                (string) $this->shown($image, 'label'),
                (int) $this->shown($image, 'disabled'),
                (int) $this->shown($image, 'position'),
            ];
        }
        ksort($shown);

        return $shown;
    }

    /**
     * An image's own value of a field, else its default's: the value shown.
     *
     * @param array<string,mixed> $image
     * @param string $field
     * @return mixed
     */
    private function shown(array $image, string $field): mixed
    {
        $value = $image[$field] ?? null;

        return $value === null || $value === '' ? ($image[$field . '_default'] ?? $value) : $value;
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
