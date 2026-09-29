<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Setup\RecurringData;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecurringDataTest extends TestCase
{
    public function testEverySetupRunQueuesEveryRegisteredGroupAndOnlyTheSitemapsThatHaveNoFile(): void
    {
        $queued      = [];
        $invalidator = $this->createMock(Invalidator::class);
        $invalidator->method('invalidate')->willReturnCallback(
            static function (string $group) use (&$queued): void {
                $queued[] = $group;
            }
        );
        $invalidator->expects($this->once())->method('invalidateMissingSitemaps');
        // The rest live in pub/, which a deployment does not clear, and core's cron regenerates them.
        $invalidator->expects($this->never())->method('invalidateSitemap');

        (new RecurringData($invalidator, $this->pool(), $this->createStub(LoggerInterface::class)))->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        $this->assertSame(['one', 'two'], $queued);
    }

    public function testAFailureIsLoggedAndNeverBreaksSetup(): void
    {
        $invalidator = $this->createStub(Invalidator::class);
        $invalidator->method('invalidate')->willThrowException(new \RuntimeException('no stores yet'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('no stores yet'));

        (new RecurringData($invalidator, $this->pool(), $logger))->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );
    }

    /**
     * A pool with one handler owning the groups `one` and `two`.
     *
     * @return HandlerPool
     */
    private function pool(): HandlerPool
    {
        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);

        return new HandlerPool([$handler]);
    }
}
