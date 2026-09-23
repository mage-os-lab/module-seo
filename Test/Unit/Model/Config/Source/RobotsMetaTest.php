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

    public function testEveryIndexFollowArchiveCombinationCanBeChosen(): void
    {
        // Replaces an assertion on the number of options, which said nothing about what a
        // merchant can actually express. noarchive used to be offered only beside NOINDEX, so
        // "index this page but keep no cached copy" could not be asked for at all.
        $values = array_column($this->source->toOptionArray(), 'value');

        foreach (['INDEX', 'NOINDEX'] as $index) {
            foreach (['FOLLOW', 'NOFOLLOW'] as $follow) {
                $this->assertContains("{$index},{$follow}", $values);
                $this->assertContains("{$index},{$follow},noarchive", $values);
            }
        }
    }

    public function testTheFirstOptionDefersToMagento(): void
    {
        $options = $this->source->toOptionArray();

        $this->assertSame('', $options[0]['value'], 'An empty value means "no override".');
    }

    public function testIncludesRichPreviewDirective(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('INDEX,FOLLOW,max-image-preview:large,max-snippet:-1', $values);
    }

    public function testIncludesNoarchiveDirective(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('NOINDEX,FOLLOW,noarchive', $values);
    }

    public function testIncludesAiBlockingDirective(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('NOINDEX,NOFOLLOW,noai,noimageai', $values);
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
