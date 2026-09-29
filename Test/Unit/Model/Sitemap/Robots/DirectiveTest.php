<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\Robots;

use MageOS\Seo\Model\Sitemap\Robots\Directive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DirectiveTest extends TestCase
{
    /**
     * @return array<string,array{string,bool}>
     */
    public static function directives(): array
    {
        return [
            'noindex, as core writes it'   => ['NOINDEX,FOLLOW', true],
            'noindex, lower case'          => ['noindex,nofollow', true],
            'noindex among others, spaced' => ['INDEX, NOARCHIVE , NOINDEX', true],
            'none is noindex and nofollow' => ['NONE', true],
            'index'                        => ['INDEX,FOLLOW', false],
            'nofollow alone'               => ['INDEX,NOFOLLOW', false],
            'noimageindex is not noindex'  => ['INDEX,FOLLOW,noimageindex', false],
            'empty'                        => ['', false],
        ];
    }

    /**
     * @dataProvider directives
     * @param string $directive
     * @param bool $noindex
     * @return void
     */
    #[DataProvider('directives')]
    public function testRecognisesNoindex(string $directive, bool $noindex): void
    {
        $this->assertSame($noindex, (new Directive($directive))->isNoindex());
    }

    public function testKeepsTheDirectiveAsGiven(): void
    {
        $this->assertSame('NOINDEX,FOLLOW', (new Directive('NOINDEX,FOLLOW'))->getDirective());
    }
}
