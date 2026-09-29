<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Sitemap;

use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Sitemap\Generator;
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

        $proceeded = false;
        $result    = (new UseSeoGenerator($config, $generator))->aroundGenerateXml(
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

        $result = (new UseSeoGenerator($config, $generator))->aroundGenerateXml(
            $sitemap,
            function (): never {
                $this->fail('Core\'s generator ran as well.');
            }
        );

        $this->assertSame($sitemap, $result);
    }

    /**
     * @param int $storeId
     * @return Sitemap
     */
    private function sitemap(int $storeId): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getStoreId' ? $storeId : null
        );

        return $sitemap;
    }
}
