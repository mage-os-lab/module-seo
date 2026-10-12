<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\System\Message;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Notification\MessageInterface;
use MageOS\Seo\Model\Migration\LegacyImport;

/**
 * The admin's System Messages bar, about the modules this one replaces. Only to admins who may
 * manage SEO.
 *
 * - **While an import has not finished** and its module left data behind (issue #27).
 *   setup:upgrade tries each import once, so this is how a failed one is noticed.
 * - **Once MageOS_MetaRobotsTag's import has finished, while that module is still on** (review
 *   finding H6). It has no switch of its own, so it keeps changing the robots tag of the pages it
 *   was set up for, on top of this module's settings. Disabling it is the cutover.
 *
 * Most admins have no server access, so the text says what happens without anyone acting before
 * the command a developer runs.
 */
class LegacyImportStatus implements MessageInterface
{
    private const ACL_RESOURCE = 'MageOS_Seo::seo';

    /**
     * @param LegacyImport $legacyImport
     * @param ModuleManager $moduleManager
     * @param AuthorizationInterface $authorization
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly LegacyImport           $legacyImport,
        private readonly ModuleManager          $moduleManager,
        private readonly AuthorizationInterface $authorization,
        private readonly Escaper                $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        $pending = $this->legacyImport->pending();
        sort($pending);

        // phpcs:ignore Magento2.Security.InsecureFunction -- an identity, as core's messages use, not a secret
        return md5(
            'MAGEOS_SEO_LEGACY_IMPORT|' . implode('|', $pending) . '|' . ($this->canSwitchOffMetaRobotsTag() ? 1 : 0)
        );
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->authorization->isAllowed(self::ACL_RESOURCE)
            && ($this->legacyImport->pending() !== [] || $this->canSwitchOffMetaRobotsTag());
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $text    = '';
        $pending = $this->legacyImport->pending();
        if ($pending !== []) {
            $lines = [];
            foreach ($pending as $module) {
                $lines[] = $this->escaper->escapeHtml($this->describe($module));
            }

            $text .= '<strong>'
                . $this->escaper->escapeHtml(
                    (string) __('Settings from an earlier SEO module have not been carried over yet.')
                )
                . '</strong><ul><li>' . implode('</li><li>', $lines) . '</li></ul><p>'
                . $this->escaper->escapeHtml((string) __(
                    'Nothing is lost: they stay where they are until they are carried over, and this message'
                    . ' disappears by itself once they have been.'
                ))
                . ' ' . __(
                    'A developer with access to the server carries them over with %1, and can first list what'
                    . ' would change with %2.',
                    '<code>bin/magento seo:import-legacy</code>',
                    '<code>bin/magento seo:import-legacy --dry-run</code>'
                )
                . ' ' . $this->escaper->escapeHtml((string) __('The system log has the details of the last attempt.'))
                . '</p>';
        }

        if ($this->canSwitchOffMetaRobotsTag()) {
            $text .= '<strong>'
                . $this->escaper->escapeHtml((string) __('The Meta Robots Tag module can be switched off.'))
                . '</strong><p>'
                . $this->escaper->escapeHtml((string) __(
                    'Its settings have been carried over to the Robots Meta settings of products, categories'
                    . ' and CMS pages. While it stays on, it keeps changing the robots meta tag of the pages it'
                    . ' was set up for, on top of those settings. This message disappears by itself once it is'
                    . ' off.'
                ))
                . ' ' . __(
                    'A developer with access to the server switches it off with %1.',
                    '<code>bin/magento module:disable ' . LegacyImport::META_ROBOTS_TAG . '</code>'
                )
                . '</p>';
        }

        return $text;
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return $this->legacyImport->pending() !== [] ? self::SEVERITY_MAJOR : self::SEVERITY_NOTICE;
    }

    /**
     * Whether MageOS_MetaRobotsTag's import has finished and the module is still on.
     *
     * @return bool
     */
    private function canSwitchOffMetaRobotsTag(): bool
    {
        return $this->legacyImport->finishedAt(LegacyImport::META_ROBOTS_TAG) !== null
            && $this->moduleManager->isEnabled(LegacyImport::META_ROBOTS_TAG);
    }

    /**
     * What is waiting to be carried over from a module, by the module's label.
     *
     * @param string $module
     * @return string
     */
    private function describe(string $module): string
    {
        return (string) match ($module) {
            LegacyImport::META_ROBOTS_TAG => __(
                'Meta Robots Tag: the robots settings of products, categories and CMS pages.'
            ),
            LegacyImport::HREFLANG        => __(
                'Hreflang: its hreflang settings, and the links between translations of CMS pages.'
            ),
            default                       => __('%1: its settings.', $module),
        };
    }
}
