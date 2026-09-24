<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Sitemap\Model\SitemapItem as CoreSitemapItem;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;

/**
 * Core's sitemap item, plus the entity it lists and a data bag.
 *
 * Extends core's item rather than re-implementing it, so the five core fields behave exactly as
 * core's generator expects.
 */
class SitemapItem extends CoreSitemapItem implements SitemapItemInterface
{
    /**
     * @var array<string,object>
     */
    private array $dataBag = [];

    /**
     * The first five as core's item documents them.
     *
     * `$images` leads with `array` because core documents it so, and Magento's constructor check —
     * run by `setup:di:compile` — compares the first documented type of each argument passed to the
     * parent; anything else fails compilation. Core's own resource models pass a DataObject.
     *
     * @param string $url
     * @param string $priority
     * @param string $changeFrequency
     * @param string|null $updatedAt
     * @param array|\Magento\Framework\DataObject|null $images
     * @param string|null $entityType
     * @param int|null $entityId
     * @param array<string,object> $dataBag
     */
    public function __construct(
        $url,
        $priority,
        $changeFrequency,
        $updatedAt = null,
        $images = null,
        private readonly ?string $entityType = null,
        private readonly ?int $entityId = null,
        array $dataBag = []
    ) {
        parent::__construct($url, $priority, $changeFrequency, $updatedAt, $images);
        $this->setDataBag($dataBag);
    }

    /**
     * @inheritdoc
     */
    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    /**
     * @inheritdoc
     */
    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    /**
     * @inheritdoc
     */
    public function getDataBag(): array
    {
        return $this->dataBag;
    }

    /**
     * @inheritdoc
     */
    public function setDataBag(array $dataBag): void
    {
        foreach ($dataBag as $key => $value) {
            if (!\is_string($key) || !\is_object($value)) {
                throw new \InvalidArgumentException(
                    'A sitemap item data bag maps extension codes to objects; got '
                    . get_debug_type($key) . ' => ' . get_debug_type($value) . '.'
                );
            }
        }

        $this->dataBag = $dataBag;
    }

    /**
     * @inheritdoc
     */
    public function updateDataBag(string $key, object $value): void
    {
        $this->dataBag[$key] = $value;
    }
}
