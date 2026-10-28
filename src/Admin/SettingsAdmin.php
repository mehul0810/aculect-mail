<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Alerts\FailureAlertSettings;
use OneSMTP\Alerts\FailureAlertSettingsRepository;
use OneSMTP\Core\Capabilities;
use OneSMTP\Core\RetentionPolicy;
use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Settings\AttachmentLoggingSettings;
use OneSMTP\Settings\AttachmentLoggingSettingsRepository;
use OneSMTP\Settings\BackgroundSendingSettings;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\RateLimitSettings;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SettingsTransferService;
use OneSMTP\Settings\SimulationModeSettings;
use OneSMTP\Settings\SimulationModeSettingsRepository;
use OneSMTP\Summary\WeeklySummarySettings;
use OneSMTP\Summary\WeeklySummarySettingsRepository;
use RuntimeException;

final class SettingsAdmin
{
    private const ACTION_NAME = 'onesmtp_save_settings';
    private const NONCE_NAME = 'onesmtp_settings_nonce';
    private const EXPORT_ACTION = 'export_settings';
    private const IMPORT_ACTION = 'import_settings';
    private const EXPORT_NONCE_NAME = 'onesmtp_settings_export_nonce';
    private const IMPORT_NONCE_NAME = 'onesmtp_settings_import_nonce';

    public function __construct(
        private ?SenderIdentityRepository $senderIdentity = null,
        private ?RateLimitSettingsRepository $rateLimits = null,
        private ?FailureAlertSettingsRepository $failureAlerts = null,
        private ?BackgroundSendingSettingsRepository $backgroundSending = null,
        private ?SettingsTransferService $transfers = null,
        private ?AttachmentLoggingSettingsRepository $attachmentLogging = null,
        private ?WeeklySummarySettingsRepository $weeklySummary = null,
        private ?AdminAuditLogger $auditLogger = null,
        private ?AdminRequest $request = null,
        private ?SimulationModeSettingsRepository $simulationMode = null,
        private ?MailDeliveryOwnership $deliveryOwnership = null
    ) {
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->rateLimits = $rateLimits ?? new RateLimitSettingsRepository();
        $this->failureAlerts = $failureAlerts ?? new FailureAlertSettingsRepository();
        $this->backgroundSending = $backgroundSending ?? new BackgroundSendingSettingsRepository();
        $this->transfers = $transfers ?? new SettingsTransferService();
        $this->attachmentLogging = $attachmentLogging ?? new AttachmentLoggingSettingsRepository();
        $this->weeklySummary = $weeklySummary ?? new WeeklySummarySettingsRepository();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
        $this->request = $request ?? new AdminRequest();
        $this->simulationMode = $simulationMode ?? new SimulationModeSettingsRepository();
        $this->deliveryOwnership = $deliveryOwnership ?? new MailDeliveryOwnership();
    }

    public function handleRequest(): void
    {
        if ( ! in_array(($GLOBALS['pagenow'] ?? ''), ['admin.php', 'options-general.php'], true)) {
            return;
        }

        $method = $this->request->method();
        if ($method === 'GET') {
            $action = $this->request->getAction('onesmtp_settings_action');
            if ($action === self::EXPORT_ACTION) {
                $this->handleExport();
            }

            return;
        }

        $action = $this->request->postAction('onesmtp_settings_action');

        if ($action !== self::IMPORT_ACTION) {
            if ( ! Capabilities::canManage()) {
                wp_die(
                    esc_html__('You are not allowed to manage Aculect Mail settings.', 'onesmtp'),
                    esc_html__('Aculect Mail access denied', 'onesmtp'),
                    ['response' => 403]
                );
            }
            check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);
        }

        try {
            if ($action === self::IMPORT_ACTION) {
                $this->handleImport();
                return;
            }

            if ($action === 'save_rate_limits') {
                $limits = RateLimitSettings::fromArray([
                    'per_minute' => isset($_POST['rate_limit_per_minute']) ? wp_unslash( (string) $_POST['rate_limit_per_minute']) : 0,
                    'per_hour' => isset($_POST['rate_limit_per_hour']) ? wp_unslash( (string) $_POST['rate_limit_per_hour']) : 0,
                    'per_day' => isset($_POST['rate_limit_per_day']) ? wp_unslash( (string) $_POST['rate_limit_per_day']) : 0,
                ]);
                $this->rateLimits->save($limits);
                $this->auditLogger->logSettingsChange('rate_limits', array_merge(
                    $limits->toArray(),
                    ['source' => 'settings_admin']
                ));
                $this->redirect('rate_limits_saved');
                return;
            }

            if ($action === 'save_failure_alerts') {
                $storedAlerts = $this->failureAlerts->get()->toArray();
                $alerts = FailureAlertSettings::fromArray([
                    'email_enabled' => isset($_POST['failure_alert_email_enabled']),
                    'email_recipients' => isset($_POST['failure_alert_email_recipients']) ? wp_unslash( (string) $_POST['failure_alert_email_recipients']) : ( ! isset($_POST['failure_alert_email_enabled']) ? $storedAlerts['email_recipients'] : ''),
                    'webhook_enabled' => isset($_POST['failure_alert_webhook_enabled']),
                    'webhook_url' => isset($_POST['failure_alert_webhook_url']) ? wp_unslash( (string) $_POST['failure_alert_webhook_url']) : ( ! isset($_POST['failure_alert_webhook_enabled']) ? $storedAlerts['webhook_url'] : ''),
                    'throttle_seconds' => isset($_POST['failure_alert_throttle_seconds']) ? wp_unslash( (string) $_POST['failure_alert_throttle_seconds']) : 900,
                ]);
                $this->failureAlerts->save($alerts);
                $alertValues = $alerts->toArray();
                $this->auditLogger->logSettingsChange('failure_alerts', [
                    'source' => 'settings_admin',
                    'email_enabled' => ! empty($alertValues['email_enabled']),
                    'email_recipient_count' => count( (array) ($alertValues['email_recipients'] ?? [])),
                    'webhook_enabled' => ! empty($alertValues['webhook_enabled']),
                    'throttle_seconds' => (int) ($alertValues['throttle_seconds'] ?? 0),
                ]);
                $this->redirect('failure_alerts_saved');
                return;
            }

            if ($action === 'save_background_sending') {
                $background = BackgroundSendingSettings::fromArray([
                    'enabled' => isset($_POST['background_sending_enabled']),
                ]);
                $this->backgroundSending->save($background);
                $this->auditLogger->logSettingsChange('background_sending', [
                    'source' => 'settings_admin',
                    'enabled' => $background->toArray()['enabled'] ?? false,
                ]);
                $this->redirect('background_sending_saved');
                return;
            }

            if ($action === 'save_simulation_mode') {
                if (isset($_POST['simulation_mode_enabled']) && ! $this->deliveryOwnership->canAculectDeliver()) {
                    $this->redirect(
                        'simulation_mode_owner_conflict',
                        __('Simulation mode cannot be enabled while SureMail owns live WordPress delivery. Complete or pause the migration first.', 'onesmtp')
                    );
                    return;
                }
                $simulation = SimulationModeSettings::fromArray([
                    'enabled' => isset($_POST['simulation_mode_enabled']),
                ]);
                $this->simulationMode->save($simulation);
                $this->auditLogger->logSettingsChange('simulation_mode', [
                    'source' => 'settings_admin',
                    'enabled' => $simulation->isEnabled(),
                ]);
                $this->redirect('simulation_mode_saved');
                return;
            }

            if ($action === 'save_attachment_logging') {
                $attachmentLogging = AttachmentLoggingSettings::fromArray([
                    'enabled' => isset($_POST['attachment_logging_enabled']),
                ]);
                $this->attachmentLogging->save($attachmentLogging);
                $this->auditLogger->logSettingsChange('attachment_logging', [
                    'source' => 'settings_admin',
                    'enabled' => $attachmentLogging->toArray()['enabled'] ?? false,
                ]);
                $this->redirect('attachment_logging_saved');
                return;
            }

            if ($action === 'save_weekly_summary') {
                $storedSummary = $this->weeklySummary->get()->toArray();
                $summary = WeeklySummarySettings::fromArray([
                    'enabled' => isset($_POST['weekly_summary_enabled']),
                    'email_recipients' => isset($_POST['weekly_summary_email_recipients']) ? wp_unslash( (string) $_POST['weekly_summary_email_recipients']) : ( ! isset($_POST['weekly_summary_enabled']) ? $storedSummary['email_recipients'] : ''),
                ]);
                $this->weeklySummary->save($summary);
                $summaryValues = $summary->toArray();
                $this->auditLogger->logSettingsChange('weekly_summary', [
                    'source' => 'settings_admin',
                    'enabled' => ! empty($summaryValues['enabled']),
                    'recipient_count' => count( (array) ($summaryValues['email_recipients'] ?? [])),
                ]);
                $this->redirect('weekly_summary_saved');
                return;
            }

            if ($action !== 'save_sender_identity') {
                return;
            }

            $identityValues = [
                'from_email' => isset($_POST['from_email']) ? wp_unslash( (string) $_POST['from_email']) : '',
                'from_name' => isset($_POST['from_name']) ? wp_unslash( (string) $_POST['from_name']) : '',
                'reply_to' => isset($_POST['reply_to']) ? wp_unslash( (string) $_POST['reply_to']) : '',
                'bcc' => isset($_POST['bcc']) ? wp_unslash( (string) $_POST['bcc']) : '',
                'force_from_email' => isset($_POST['force_from_email']),
                'force_from_name' => isset($_POST['force_from_name']),
                'force_reply_to' => isset($_POST['force_reply_to']),
                'force_bcc' => isset($_POST['force_bcc']),
            ];
            // The guided first step edits only the sender name and address.
            // Preserve explicit header overrides and other saved identity fields.
            if ($this->request->postAction('onesmtp_return_tab') === 'onesmtp-overview') {
                $identityValues = array_replace($this->senderIdentity->get()->toArray(), [
                    'from_email' => $identityValues['from_email'],
                    'from_name' => $identityValues['from_name'],
                ]);
            }
            $identity = SenderIdentity::fromArray($identityValues);

            $this->senderIdentity->save($identity);
            $values = $identity->toArray();
            $this->auditLogger->logSettingsChange('sender_identity', [
                'source' => 'settings_admin',
                'reply_to_count' => count( (array) ($values['reply_to'] ?? [])),
                'bcc_count' => count( (array) ($values['bcc'] ?? [])),
                'force_from_email' => ! empty($values['force_from_email']),
                'force_from_name' => ! empty($values['force_from_name']),
                'force_reply_to' => ! empty($values['force_reply_to']),
                'force_bcc' => ! empty($values['force_bcc']),
            ]);
            $this->redirect('saved');
        } catch (InvalidArgumentException $e) {
            $this->redirect('invalid', $e->getMessage());
        }
    }

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $status = isset($_GET['onesmtp_settings_status']) ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_settings_status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $message = isset($_GET['onesmtp_settings_message']) ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_settings_message'])) : '';

        echo '<div class="onesmtp-settings-shell">';
        $this->renderStatusNotice($status, $message);
        SettingsView::render($this->senderIdentity->get()->toArray(), $this->failureAlerts->get()->toArray(), $this->weeklySummary->get()->toArray());
        echo '</div>';
    }

    /**
     * Render delivery controls and data-transfer operations intended for
     * experienced administrators. This is kept separate from daily settings
     * so sender identity and notifications remain easy to scan.
     */
    public function renderAdvanced(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $status = isset($_GET['onesmtp_settings_status']) ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_settings_status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $message = isset($_GET['onesmtp_settings_message']) ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_settings_message'])) : '';
        $limits = $this->rateLimits->get()->toArray();
        $backgroundSending = $this->backgroundSending->get();
        $attachmentLogging = $this->attachmentLogging->get();
        $simulationMode = $this->simulationMode->get();
        $actionUrl = admin_url('options-general.php?page=onesmtp&tab=onesmtp-advanced#onesmtp-advanced');

        echo '<div class="onesmtp-settings-shell onesmtp-advanced-settings-shell">';
        $this->renderStatusNotice($status, $message);
        echo '<div class="onesmtp-settings-grid"><div data-onesmtp-advanced-group="delivery">';

        $this->renderPanel(
            __('Simulation mode', 'onesmtp'),
            __('For staging and development only. Aculect Mail captures outgoing messages in the email log as Simulated and never contacts a provider or reports them as delivered.', 'onesmtp'),
            false,
            function () use ($simulationMode, $actionUrl): void {
                if ( ! $this->deliveryOwnership->canAculectDeliver()) {
                    $this->renderInlineNotice('error', __('SureMail currently owns live WordPress delivery. Aculect Mail simulation mode cannot guarantee that messages are captured instead of sent until delivery ownership is migrated.', 'onesmtp'));
                }
                if ($simulationMode->isEnabled()) {
                    $this->renderInlineNotice('warning', __('Simulation mode is active. WordPress mail calls return successfully, but no email is sent.', 'onesmtp'));
                }
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_simulation_mode">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                AdminUi::nonce(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('simulation_mode_enabled', __('Enable simulation mode on this site.', 'onesmtp'), $simulationMode->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save simulation mode', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Delivery rate limits', 'onesmtp'),
            __('Set optional site-wide delivery caps. When a cap is exhausted, Aculect Mail defers queued mail until capacity is available. Use 0 to disable a limit.', 'onesmtp'),
            false,
            function () use ($limits, $actionUrl): void {
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_rate_limits">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                AdminUi::nonce(self::ACTION_NAME, self::NONCE_NAME);
                echo '<div class="onesmtp-rate-fields">';
                AdminUi::field('rate_limit_per_minute', __('Per-minute limit', 'onesmtp'), (string) ($limits['per_minute'] ?? 0), 'number');
                AdminUi::field('rate_limit_per_hour', __('Hourly limit', 'onesmtp'), (string) ($limits['per_hour'] ?? 0), 'number');
                AdminUi::field('rate_limit_per_day', __('Daily limit', 'onesmtp'), (string) ($limits['per_day'] ?? 0), 'number');
                echo '</div>';
                $this->renderActionFooter(__('Save delivery rate limits', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Background sending', 'onesmtp'),
            __('Queue normal WordPress mail for asynchronous delivery so user-facing requests are not held by provider latency. Provider test emails and manual resends continue to run synchronously.', 'onesmtp'),
            false,
            function () use ($backgroundSending, $actionUrl): void {
                if ( ! (new \OneSMTP\Queue\ActionSchedulerHealth())->isAvailable()) {
                    AdminUi::notice(__('Scheduler unavailable', 'onesmtp'), 'warning', __('Background sending and automatic retries require Action Scheduler. Review Diagnostics before enabling background sending.', 'onesmtp'));
                }
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_background_sending">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                AdminUi::nonce(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('background_sending_enabled', __('Enable background sending for normal mail.', 'onesmtp'), $backgroundSending->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save background sending', 'onesmtp'));
                echo '</form>';
            }
        );

        echo '</div><div data-onesmtp-advanced-group="privacy">';
        $this->renderPanel(
            __('Attachment logging', 'onesmtp'),
            __('When enabled, Aculect Mail stores attachment metadata only: count, safe filename, extension, and file size when available. File contents and raw server paths are not copied into logs.', 'onesmtp'),
            false,
            function () use ($attachmentLogging, $actionUrl): void {
                if ( ! $attachmentLogging->isEnabled()) {
                    $this->renderInlineNotice('info', __('Attachment logging is off. Aculect Mail removes raw attachment paths from stored log payloads by default.', 'onesmtp'));
                }

                echo '<p class="description">';
                echo esc_html(
                    sprintf(
                        /* translators: %d: log retention days. */
                        __('Attachment metadata is deleted with the parent email log according to the current %d-day log retention policy. Messages with file attachments may not preserve attachments for background retries or manual resend unless the source can provide them again.', 'onesmtp'),
                        RetentionPolicy::getLogRetentionDays()
                    )
                );
                echo '</p>';
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_attachment_logging">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                AdminUi::nonce(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('attachment_logging_enabled', __('Enable privacy-safe attachment metadata in email logs.', 'onesmtp'), $attachmentLogging->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save attachment logging', 'onesmtp'));
                echo '</form>';
            }
        );

        echo '</div><div data-onesmtp-advanced-group="transfer">';
        $this->renderPanel(
            __('Settings import/export', 'onesmtp'),
            __('Move safe Aculect Mail configuration between environments without exposing secrets, credentials, raw recipients, headers, or payload data.', 'onesmtp'),
            true,
            function (): void {
                $this->renderImportExport('onesmtp-advanced');
            }
        );

        echo '</div></div>';
        echo '</div>';
    }



    private function renderCheckbox(string $name, string $label, bool $checked): void
    {
        AdminUi::toggle($name, $label, $checked);
    }


    private function renderImportExport(string $returnTab): void
    {
        $returnUrl = admin_url('options-general.php?page=onesmtp&tab=' . rawurlencode($returnTab) . '#' . $returnTab);
        $downloadUrl = add_query_arg(
            [
                'page' => 'onesmtp',
                'onesmtp_settings_action' => self::EXPORT_ACTION,
                self::EXPORT_NONCE_NAME => wp_create_nonce(self::EXPORT_ACTION),
            ],
            $returnUrl
        );

        echo '<p>' . esc_html__('Download a privacy-safe JSON settings file for migration or backup. Provider secrets, credentials, tokens, passwords, API keys, webhook URLs, raw recipients, message bodies, raw headers, and payload JSON are excluded by default.', 'onesmtp') . '</p>';
        echo '<div class="onesmtp-settings-actions onesmtp-settings-actions--static">';
        echo '<a class="button button-secondary" href="' . esc_url($downloadUrl) . '">' . esc_html__('Download safe settings export', 'onesmtp') . '</a>';
        echo '</div>';

        echo '<form class="onesmtp-settings-form onesmtp-settings-import" method="post" action="' . esc_url($returnUrl) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="' . esc_attr(self::IMPORT_ACTION) . '">';
        echo '<input type="hidden" name="onesmtp_return_tab" value="' . esc_attr($returnTab) . '">';
        AdminUi::nonce(self::IMPORT_ACTION, self::IMPORT_NONCE_NAME);
        echo '<p><label for="onesmtp-settings-import-json">' . esc_html__('Import safe settings JSON', 'onesmtp') . '</label></p>';
        echo '<textarea id="onesmtp-settings-import-json" class="large-text code" rows="10" name="onesmtp_settings_import_json" spellcheck="false"></textarea>';
        echo '<p class="description">' . esc_html__('Only supported non-secret settings are imported. Secret, credential, webhook URL, raw recipient, message body, raw header, and payload fields are ignored.', 'onesmtp') . '</p>';
        $this->renderActionFooter(__('Import safe settings', 'onesmtp'), 'secondary');
        echo '</form>';
    }

    private function renderStatusNotice(string $status, string $message): void
    {
        $noticeClass = '';
        $noticeText = '';

        if ($status === 'saved') {
            $noticeClass = 'success';
            $noticeText = __('Sender identity settings saved.', 'onesmtp');
        } elseif ($status === 'rate_limits_saved') {
            $noticeClass = 'success';
            $noticeText = __('Delivery rate limit settings saved.', 'onesmtp');
        } elseif ($status === 'failure_alerts_saved') {
            $noticeClass = 'success';
            $noticeText = __('Failure alert settings saved.', 'onesmtp');
        } elseif ($status === 'background_sending_saved') {
            $noticeClass = 'success';
            $noticeText = __('Background sending settings saved.', 'onesmtp');
        } elseif ($status === 'simulation_mode_saved') {
            $noticeClass = 'success';
            $noticeText = __('Simulation mode settings saved.', 'onesmtp');
        } elseif ($status === 'simulation_mode_owner_conflict') {
            $noticeClass = 'error';
            $noticeText = $message !== '' ? $message : __('Simulation mode cannot be enabled while another plugin owns live delivery.', 'onesmtp');
        } elseif ($status === 'attachment_logging_saved') {
            $noticeClass = 'success';
            $noticeText = __('Attachment logging settings saved.', 'onesmtp');
        } elseif ($status === 'weekly_summary_saved') {
            $noticeClass = 'success';
            $noticeText = __('Weekly delivery summary settings saved.', 'onesmtp');
        } elseif ($status === 'imported') {
            $noticeClass = 'success';
            $noticeText = $message !== '' ? $message : __('Aculect Mail settings imported. Secrets and recipient fields were excluded.', 'onesmtp');
        } elseif ($status === 'invalid') {
            $noticeClass = 'error';
            $noticeText = $message !== '' ? $message : __('Aculect Mail settings could not be saved.', 'onesmtp');
        }

        if ($noticeClass === '' || $noticeText === '') {
            return;
        }

        echo '<div class="onesmtp-settings-notices">';
        $this->renderInlineNotice($noticeClass, $noticeText);
        echo '</div>';
    }

    /**
     * @param callable():void $content
     */
    private function renderPanel(string $title, string $description, bool $fullWidth, callable $content): void
    {
        $classes = 'onesmtp-settings-panel postbox';
        if ($fullWidth) {
            $classes .= ' onesmtp-settings-panel--full';
        }

        echo '<section class="' . esc_attr($classes) . '">';
        echo '<div class="postbox-header"><h3 class="hndle">' . esc_html($title) . '</h3></div>';
        echo '<div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html($description) . '</p>';
        $content();
        echo '</div>';
        echo '</section>';
    }

    private function renderInlineNotice(string $type, string $message): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderActionFooter(string $label, string $type = 'primary'): void
    {
        echo '<div class="onesmtp-settings-actions">';
        AdminUi::submit($label, $type, 'submit', false);
        echo '</div>';
    }

    private function handleExport(): void
    {
        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to export Aculect Mail settings.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_GET[ self::EXPORT_NONCE_NAME ]) ? sanitize_text_field(wp_unslash( (string) $_GET[ self::EXPORT_NONCE_NAME ])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::EXPORT_ACTION)) {
            wp_die(
                esc_html__('The Aculect Mail settings export link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('Aculect Mail export denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $payload = $this->transfers->export();

        if ( ! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=aculect-mail-safe-settings.json');
            header('X-Content-Type-Options: nosniff');
        }

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->isTestingRuntime()) {
            throw new RuntimeException('Aculect Mail settings exported.');
        }

        exit;
    }

    private function handleImport(): void
    {
        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to import Aculect Mail settings.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_POST[ self::IMPORT_NONCE_NAME ]) ? sanitize_text_field(wp_unslash( (string) $_POST[ self::IMPORT_NONCE_NAME ])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::IMPORT_ACTION)) {
            wp_die(
                esc_html__('The Aculect Mail settings import form has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('Aculect Mail import denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $json = isset($_POST['onesmtp_settings_import_json']) ? wp_unslash( (string) $_POST['onesmtp_settings_import_json']) : '';
        $summary = $this->transfers->importJson($json, self::IMPORT_NONCE_NAME);
        $this->auditLogger->logSettingsChange('import', array_merge($summary, ['source' => 'settings_admin']));
        $message = sprintf(
            /* translators: 1: imported settings group count, 2: imported provider count, 3: excluded field count. */
            __('Imported %1$d settings groups and %2$d providers. Excluded %3$d unsafe fields.', 'onesmtp'),
            count($summary['settings_groups']),
            $summary['providers_imported'],
            $summary['excluded_fields']
        );

        $this->redirect('imported', $message);
    }

    private function redirect(string $status, string $message = ''): void
    {
        $args = ['onesmtp_settings_status' => $status];
        if ($message !== '') {
            $args['onesmtp_settings_message'] = $message;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized redirect tab only; mutation handlers already check capability and their action nonce.
        $returnTab = isset($_POST['onesmtp_return_tab']) ? sanitize_key(wp_unslash( (string) $_POST['onesmtp_return_tab'])) : '';
        $returnTarget = match ($returnTab) {
            'onesmtp-overview' => '#onesmtp-overview',
            'onesmtp-advanced' => '#onesmtp-advanced',
            default => '#onesmtp-settings',
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Redirect-only state after the mutation handler's capability and nonce checks.
        $action = isset($_POST['onesmtp_settings_action']) ? sanitize_key(wp_unslash( (string) $_POST['onesmtp_settings_action'])) : '';
        if ($returnTab !== 'onesmtp-overview' && in_array($action, ['save_failure_alerts', 'save_weekly_summary'], true)) {
            $returnTab = 'onesmtp-settings';
            $returnTarget = '#onesmtp-settings-notifications';
        } elseif ($returnTab === 'onesmtp-advanced' && $action === 'save_attachment_logging') {
            $returnTarget = '#onesmtp-advanced-privacy';
        } elseif ($returnTab === 'onesmtp-advanced' && $status === 'imported') {
            $returnTarget = '#onesmtp-advanced-transfer';
        }
        $returnUrl = 'options-general.php?page=onesmtp' . ($returnTab !== '' ? '&tab=' . rawurlencode($returnTab) : '') . $returnTarget;
        wp_safe_redirect(add_query_arg($args, admin_url($returnUrl)));
        throw new RuntimeException('Aculect Mail settings admin redirected.');
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
