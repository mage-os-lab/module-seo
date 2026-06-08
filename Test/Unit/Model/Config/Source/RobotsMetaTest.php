<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\RobotsMeta;
use PHPUnit\Framework\TestCase;

class RobotsMetaTest extends TestCase
{
    /**
     * @var RobotsMeta
     */
    private RobotsMeta $source;

    protected function setUp(): void
    {
        $this->source = new RobotsMeta();
    }

    public function testToOptionArrayReturnsFourOptions(): void
    {
        $options = $this->source->toOptionArray();
        $this->assertCount(4, $options);
    }

    public function testEachOptionHasValueAndLabelKeys(): void
    {
        foreach ($this->source->toOptionArray() as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    public function testIncludesIndexFollow(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('INDEX,FOLLOW', $values);
    }

    public function testIncludesNoindexFollow(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('NOINDEX,FOLLOW', $values);
    }

    public function testIncludesIndexNofollow(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('INDEX,NOFOLLOW', $values);
    }

    public function testIncludesNoindexNofollow(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('NOINDEX,NOFOLLOW', $values);
    }

    public function testLabelsAreNonEmptyStrings(): void
    {
        foreach ($this->source->toOptionArray() as $option) {
            $this->assertIsString($option['label']);
            $this->assertNotSame('', $option['label']);
        }
    }

    public function testValuesAreUnique(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertCount(\count($values), array_unique($values));
    }
}
