<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\System\Message;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Notification\MessageInterface;
use MageOS\Seo\Model\Migration\LegacyImport;
use MageOS\Seo\Model\System\Message\LegacyImportStatus;
use PHPUnit\Framework\TestCase;

/**
 * The System Messages bar while an import from a module this one replaces has not finished (issue
 * #27), and, once MageOS_MetaRobotsTag's has, while that module is still on (review finding H6).
 */
class LegacyImportStatusTest extends TestCase
{
    /**
     * @return void
     */
    public function testNothingShowsOnceEveryImportFinishedAndMetaRobotsTagIsOff(): void
    {
        $this->assertFalse($this->message([], true, false)->isDisplayed());
    }

    /**
     * @return void
     */
    public function testNothingShowsWhileMetaRobotsTagIsOnButItsImportHasNotFinished(): void
    {
        $this->assertFalse(
            $this->message([], false, true)->isDisplayed(),
            'Without data to import there is nothing to say; with it, the import is pending instead.'
        );
    }

    /**
     * @return void
     */
    public function testAnUnfinishedImportIsShownToAnAdminWhoMayManageSeo(): void
    {
        $message = $this->message([LegacyImport::META_ROBOTS_TAG, LegacyImport::HREFLANG], false, true);

        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertSame(
            '<strong>Settings from an earlier SEO module have not been carried over yet.</strong>'
            . '<ul><li>Meta Robots Tag: the robots settings of products, categories and CMS pages.</li>'
            . '<li>Hreflang: its hreflang settings, and the links between translations of CMS pages.</li></ul>'
            . '<p>Nothing is lost: they stay where they are until they are carried over, and this message'
            . ' disappears by itself once they have been. A developer with access to the server carries them'
            . ' over with <code>bin/magento seo:import-legacy</code>, and can first list what would change'
            . ' with <code>bin/magento seo:import-legacy --dry-run</code>. The system log has the details of'
            . ' the last attempt.</p>',
            $message->getText()
        );

        $this->assertFalse(
            $this->message([LegacyImport::HREFLANG], false, false, false)->isDisplayed(),
            'Not to an admin who may not manage SEO.'
        );
    }

    /**
     * @return void
     */
    public function testOnceMetaRobotsTagsImportFinishedSwitchingItOffIsAdvised(): void
    {
        $message = $this->message([], true, true);

        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_NOTICE, $message->getSeverity());
        $this->assertSame(
            '<strong>The Meta Robots Tag module can be removed.</strong>'
            . '<p>Its settings have been carried over to the Robots Meta settings of products, categories'
            . ' and CMS pages. While it stays on, it keeps changing the robots meta tag of the pages it was'
            . ' set up for, on top of those settings. This message disappears by itself once it is off. A'
            . ' developer with access to the server removes it with'
            . ' <code>composer remove mage-os/module-meta-robots-tag</code>; disabling it instead drops its'
            . ' CMS page columns.</p>',
            $message->getText()
        );
    }

    /**
     * @return void
     */
    public function testBothAreShownTogether(): void
    {
        $message = $this->message([LegacyImport::HREFLANG], true, true);

        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertStringContainsString('<li>Hreflang:', $message->getText());
        $this->assertStringNotContainsString('<li>Meta Robots Tag:', $message->getText());
        $this->assertStringContainsString('composer remove mage-os/module-meta-robots-tag', $message->getText());
    }

    /**
     * The identity follows what is shown, so a dismissed state does not hide a new one.
     *
     * @return void
     */
    public function testTheIdentityFollowsWhatIsShown(): void
    {
        $identities = [
            $this->message([LegacyImport::HREFLANG], false, true)->getIdentity(),
            $this->message([LegacyImport::HREFLANG, LegacyImport::META_ROBOTS_TAG], false, true)->getIdentity(),
            $this->message([], true, true)->getIdentity(),
            $this->message([LegacyImport::HREFLANG], true, true)->getIdentity(),
        ];

        $this->assertCount(4, array_unique($identities));
        $this->assertSame(
            $identities[0],
            $this->message([LegacyImport::HREFLANG], false, true)->getIdentity()
        );
        $this->assertSame(
            $identities[1],
            $this->message([LegacyImport::META_ROBOTS_TAG, LegacyImport::HREFLANG], false, true)->getIdentity(),
            'Not the order the imports are listed in.'
        );
    }

    /**
     * An import another module registers in di.xml is listed by its module's name.
     *
     * @return void
     */
    public function testAnotherModulesPendingImportIsListedByName(): void
    {
        $this->assertStringContainsString(
            '<li>Vendor_LegacySeo: its settings.</li>',
            $this->message(['Vendor_LegacySeo'], false, false)->getText()
        );
    }

    /**
     * The message, for the imports pending and MageOS_MetaRobotsTag's state.
     *
     * @param string[] $pending
     * @param bool $robotsFinished Whether MageOS_MetaRobotsTag's import has finished
     * @param bool $robotsEnabled Whether MageOS_MetaRobotsTag is enabled
     * @param bool $allowed Whether the admin may manage SEO
     * @return LegacyImportStatus
     */
    private function message(
        array $pending,
        bool $robotsFinished,
        bool $robotsEnabled,
        bool $allowed = true
    ): LegacyImportStatus {
        $legacyImport = $this->createStub(LegacyImport::class);
        $legacyImport->method('pending')->willReturn($pending);
        $legacyImport->method('finishedAt')->willReturnCallback(
            static fn (string $module): ?string => $module === LegacyImport::META_ROBOTS_TAG && $robotsFinished
                ? '2026-10-10 09:15:00'
                : null
        );

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn (string $module): bool => $module === LegacyImport::META_ROBOTS_TAG && $robotsEnabled
        );

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $resource === 'MageOS_Seo::seo' && $allowed
        );

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );

        return new LegacyImportStatus($legacyImport, $moduleManager, $authorization, $escaper);
    }
}
