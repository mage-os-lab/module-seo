<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Faq;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\Faq\GroupReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading FAQ groups as the table source serves them: a group's active entries in order, and the
 * group identifiers in use. Entries are saved through the service-contract repository.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class GroupReaderTest extends TestCase
{
    /**
     * @var FaqRepositoryInterface|null
     */
    private ?FaqRepositoryInterface $repository = null;

    /**
     * @var GroupReader|null
     */
    private ?GroupReader $groupReader = null;

    protected function setUp(): void
    {
        parent::setUp();
        $objectManager     = Bootstrap::getObjectManager();
        $this->repository  = $objectManager->get(FaqRepositoryInterface::class);
        $this->groupReader = $objectManager->get(GroupReader::class);
    }

    public function testAGroupsActiveEntriesComeBackInSortOrder(): void
    {
        // Saved out of order, so passing means the sort_order is what ordered them.
        $this->repository->save($this->faq('faqgrp', 'Second', 2));
        $this->repository->save($this->faq('faqgrp', 'First', 1));
        $inactive = $this->faq('faqgrp', 'Hidden', 3);
        $inactive->setIsActive(false);
        $this->repository->save($inactive);

        $questions = array_column($this->groupReader->getByIdentifier('faqgrp', 1), 'question');

        $this->assertSame(['First', 'Second'], $questions);
        $this->assertNotContains('Hidden', $questions);
    }

    public function testEntriesSharingASortOrderComeBackInAStableOrder(): void
    {
        // Equal sort_order values leave the order to the storage engine unless something breaks
        // the tie, and this list is rendered into FAQPage JSON-LD that the page cache keeps.
        foreach (['Alpha', 'Bravo', 'Charlie'] as $question) {
            $this->repository->save($this->faq('tiegrp', $question, 5));
        }

        $questions = array_column($this->groupReader->getByIdentifier('tiegrp', 1), 'question');

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $questions);
    }

    public function testIdentifiersAreListedOnceEachAndSorted(): void
    {
        $this->repository->save($this->faq('zz-returns', 'Q1', 0));
        $this->repository->save($this->faq('zz-returns', 'Q2', 0));
        $inactive = $this->faq('zz-delivery', 'Q3', 0);
        $inactive->setIsActive(false);
        $this->repository->save($inactive);

        $identifiers = $this->groupReader->getIdentifiers();

        $this->assertSame(1, \count(array_keys($identifiers, 'zz-returns', true)));
        $this->assertContains('zz-delivery', $identifiers, 'A group whose entries are all inactive is still a group.');
        $this->assertSame(array_values(array_unique($identifiers)), $identifiers);
        $sorted = $identifiers;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $identifiers);
    }

    /**
     * An active entry for all store views.
     *
     * @param string $identifier
     * @param string $question
     * @param int $sortOrder
     * @return FaqInterface
     */
    private function faq(string $identifier, string $question, int $sortOrder): FaqInterface
    {
        /** @var Faq $faq */
        $faq = Bootstrap::getObjectManager()->create(Faq::class);
        $faq->setIdentifier($identifier);
        $faq->setStoreId(0);
        $faq->setQuestion($question);
        $faq->setAnswer('A');
        $faq->setSortOrder($sortOrder);
        $faq->setIsActive(true);

        return $faq;
    }
}
