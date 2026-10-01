<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\Data\FaqSearchResultsInterface;

/**
 * Reads, lists, saves and deletes FAQ entries.
 *
 * @api
 */
interface FaqRepositoryInterface
{
    /**
     * Load a FAQ entry by ID.
     *
     * @param int $entityId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function getById(int $entityId): FaqInterface;

    /**
     * FAQ entries matching the criteria: filters, sort orders and a page.
     *
     * Extension attributes other modules join in (a `<join>` in extension_attributes.xml) are
     * loaded with the entries.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \MageOS\Seo\Api\Data\FaqSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): FaqSearchResultsInterface;

    /**
     * Persist a FAQ entry.
     *
     * @param \MageOS\Seo\Api\Data\FaqInterface $faq
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @return \MageOS\Seo\Api\Data\FaqInterface
     */
    public function save(FaqInterface $faq): FaqInterface;

    /**
     * Delete a FAQ entry.
     *
     * @param \MageOS\Seo\Api\Data\FaqInterface $faq
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     * @return void
     */
    public function delete(FaqInterface $faq): void;

    /**
     * Delete a FAQ entry by ID.
     *
     * @param int $entityId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     * @return void
     */
    public function deleteById(int $entityId): void;
}
