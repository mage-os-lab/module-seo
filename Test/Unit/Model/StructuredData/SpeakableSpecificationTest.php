<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData;

use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;
use PHPUnit\Framework\TestCase;

/**
 * The SpeakableSpecification the page's own node carries, from the Speakable settings.
 */
class SpeakableSpecificationTest extends TestCase
{
    public function testNothingWhenSpeakableIsOff(): void
    {
        $this->assertNull($this->specification(false, ['.page-title'])->get());
    }

    public function testNothingWhenThereAreNoSelectors(): void
    {
        $this->assertNull($this->specification(true, [])->get());
    }

    public function testTheSelectorsInTheirOrder(): void
    {
        $this->assertSame(
            ['@type' => 'SpeakableSpecification', 'cssSelector' => ['.page-title', '.category-description']],
            $this->specification(true, ['.page-title', '.category-description'])->get()
        );
    }

    /**
     * @param bool $enabled
     * @param string[] $selectors
     * @return SpeakableSpecification
     */
    private function specification(bool $enabled, array $selectors): SpeakableSpecification
    {
        $config = $this->createStub(Config::class);
        $config->method('isSpeakableEnabled')->willReturn($enabled);
        $config->method('getSpeakableCssSelectors')->willReturn($selectors);

        return new SpeakableSpecification($config);
    }
}
