<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer\Adminhtml;

use Magento\Cms\Model\Page;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Cms\HreflangGroup;
use MageOS\Seo\Model\Cms\TranslationGroupCache;
use MageOS\Seo\Observer\Adminhtml\SaveCmsPageSeoConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which values reach the repository, and when the translation group's other pages are refreshed.
 * That the admin form and this observer agree on the row is covered end to end by
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

    public function testChangingTheGroupRefreshesBothGroupsPages(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn('old-group');
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->once())->method('purge')->with(['old-group', 'new-group']);

        $this->observer($repository, null, $cache)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'new-group',
        ])));
    }

    public function testAnUnchangedGroupRefreshesNothing(): void
    {
        // Every save of the form posts the group back; only a change reaches other pages.
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn('about-us');
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->never())->method('purge');

        $this->observer($repository, null, $cache)->execute($this->eventFor($this->page(7, [
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
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->never())->method('purge');

        $this->observer($repository, $messages, $cache)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'about-us',
        ])));
    }

    public function testAFailedRefreshIsLoggedWithoutClaimingTheSaveFailed(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn(null);
        $cache = $this->createStub(TranslationGroupCache::class);
        $cache->method('purge')->willThrowException(new \RuntimeException('Varnish unreachable'));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addWarningMessage');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('could not refresh the translation group'));

        $this->observer($repository, $messages, $cache, $logger)->execute($this->eventFor($this->page(7, [
            SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP => 'about-us',
        ])));
    }

    /**
     * @param ConfigRepository $repository
     * @param ManagerInterface|null $messages
     * @param TranslationGroupCache|null $cache
     * @param LoggerInterface|null $logger
     * @return SaveCmsPageSeoConfig
     */
    private function observer(
        ConfigRepository $repository,
        ?ManagerInterface $messages = null,
        ?TranslationGroupCache $cache = null,
        ?LoggerInterface $logger = null
    ): SaveCmsPageSeoConfig {
        return new SaveCmsPageSeoConfig(
            $repository,
            new HreflangGroup(),
            $cache ?? $this->createStub(TranslationGroupCache::class),
            $messages ?? $this->createStub(ManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
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
