<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapFactory;
use MageOS\Seo\Model\Sitemap\SitemapFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A Site Map entry is named by its path and file, as Marketing → Site Map lists it.
 *
 * The sitemap factory is Magento's generated class, so this test needs an installation to have
 * generated it. The mutation-testing run works from the module directory alone and excludes this
 * group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class SitemapFileTest extends TestCase
{
    public function testAnEntryIsNamedByItsPathAndFile(): void
    {
        $this->assertSame('/media/sitemap.xml', $this->sitemapFile(['/media/', 'sitemap.xml'])->pathOf(3));
    }

    public function testAnEntryThatDoesNotExistHasNoFile(): void
    {
        $this->assertNull($this->sitemapFile([null, null])->pathOf(3));
    }

    /**
     * The lookup, over an entry with the given path and file name, loaded by ID 3 only.
     *
     * @param array{0: string|null, 1: string|null} $pathAndFile
     * @return SitemapFile
     */
    private function sitemapFile(array $pathAndFile): SitemapFile
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => match ($method) {
                'getSitemapPath'     => $pathAndFile[0],
                'getSitemapFilename' => $pathAndFile[1],
                default              => null,
            }
        );
        $factory = $this->createStub(SitemapFactory::class);
        $factory->method('create')->willReturn($sitemap);
        $resource = $this->createMock(SitemapResource::class);
        $resource->expects($this->once())->method('load')->with($sitemap, 3);

        return new SitemapFile($factory, $resource);
    }
}
