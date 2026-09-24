<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Setup\RecurringData;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecurringDataTest extends TestCase
{
    public function testEverySetupRunQueuesEveryFeedAndOnlyTheSitemapsThatHaveNoFile(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateLlms');
        $invalidator->expects($this->once())->method('invalidateJsonl');
        $invalidator->expects($this->once())->method('invalidateMissingSitemaps');
        // The rest live in pub/, which a deployment does not clear, and core's cron regenerates them.
        $invalidator->expects($this->never())->method('invalidateSitemap');

        (new RecurringData($invalidator, $this->createStub(LoggerInterface::class)))->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );
    }

    public function testAFailureIsLoggedAndNeverBreaksSetup(): void
    {
        $invalidator = $this->createStub(FeedInvalidator::class);
        $invalidator->method('invalidateLlms')->willThrowException(new \RuntimeException('no stores yet'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('no stores yet'));

        (new RecurringData($invalidator, $logger))->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );
    }
}
