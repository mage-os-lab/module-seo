<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Observer\RemoveCmsPageConfigOnDelete;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted CMS page's configuration rows go with it; a failure is logged, never turned into an
 * error for the delete that already happened. That the rows' deletion purges the cached pages of
 * the page's translation group is the resource model's, tested end to end by
 * Test/Integration/Model/Hreflang/CmsTranslationGroupTest.
 */
class RemoveCmsPageConfigOnDeleteTest extends TestCase
{
    public function testThePagesRowsAreDeleted(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('deleteForPages')->with([12]);

        $this->observer($repository)->execute($this->event(['object' => $this->page(12)]));
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

    public function testSomethingOtherThanAPageIsIgnored(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('deleteForPages');

        $this->observer($repository)->execute($this->event(['object' => new \stdClass()]));
        $this->observer($repository)->execute($this->event([]));
    }

    public function testAPageWithoutAnIdIsIgnored(): void
    {
        // Nothing is logged: the guard stops it, not the catch below it.
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('deleteForPages');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->observer($repository, $logger)->execute($this->event(['object' => $this->page(null)]));
        $this->observer($repository, $logger)->execute($this->event(['object' => $this->page(0)]));
    }

    public function testAFailureIsLoggedWithThePageAndNotRethrown(): void
    {
        $failure    = new \RuntimeException('Deadlock');
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('deleteForPages')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'MageOS_Seo: could not remove the SEO configuration of deleted CMS page 12',
            ['exception' => $failure]
        );

        $this->observer($repository, $logger)->execute($this->event(['object' => $this->page(12)]));
    }

    /**
     * The observer, with stubs for whatever the test does not look at.
     *
     * @param ConfigRepository $repository
     * @param LoggerInterface|null $logger
     * @return RemoveCmsPageConfigOnDelete
     */
    private function observer(
        ConfigRepository $repository,
        ?LoggerInterface $logger = null
    ): RemoveCmsPageConfigOnDelete {
        return new RemoveCmsPageConfigOnDelete(
            $repository,
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
