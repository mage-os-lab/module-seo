<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Config;

/**
 * Generating real sitemaps into pub/media/sitemap, through core's own entry point, and reading them.
 *
 * Every file a test writes starts with the name setUpSitemaps() chose, so
 * removeGeneratedSitemaps() can clear them all and nothing else.
 */
trait GeneratesSitemaps
{
    /**
     * The name every sitemap file of the running test starts with.
     *
     * @var string|null
     */
    private ?string $sitemapName = null;

    /**
     * The sitemaps the running test created; generation saves them.
     *
     * @var Sitemap[]|null
     */
    private ?array $createdSitemaps = [];

    /**
     * Choose a file name no earlier run can have used.
     *
     * Short on purpose: core's `sitemap.sitemap_filename` holds 32 characters, and Magento's
     * connection runs without strict mode, so a longer name is truncated on save without an error —
     * losing `.xml`, which only shows once cron reloads the sitemap and writes an index with no
     * extension. Name, suffix and `.xml` must fit in 32.
     *
     * @return void
     */
    private function setUpSitemaps(): void
    {
        $this->sitemapName = 'mageos_' . uniqid();
    }

    /**
     * Delete the running test's sitemaps and every file they wrote.
     *
     * @return void
     */
    private function removeGeneratedSitemaps(): void
    {
        $resource = Bootstrap::getObjectManager()->get(SitemapResource::class);
        foreach ($this->createdSitemaps as $sitemap) {
            if ($sitemap->getId()) {
                $resource->delete($sitemap);
            }
        }
        $this->createdSitemaps = [];

        $pub = $this->pub();
        if (!$pub->isExist('media/sitemap')) {
            return;
        }
        foreach ($pub->read('media/sitemap') as $path) {
            if (str_starts_with(substr($path, (int) strrpos('/' . $path, '/')), (string) $this->sitemapName)) {
                $pub->delete($path);
            }
        }
    }

    /**
     * A sitemap for the store view in pub/media/sitemap, not yet saved.
     *
     * @param int $storeId
     * @param string $suffix Added to the file name, so several sitemaps in one test do not collide
     * @return Sitemap
     */
    private function sitemapFor(int $storeId, string $suffix = ''): Sitemap
    {
        /** @var Sitemap $sitemap */
        $sitemap = Bootstrap::getObjectManager()->create(Sitemap::class);
        $sitemap->setData([
            'sitemap_filename' => $this->sitemapName . $suffix . '.xml',
            'sitemap_path'     => '/media/sitemap/',
            'store_id'         => $storeId,
        ]);
        $this->createdSitemaps[] = $sitemap;

        return $sitemap;
    }

    /**
     * Generate the sitemap under the store view's frontend, as core's controller and cron do.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    private function generateSitemap(Sitemap $sitemap): void
    {
        $emulation = Bootstrap::getObjectManager()->get(Emulation::class);
        $emulation->startEnvironmentEmulation((int) $sitemap->getStoreId(), Area::AREA_FRONTEND, true);
        try {
            $sitemap->generateXml();
        } finally {
            $emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Generate a sitemap for the store view and return its URL files, keyed by name.
     *
     * For an index, the files it lists; for core's single file, that file.
     *
     * @param int $storeId
     * @param string $suffix
     * @return array<string,string>
     */
    private function generateFor(int $storeId, string $suffix = ''): array
    {
        $sitemap = $this->sitemapFor($storeId, $suffix);
        $this->generateSitemap($sitemap);

        $index = $this->pub()->readFile('media/sitemap/' . $sitemap->getSitemapFilename());
        if (!str_contains($index, '<sitemapindex')) {
            return [(string) $sitemap->getSitemapFilename() => $index];
        }

        preg_match_all('#<loc>[^<]*/([^/<]+\.xml)</loc>#', $index, $matches);
        $files = [];
        foreach ($matches[1] as $name) {
            $files[$name] = $this->pub()->readFile('media/sitemap/' . $name);
        }

        return $files;
    }

    /**
     * Every `<url>` row of the given files.
     *
     * @param array<string,string> $files
     * @return string[]
     */
    private function urlRows(array $files): array
    {
        $rows = [];
        foreach ($files as $xml) {
            preg_match_all('#<url>.*?</url>#s', $xml, $matches);
            $rows[] = $matches[0];
        }

        return array_merge([], ...$rows);
    }

    /**
     * Set a value for one store view.
     *
     * At store scope: the test configuration merges store values when it loads, so a default-scope
     * change never reaches a store-scope read.
     *
     * @param string $path
     * @param string $value
     * @param string $storeCode
     * @return void
     */
    private function setStoreConfig(string $path, string $value, string $storeCode = 'default'): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue($path, $value, 'store', $storeCode);
    }

    /**
     * Select a generator for a store view.
     *
     * @param string $generator
     * @param string $storeCode
     * @return void
     */
    private function useGenerator(string $generator, string $storeCode = 'default'): void
    {
        $this->setStoreConfig(Config::XML_SITEMAP_GENERATOR, $generator, $storeCode);
    }

    /**
     * @return WriteInterface
     */
    private function pub(): WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::PUB);
    }

    /**
     * @return int
     */
    private function defaultStoreId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
