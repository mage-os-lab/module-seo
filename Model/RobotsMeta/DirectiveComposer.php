<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta;

/**
 * Combines this module's robots directive with restrictions another module has already applied.
 *
 * Writing the resolved directive straight over the page's robots value discards whatever other
 * modules put there. MageOS_MetaRobotsTag, shipped in the Mage-OS distribution, is the case that
 * made this matter: it flips individual tokens — INDEX to NOINDEX — from per-entity flags, and a
 * wholesale replace threw those flags away. On CMS pages it always ran first, so a merchant's
 * per-page noindex was lost every time this module had a CMS default to apply.
 *
 * The rule: a **restriction** on the page that is **not part of core's default** was added by
 * another module, and survives. Everything else — including every token that came from core's
 * Design → Search Engine Robots setting — is this module's to override, as documented. So a
 * staging store whose core default is NOINDEX,NOFOLLOW can still be lifted to INDEX by an
 * override here, while a noindex a merchant set on a single page stays put.
 *
 * The result is the same whichever observer runs first: if the other module runs later it patches
 * this value; if it ran earlier, its tokens are carried through here.
 */
class DirectiveComposer
{
    /**
     * Compose the directive to write.
     *
     * @param string $resolved This module's directive for the page
     * @param string $onPage What the page's robots value holds before this module writes
     * @param string $coreDefault Core's configured default for the store
     * @return string
     */
    public function compose(string $resolved, string $onPage, string $coreDefault): string
    {
        $directives = $this->tokens($resolved);
        $default    = array_map('strtolower', $this->tokens($coreDefault));

        foreach ($this->tokens($onPage) as $token) {
            if (!$this->isRestriction($token) || \in_array(strtolower($token), $default, true)) {
                continue;
            }

            $directives = $this->withRestriction($directives, $token);
        }

        return implode(',', $directives);
    }

    /**
     * Apply a restriction: replace its permissive counterpart, or add it if neither is present.
     *
     * @param string[] $directives
     * @param string $restriction e.g. NOINDEX, whose counterpart is INDEX
     * @return string[]
     */
    private function withRestriction(array $directives, string $restriction): array
    {
        $restrictionLower = strtolower($restriction);
        $counterpart      = substr($restrictionLower, 2);

        foreach ($directives as $index => $directive) {
            $directiveLower = strtolower($directive);

            if ($directiveLower === $restrictionLower) {
                return $directives;
            }

            if ($directiveLower === $counterpart) {
                $directives[$index] = $restriction;
                return $directives;
            }
        }

        $directives[] = $restriction;

        return $directives;
    }

    /**
     * Whether a directive narrows what crawlers may do.
     *
     * Every restricting robots directive is spelled `no…` — noindex, nofollow, noarchive,
     * nosnippet, noimageindex, notranslate, none, and the AI directives noai and noimageai —
     * so a third party's restriction is recognised without this class having to list them.
     *
     * @param string $directive
     * @return bool
     */
    private function isRestriction(string $directive): bool
    {
        return str_starts_with(strtolower($directive), 'no');
    }

    /**
     * Split a robots value into trimmed, non-empty directives.
     *
     * @param string $value
     * @return string[]
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $token): bool => $token !== ''
        ));
    }
}
