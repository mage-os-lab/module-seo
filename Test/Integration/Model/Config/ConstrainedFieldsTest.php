<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Config;

use Magento\Config\Model\Config\Source\Yesno;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Date as DateElement;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Block\Adminhtml\System\Config\Field\Date;
use MageOS\Seo\Model\Config\Backend\ReturnCountries;
use MageOS\Seo\Model\Config\Backend\SecurityTxtExpires;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\TestCase;

/**
 * Settings whose valid values are a known set are chosen, not typed.
 *
 * The two countries were typed ISO codes and went into the structured data as entered, so `gb`, `UK`
 * or `England` reached Google. security.txt's Expires was a typed timestamp, written out as entered.
 *
 * @magentoAppArea adminhtml
 */
class ConstrainedFieldsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function countryFieldProvider(): array
    {
        return array_map(static fn (array $paths): array => [$paths[0]], self::worldwideProvider());
    }

    /**
     * Each country field, with the Worldwide field that hides it.
     *
     * @return array<string, array{string, string}>
     */
    public static function worldwideProvider(): array
    {
        return [
            'return policy'    => [
                'mageos_seo_merchant/return/applicable_country',
                'mageos_seo_merchant/return/worldwide',
            ],
            'shipping details' => [
                'mageos_seo_merchant/shipping/destination_country',
                'mageos_seo_merchant/shipping/worldwide',
            ],
        ];
    }

    /**
     * Most shops sell to more than one country, so several can be chosen, as in core's Allow
     * Countries, and every one can be cleared.
     *
     * @dataProvider countryFieldProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('countryFieldProvider')]
    public function testTheCountriesAreChosenFromCoresCountryList(string $path): void
    {
        $field = $this->field($path);

        $this->assertSame('multiselect', $field->getType());
        $this->assertSame(Country::class, $field->getData()['source_model'] ?? null);
        $this->assertTrue($field->canBeEmpty());

        $values = array_column($field->getOptions(), 'value');
        $this->assertContains('GB', $values);
        $this->assertContains('NL', $values);
        $this->assertNotContains('UK', $values);
        $this->assertNotContains('', $values, 'A multiselect needs no "--Please Select--"');
    }

    /**
     * Worldwide is its own Yes/No, and the countries are shown only while it is off.
     *
     * @dataProvider worldwideProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('worldwideProvider')]
    public function testTheCountriesAreShownOnlyWhenNotWorldwide(string $path, string $worldwidePath): void
    {
        $worldwide = $this->field($worldwidePath);
        $this->assertSame('select', $worldwide->getType());
        $this->assertSame(Yesno::class, $worldwide->getData()['source_model'] ?? null);

        $depends = $this->field($path)->getData()['depends']['fields'] ?? [];
        $this->assertSame('0', (string) ($depends['worldwide']['value'] ?? ''));
        $this->assertSame('1', (string) ($depends['enabled']['value'] ?? ''));
    }

    public function testTheReturnCountriesSaveThroughTheirLimitCheck(): void
    {
        $this->assertSame(
            ReturnCountries::class,
            $this->field('mageos_seo_merchant/return/applicable_country')->getData()['backend_model'] ?? null
        );
    }

    public function testExpiresIsADateCheckedOnSave(): void
    {
        $field = $this->field(UcpConfig::XML_SECURITY_TXT_EXPIRES);

        $this->assertSame('date', $field->getType());
        $this->assertSame(Date::class, $field->getFrontendModel());
        $this->assertSame(SecurityTxtExpires::class, $field->getData()['backend_model'] ?? null);
    }

    /**
     * The field renders as a calendar input, without a time, showing the stored date as YYYY-MM-DD:
     * the form the backend model accepts.
     */
    public function testExpiresRendersAsACalendarShowingTheStoredDate(): void
    {
        $html = $this->renderedExpires('2027-01-01');

        $this->assertStringContainsString('value="2027-01-01"', $html);
        $this->assertStringContainsString('"calendar":{"dateFormat":"yyyy-MM-dd","showsTime":false', $html);
    }

    /**
     * A timestamp typed before the field was a date. What it shows decides what a save stores, so
     * the CHANGELOG's account of it rests on this.
     */
    public function testATimestampSavedBeforeShowsAsADate(): void
    {
        $this->assertStringContainsString('value="2027-01-01"', $this->renderedExpires('2027-01-01T00:00:00.000Z'));
    }

    /**
     * The Expires field rendered through its frontend model, attribute values decoded.
     *
     * @param string $storedValue
     * @return string
     */
    private function renderedExpires(string $storedValue): string
    {
        $element = Bootstrap::getObjectManager()->create(DateElement::class, [
            'data' => ['html_id' => 'security_txt_expires', 'name' => 'expires', 'value' => $storedValue],
        ]);
        $element->setForm(Bootstrap::getObjectManager()->create(Form::class));

        return html_entity_decode(Bootstrap::getObjectManager()->create(Date::class)->render($element));
    }

    /**
     * A field of the merged system configuration.
     *
     * @param string $path
     * @return Field
     */
    private function field(string $path): Field
    {
        $field = Bootstrap::getObjectManager()->get(Structure::class)->getElement($path);
        $this->assertInstanceOf(Field::class, $field);

        return $field;
    }
}
