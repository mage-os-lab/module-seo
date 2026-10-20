<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Issue #43: a sitemap in a directory holding `.htaccess` — `pub/` itself, Magento's default — is
 * generated and deleted with its files.
 *
 * Magento's directory listing (`Directory\Read::read()`) passes every entry through the deny list,
 * which refuses any `.htaccess`, so listing `pub/` threw: generation failed after writing, every
 * run, its stale files never removed, and a deleted entry's files stayed. A directory of the test's
 * own with a `.htaccess` in it stands in for `pub/`.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class HtaccessBesideSitemapTest extends TestCase
{
    use GeneratesSitemaps;

    /**
     * The test's sitemap directory under pub/.
     *
     * @var string|null
     */
    private ?string $directory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpSitemaps();
        $this->directory = 'media/' . $this->sitemapName;
        $pub = $this->pub();
        $pub->create($this->directory);
        // Through the driver: the directory object refuses to write a .htaccess too.
        $pub->getDriver()->filePutContents($this->absolute('.htaccess'), "Require all denied\n");
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeGeneratedSitemaps();
        $pub = $this->pub();
        $pub->getDriver()->deleteDirectory($pub->getAbsolutePath((string) $this->directory));
    }

    /**
     * @return void
     */
    public function testGenerationRemovesAStaleFileBesideAnHtaccess(): void
    {
        $store   = $this->defaultStoreId();
        $stale   = "{$this->sitemapName}-{$store}-products-7.xml";
        $sitemap = $this->sitemapHere();
        $this->generateSitemap($sitemap);
        $this->write($stale);

        $this->generateSitemap($sitemap);

        $names = $this->names();
        $this->assertNotContains($stale, $names, 'The stale file was removed.');
        $this->assertContains('.htaccess', $names, 'The .htaccess stays.');
        $this->assertContains((string) $sitemap->getSitemapFilename(), $names, 'The index was written.');
    }

    /**
     * @return void
     */
    public function testDeletingAnEntryBesideAnHtaccessRemovesItsFiles(): void
    {
        $store   = $this->defaultStoreId();
        $sitemap = $this->sitemapHere();
        $sitemap->save();
        $own = ["{$this->sitemapName}-{$store}-pages-a-1.xml", (string) $sitemap->getSitemapFilename()];
        foreach ($own as $name) {
            $this->write($name);
        }

        Bootstrap::getObjectManager()->get(SitemapResource::class)->delete($sitemap);

        $this->assertSame(['.htaccess'], $this->names());
    }

    /**
     * A sitemap for the default store view in the test's directory, not yet saved.
     *
     * @return Sitemap
     */
    private function sitemapHere(): Sitemap
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->setSitemapPath('/' . $this->directory . '/');

        return $sitemap;
    }

    /**
     * Write an empty file into the test's directory.
     *
     * @param string $name
     * @return void
     */
    private function write(string $name): void
    {
        $this->pub()->getDriver()->filePutContents($this->absolute($name), '');
    }

    /**
     * The names in the test's directory, sorted, read through the driver.
     *
     * @return string[]
     */
    private function names(): array
    {
        $pub   = $this->pub();
        $names = array_map(
            'basename',
            $pub->getDriver()->readDirectory($pub->getAbsolutePath((string) $this->directory))
        );
        sort($names);

        return $names;
    }

    /**
     * The absolute path of a file in the test's directory.
     *
     * @param string $name
     * @return string
     */
    private function absolute(string $name): string
    {
        return rtrim($this->pub()->getAbsolutePath((string) $this->directory), '/') . '/' . $name;
    }
}
