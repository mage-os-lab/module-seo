<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Ui\DataProvider;

use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Ui\DataProvider\OrganizationDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Organization form shows the stored logo choice (issue #37), and says what the scope being
 * edited publishes with "Use Design Logo" (decision 3 (a)).
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class OrganizationDataProviderTest extends TestCase
{
    private const NOTICE_START = 'Use your existing Design logo, or upload a dedicated SEO logo (recommended: min'
        . ' 112x112px).';

    /**
     * Media files written by the running test.
     *
     * @var string[]|null
     */
    private ?array $writtenFiles = [];

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
    }

    /**
     * Before, the choice was guessed by comparing the stored URL with today's design logo, so a
     * changed theme logo turned the form to the custom logo.
     *
     * @return void
     */
    public function testTheStoredChoiceIsShownWhateverTheDesignLogoIsNow(): void
    {
        $this->designLogo('default', 'stores/now.png');
        $this->organization(OrganizationInterface::LOGO_SOURCE_DESIGN, 'https://old.example/media/logo/before.png');

        $this->assertSame('1', $this->formData()['use_design_logo']);
    }

    /**
     * A custom logo comes back with its media-relative file, which the form posts back, and a
     * preview URL.
     *
     * @return void
     */
    public function testACustomLogoIsShownWithItsFile(): void
    {
        $this->organization(OrganizationInterface::LOGO_SOURCE_CUSTOM, 'mage-os/seo/logo/square.png');

        $data = $this->formData();

        $this->assertSame('0', $data['use_design_logo']);
        $this->assertSame('mage-os/seo/logo/square.png', $data['logo_upload'][0]['file']);
        $this->assertStringEndsWith('/mage-os/seo/logo/square.png', $data['logo_upload'][0]['url']);
        $this->assertSame('square.png', $data['logo_upload'][0]['name']);
    }

    /**
     * @return void
     */
    public function testAtAStoreTheNoticeNamesTheLogoItPublishes(): void
    {
        $store = Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default');
        $this->designLogo('default', 'stores/brand.png');

        $notice = $this->notice(['store' => (int) $store->getId()]);

        $this->assertSame(
            self::NOTICE_START . ' With Use Design Logo, this store publishes '
            . $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'logo/stores/brand.png.',
            $notice
        );
    }

    /**
     * @return void
     */
    public function testAtAStoreWithoutADesignLogoTheNoticeSaysNoneIsPublished(): void
    {
        $store = Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default');

        $this->assertSame(
            self::NOTICE_START . ' With Use Design Logo, this store publishes no logo: it has none under'
            . ' Content → Design → Configuration.',
            $this->notice(['store' => (int) $store->getId()])
        );
    }

    /**
     * @return void
     */
    public function testAtAWebsiteOrTheDefaultEachStorePublishesItsOwn(): void
    {
        $expected = self::NOTICE_START . ' With Use Design Logo, each store publishes its own, from'
            . ' Content → Design → Configuration.';

        $this->assertSame($expected, $this->notice([]));
        $this->assertSame($expected, $this->notice(['website' => 1]));
    }

    /**
     * Set a store view's design logo, with its file.
     *
     * @param string $storeCode
     * @param string $file Relative to media/logo, as the setting holds it
     * @return void
     */
    private function designLogo(string $storeCode, string $file): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue('design/header/logo_src', $file, ScopeInterface::SCOPE_STORE, $storeCode);

        $path = 'logo/' . $file;
        Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA)
            ->writeFile($path, 'a logo');
        $this->writtenFiles[] = $path;
    }

    /**
     * The default scope's record, with the given logo.
     *
     * @param string $source
     * @param string $logoPath
     * @return void
     */
    private function organization(string $source, string $logoPath): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get('default', 0);
        $organization->setName('Acme');
        $organization->setLogoSource($source);
        $organization->setLogoPath($logoPath);
        $repository->save($organization);
    }

    /**
     * The form's data for the default scope's record.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $data = $this->dataProvider([])->getData();

        return (array) reset($data);
    }

    /**
     * The Logo Source field's notice for the scope the request names.
     *
     * @param array<string, int> $params
     * @return string
     */
    private function notice(array $params): string
    {
        $field = $this->dataProvider($params)->getMeta()['organization_logo']['children']['use_design_logo'];

        return (string) $field['arguments']['data']['config']['notice'];
    }

    /**
     * The form's data provider, for a request with the given scope parameters.
     *
     * @param array<string, int> $params
     * @return OrganizationDataProvider
     */
    private function dataProvider(array $params): OrganizationDataProvider
    {
        Bootstrap::getObjectManager()->get(RequestInterface::class)->setParams($params);

        return Bootstrap::getObjectManager()->create(OrganizationDataProvider::class, [
            'name'             => 'mageos_seo_organization_form_data_source',
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'entity_id',
        ]);
    }
}
