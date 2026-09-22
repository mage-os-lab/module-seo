<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta;

use MageOS\Seo\Model\RobotsMeta\DirectiveComposer;
use PHPUnit\Framework\TestCase;

class DirectiveComposerTest extends TestCase
{
    private DirectiveComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new DirectiveComposer();
    }

    public function testWithNothingAddedTheResolvedDirectiveIsWritten(): void
    {
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->composer->compose('NOINDEX,FOLLOW', 'INDEX,FOLLOW', 'INDEX,FOLLOW')
        );
    }

    public function testAnotherModulesNoindexSurvives(): void
    {
        // MetaRobotsTag's per-page flag turned core's INDEX into NOINDEX before this module ran.
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->composer->compose('INDEX,FOLLOW', 'NOINDEX,FOLLOW', 'INDEX,FOLLOW')
        );
    }

    public function testCoresOwnDefaultCanStillBeOverridden(): void
    {
        // The case that rules out "any restriction wins": a staging store's core default is
        // NOINDEX,NOFOLLOW, and an override here is documented to replace it.
        $this->assertSame(
            'INDEX,FOLLOW',
            $this->composer->compose('INDEX,FOLLOW', 'NOINDEX,NOFOLLOW', 'NOINDEX,NOFOLLOW')
        );
    }

    public function testARestrictionWithNoCounterpartIsAppended(): void
    {
        $this->assertSame(
            'INDEX,FOLLOW,NOARCHIVE',
            $this->composer->compose('INDEX,FOLLOW', 'INDEX,FOLLOW,NOARCHIVE', 'INDEX,FOLLOW')
        );
    }

    public function testAnAddedRestrictionOnTopOfARestrictiveDefaultStillSurvives(): void
    {
        // Staging store, and a merchant's noarchive flag on this page: the default is overridden,
        // the flag is kept.
        $this->assertSame(
            'INDEX,FOLLOW,NOARCHIVE',
            $this->composer->compose('INDEX,FOLLOW', 'NOINDEX,NOFOLLOW,NOARCHIVE', 'NOINDEX,NOFOLLOW')
        );
    }

    public function testThirdPartyRestrictionsAreRecognisedWithoutBeingListed(): void
    {
        $this->assertSame(
            'INDEX,FOLLOW,nosnippet,noimageai',
            $this->composer->compose('INDEX,FOLLOW', 'INDEX,FOLLOW,nosnippet,noimageai', 'INDEX,FOLLOW')
        );
    }

    public function testPermissiveTokensOnThePageAreNotCarriedOver(): void
    {
        // Only restrictions are protected; anything else on the page is this module's to decide.
        $this->assertSame(
            'NOINDEX,NOFOLLOW',
            $this->composer->compose('NOINDEX,NOFOLLOW', 'INDEX,FOLLOW,max-snippet:-1', '')
        );
    }

    public function testMatchingIsCaseInsensitiveAndDoesNotDuplicate(): void
    {
        // This module's presets mix case (NOINDEX,FOLLOW,noarchive); another module writes upper.
        $this->assertSame(
            'NOINDEX,FOLLOW,noarchive',
            $this->composer->compose('NOINDEX,FOLLOW,noarchive', 'INDEX,FOLLOW,NOARCHIVE', 'INDEX,FOLLOW')
        );
    }

    public function testTheResolvedDirectivesOwnCaseIsKept(): void
    {
        $this->assertSame(
            'noindex,FOLLOW',
            $this->composer->compose('INDEX,FOLLOW', 'noindex,FOLLOW', 'INDEX,FOLLOW')
        );
    }

    public function testWhitespaceAndEmptyTokensAreIgnored(): void
    {
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->composer->compose('INDEX, FOLLOW', ' NOINDEX , FOLLOW,, ', 'INDEX,FOLLOW')
        );
    }

    public function testAnEmptyPageValueLeavesTheResolvedDirectiveAlone(): void
    {
        $this->assertSame('INDEX,FOLLOW', $this->composer->compose('INDEX,FOLLOW', '', ''));
    }

    public function testTheResultIsTheSameWhicheverModuleRanFirst(): void
    {
        // If the other module runs after, it patches this module's value; if it ran before, the
        // composer carries its token through. Both orders have to land on the same directive.
        $ranBefore = $this->composer->compose('INDEX,FOLLOW', 'NOINDEX,FOLLOW', 'INDEX,FOLLOW');
        $ranAfter  = str_replace('INDEX', 'NOINDEX', 'INDEX,FOLLOW');

        $this->assertSame($ranAfter, $ranBefore);
    }
}
