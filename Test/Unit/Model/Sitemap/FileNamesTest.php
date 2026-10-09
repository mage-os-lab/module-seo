<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Sitemap\FileNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileNamesTest extends TestCase
{
    private const TYPES = ['pages', 'products', 'blog-posts'];

    /**
     * @return void
     */
    public function testNamesAreBuiltFromTheEntrysNameStoreTypeAndSet(): void
    {
        $names   = new FileNames();
        $sitemap = $this->sitemap();

        $this->assertSame('/media/sitemap', $names->directory($sitemap));
        $this->assertSame('/media/sitemap/sitemap.xml', $names->path($sitemap, 'sitemap.xml'));
        $this->assertSame('sitemap.xml', $names->index($sitemap));
        $this->assertSame('sitemap-3-blog-posts-b-12.xml', $names->child($sitemap, 'blog-posts', 'b', 12));
        $this->assertSame('sitemap-3-pages-a-1.xml.tmp', $names->temporary('sitemap-3-pages-a-1.xml'));
        $this->assertSame('sitemap.xml', $names->nameOf('media/sitemap/sitemap.xml'));
        $this->assertSame('sitemap.xml', $names->nameOf('sitemap.xml'));
    }

    /**
     * @return void
     */
    public function testTheSetOfAFileIsReadFromItsName(): void
    {
        $names   = new FileNames();
        $sitemap = $this->sitemap();

        $this->assertSame('a', $names->setOf($sitemap, 'pages', 'sitemap-3-pages-a-1.xml'));
        $this->assertSame('b', $names->setOf($sitemap, 'blog-posts', 'sitemap-3-blog-posts-b-2.xml'));
        $this->assertNull($names->setOf($sitemap, 'pages', 'sitemap-3-pages-1.xml'), 'A name from before the sets.');
        $this->assertNull($names->setOf($sitemap, 'pages', 'sitemap-3-pages-c-1.xml'), 'Not a set.');
        $this->assertNull($names->setOf($sitemap, 'pages', 'sitemap-3-products-a-1.xml'), 'Another type.');
        $this->assertNull($names->setOf($sitemap, 'pages', 'sitemap-4-pages-a-1.xml'), 'Another store view.');
        $this->assertNull($names->setOf($sitemap, 'pages', 'sitemap-3-pages-a-1.xml.tmp'), 'Not complete.');
    }

    /**
     * @dataProvider files
     * @param string $fileName
     * @param bool $own
     * @return void
     */
    #[DataProvider('files')]
    public function testOnlyThisSitemapsFilesAreItsOwn(string $fileName, bool $own): void
    {
        $this->assertSame($own, (new FileNames())->isOwn($this->sitemap(), self::TYPES, $fileName));
    }

    /**
     * @return array<string,array{string,bool}>
     */
    public static function files(): array
    {
        return [
            'a set'                     => ['sitemap-3-pages-a-1.xml', true],
            'the other set'             => ['sitemap-3-blog-posts-b-7.xml', true],
            'from before the sets'      => ['sitemap-3-products-2.xml', true],
            "core's"                    => ['sitemap-3-1.xml', true],
            'being written'             => ['sitemap-3-pages-a-1.xml.tmp', true],
            'the index being written'   => ['sitemap.xml.tmp', true],
            'the index'                 => ['sitemap.xml', false],
            'a type not registered'     => ['sitemap-3-blog-a-1.xml', false],
            'another store view'        => ['sitemap-30-pages-a-1.xml', false],
            'another sitemap'           => ['sitemapx-3-pages-a-1.xml', false],
            'another sitemap\'s set'    => ['sitemap-3-x-3-pages-a-1.xml', false],
            'not a set'                 => ['sitemap-3-pages-c-1.xml', false],
            'something else'            => ['robots.txt', false],
        ];
    }

    /**
     * A name is matched as written: a dot in it is a dot, not any character.
     *
     * @return void
     */
    public function testANameIsMatchedLiterally(): void
    {
        $names   = new FileNames();
        $sitemap = $this->sitemap('site.map.xml');

        $this->assertSame('a', $names->setOf($sitemap, 'pages', 'site.map-3-pages-a-1.xml'));
        $this->assertNull($names->setOf($sitemap, 'pages', 'siteXmap-3-pages-a-1.xml'));
        $this->assertTrue($names->isOwn($sitemap, self::TYPES, 'site.map-3-pages-a-1.xml'));
        $this->assertFalse($names->isOwn($sitemap, self::TYPES, 'siteXmap-3-pages-a-1.xml'));
        $this->assertFalse($names->isOwn($sitemap, self::TYPES, 'site.map-3-pages-a-1.xmlXtmp'));
        $this->assertTrue($names->isOwn($sitemap, self::TYPES, 'site.map.xml.tmp'));
    }

    /**
     * A sitemap of store view 3, in /media/sitemap/.
     *
     * @param string $fileName
     * @return Sitemap
     */
    private function sitemap(string $fileName = 'sitemap.xml'): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => match ($method) {
                'getSitemapPath'     => '/media/sitemap/',
                'getSitemapFilename' => $fileName,
                'getStoreId'         => '3',
                default              => null,
            }
        );

        return $sitemap;
    }
}
