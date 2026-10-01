<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\FaqExtensionInterface;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\Data\FaqSearchResultsInterface;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\FaqRepository;
use PHPUnit\Framework\TestCase;

/**
 * The service-contract repository. Reading a group for display is Faq\GroupReader's
 * (Test\Integration\Model\Faq\GroupReaderTest).
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class FaqRepositoryTest extends TestCase
{
    /**
     * @var FaqRepositoryInterface
     */
    private ?FaqRepositoryInterface $repository = null;

    protected function setUp(): void
    {
        parent::setUp();
        $om = Bootstrap::getObjectManager();
        $this->repository = $om->get(FaqRepositoryInterface::class)
            ?? $om->create(FaqRepository::class);
    }

    private function newFaq(string $identifier, string $question, string $answer): FaqInterface
    {
        /** @var Faq $faq */
        $faq = Bootstrap::getObjectManager()->create(Faq::class);
        $faq->setIdentifier($identifier);
        $faq->setStoreId(0);
        $faq->setQuestion($question);
        $faq->setAnswer($answer);
        $faq->setSortOrder(0);
        $faq->setIsActive(true);
        return $faq;
    }

    public function testSaveAndGetByIdRoundTrip(): void
    {
        $faq   = $this->repository->save($this->newFaq('shipping', 'Ship to EU?', 'Yes.'));
        $loaded = $this->repository->getById($faq->getEntityId());

        $this->assertSame('shipping', $loaded->getIdentifier());
        $this->assertSame('Ship to EU?', $loaded->getQuestion());
        $this->assertSame('Yes.', $loaded->getAnswer());
        $this->assertTrue($loaded->getIsActive());
    }

    public function testGetByIdThrowsForMissing(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(999999);
    }

    public function testDeleteRemovesEntry(): void
    {
        $faq = $this->repository->save($this->newFaq('returns', 'Q', 'A'));
        $id  = $faq->getEntityId();
        $this->repository->delete($faq);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById($id);
    }

    public function testDeleteByIdRemovesEntry(): void
    {
        $faq = $this->repository->save($this->newFaq('returns', 'Q', 'A'));
        $id  = $faq->getEntityId();
        $this->repository->deleteById($id);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById($id);
    }

    public function testGetListFiltersSortsAndPages(): void
    {
        $group = 'getlist-' . uniqid();
        // Saved out of order, so the order that comes back is the sort's.
        foreach ([3 => 'Third', 1 => 'First', 2 => 'Second'] as $sortOrder => $question) {
            $this->repository->save($this->sorted($this->newFaq($group, $question, 'A'), $sortOrder));
        }
        $this->repository->save($this->newFaq('elsewhere-' . uniqid(), 'Not in the group', 'A'));

        $firstPage = $this->repository->getList($this->criteria($group, 2, 1));
        $lastPage  = $this->repository->getList($this->criteria($group, 2, 2));

        $this->assertInstanceOf(FaqSearchResultsInterface::class, $firstPage);
        $this->assertSame(3, $firstPage->getTotalCount(), 'The total counts every match, not one page.');
        $this->assertSame(['First', 'Second'], $this->questions($firstPage->getItems()));
        $this->assertSame(['Third'], $this->questions($lastPage->getItems()));
    }

    public function testGetListHandsBackTheCriteriaItWasGiven(): void
    {
        $criteria = $this->criteria('getlist-' . uniqid(), 10, 1);

        $this->assertSame($criteria, $this->repository->getList($criteria)->getSearchCriteria());
    }

    public function testGetListWithoutCriteriaFindsEverySavedEntry(): void
    {
        $saved = $this->repository->save($this->newFaq('getlist-' . uniqid(), 'Anyone?', 'A'));

        $criteria = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class)->create();
        $ids      = array_map(
            static fn (FaqInterface $faq): int => $faq->getEntityId(),
            $this->repository->getList($criteria)->getItems()
        );

        $this->assertContains($saved->getEntityId(), $ids);
    }

    public function testAFaqCarriesExtensionAttributes(): void
    {
        // Other modules add fields to the FAQ through extension_attributes.xml.
        $saved = $this->repository->save($this->newFaq('ext-' . uniqid(), 'Q', 'A'));

        $this->assertInstanceOf(
            FaqExtensionInterface::class,
            $this->repository->getById($saved->getEntityId())->getExtensionAttributes()
        );
    }

    /**
     * Criteria for one group, by sort order, a page at a time.
     *
     * @param string $identifier
     * @param int $pageSize
     * @param int $page
     * @return SearchCriteriaInterface
     */
    private function criteria(string $identifier, int $pageSize, int $page): SearchCriteriaInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $sortOrder     = $objectManager->create(SortOrderBuilder::class)
            ->setField('sort_order')
            ->setAscendingDirection()
            ->create();

        return $objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('identifier', $identifier)
            ->setSortOrders([$sortOrder])
            ->setPageSize($pageSize)
            ->setCurrentPage($page)
            ->create();
    }

    /**
     * @param FaqInterface[] $faqs
     * @return string[]
     */
    private function questions(array $faqs): array
    {
        return array_values(array_map(static fn (FaqInterface $faq): string => $faq->getQuestion(), $faqs));
    }

    /**
     * @param FaqInterface $faq
     * @param int $sortOrder
     * @return FaqInterface
     */
    private function sorted(FaqInterface $faq, int $sortOrder): FaqInterface
    {
        $faq->setSortOrder($sortOrder);

        return $faq;
    }
}
