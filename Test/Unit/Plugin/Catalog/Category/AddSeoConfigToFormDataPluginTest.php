<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Catalog\Category;

use Magento\Catalog\Model\Category\DataProvider;
use MageOS\Seo\Plugin\Catalog\Category\AddSeoConfigToFormDataPlugin;
use MageOS\Seo\Ui\DataProvider\Category\Form\Modifier\SeoModifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddSeoConfigToFormDataPluginTest extends TestCase
{
    /**
     * @var SeoModifier&MockObject
     */
    private SeoModifier&MockObject $seoModifier;

    /**
     * @var AddSeoConfigToFormDataPlugin
     */
    private AddSeoConfigToFormDataPlugin $plugin;

    protected function setUp(): void
    {
        $this->seoModifier = $this->createMock(SeoModifier::class);
        $this->plugin      = new AddSeoConfigToFormDataPlugin($this->seoModifier);
    }

    public function testAddsSeoConfigToLoadedFormData(): void
    {
        $loaded   = [7 => ['name' => 'Shirts']];
        $modified = [7 => ['name' => 'Shirts', 'mageos_seo_schema_template' => 'GenericProduct']];
        $this->seoModifier->expects($this->once())->method('modifyData')->with($loaded)->willReturn($modified);

        $this->assertSame($modified, $this->plugin->afterGetData($this->createStub(DataProvider::class), $loaded));
    }

    public function testLeavesResultAloneWhenNoCategoryIsLoaded(): void
    {
        $this->seoModifier->expects($this->never())->method('modifyData');
        $subject = $this->createStub(DataProvider::class);

        $this->assertNull($this->plugin->afterGetData($subject, null));
        $this->assertSame([], $this->plugin->afterGetData($subject, []));
    }
}
