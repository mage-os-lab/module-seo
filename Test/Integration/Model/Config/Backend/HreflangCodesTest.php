<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Config\Backend\HreflangCodes;
use PHPUnit\Framework\TestCase;

/**
 * The hreflang codes a store view claims are checked on save, against the real country directory.
 *
 * The region check is why this is an integration test: `en-UK` is well-formed, and only the
 * directory knows it is not a country.
 *
 * @magentoAppArea adminhtml
 */
class HreflangCodesTest extends TestCase
{
    /**
     * @return void
     */
    public function testCodesAreStoredNormalised(): void
    {
        $this->assertSame('en-GB,fr-CA,zh-Hant-TW,de', $this->save('en_gb, FR-ca ,zh-hant-tw,,DE'));
    }

    /**
     * @return void
     */
    public function testAnEmptyValueIsAllowed(): void
    {
        // Empty means "use the store view's locale".
        $this->assertSame('', $this->save(''));
    }

    /**
     * @return void
     */
    public function testARegionThatIsNotACountryIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/UK/');

        $this->save('en-GB, en-UK');
    }

    /**
     * @return void
     */
    public function testAMalformedCodeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/english/');

        $this->save('en-GB, english');
    }

    /**
     * @return void
     */
    public function testTheSameCodeTwiceIsRefusedWhateverItsCase(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/en-GB/');

        $this->save('en-GB, en_gb');
    }

    /**
     * Run the backend model's save validation and return the value it would store.
     *
     * @param string $value
     * @return string
     */
    private function save(string $value): string
    {
        /** @var HreflangCodes $backend */
        $backend = Bootstrap::getObjectManager()->create(HreflangCodes::class);
        $backend->setPath(Config::XML_HREFLANG_CODES)
            ->setScope('stores')
            ->setScopeId(1)
            ->setValue($value);

        $backend->beforeSave();

        return (string) $backend->getValue();
    }
}
