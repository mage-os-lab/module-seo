<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config\Source\RobotsMeta\ProductOverride;
use MageOS\Seo\Ui\DataProvider\Product\Form\Modifier\SeoModifier;
use PHPUnit\Framework\TestCase;

class SeoModifierTest extends TestCase
{
    /**
     * The fieldset must bind to the provider's "data" branch: product_form.xml has no form-level
     * dataScope, so an empty fieldset scope would put the fields outside the loaded and submitted
     * data. The field scopes are the POST keys the save observer reads.
     */
    public function testFieldsetBindsFieldsToTheFormDataBranch(): void
    {
        $meta = $this->modifier([])->modifyMeta([]);

        $fieldset = $meta['mageos_seo_advanced'];
        $this->assertSame('data', $fieldset['arguments']['data']['config']['dataScope']);
        $this->assertSame(
            'mageos_seo_override_fields',
            $fieldset['children']['mageos_seo_override_fields']['arguments']['data']['config']['dataScope']
        );
        $this->assertSame(
            'mageos_seo_robots_meta',
            $fieldset['children']['mageos_seo_robots_meta']['arguments']['data']['config']['dataScope']
        );
    }

    public function testKeepsExistingMeta(): void
    {
        $meta = $this->modifier([])->modifyMeta(['product-details' => ['children' => []]]);

        $this->assertArrayHasKey('product-details', $meta);
    }

    public function testLoadsStoredOverridesForTheProductAndStoreInTheRequest(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->once())->method('getForProduct')->with(42, 3)->willReturn([
            'override_fields' => ['color' => 'Midnight Blue'],
            'robots_meta'     => 'NOINDEX,FOLLOW',
        ]);

        $data = $this->modifier(['id' => '42', 'store' => '3'], $repository)
            ->modifyData([42 => ['product' => ['name' => 'Shirt']]]);

        $this->assertSame(['name' => 'Shirt'], $data[42]['product']);
        $this->assertSame('NOINDEX,FOLLOW', $data[42]['mageos_seo_robots_meta']);
        $this->assertSame(['color' => 'Midnight Blue'], json_decode($data[42]['mageos_seo_override_fields'], true));
    }

    public function testLoadsEmptyValuesWhenNothingIsStored(): void
    {
        $repository = $this->createStub(ProductOverrideRepository::class);
        $repository->method('getForProduct')->willReturn(['override_fields' => [], 'robots_meta' => null]);

        $data = $this->modifier(['id' => '42'], $repository)->modifyData([]);

        $this->assertSame('', $data[42]['mageos_seo_override_fields']);
        $this->assertSame('', $data[42]['mageos_seo_robots_meta']);
    }

    public function testLeavesDataAloneForANewProduct(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->never())->method('getForProduct');

        $data = ['' => ['product' => []]];

        $this->assertSame($data, $this->modifier([], $repository)->modifyData($data));
    }

    /**
     * Build the modifier around a request carrying the given parameters.
     *
     * @param array<string, string> $params
     * @param ProductOverrideRepository|null $repository
     * @return SeoModifier
     */
    private function modifier(array $params, ?ProductOverrideRepository $repository = null): SeoModifier
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );
        $robotsMeta = $this->createStub(ProductOverride::class);
        $robotsMeta->method('toOptionArray')->willReturn([['value' => 'NOINDEX,FOLLOW', 'label' => 'NOINDEX, FOLLOW']]);

        return new SeoModifier(
            $request,
            $repository ?? $this->createStub(ProductOverrideRepository::class),
            $robotsMeta
        );
    }
}
