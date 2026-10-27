<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentityRepository;

final class SetupWizard
{
    private const ACTION_NAME = 'onesmtp_setup_action';
    private const NONCE_NAME  = 'onesmtp_setup_nonce';

    private ProviderRepository $providers;
    private ProviderDeliveryManager $deliveryManager;
    private EventRepository $events;
    private AdminAuditLogger $auditLogger;
    private SenderIdentityRepository $senderIdentity;

    public function __construct(
        ?ProviderRepository $providers = null,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?EventRepository $events = null,
        ?AdminAuditLogger $auditLogger = null,
        ?SenderIdentityRepository $senderIdentity = null
    ) {
        $this->providers       = $providers ?? new ProviderRepository();
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->events          = $events ?? new EventRepository();
        $this->auditLogger     = $auditLogger ?? new AdminAuditLogger();
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
    }

    public function handleRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = isset($_POST[ self::ACTION_NAME ]) ? sanitize_key(wp_unslash( (string) $_POST[ self::ACTION_NAME ])) : '';
        if ($action === '') {
            return;
        }

        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to run the Aculect Mail setup wizard.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        if ($action === 'save_provider') {
            $provider = $this->normalizePostedProvider();
            $providerId = $this->providers->save($provider);
            if ($providerId > 0) {
                $this->events->add('setup_provider_saved', ['source' => 'setup_wizard'], null, $providerId);
                $this->auditLogger->logProviderChange('created', $providerId, [
                    'source' => 'setup_wizard',
                    'provider_name' => (string) ($provider['name'] ?? ''),
                    'adapter_type' => (string) ($provider['adapter_type'] ?? ''),
                    'is_active' => ! empty($provider['is_active']),
                    'safe_config_fields' => $this->safeConfigFieldNames($provider),
                    'credential_fields_updated' => $this->sensitiveConfigFieldNames($provider),
                ]);
            }

            $this->redirect($providerId > 0 ? 'provider_saved' : 'failed');
        }

        if ($action === 'send_test') {
            $this->handleTestEmail();
        }

        $this->redirect('failed');
    }

    public function render(): void
    {
        $providers = $this->providers->getAllSafe();
        $senderReady = $this->isSenderIdentityReady();
        $providerReady = $this->hasActiveProvider($providers);
        $testPassed = $this->latestStatus() === 'test_sent';
        $completed = (int) $senderReady + (int) $providerReady + (int) $testPassed;
        echo '<div id="onesmtp-delivery" class="onesmtp-setup-shell"><div class="onesmtp-setup-main">';
        $this->renderNotice();
        AdminUi::notice($completed === 3 ? __('Setup test passed', 'onesmtp') : __('Email delivery needs setup', 'onesmtp'), $completed === 3 ? 'success' : 'warning', sprintf(
            /* translators: %d: completed setup steps out of three. */
            __('%d of 3 essentials complete', 'onesmtp'), $completed
        ));
        echo '<div class="onesmtp-setup-panel">';
        $this->renderOverviewSetupCard($providers);
        echo '<div class="onesmtp-setup-content">';
        if ( ! $senderReady) {
            $this->renderSenderIdentityInlineForm();
        } elseif ( ! $providerReady) {
            echo '<h3>' . esc_html__('Connect a provider', 'onesmtp') . '</h3><p>' . esc_html__('Add credentials for your email service, then explicitly send a test. Saving a connection does not prove successful delivery.', 'onesmtp') . '</p>';
            AdminUi::link(__('Connect a provider', 'onesmtp'), admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers'), 'plus', true);
        } else {
            $this->renderTestForm($providers);
            $this->renderCompletion($providers);
        }
        echo '</div></div>';
        $this->renderHealthCards($providers);
        echo '</div></div>';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function renderOverviewSetupCard(array $providers): void
    {
        $steps = [
            [__('Sender identity', 'onesmtp'), __('Choose your default name and address.', 'onesmtp'), $this->isSenderIdentityReady()],
            [__('Connect a provider', 'onesmtp'), __('Add credentials for your email service.', 'onesmtp'), $this->hasActiveProvider($providers)],
            [__('Send a test', 'onesmtp'), __('Send a test, then check the recipient inbox.', 'onesmtp'), $this->latestStatus() === 'test_sent'],
        ];
        echo '<aside class="onesmtp-setup-progress onesmtp-dark-panel"><p class="onesmtp-eyebrow">' . esc_html__('Get connected', 'onesmtp') . '</p><h3>' . esc_html__('Your first test email starts here.', 'onesmtp') . '</h3><ol class="onesmtp-overview-steps">';
        $current = false;
        foreach ($steps as $index => [$label, $description, $complete]) {
            $state = $complete ? 'is-complete' : ( ! $current ? 'is-current' : 'is-pending');
            $current = $current || ! $complete;
            echo '<li class="onesmtp-overview-step ' . esc_attr($state) . '"><span class="onesmtp-overview-step-number">' . esc_html( (string) ($index + 1)) . '</span><span><strong>' . esc_html($label) . '</strong><small>' . esc_html($complete ? __('Complete', 'onesmtp') : $description) . '</small></span></li>';
        }
        echo '</ol><p class="onesmtp-setup-guidance">' . esc_html__('You control when credentials are saved and when a test email is sent.', 'onesmtp') . '</p></aside>';
    }

    private function renderSenderIdentityInlineForm(): void
    {
        $values = $this->senderIdentity->get()->toArray();
        echo '<div id="onesmtp-sender-identity-fallback" class="onesmtp-overview-inline-form"><div class="onesmtp-panel-heading"><h3>';
        AdminUi::icon('user');
        echo ' ' . esc_html__('Add your sender identity', 'onesmtp') . '</h3><span class="onesmtp-badge is-info">' . esc_html__('Step 1 of 3', 'onesmtp') . '</span></div><p>' . esc_html__('Use a name your recipients recognize and an email address verified with your provider.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-overview#onesmtp-sender-identity-fallback')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_sender_identity"><input type="hidden" name="onesmtp_return_tab" value="onesmtp-overview">';
        AdminUi::nonce('onesmtp_save_settings', 'onesmtp_settings_nonce');
        AdminUi::field('from_name', __('From name', 'onesmtp'), (string) ($values['from_name'] ?? ''), 'text', __('Example: your company or website name', 'onesmtp'), true, idPrefix: 'onesmtp-setup-');
        AdminUi::field('from_email', __('From email', 'onesmtp'), (string) ($values['from_email'] ?? ''), 'email', __('Your provider may require domain verification.', 'onesmtp'), true, idPrefix: 'onesmtp-setup-');
        echo '<p class="onesmtp-field-help">' . esc_html__('Existing message headers are preserved. Overrides are available in Settings.', 'onesmtp') . '</p><div class="onesmtp-form-footer"><span>' . esc_html__('Next: connect a provider', 'onesmtp') . '</span><button type="submit" class="button button-primary">' . esc_html__('Save and continue', 'onesmtp') . '</button></div></form></div>';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function renderHealthCards(array $providers): void
    {
        $queue = (new \OneSMTP\Repository\MessageRepository())->getQueueStatusSummary(gmdate('Y-m-d H:i:s', time() - 300));
        $scheduler = (new \OneSMTP\Queue\ActionSchedulerHealth())->isAvailable();
        $cards = [
            ['server-stack', __('Providers', 'onesmtp'), sprintf(/* translators: %d: configured connection count. */ __('%d connected', 'onesmtp'), count($providers)), __('Add a service to send WordPress email.', 'onesmtp'), __('View providers', 'onesmtp'), 'onesmtp-providers', 'onesmtp-providers'],
            ['inbox', __('Email queue', 'onesmtp'), sprintf(/* translators: %d: queued message count. */ __('%d waiting', 'onesmtp'), (int) $queue['queued_count']), __('Review delivery activity and the next safe action.', 'onesmtp'), __('Review activity', 'onesmtp'), 'onesmtp-activity', 'onesmtp-activity'],
            ['clock', __('Retry scheduler', 'onesmtp'), $scheduler ? __('Available', 'onesmtp') : __('Unavailable', 'onesmtp'), __('Action Scheduler is required for automatic retries.', 'onesmtp'), __('View diagnostics', 'onesmtp'), 'onesmtp-advanced', 'onesmtp-diagnostics'],
        ];
        echo '<div class="onesmtp-health-cards">';
        foreach ($cards as [$icon, $label, $value, $description, $action, $tab, $hash]) {
            echo '<section class="onesmtp-panel"><p class="onesmtp-health-label">';
            AdminUi::icon($icon);
            echo esc_html($label) . '</p><h3>' . esc_html($value) . '</h3><p>' . esc_html($description) . '</p>';
            AdminUi::link($action, admin_url('options-general.php?page=onesmtp&tab=' . $tab . '#' . $hash));
            echo '</section>';
        }
        echo '</div>';
    }


    private function isSenderIdentityReady(): bool
    {
        $values = $this->senderIdentity->get()->toArray();
        return trim( (string) ($values['from_email'] ?? '')) !== '' && trim( (string) ($values['from_name'] ?? '')) !== '';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderTestForm(array $providers): void
    {
        $this->renderPanelOpen(
            __('Test email', 'onesmtp'),
            __('Use a live provider from this workspace to confirm outbound delivery before you leave setup.', 'onesmtp')
        );

        if ( ! $this->hasActiveProvider($providers)) {
            echo '<p>' . esc_html__('Add and activate a provider before sending a setup test email.', 'onesmtp') . '</p>';
            $this->renderPanelClose();

            return;
        }

        echo '<form method="post">';
        AdminUi::nonce(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="send_test">';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderProviderSelect($providers);
        $this->renderTextInput('test_to', __('Recipient email', 'onesmtp'), (string) get_option('admin_email'), 'email');
        echo '</tbody></table>';
        AdminUi::submit(__('Send test email', 'onesmtp'));
        echo '</form>';
        $this->renderPanelClose();
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderCompletion(array $providers): void
    {
        echo '<div class="onesmtp-setup-confirmation">';
        echo '<h4>' . esc_html__('Confirmation', 'onesmtp') . '</h4><span class="screen-reader-text">' . esc_html__('Complete', 'onesmtp') . '</span>';

        if ( ! $this->hasActiveProvider($providers)) {
            echo '<p>' . esc_html__('Setup is incomplete. Add an active provider, then send a test email to finish the guided setup.', 'onesmtp') . '</p>';
            echo '</div>';

            return;
        }

        if ($this->activeProviderCount($providers) < 2) {
            echo '<p>' . esc_html__('A backup provider is recommended for failover. You can add one now or return later from Providers.', 'onesmtp') . '</p>';
        }

        echo '<p>' . esc_html__('Setup actions are written to the Aculect Mail event log so administrators can confirm the setup path is being recorded.', 'onesmtp') . '</p>';
        echo '</div>';
    }

    private function handleTestEmail(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
        $providerId = isset($_POST['provider_id']) ? absint(wp_unslash( (string) $_POST['provider_id'])) : 0;
        $provider = $providerId > 0 ? $this->providers->find($providerId) : null;
        if ( ! is_array($provider)) {
            $this->redirect('failed');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
        $to = isset($_POST['test_to']) ? sanitize_email(wp_unslash( (string) $_POST['test_to'])) : '';
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->redirect('invalid_recipient');
        }

        $result = $this->deliveryManager->send(
            $provider,
            [
                'to' => [$to],
                'subject' => '[Aculect Mail] Setup test email',
                'message' => 'This is a setup test email sent by Aculect Mail.',
                'headers' => [],
                'meta' => [
                    'source' => 'setup_wizard',
                ],
            ]
        );

        $this->events->add(
            'setup_test_email',
            [
                'ok' => $result->isSuccess(),
                'code' => $result->getCode(),
            ],
            null,
            (int) ($provider['id'] ?? $providerId)
        );

        $this->redirect($result->isSuccess() ? 'test_sent' : 'test_failed');
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePostedProvider(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
        $adapterType = isset($_POST['adapter_type']) ? sanitize_key(wp_unslash( (string) $_POST['adapter_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
        $fromEmail = isset($_POST['from_email']) ? sanitize_email(wp_unslash( (string) $_POST['from_email'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
        $fromName = isset($_POST['from_name']) ? sanitize_text_field(wp_unslash( (string) $_POST['from_name'])) : '';

        $config = [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];

        foreach (['host', 'port', 'username', 'password', 'api_key'] as $field) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
            $value = isset($_POST[ $field ]) ? sanitize_text_field(wp_unslash( (string) $_POST[ $field ])) : '';
            if ($value !== '') {
                $config[ $field ] = $value;
            }
        }

        return [
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private helper reached only after handleRequest() checks capability and the action nonce.
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash( (string) $_POST['name'])) : '',
            'adapter_type' => ProviderTypes::isSupported($adapterType) ? $adapterType : '',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'config' => array_filter($config, static fn (string $value): bool => $value !== ''),
        ];
    }

    private function renderTextInput(string $name, string $label, string $value = '', string $type = 'text'): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId($name)) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" id="' . esc_attr($this->fieldId($name)) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '">';
        echo '</td></tr>';
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<int,string>
     */
    private function safeConfigFieldNames(array $provider): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $fields = [];

        foreach ($config as $field => $value) {
            if ( ! is_scalar($value) || $value === '' || $this->isSensitiveConfigField( (string) $field)) {
                continue;
            }

            $fields[] = sanitize_key( (string) $field);
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<int,string>
     */
    private function sensitiveConfigFieldNames(array $provider): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $fields = [];

        foreach ($config as $field => $value) {
            if ( ! is_scalar($value) || $value === '' || ! $this->isSensitiveConfigField( (string) $field)) {
                continue;
            }

            $fields[] = sanitize_key( (string) $field);
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    private function isSensitiveConfigField(string $field): bool
    {
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key/i', $field);
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderProviderSelect(array $providers): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId('provider_id')) . '">' . esc_html__('Provider', 'onesmtp') . '</label></th><td>';
        echo '<select id="' . esc_attr($this->fieldId('provider_id')) . '" name="provider_id">';
        foreach ($providers as $provider) {
            if (empty($provider['is_active'])) {
                continue;
            }

            echo '<option value="' . esc_attr( (string) ( (int) ($provider['id'] ?? 0))) . '">' . esc_html( (string) ($provider['name'] ?? '')) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function hasActiveProvider(array $providers): bool
    {
        return $this->activeProviderCount($providers) > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function activeProviderCount(array $providers): int
    {
        $count = 0;

        foreach ($providers as $provider) {
            if ( ! empty($provider['is_active'])) {
                ++$count;
            }
        }

        return $count;
    }

    private function renderNotice(): void
    {
        $status = $this->latestStatus();
        if ($status === '') {
            return;
        }

        $messages = [
            'provider_saved' => __('Provider saved. Send a test email to verify setup.', 'onesmtp'),
            'test_sent' => __('Test email sent. Setup actions are being recorded in the event log.', 'onesmtp'),
            'test_failed' => __('Test email failed. Review provider credentials and try again.', 'onesmtp'),
            'invalid_recipient' => __('Enter a valid recipient email address for the test email.', 'onesmtp'),
            'failed' => __('Setup action failed.', 'onesmtp'),
        ];

        $message = $messages[ $status ] ?? '';
        if ($message === '') {
            return;
        }

        $class = in_array($status, ['provider_saved', 'test_sent'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderPanelOpen(string $title, string $description, bool $fullWidth = false): void
    {
        $classes = 'onesmtp-setup-panel postbox';
        if ($fullWidth) {
            $classes .= ' onesmtp-setup-panel--full';
        }

        echo '<section class="' . esc_attr($classes) . '">';
        echo '<div class="postbox-header">';
        echo '<h3 class="hndle">' . esc_html($title) . '</h3>';
        echo '</div>';
        echo '<div class="inside">';
        echo '<p class="onesmtp-setup-panel-description">' . esc_html($description) . '</p>';
    }

    private function renderPanelClose(): void
    {
        echo '</div></section>';
    }

    private function latestStatus(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        return isset($_GET['onesmtp_setup_status']) ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_setup_status'])) : '';
    }

    private function redirect(string $status): void
    {
        $url = add_query_arg(
            ['onesmtp_setup_status' => $status],
            admin_url('options-general.php?page=onesmtp#onesmtp-setup')
        );

        wp_safe_redirect($url);
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new \RuntimeException('Aculect Mail setup wizard redirected.');
        }

        exit;
    }

    private function fieldId(string $name): string
    {
        return 'onesmtp-setup-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    }
}
