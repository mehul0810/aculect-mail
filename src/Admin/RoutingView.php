<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Queue\ActionSchedulerHealth;

/** Explain the existing selection policy without predicting a weighted choice. */
final class RoutingView
{
    public static function render(array $providers): void
    {
        echo '<div class="onesmtp-dark-panel onesmtp-routing-banner"><h3>';
        AdminUi::icon('arrows');
        echo esc_html__('Priority first. Failover when needed.', 'onesmtp') . '</h3><p>' . esc_html__('The highest-priority healthy provider is selected. Lower priority numbers are evaluated first.', 'onesmtp') . '</p></div><div class="onesmtp-routing-layout"><section class="onesmtp-panel onesmtp-routing-card"><h3>' . esc_html__('Default delivery path', 'onesmtp') . '</h3><div class="onesmtp-route-diagram">';
        foreach ([['envelope', __('WordPress', 'onesmtp'), __('A new message', 'onesmtp')], ['server-stack', $providers === [] ? __('No active provider', 'onesmtp') : __('Healthy provider', 'onesmtp'), __('Priority, weight and health', 'onesmtp')], ['check-circle', __('Provider accepted', 'onesmtp'), __('Recorded as a sent attempt', 'onesmtp')]] as $index => [$icon, $label, $description]) {
            if ($index > 0) {
                echo '<span class="onesmtp-route-line" aria-hidden="true">';
                AdminUi::icon('arrow-right');
                echo '</span>';
            }
            echo '<div class="onesmtp-route-node">';
            AdminUi::icon($icon);
            echo '<strong>' . esc_html($label) . '</strong><small>' . esc_html($description) . '</small></div>';
        }
        echo '</div>';
        if ($providers === []) {
            AdminUi::notice(__('Connect a provider to activate routing and failover.', 'onesmtp'));
        } else {
            echo '<div class="onesmtp-route-connections"><h4>' . esc_html__('Active connections', 'onesmtp') . '</h4><ul>';
            foreach ($providers as $provider) {
                echo '<li><strong>' . esc_html( (string) ($provider['name'] ?? '')) . '</strong><span>' . esc_html(sprintf(
                    /* translators: 1: provider priority, 2: provider weight. */
                    __('Priority %1$d · Weight %2$d', 'onesmtp'),
                    (int) ($provider['priority'] ?? 100),
                    (int) ($provider['weight'] ?? 1)
                )) . '</span></li>';
            }
            echo '</ul><p>' . esc_html__('If a provider fails, Aculect Mail tries another healthy active provider when one is available. Every provider change is recorded with the attempt.', 'onesmtp') . '</p></div>';
        }
        AdminUi::notice(__('Provider acceptance is not inbox placement', 'onesmtp'), 'info', __('A successful API or SMTP response confirms that the provider accepted the message.', 'onesmtp'));
        AdminUi::link(__('Manage providers', 'onesmtp'), admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers'));
        echo '</section><aside class="onesmtp-context-note"><h3>' . esc_html__('How selection works', 'onesmtp') . '</h3><ol class="onesmtp-policy-list">';
        foreach ([[__('Priority', 'onesmtp'), __('Lower numbers are selected first.', 'onesmtp')], [__('Weight', 'onesmtp'), __('Weights influence selection when priorities are equal.', 'onesmtp')], [__('Provider health', 'onesmtp'), __('Open or cooling-down providers are skipped.', 'onesmtp')]] as [$title, $description]) {
            echo '<li><strong>' . esc_html($title) . '</strong><p>' . esc_html($description) . '</p></li>';
        }
        echo '</ol></aside></div><section class="onesmtp-panel"><h3>' . esc_html__('Retries and recovery', 'onesmtp') . '</h3><p>' . esc_html__('Retries continue through the delivery pipeline within each message’s attempt limit. Each attempt, provider switch, and terminal failure stays linked to the message.', 'onesmtp') . '</p>';
        if ( ! (new ActionSchedulerHealth())->isAvailable()) {
            AdminUi::notice(__('Automatic retries need Action Scheduler', 'onesmtp'), 'warning', __('Review diagnostics before relying on queued delivery.', 'onesmtp'));
        }
        AdminUi::link(__('Open queue diagnostics', 'onesmtp'), admin_url('options-general.php?page=onesmtp&tab=onesmtp-advanced#onesmtp-diagnostics'));
        echo '</section>';
    }
}
