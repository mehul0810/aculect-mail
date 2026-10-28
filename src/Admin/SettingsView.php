<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

/** Presentation only; SettingsAdmin retains permission, nonce and save handling. */
final class SettingsView
{
    public static function render(array $identity, array $alerts, array $summary): void
    {
        echo '<div class="onesmtp-subnav" data-onesmtp-subnav="settings"><nav aria-label="' . esc_attr__('Settings sections', 'onesmtp') . '">';
        foreach ([
			'general' => __('General', 'onesmtp'),
			'notifications' => __('Notifications', 'onesmtp'),
		] as $key => $label) {
            echo '<a data-onesmtp-subtab="' . esc_attr($key) . '" href="#onesmtp-settings-' . esc_attr($key) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        echo '<div class="onesmtp-settings-group" data-onesmtp-settings-group="general"><div class="onesmtp-sender-layout">';
        self::sender($identity);
        echo '<aside class="onesmtp-sender-preview"><div class="onesmtp-dark-panel"><p class="onesmtp-eyebrow">' . esc_html__('Sender preview', 'onesmtp') . '</p><h3 data-onesmtp-preview-name>' . esc_html( (string) ($identity['from_name'] ?: __('Sender name', 'onesmtp'))) . '</h3><p data-onesmtp-preview-email>' . esc_html( (string) ($identity['from_email'] ?: __('Not configured', 'onesmtp'))) . '</p></div></aside></div></div>';
        echo '<div class="onesmtp-settings-group" data-onesmtp-settings-group="notifications"><div class="onesmtp-notifications-layout">';
        self::alerts($alerts);
        echo '<div>';
        self::summary($summary);
        echo '</div></div></div>';
    }

    private static function sender(array $values): void
    {
        echo '<section class="onesmtp-panel"><h3 class="onesmtp-panel-title">';
        AdminUi::icon('user');
        echo esc_html__('Sender identity', 'onesmtp') . '</h3><p>' . esc_html__('Set the defaults for outgoing WordPress mail. Existing message headers stay intact unless you enable an override.', 'onesmtp') . '</p>';
        self::formOpen('save_sender_identity', 'general');
        $fields = [
            ['from_name', __('From name', 'onesmtp'), 'text', __('The name people see in their inbox.', 'onesmtp'), __('Override existing name', 'onesmtp')],
            ['from_email', __('From email', 'onesmtp'), 'email', __('Verify this address with your provider.', 'onesmtp'), __('Override existing email', 'onesmtp')],
            ['reply_to', __('Reply-To', 'onesmtp'), 'text', __('Separate multiple addresses with commas.', 'onesmtp'), __('Override Reply-To', 'onesmtp')],
            ['bcc', __('BCC (optional)', 'onesmtp'), 'text', __('These recipients receive a hidden copy.', 'onesmtp'), __('Override BCC', 'onesmtp')],
        ];
        echo '<div class="onesmtp-fields-grid">';
        foreach ($fields as [$name, $label, $type, $help, $override]) {
            $value = $values[ $name ] ?? '';
            echo '<div class="onesmtp-sender-field">';
            AdminUi::field($name, $label, is_array($value) ? implode(', ', $value) : (string) $value, $type, $help);
            AdminUi::toggle('force_' . $name, $override, ! empty($values[ 'force_' . $name ]), __('Apply this value even when a message supplies its own.', 'onesmtp'));
            echo '</div>';
        }
        echo '</div>';
        AdminUi::notice(__('Defaults apply only when needed', 'onesmtp'), 'info', __('Each override is explicit. With an override off, plugin and theme headers are preserved.', 'onesmtp'));
        echo '<div class="onesmtp-form-footer"><p>' . esc_html__('Changes apply to future emails.', 'onesmtp') . '</p>';
        self::save(__('Save sender identity', 'onesmtp'), true);
        echo '</div></form></section>';
    }

    private static function alerts(array $values): void
    {
        $emailEnabled = ! empty($values['email_enabled']);
        $webhookEnabled = ! empty($values['webhook_enabled']);
        echo '<section class="onesmtp-panel"><h3>' . esc_html__('Failure alerts', 'onesmtp') . '</h3><p>' . esc_html__('Notify your team when a message reaches a terminal delivery failure.', 'onesmtp') . '</p>';
        self::formOpen('save_failure_alerts', 'notifications');
        AdminUi::toggle('failure_alert_email_enabled', __('Email alerts', 'onesmtp'), $emailEnabled, __('Send a privacy-safe failure summary to your team.', 'onesmtp'));
        AdminUi::field('failure_alert_email_recipients', __('Alert recipients', 'onesmtp'), implode(', ', (array) ($values['email_recipients'] ?? [])), 'text', __('Enable email alerts to edit recipients. Separate addresses with commas.', 'onesmtp'), false, 'failure_alert_email_enabled', ! $emailEnabled);
        echo '<hr>';
        AdminUi::toggle('failure_alert_webhook_enabled', __('HTTPS webhook', 'onesmtp'), $webhookEnabled, __('Send safe event details to your own endpoint.', 'onesmtp'));
        AdminUi::field('failure_alert_webhook_url', __('Webhook URL', 'onesmtp'), (string) ($values['webhook_url'] ?? ''), 'url', __('Enable HTTPS webhook to edit. Use HTTPS; excluded from settings exports.', 'onesmtp'), false, 'failure_alert_webhook_enabled', ! $webhookEnabled);
        AdminUi::field('failure_alert_throttle_seconds', __('Throttle window (seconds)', 'onesmtp'), (string) ($values['throttle_seconds'] ?? 900), 'number', __('Limits repeated notifications for the same condition.', 'onesmtp'));
        AdminUi::notice(__('No message content is shared', 'onesmtp'), 'info', __('Alert payloads contain IDs, hashes, status, provider summary, reason, and category only.', 'onesmtp'));
        self::save(__('Save failure alerts', 'onesmtp'), true);
        echo '</form></section>';
    }

    private static function summary(array $values): void
    {
        $enabled = ! empty($values['enabled']);
        echo '<section class="onesmtp-panel"><h3>' . esc_html__('Weekly delivery summary', 'onesmtp') . '</h3><p>' . esc_html__('One email with aggregate delivery health: sent, failed, retried, pending, and failover counts.', 'onesmtp') . '</p>';
        self::formOpen('save_weekly_summary', 'notifications');
        AdminUi::toggle('weekly_summary_enabled', __('Enable summary email', 'onesmtp'), $enabled, __('Requires at least one recipient.', 'onesmtp'));
        AdminUi::field('weekly_summary_email_recipients', __('Summary recipients', 'onesmtp'), implode(', ', (array) ($values['email_recipients'] ?? [])), 'text', __('Enable the weekly summary to edit recipients. Separate addresses with commas.', 'onesmtp'), false, 'weekly_summary_enabled', ! $enabled);
        self::save(__('Save weekly delivery summary', 'onesmtp'));
        echo '</form></section>';
    }

    private static function formOpen(string $action, string $section): void
    {
        echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-settings#onesmtp-settings-' . $section)) . '"><input type="hidden" name="onesmtp_settings_action" value="' . esc_attr($action) . '">';
        AdminUi::nonce('onesmtp_save_settings', 'onesmtp_settings_nonce');
    }

    private static function save(string $label, bool $primary = false): void
    {
        echo '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button>';
    }
}
