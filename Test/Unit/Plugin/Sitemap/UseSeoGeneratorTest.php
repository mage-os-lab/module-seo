<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Sitemap;

use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Sitemap\Generator;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use MageOS\Seo\Plugin\Sitemap\UseSeoGenerator;
use PHPUnit\Framework\TestCase;

class UseSeoGeneratorTest extends TestCase
{
    public function testWithMagentoSelectedCoreGenerates(): void
    {
        $sitemap = $this->sitemap(3);
        $config  = $this->createStub(Config::class);
        $config->method('isSitemapGeneratorEnabled')->willReturnMap([[3, false]]);
        $generator = $this->createMock(Generator::class);
        $generator->expects($this->never())->method('generate');
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->never())->method('rebuiltWhole');

        $proceeded = false;
        $result    = $this->plugin($config, $generator, $problemLog)->aroundGenerateXml(
            $sitemap,
            static function () use (&$proceeded, $sitemap): Sitemap {
                $proceeded = true;
                return $sitemap;
            }
        );

        $this->assertTrue($proceeded);
        $this->assertSame($sitemap, $result);
    }

    public function testWithMageOsSeoSelectedCoreDoesNotRun(): void
    {
        $sitemap = $this->sitemap(3);
        $config  = $this->createStub(Config::class);
        $config->method('isSitemapGeneratorEnabled')->willReturnMap([[3, true]]);
        $generator = $this->createMock(Generator::class);
        $generator->expects($this->once())->method('generate')->with($sitemap);

        $result = $this->plugin($config, $generator)->aroundGenerateXml(
            $sitemap,
            function (): never {
                $this->fail('Core\'s generator ran as well.');
            }
        );

        $this->assertSame($sitemap, $result);
    }

    public function testAWholeSitemapWrittenSettlesItsProblemsInEverySitemapGroup(): void
    {
        // Core's cron and the Generate button write the whole file, so whatever the queue's
        // rebuilds of its parts left behind is settled by it.
        $settles    = null;
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuiltWhole')
            ->with('sitemap-*', 7, null, $this->callback(static function (callable $callback) use (&$settles): bool {
                $settles = $callback;
                return true;
            }));

        $this->plugin(problemLog: $problemLog)->aroundGenerateXml($this->sitemap(3, 7), $this->noCore(...));

        $this->assertIsCallable($settles);
        $this->assertTrue($settles('sitemap-products'));
        $this->assertTrue($settles(RebuildGroup::MISSING));
        $this->assertFalse($settles('jsonl'));
    }

    public function testAFailedWholeSitemapIsRecordedAndStillThrown(): void
    {
        $generator = $this->createStub(Generator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('Disk full'));
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuiltWhole')->with('sitemap-*', 7, 'Disk full');

        $this->expectExceptionMessage('Disk full');
        $this->plugin(generator: $generator, problemLog: $problemLog)
            ->aroundGenerateXml($this->sitemap(3, 7), $this->noCore(...));
    }

    public function testASitemapBeingWrittenElsewhereRecordsNothing(): void
    {
        $generator = $this->createStub(Generator::class);
        $generator->method('generate')->willThrowException(new SitemapRebuildInProgressException(__('Busy')));
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->never())->method('rebuiltWhole');

        $this->expectException(SitemapRebuildInProgressException::class);
        $this->plugin(generator: $generator, problemLog: $problemLog)
            ->aroundGenerateXml($this->sitemap(3, 7), $this->noCore(...));
    }

    /**
     * Core's generator, which must not run when this module's is selected.
     *
     * @return never
     */
    private function noCore(): never
    {
        $this->fail('Core\'s generator ran.');
    }

    /**
     * The plugin; without a config, this module's generator is selected for every store view.
     *
     * @param Config|null $config
     * @param Generator|null $generator
     * @param ProblemLog|null $problemLog
     * @return UseSeoGenerator
     */
    private function plugin(
        ?Config $config = null,
        ?Generator $generator = null,
        ?ProblemLog $problemLog = null
    ): UseSeoGenerator {
        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('isSitemapGeneratorEnabled')->willReturn(true);
        }

        return new UseSeoGenerator(
            $config,
            $generator ?? $this->createStub(Generator::class),
            $problemLog ?? $this->createStub(ProblemLog::class),
            new RebuildGroup()
        );
    }

    /**
     * @param int $storeId
     * @param int $sitemapId
     * @return Sitemap
     */
    private function sitemap(int $storeId, int $sitemapId = 1): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('getId')->willReturn($sitemapId);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getStoreId' ? $storeId : null
        );

        return $sitemap;
    }
}
