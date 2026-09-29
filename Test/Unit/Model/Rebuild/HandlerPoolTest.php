<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HandlerPoolTest extends TestCase
{
    /**
     * @return void
     */
    public function testAGroupIsFoundByTheHandlerThatOwnsIt(): void
    {
        $first  = $this->handler(['one', 'two']);
        $second = $this->handler(['three']);
        $pool   = new HandlerPool(['first' => $first, 'second' => $second]);

        $this->assertSame($first, $pool->get('two'));
        $this->assertSame($second, $pool->get('three'));
        $this->assertNull($pool->get('four'));
        $this->assertSame(['one', 'two', 'three'], $pool->getGroups());
        $this->assertSame([$first, $second], $pool->getHandlers());
    }

    /**
     * @return void
     */
    public function testAnEmptyPoolOwnsNothing(): void
    {
        $pool = new HandlerPool();

        $this->assertNull($pool->get('llms'));
        $this->assertSame([], $pool->getGroups());
        $this->assertSame([], $pool->getHandlers());
    }

    /**
     * A group two handlers claim, or one named like a sitemap group, could only reach one of them.
     *
     * @return array<string, array{0: string[], 1: string[], 2: string}>
     */
    public static function refusedGroups(): array
    {
        return [
            'claimed twice'          => [['one'], ['one'], 'claimed by both'],
            'a sitemap type'         => [['sitemap-blog'], [], 'named like a sitemap group'],
            'the sitemaps first build' => [['sitemaps-missing'], [], 'named like a sitemap group'],
        ];
    }

    /**
     * @dataProvider refusedGroups
     * @param string[] $firstGroups
     * @param string[] $secondGroups
     * @param string $reason
     * @return void
     */
    #[DataProvider('refusedGroups')]
    public function testAGroupThatCannotBeRoutedIsRefused(array $firstGroups, array $secondGroups, string $reason): void
    {
        $pool = new HandlerPool([$this->handler($firstGroups), $this->handler($secondGroups)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($reason);
        $pool->getGroups();
    }

    /**
     * @return void
     */
    public function testTheGroupsAreAskedForOnce(): void
    {
        $handler = $this->createMock(GroupHandlerInterface::class);
        $handler->expects($this->once())->method('getGroups')->willReturn(['one']);
        $pool = new HandlerPool([$handler]);

        $pool->get('one');
        $pool->getGroups();
    }

    /**
     * @param string[] $groups
     * @return GroupHandlerInterface
     */
    private function handler(array $groups): GroupHandlerInterface
    {
        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn($groups);

        return $handler;
    }
}
