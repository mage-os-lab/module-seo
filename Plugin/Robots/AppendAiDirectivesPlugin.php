<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Robots;

use Magento\Robots\Model\Robots;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Config\Source\AiBots;

/**
 * Appends per-user-agent AI crawler directives to robots.txt.
 *
 * Emits a "Disallow: /" group only for AI user-agents on the configured disallow list. Allowed
 * bots get no group of their own: under robots.txt group-matching semantics a crawler obeys only
 * its most specific User-agent group, so giving an allowed bot an "Allow: /" group would exempt
 * it from every rule in the store's "User-agent: *" group. Disabled by default; appends nothing
 * when disabled so the store's robots.txt is unchanged.
 */
class AppendAiDirectivesPlugin
{
    /**
     * @param Config $aeoConfig
     * @param AiBots $aiBots
     */
    public function __construct(
        private readonly Config $aeoConfig,
        private readonly AiBots $aiBots
    ) {
    }

    /**
     * Append AI crawler directives after the standard robots.txt content.
     *
     * @param Robots $subject
     * @param string|null $result
     * @return string
     */
    public function afterGetData(Robots $subject, ?string $result): string
    {
        $directives = $this->buildAiDirectives();
        if ($directives === '') {
            return $result ?? '';
        }

        return rtrim($result ?? '') . "\n\n" . $directives . "\n";
    }

    /**
     * Build the AI crawler directive block, or an empty string when disabled or nothing is disallowed.
     *
     * @return string
     */
    public function buildAiDirectives(): string
    {
        if (!$this->aeoConfig->isAiRobotsEnabled()) {
            return '';
        }

        $disallowed = $this->aeoConfig->getAiDisallowedBots();

        $blocks = ['# AI crawlers (managed by MageOS_Seo)'];
        foreach ($this->aiBots->toOptionArray() as $option) {
            $bot = (string) $option['value'];
            if (!\in_array($bot, $disallowed, true)) {
                // Allowed bots fall through to the store's User-agent: * group.
                continue;
            }
            $blocks[] = "User-agent: {$bot}";
            $blocks[] = 'Disallow: /';
            $blocks[] = '';
        }

        if (\count($blocks) === 1) {
            return '';
        }

        return trim(implode("\n", $blocks));
    }
}
