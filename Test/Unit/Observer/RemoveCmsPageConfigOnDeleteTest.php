<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Cms\TranslationGroupCache;
use MageOS\Seo\Observer\RemoveCmsPageConfigOnDelete;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted CMS page's configuration rows go with it, and its translation group's cached pages are
 * purged; a failure is logged, never turned into an error for the delete that already happened.
 */
class RemoveCmsPageConfigOnDeleteTest extends TestCase
{
    /**
     * The calls made, in order.
     *
     * @var string[]
     */
    private array $calls = [];

    public function testTheGroupIsReadThenTheRowsDeletedThenTheGroupPurged(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('getHreflangGroup')->with(12)
            ->willReturnCallback(function (): string {
                $this->calls[] = 'read group';

                return 'about-us';
            });
        $repository->expects($this->once())->method('deleteForPages')->with([12])
            ->willReturnCallback(function (): int {
                $this->calls[] = 'delete rows';

                return 1;
            });
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->once())->method('purge')->with(['about-us'])
            ->willReturnCallback(function (): void {
                $this->calls[] = 'purge group';
            });

        $this->observer($repository, $cache)->execute($this->event(['object' => $this->page(12)]));

        $this->assertSame(['read group', 'delete rows', 'purge group'], $this->calls);
    }

    public function testThePageMayArriveAsPage(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('deleteForPages')->with([7]);

        $this->observer($repository)->execute($this->event(['page' => $this->page(7)]));
    }

    public function testThePageUnderObjectWinsOverOneUnderPage(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('deleteForPages')->with([12]);

        $this->observer($repository)->execute(
            $this->event(['object' => $this->page(12), 'page' => $this->page(7)])
        );
    }

    public function testAPageWithoutAGroupIsPurgedAsNoGroup(): void
    {
        // TranslationGroupCache::purge() ignores nulls: a page in no group purges nothing.
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getHreflangGroup')->willReturn(null);
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->once())->method('purge')->with([null]);

        $this->observer($repository, $cache)->execute($this->event(['object' => $this->page(12)]));
    }

    public function testSomethingOtherThanAPageIsIgnored(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('getHreflangGroup');
        $repository->expects($this->never())->method('deleteForPages');

        $this->observer($repository)->execute($this->event(['object' => new \stdClass()]));
        $this->observer($repository)->execute($this->event([]));
    }

    public function testAPageWithoutAnIdIsIgnored(): void
    {
        // Nothing is read either, and nothing logged: the guard stops it, not the catch below it.
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('getHreflangGroup');
        $repository->expects($this->never())->method('deleteForPages');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->observer($repository, null, $logger)->execute($this->event(['object' => $this->page(null)]));
        $this->observer($repository, null, $logger)->execute($this->event(['object' => $this->page(0)]));
    }

    public function testAFailureIsLoggedWithThePageAndNotRethrown(): void
    {
        $failure    = new \RuntimeException('Deadlock');
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('deleteForPages')->willThrowException($failure);
        $cache = $this->createMock(TranslationGroupCache::class);
        $cache->expects($this->never())->method('purge');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'MageOS_Seo: could not remove the SEO configuration of deleted CMS page 12',
            ['exception' => $failure]
        );

        $this->observer($repository, $cache, $logger)->execute($this->event(['object' => $this->page(12)]));
    }

    /**
     * The observer, with stubs for whatever the test does not look at.
     *
     * @param ConfigRepository $repository
     * @param TranslationGroupCache|null $cache
     * @param LoggerInterface|null $logger
     * @return RemoveCmsPageConfigOnDelete
     */
    private function observer(
        ConfigRepository $repository,
        ?TranslationGroupCache $cache = null,
        ?LoggerInterface $logger = null
    ): RemoveCmsPageConfigOnDelete {
        return new RemoveCmsPageConfigOnDelete(
            $repository,
            $cache ?? $this->createStub(TranslationGroupCache::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A CMS page with the ID.
     *
     * @param int|null $id
     * @return PageInterface
     */
    private function page(?int $id): PageInterface
    {
        $page = $this->createStub(PageInterface::class);
        $page->method('getId')->willReturn($id);

        return $page;
    }

    /**
     * The observer argument for an event carrying the data.
     *
     * @param array<string,mixed> $data
     * @return Observer
     */
    private function event(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }
}
