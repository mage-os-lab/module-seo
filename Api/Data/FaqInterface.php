<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * An FAQ entry: one question and its answer in a group, for a store view (0 = all store views).
 *
 * Extensible: other modules add fields through extension_attributes.xml.
 *
 * @api
 */
interface FaqInterface extends ExtensibleDataInterface
{
    public const ENTITY_ID  = 'entity_id';
    public const IDENTIFIER  = 'identifier';
    public const STORE_ID    = 'store_id';
    public const QUESTION    = 'question';
    public const ANSWER      = 'answer';
    public const SORT_ORDER  = 'sort_order';
    public const IS_ACTIVE   = 'is_active';

    /**
     * Get entity ID.
     *
     * @return int
     */
    public function getEntityId(): int;

    /**
     * Get the group identifier the FAQ belongs to.
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Set the group identifier.
     *
     * @param string $identifier
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setIdentifier(string $identifier): FaqInterface;

    /**
     * Get the store view ID (0 = all stores).
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Set the store view ID.
     *
     * @param int $storeId
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setStoreId(int $storeId): FaqInterface;

    /**
     * Get the question text.
     *
     * @return string
     */
    public function getQuestion(): string;

    /**
     * Set the question text.
     *
     * @param string $question
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setQuestion(string $question): FaqInterface;

    /**
     * Get the answer text.
     *
     * @return string
     */
    public function getAnswer(): string;

    /**
     * Set the answer text.
     *
     * @param string $answer
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setAnswer(string $answer): FaqInterface;

    /**
     * Get the sort order.
     *
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * Set the sort order.
     *
     * @param int $sortOrder
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setSortOrder(int $sortOrder): FaqInterface;

    /**
     * Whether the FAQ entry is enabled.
     *
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * Set the enabled flag.
     *
     * @param bool $isActive
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setIsActive(bool $isActive): FaqInterface;

    /**
     * The fields other modules add through extension_attributes.xml.
     *
     * @return \MageOS\Seo\Api\Data\FaqExtensionInterface|null
     */
    public function getExtensionAttributes(): ?FaqExtensionInterface;

    /**
     * Set the fields other modules add.
     *
     * @param \MageOS\Seo\Api\Data\FaqExtensionInterface $extensionAttributes
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function setExtensionAttributes(FaqExtensionInterface $extensionAttributes): FaqInterface;
}
