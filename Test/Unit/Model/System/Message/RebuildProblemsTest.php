<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\System\Message;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use MageOS\Seo\Model\System\Message\RebuildProblems;
use PHPUnit\Framework\TestCase;

/**
 * The System Messages bar while SEO files are out of date: shown to admins who manage SEO, gone once
 * the problems are, and readable by someone with no server access.
 */
class RebuildProblemsTest extends TestCase
{
    /**
     * The problems, as ProblemLog::all() returns them.
     *
     * @var array<string, array<int|string, array<string, mixed>>>
     */
    private array $problems = [];

    protected function setUp(): void
    {
        $this->problems = ['llms' => [1 => $this->entry(ProblemLog::KIND_FAILED)]];
    }

    public function testShownWhileThereAreProblemsToAdminsWhoManageSeo(): void
    {
        $this->assertTrue($this->message()->isDisplayed());
        $this->assertFalse($this->message(allowed: false)->isDisplayed());

        $this->problems = [];
        $this->assertFalse($this->message()->isDisplayed());
    }

    public function testItIsAMajorMessage(): void
    {
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $this->message()->getSeverity());
    }

    public function testANewProblemMakesANewMessage(): void
    {
        // The identity is what core keys a message on, so a changed set shows again.
        $before = $this->message()->getIdentity();
        $this->problems['jsonl'] = [2 => $this->entry(ProblemLog::KIND_DEGRADED)];

        $this->assertNotSame($before, $this->message()->getIdentity());
    }

    public function testTheSameProblemsKeepTheirMessage(): void
    {
        $this->assertSame($this->message()->getIdentity(), $this->message()->getIdentity());
    }

    public function testEachProblemIsALineFollowedByWhatHappensNextAndWhatADeveloperCanRun(): void
    {
        $this->problems['jsonl'] = [2 => $this->entry(ProblemLog::KIND_DEGRADED)];

        $this->assertSame(
            '<strong>Some SEO files are out of date.</strong>'
            . '<ul><li>html line llms/1</li><li>html line jsonl/2</li></ul>'
            . '<p>This message disappears by itself once the files are rebuilt.'
            . ' To rebuild sooner, a developer with access to the server can run'
            . ' <code>run llms</code>, <code>run jsonl</code>.'
            . ' The system log has the details.</p>',
            $this->message()->getText()
        );
    }

    public function testASitemapProblemPointsAtTheGenerateButtonFirst(): void
    {
        $this->problems = ['sitemap-products' => [3 => $this->entry(ProblemLog::KIND_FAILED)]];

        $this->assertStringContainsString(
            'rebuilt. A Site Map can also be generated again under Marketing → Site Map. To rebuild sooner',
            $this->message()->getText()
        );
    }

    public function testAStalledQueueNamesTheGroupInItsCommandAndTheConsumerToCheck(): void
    {
        $this->problems = [
            ProblemLog::GROUP_QUEUE => ['jsonl' => $this->entry(ProblemLog::KIND_STALLED)],
            RebuildGroup::MISSING   => [3 => $this->entry(ProblemLog::KIND_FAILED)],
        ];

        $text = $this->message()->getText();

        $this->assertStringContainsString('<code>run jsonl</code>, <code>run sitemaps-missing</code>.', $text);
        $this->assertStringContainsString('check that the mageosSeoFeedRegenerate consumer is started', $text);
        $this->assertStringContainsString('A Site Map can also be generated again', $text);
    }

    public function testOnlyTheFirstFiveAreListedAndTheRestCounted(): void
    {
        $this->problems = ['llms' => array_fill(1, 7, $this->entry(ProblemLog::KIND_FAILED))];

        $text = $this->message()->getText();

        $this->assertSame(6, substr_count($text, '<li>'));
        $this->assertStringContainsString('html line llms/5</li><li>…and 2 more.</li>', $text);
        $this->assertStringNotContainsString('llms/6', $text);
    }

    public function testFiveProblemsAreAllListedWithNothingCounted(): void
    {
        $this->problems = ['llms' => array_fill(1, 5, $this->entry(ProblemLog::KIND_FAILED))];

        $text = $this->message()->getText();

        $this->assertSame(5, substr_count($text, '<li>'));
        $this->assertStringNotContainsString('more.', $text);
        $this->assertSame(1, substr_count($text, '<code>run llms</code>'), 'One command per group.');
    }

    public function testNothingElseIsSaidWhenNothingStalledAndNoSitemapIsAffected(): void
    {
        $text = $this->message()->getText();

        $this->assertStringNotContainsString('Site Map', $text);
        $this->assertStringNotContainsString('consumer', $text);
    }

    /**
     * @param string $kind
     * @return array{kind: string, reason: string, arguments: null, since: int}
     */
    private function entry(string $kind): array
    {
        return ['kind' => $kind, 'reason' => 'Disk full', 'arguments' => null, 'since' => 1_800_000_000];
    }

    /**
     * The message over the current problems; the formatter writes "html line GROUP/ID" and
     * "run GROUP" so the test sees what was asked of it.
     *
     * @param bool $allowed Whether the admin may manage SEO
     * @return RebuildProblems
     */
    private function message(bool $allowed = true): RebuildProblems
    {
        $problemLog = $this->createStub(ProblemLog::class);
        $problemLog->method('all')->willReturnCallback(fn (): array => $this->problems);

        $formatter = $this->createStub(ProblemFormatter::class);
        $formatter->method('line')->willReturnCallback(
            static fn (string $group, string $id, array $entry, bool $html): string
                => ($html ? 'html' : 'plain') . " line {$group}/{$id}"
        );
        $formatter->method('command')->willReturnCallback(
            static fn (string $group): ?string => $group === ProblemLog::GROUP_QUEUE ? null : 'run ' . $group
        );

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $allowed && $resource === 'MageOS_Seo::seo'
        );

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );

        return new RebuildProblems($problemLog, $formatter, $authorization, new RebuildGroup(), $escaper);
    }
}
