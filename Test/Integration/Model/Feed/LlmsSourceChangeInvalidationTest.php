<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\OrganizationRepository;
use PHPUnit\Framework\TestCase;

/**
 * The FAQs and the Organization feed /llms.txt and /llms-full.txt, so saving or deleting either
 * queues their rebuild however it is done — the admin form, the REST API, an import, a data patch.
 * The feeds learn of it from the models' own events rather than from a caller remembering to ask.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class LlmsSourceChangeInvalidationTest extends TestCase
{
    private const LLMS_PENDING = 'mageos_seo_feed_pending_llms';

    /**
     * Leave no pending request, and drop the Organizations the rolled-back saves left memoised.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag(self::LLMS_PENDING);
        Bootstrap::getObjectManager()->get(OrganizationRepository::class)->_resetState();
    }

    /**
     * @return void
     */
    public function testSavingAFaqThroughTheRepositoryQueuesLlms(): void
    {
        $faq = $this->newFaq();

        $this->assertQueuedBy(fn () => $this->faqs()->save($faq));
    }

    /**
     * @return void
     */
    public function testDeletingAFaqThroughTheRepositoryQueuesLlms(): void
    {
        $faq = $this->faqs()->save($this->newFaq());

        $this->assertQueuedBy(fn () => $this->faqs()->delete($faq));
    }

    /**
     * @return void
     */
    public function testSavingTheOrganizationThroughTheRepositoryQueuesLlms(): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get();
        $organization->setName('Makers Workshop');

        $this->assertQueuedBy(fn () => $repository->save($organization));
    }

    /**
     * Assert the change queues a rebuild of the llms documents.
     *
     * @param callable $change
     * @return void
     */
    private function assertQueuedBy(callable $change): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->deleteFlag(self::LLMS_PENDING);

        $change();

        $this->assertNotNull($flags->getFlagData(self::LLMS_PENDING), 'No rebuild of the llms documents was queued.');
    }

    /**
     * An active FAQ for every store view, not yet saved.
     *
     * @return FaqInterface
     */
    private function newFaq(): FaqInterface
    {
        /** @var Faq $faq */
        $faq = Bootstrap::getObjectManager()->create(Faq::class);
        $faq->setIdentifier('global');
        $faq->setStoreId(0);
        $faq->setQuestion('Do you ship worldwide?');
        $faq->setAnswer('An answer.');
        $faq->setSortOrder(0);
        $faq->setIsActive(true);

        return $faq;
    }

    /**
     * @return FaqRepositoryInterface
     */
    private function faqs(): FaqRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(FaqRepositoryInterface::class);
    }
}
