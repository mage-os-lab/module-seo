<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Migration;

use MageOS\Seo\Model\Migration\FlagsToDirective;
use PHPUnit\Framework\TestCase;

class FlagsToDirectiveTest extends TestCase
{
    private FlagsToDirective $converter;

    protected function setUp(): void
    {
        $this->converter = new FlagsToDirective();
    }

    public function testAnEntityWithNoFlagsIsNotMigrated(): void
    {
        // It was never overridden. Writing a directive would freeze today's store default into a
        // per-entity setting that stops following the store.
        $this->assertNull($this->converter->convert([], 'INDEX,FOLLOW'));
        $this->assertNull(
            $this->converter->convert(['no_index' => 0, 'no_follow' => 0, 'no_archive' => 0], 'INDEX,FOLLOW')
        );
    }

    public function testNoIndexAgainstAPermissiveDefault(): void
    {
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->converter->convert(['no_index' => 1], 'INDEX,FOLLOW')
        );
    }

    public function testNoFollowAgainstAPermissiveDefault(): void
    {
        $this->assertSame(
            'INDEX,NOFOLLOW',
            $this->converter->convert(['no_follow' => 1], 'INDEX,FOLLOW')
        );
    }

    public function testBothFlags(): void
    {
        $this->assertSame(
            'NOINDEX,NOFOLLOW',
            $this->converter->convert(['no_index' => 1, 'no_follow' => 1], 'INDEX,FOLLOW')
        );
    }

    public function testNoArchiveAloneKeepsWhateverTheDefaultGave(): void
    {
        // The case that rules out assuming INDEX,FOLLOW: the page was indexed and followed only
        // because the store said so, and only archiving was ever overridden.
        $this->assertSame(
            'INDEX,FOLLOW,noarchive',
            $this->converter->convert(['no_archive' => 1], 'INDEX,FOLLOW')
        );
    }

    public function testNoArchiveAloneAgainstARestrictiveDefault(): void
    {
        // A staging store: the visitor was served NOINDEX,NOFOLLOW, and migrating to INDEX,FOLLOW
        // would publish a site that was deliberately hidden.
        $this->assertSame(
            'NOINDEX,NOFOLLOW,noarchive',
            $this->converter->convert(['no_archive' => 1], 'NOINDEX,NOFOLLOW')
        );
    }

    public function testAFlagStillWinsOverAPermissiveDefault(): void
    {
        $this->assertSame(
            'NOINDEX,NOFOLLOW',
            $this->converter->convert(['no_index' => 1], 'INDEX,NOFOLLOW')
        );
    }

    public function testAllThreeFlags(): void
    {
        $this->assertSame(
            'NOINDEX,NOFOLLOW,noarchive',
            $this->converter->convert(
                ['no_index' => 1, 'no_follow' => 1, 'no_archive' => 1],
                'INDEX,FOLLOW'
            )
        );
    }

    public function testAnEmptyStoreDefaultIsTreatedAsPermissive(): void
    {
        // Nothing configured means Magento emits no robots meta, which crawlers read as
        // index,follow — so that is what the unflagged half becomes.
        $this->assertSame(
            'INDEX,FOLLOW,noarchive',
            $this->converter->convert(['no_archive' => 1], '')
        );
    }

    public function testTheDefaultIsMatchedWhateverItsCaseAndSpacing(): void
    {
        $this->assertSame(
            'NOINDEX,FOLLOW,noarchive',
            $this->converter->convert(['no_archive' => 1], ' noindex , follow ')
        );
    }

    public function testExtraDirectivesInTheDefaultAreNotCarriedOver(): void
    {
        // Only index, follow and archive have flags; anything else the store default carried is
        // not something MageOS_MetaRobotsTag ever managed.
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->converter->convert(['no_index' => 1], 'INDEX,FOLLOW,max-snippet:-1')
        );
    }
}
