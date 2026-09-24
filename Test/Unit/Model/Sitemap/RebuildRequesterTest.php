<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use MageOS\Seo\Api\Sitemap\RebuildRequesterInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Sitemap\Rebuilder;
use MageOS\Seo\Model\Sitemap\RebuildRequester;
use PHPUnit\Framework\TestCase;

class RebuildRequesterTest extends TestCase
{
    public function testATypeAProviderHasIsQueued(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateSitemap')->with('blog-posts');

        $this->requester($invalidator)->request('blog-posts');
    }

    public function testEveryTypeIsQueuedAsOne(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateSitemap')->with(RebuildRequesterInterface::ALL_TYPES);

        $this->requester($invalidator)->request(RebuildRequesterInterface::ALL_TYPES);
    }

    public function testATypeNoProviderHasIsRefusedWhereItIsAskedFor(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateSitemap');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No sitemap provider has the type "blog-post". Types: pages, blog-posts');

        $this->requester($invalidator)->request('blog-post');
    }

    /**
     * A requester over a generator that writes pages and blog posts.
     *
     * @param FeedInvalidator $invalidator
     * @return RebuildRequester
     */
    private function requester(FeedInvalidator $invalidator): RebuildRequester
    {
        $rebuilder = $this->createStub(Rebuilder::class);
        $rebuilder->method('types')->willReturn(['pages', 'blog-posts']);
        $rebuilder->method('hasType')->willReturnCallback(
            static fn (string $type): bool => \in_array($type, ['*', 'pages', 'blog-posts'], true)
        );

        return new RebuildRequester($rebuilder, $invalidator);
    }
}
