<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Faq\Source;

use MageOS\Seo\Model\Faq\GroupReader;
use MageOS\Seo\Model\Faq\Source\TableFaqSource;
use PHPUnit\Framework\TestCase;

class TableFaqSourceTest extends TestCase
{
    public function testDelegatesToTheGroupReader(): void
    {
        $faqs        = [['question' => 'Q', 'answer' => 'A']];
        $groupReader = $this->createMock(GroupReader::class);
        $groupReader->expects($this->once())
            ->method('getByIdentifier')->with('shipping', 2)->willReturn($faqs);

        $this->assertSame($faqs, (new TableFaqSource($groupReader))->getFaqs('shipping', 2));
    }

    public function testTheGroupsAreTheTables(): void
    {
        $groupReader = $this->createStub(GroupReader::class);
        $groupReader->method('getIdentifiers')->willReturn(['global', 'shipping']);

        $this->assertSame(['global', 'shipping'], (new TableFaqSource($groupReader))->getIdentifiers());
    }
}
