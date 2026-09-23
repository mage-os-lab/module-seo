<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer\Adminhtml;

use Magento\Cms\Model\Page;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Cms\HreflangGroup;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Observer\Adminhtml\SaveCmsPageSeoConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which values reach the repository, and when the hreflang sitemap is queued. That the admin form
 * and this observer agree on the row is covered end to end by
 * Test/Integration/Controller/Adminhtml/SeoFieldsetPersistenceTest.
 */
class SaveCmsPageSeoConfigTest extends TestCase
{
    public function testAPageSavedWithoutTheFieldsetIsLeftAlone(): void
    {
        // A REST call or an import: no posted SEO fields.
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer($repository)->execute($this->eventFor($this->page(7, ['title' => 'About'])));
    }

    public function testBothFieldsAreSavedToTheGlobalRowNormalised(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(
            7,
            ['robots_meta' => 'NOINDEX,FOLLOW', 'hreflang_group' => 'about-us']
        );

        $this->observer($repository)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_ROBOTS_META    => 'NOINDEX,FOLLOW',
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => ' About-Us ',
        ])));
    }

    public function testEmptyValuesAreStoredAsNothing(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(
            7,
            ['robots_meta' => null, 'hreflang_group' => null]
        );

        $this->observer($repository)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_ROBOTS_META    => '',
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => '',
        ])));
    }

    public function testAnInvalidGroupIsReportedAndNotSavedWhileTheRobotsValueIs(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(7, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with($this->stringContains('hreflang translation group was not'));

        $this->observer($repository, $messages)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_ROBOTS_META    => 'NOINDEX,FOLLOW',
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'about us!',
        ])));
    }

    public function testChangingTheGroupQueuesTheHreflangSitemap(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn('old-group');
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateHreflangSitemap');

        $this->observer($repository, null, $invalidator)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'new-group',
        ])));
    }

    public function testAnUnchangedGroupQueuesNothing(): void
    {
        // Every save of the form posts the group back; only a change reaches the sitemap.
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn('about-us');
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateHreflangSitemap');

        $this->observer($repository, null, $invalidator)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_ROBOTS_META    => 'NOINDEX,FOLLOW',
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'About-Us',
        ])));
    }

    public function testASaveFailureIsAWarningNotAnError(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('save')->willThrowException(new \RuntimeException('Deadlock'));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with($this->stringContains('SEO settings could not be saved'));
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateHreflangSitemap');

        $this->observer($repository, $messages, $invalidator)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'about-us',
        ])));
    }

    /**
     * @param ConfigRepository $repository
     * @param ManagerInterface|null $messages
     * @param FeedInvalidator|null $invalidator
     * @return SaveCmsPageSeoConfig
     */
    private function observer(
        ConfigRepository $repository,
        ?ManagerInterface $messages = null,
        ?FeedInvalidator $invalidator = null
    ): SaveCmsPageSeoConfig {
        return new SaveCmsPageSeoConfig(
            $repository,
            new HreflangGroup(),
            $invalidator ?? $this->createStub(FeedInvalidator::class),
            $messages ?? $this->createStub(ManagerInterface::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A saved CMS page carrying the given data, as core's save controller leaves it.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return Page
     */
    private function page(int $id, array $data): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('getData')->willReturnCallback(
            static fn ($key = '') => $data[$key] ?? null
        );

        return $page;
    }

    /**
     * @param object|null $page
     * @return Observer
     */
    private function eventFor(?object $page): Observer
    {
        return new Observer(['event' => new Event(['object' => $page])]);
    }
}
