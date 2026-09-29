<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Faq\Source;

use MageOS\Seo\Model\Faq\Repository;
use MageOS\Seo\Model\Faq\Source\TableFaqSource;
use PHPUnit\Framework\TestCase;

class TableFaqSourceTest extends TestCase
{
    public function testDelegatesToRepository(): void
    {
        $faqs       = [['question' => 'Q', 'answer' => 'A']];
        $repository = $this->createMock(Repository::class);
        $repository->expects($this->once())
            ->method('getByIdentifier')->with('shipping', 2)->willReturn($faqs);

        $this->assertSame($faqs, (new TableFaqSource($repository))->getFaqs('shipping', 2));
    }

    public function testTheGroupsAreTheTables(): void
    {
        $repository = $this->createStub(Repository::class);
        $repository->method('getIdentifiers')->willReturn(['global', 'shipping']);

        $this->assertSame(['global', 'shipping'], (new TableFaqSource($repository))->getIdentifiers());
    }
}
