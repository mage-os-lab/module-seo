<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Organization;

use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\StructuredData\Provider\OrganizationProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #37: the Organization's logo is resolved for the store view being rendered, when the page
 * is rendered.
 *
 * A design-mode record publishes each store view's own design logo, under that store view's media
 * URL, and follows a change to it without being saved again. A custom logo is published under each
 * store view's media URL too. Logo files are written to the test installation's media directory
 * and removed afterwards.
 *
 * Database isolation is off: creating a store view creates tables, which a transaction refuses.
 * The default scope's Organization is put back as it was, and the store view is a reverted fixture.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class LogoOutputTest extends TestCase
{
    private const MEDIA_ONE = 'https://media-one.example/media/';
    private const MEDIA_TWO = 'https://media-two.example/media/';

    /**
     * Media files written by the running test.
     *
     * @var string[]|null
     */
    private ?array $writtenFiles = [];

    /**
     * The default scope's Organization before the test, or null when there was none.
     *
     * @var array<string, mixed>|null
     */
    private ?array $savedOrganization = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $organization            = $this->repository()->get('default', 0);
        $this->savedOrganization = $organization->getEntityId() ? $organization->getData() : null;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $media = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
        foreach ($this->writtenFiles as $file) {
            if ($media->isExist($file)) {
                $media->delete($file);
            }
        }
        $this->writtenFiles = [];

        if ($this->savedOrganization === null) {
            $this->repository()->deleteForScope('default', [0]);
        } else {
            $organization = $this->repository()->get('default', 0);
            $organization->setData($this->savedOrganization);
            $this->repository()->save($organization);
        }
        $this->savedOrganization = null;
    }

    /**
     * A new repository, so nothing it remembers from before the test is read.
     *
     * @return OrganizationRepositoryInterface
     */
    private function repository(): OrganizationRepositoryInterface
    {
        return Bootstrap::getObjectManager()->create(OrganizationRepositoryInterface::class);
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testEachStoreViewPublishesItsOwnDesignLogo(): void
    {
        [$first, $second] = $this->twoStoreViews();
        $this->designLogo($first, 'stores/one/brand-one.png');
        $this->designLogo($second, 'stores/two/brand-two.png');
        $this->organization(OrganizationInterface::LOGO_SOURCE_DESIGN);

        $this->assertSame(self::MEDIA_ONE . 'logo/stores/one/brand-one.png', $this->publishedLogo($first));
        $this->assertSame(self::MEDIA_TWO . 'logo/stores/two/brand-two.png', $this->publishedLogo($second));
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAChangedDesignLogoIsPublishedWithoutSavingTheOrganization(): void
    {
        [$first] = $this->twoStoreViews();
        $this->designLogo($first, 'stores/one/before.png');
        $this->organization(OrganizationInterface::LOGO_SOURCE_DESIGN);
        $this->assertSame(self::MEDIA_ONE . 'logo/stores/one/before.png', $this->publishedLogo($first));

        $this->designLogo($first, 'stores/one/after.png');

        $this->assertSame(self::MEDIA_ONE . 'logo/stores/one/after.png', $this->publishedLogo($first));
    }

    /**
     * As the storefront header does, a design logo is used only when its file is there.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testADesignLogoWhoseFileIsMissingIsNotPublished(): void
    {
        [$first, $second] = $this->twoStoreViews();
        $this->designLogo($first, 'stores/one/brand-one.png');
        $this->designLogo($second, 'stores/two/never-uploaded.png', false);
        $this->organization(OrganizationInterface::LOGO_SOURCE_DESIGN);

        $this->assertNotNull($this->publishedLogo($first));
        $this->assertNull($this->publishedLogo($second));
    }

    /**
     * Issue #37 and decision 1 (a): the admin's media URL may not be public.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testACustomLogoIsPublishedUnderEachStoreViewsMediaUrl(): void
    {
        [$first, $second] = $this->twoStoreViews();
        $this->designLogo($first, 'stores/one/brand-one.png');
        $this->organization(OrganizationInterface::LOGO_SOURCE_CUSTOM, 'mage-os/seo/logo/square.png');

        $this->assertSame(self::MEDIA_ONE . 'mage-os/seo/logo/square.png', $this->publishedLogo($first));
        $this->assertSame(self::MEDIA_TWO . 'mage-os/seo/logo/square.png', $this->publishedLogo($second));
    }

    /**
     * A record saved before the logo source was stored, with a logo URL, keeps publishing it.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAnOlderRecordsAbsoluteLogoUrlIsPublishedAsItIs(): void
    {
        [$first] = $this->twoStoreViews();
        $this->designLogo($first, 'stores/one/brand-one.png');
        $this->organization(null, 'https://cdn.example/brand.png');

        $this->assertSame('https://cdn.example/brand.png', $this->publishedLogo($first));
    }

    /**
     * The default store view and the fixture store view, each with its own media URL.
     *
     * @return int[]
     */
    private function twoStoreViews(): array
    {
        $second = DataFixtureStorageManager::getStorage()->get('second_store');
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        foreach (['default' => self::MEDIA_ONE, $second->getCode() => self::MEDIA_TWO] as $code => $mediaUrl) {
            foreach (['web/unsecure/base_media_url', 'web/secure/base_media_url'] as $path) {
                $config->setValue($path, $mediaUrl, ScopeInterface::SCOPE_STORE, $code);
            }
        }

        // Store views keep the base URLs they worked out: new instances read the ones set above.
        $storeManager = Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
        $storeManager->reinitStores();

        return [(int) $storeManager->getStore('default')->getId(), (int) $second->getId()];
    }

    /**
     * Set a store view's design logo, as Content → Design → Configuration stores it, with its file.
     *
     * @param int $storeId
     * @param string $file Relative to media/logo, as the setting holds it
     * @param bool $withFile
     * @return void
     */
    private function designLogo(int $storeId, string $file, bool $withFile = true): void
    {
        $code = Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore($storeId)->getCode();
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue('design/header/logo_src', $file, ScopeInterface::SCOPE_STORE, $code);

        if ($withFile) {
            $path = 'logo/' . $file;
            Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA)
                ->writeFile($path, 'a logo');
            $this->writtenFiles[] = $path;
        }
    }

    /**
     * The default scope's Organization, with the given logo.
     *
     * @param string|null $source Null for a record saved before the source was stored
     * @param string $logoPath
     * @return void
     */
    private function organization(?string $source, string $logoPath = ''): void
    {
        // The shared repository, which the provider reads through: its save clears what it remembers.
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get('default', 0);
        $organization->setName('Acme');
        $organization->setUrl('https://acme.example');
        $organization->setLogoPath($logoPath);
        if ($source !== null) {
            $organization->setLogoSource($source);
        }
        $repository->save($organization);
    }

    /**
     * The logo URL the Organization schema publishes on a store view, or null for none.
     *
     * @param int $storeId
     * @return string|null
     */
    private function publishedLogo(int $storeId): ?string
    {
        Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->setCurrentStore($storeId);
        $schemas = Bootstrap::getObjectManager()->create(OrganizationProvider::class)->getSchemas();

        return $schemas[0]['logo']['url'] ?? null;
    }
}
