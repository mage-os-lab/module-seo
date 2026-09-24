<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Sitemap;

use Magento\Framework\Model\AbstractModel;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Plugin\Sitemap\QueueFirstBuild;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueFirstBuildTest extends TestCase
{
    public function testAnEntryRebuiltOnChangeWithNoFileQueuesItsFirstBuild(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateMissingSitemaps');

        $this->afterSave($this->sitemaps(true, true), $invalidator);
    }

    public function testAnEntryThatHasItsFileQueuesNothing(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateMissingSitemaps');

        $this->afterSave($this->sitemaps(true, false), $invalidator);
    }

    public function testAnEntryNotRebuiltOnChangeQueuesNothing(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateMissingSitemaps');

        $this->afterSave($this->sitemaps(false, true), $invalidator);
    }

    public function testTheAnswerForThisRequestIsForgottenOnEverySave(): void
    {
        // Whether any sitemap is rebuilt on change is answered once per request; an entry saved
        // in it changes that answer.
        $sitemaps = $this->createMock(RebuildableSitemaps::class);
        $sitemaps->expects($this->once())->method('_resetState');

        $this->afterSave($sitemaps, $this->createStub(FeedInvalidator::class));
    }

    public function testAFailureIsLoggedAndTheSaveStands(): void
    {
        $sitemaps = $this->createStub(RebuildableSitemaps::class);
        $sitemaps->method('isRebuiltOnChange')->willThrowException(new \RuntimeException('no store'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('no store'));
        $result = $this->createStub(SitemapResource::class);

        $this->assertSame(
            $result,
            (new QueueFirstBuild($sitemaps, $this->createStub(FeedInvalidator::class), $logger))->afterSave(
                $this->createStub(SitemapResource::class),
                $result,
                $this->createStub(Sitemap::class)
            )
        );
    }

    public function testAnythingButASitemapIsLeftAlone(): void
    {
        $sitemaps = $this->createMock(RebuildableSitemaps::class);
        $sitemaps->expects($this->never())->method('isRebuiltOnChange');

        (new QueueFirstBuild(
            $sitemaps,
            $this->createStub(FeedInvalidator::class),
            $this->createStub(LoggerInterface::class)
        ))->afterSave(
            $this->createStub(SitemapResource::class),
            $this->createStub(SitemapResource::class),
            $this->createStub(AbstractModel::class)
        );
    }

    /**
     * Run the plugin after a sitemap's save.
     *
     * @param RebuildableSitemaps $sitemaps
     * @param FeedInvalidator $invalidator
     * @return void
     */
    private function afterSave(RebuildableSitemaps $sitemaps, FeedInvalidator $invalidator): void
    {
        (new QueueFirstBuild($sitemaps, $invalidator, $this->createStub(LoggerInterface::class)))->afterSave(
            $this->createStub(SitemapResource::class),
            $this->createStub(SitemapResource::class),
            $this->createStub(Sitemap::class)
        );
    }

    /**
     * Sitemaps whose answers about the saved entry are given.
     *
     * @param bool $rebuiltOnChange
     * @param bool $missing
     * @return RebuildableSitemaps
     */
    private function sitemaps(bool $rebuiltOnChange, bool $missing): RebuildableSitemaps
    {
        $sitemaps = $this->createStub(RebuildableSitemaps::class);
        $sitemaps->method('isRebuiltOnChange')->willReturn($rebuiltOnChange);
        $sitemaps->method('isMissing')->willReturn($missing);

        return $sitemaps;
    }
}
