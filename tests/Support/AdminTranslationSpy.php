<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

/** Observe translation requests in admin unit tests while retaining the shared stub's behavior. */
function __(string $text, string $domain = 'default'): string
{
    if (isset($GLOBALS['onesmtp_test_translations'])) {
        $GLOBALS['onesmtp_test_translations'][] = [
            'text' => $text,
            'domain' => $domain,
        ];
    }

    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test spy forwards the original call-site arguments; it introduces no translatable strings.
    return \__($text, $domain);
}
