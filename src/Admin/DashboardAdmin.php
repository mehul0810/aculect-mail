<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\MetricsRepository;

final class DashboardAdmin
{
    private const WINDOW_LAST_24_HOURS = 'last_24_hours';
    private const WINDOW_LAST_7_DAYS = 'last_7_days';
    private const WINDOW_LAST_30_DAYS = 'last_30_days';
    private const PROVIDER_WINDOW_KEY = self::WINDOW_LAST_7_DAYS;
    private const PROVIDER_NAME_LIMIT = 80;

    private MetricsRepository $metrics;

    /** @var callable():int */
    private $nowProvider;

    /**
     * @param callable():int|null $nowProvider
     */
    public function __construct(?MetricsRepository $metrics = null, ?callable $nowProvider = null)
    {
        $this->metrics = $metrics ?? new MetricsRepository();
        $this->nowProvider = $nowProvider ?? static fn (): int => time();
    }

    public function render(): void
    {
        if ( ! Capabilities::canViewLogs()) {
            wp_die(
                esc_html__('You do not have permission to view Aculect Mail dashboard metrics.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $windows = $this->windowSummaries();
        $pending = $this->metrics->getPendingSummary();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $selectedWindow = isset($_GET['onesmtp_analytics_window']) ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_analytics_window'])) : self::PROVIDER_WINDOW_KEY;
        $selectedWindow = isset($windows[ $selectedWindow ]) ? $selectedWindow : self::PROVIDER_WINDOW_KEY;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics view selection.
        $view = isset($_GET['onesmtp_analytics_view']) ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_analytics_view'])) : '';
        $providerWindow = $windows[ $selectedWindow ];
        $providers = $this->metrics->getProviderBreakdown( (string) $providerWindow['since']);
        $empty = $this->isEmpty($windows, $pending, $providers);

        echo '<div class="onesmtp-analytics-toolbar"><p>' . esc_html__('Understand email delivery performance at a glance.', 'onesmtp') . '</p><form method="get"><input type="hidden" name="page" value="onesmtp"><input type="hidden" name="tab" value="onesmtp-analytics"><label class="screen-reader-text" for="onesmtp-analytics-window">' . esc_html__('Reporting window', 'onesmtp') . '</label><select id="onesmtp-analytics-window" name="onesmtp_analytics_window">';
        foreach ([
            self::WINDOW_LAST_24_HOURS => __('Last 24 hours', 'onesmtp'),
			self::WINDOW_LAST_7_DAYS => __('Last 7 days', 'onesmtp'),
			self::WINDOW_LAST_30_DAYS => __('Last 30 days', 'onesmtp'),
		] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . ($selectedWindow === $value ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select><button class="button" type="submit">' . esc_html__('Apply', 'onesmtp') . '</button></form></div>';
        if ($view === 'comparison') {
            AdminUi::link(__('Back to analytics', 'onesmtp'), admin_url('options-general.php?page=onesmtp&tab=onesmtp-analytics&onesmtp_analytics_window=' . $selectedWindow . '#onesmtp-analytics'), 'arrow-left');
            echo '<h3>' . esc_html__('Compare reporting periods', 'onesmtp') . '</h3>';
            $this->renderWindowTable($windows);
            $this->renderProviderContribution($providers, (string) $providerWindow['label']);
            return;
        }
        echo '<p><a class="button" href="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-analytics&onesmtp_analytics_window=' . $selectedWindow . '&onesmtp_analytics_view=comparison#onesmtp-analytics')) . '">' . esc_html__('Compare periods', 'onesmtp') . '</a></p>';
        if ($empty) {
            $this->renderEmptyAnalytics();
            return;
        }
        $this->renderSummaryCards($providerWindow);
        $this->renderProviderTable($providers, (string) $providerWindow['label']);
        $this->renderPendingSummary($pending);
    }

    private function renderEmptyAnalytics(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons renders an SVG selected from a private allowlist and escapes its only dynamic attribute.
        echo '<section class="onesmtp-analytics-empty">' . Heroicons::render('squares') . '<h3>' . esc_html__('No delivery data yet', 'onesmtp') . '</h3><p>' . esc_html__('Connect a provider and send your first message. Delivery trends and provider comparisons will appear here automatically.', 'onesmtp') . '</p><a class="button button-primary" href="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers')) . '">' . esc_html__('Connect a provider', 'onesmtp') . '</a></section>';
    }

    /** @param array<string,int|string> $window */
    private function renderSummaryCards(array $window): void
    {
        $sent = (int) ($window['sent_count'] ?? 0);
        $failed = (int) ($window['failed_count'] ?? 0);
        $total = $sent + $failed;
        $rate = $total > 0 ? number_format(($sent / $total) * 100, 1) . '%' : __('No data', 'onesmtp');
        $cards = [
            [__('Sent attempts', 'onesmtp'), $sent, __('Accepted by a provider', 'onesmtp')],
            [__('Failed attempts', 'onesmtp'), (int) ($window['failed_count'] ?? 0), __('Review Activity for context', 'onesmtp')],
            [__('Failovers', 'onesmtp'), (int) ($window['failover_count'] ?? 0), __('Provider changes', 'onesmtp')],
            [__('Retry attempts', 'onesmtp'), (int) ($window['retry_count'] ?? 0), __('Includes repeat attempts', 'onesmtp')],
        ];
        echo '<div class="onesmtp-analytics-overview"><section class="onesmtp-dark-panel onesmtp-acceptance-rate"><h3>' . esc_html__('Provider acceptance', 'onesmtp') . '</h3><strong>' . esc_html($rate) . '</strong><p>' . esc_html( (string) $window['label']) . '</p><p>' . esc_html__('Accepted attempts ÷ all completed attempts. Acceptance does not confirm inbox placement.', 'onesmtp') . '</p></section><div class="onesmtp-analytics-metrics">';
        foreach ($cards as [$label, $value, $context]) {
            echo '<section class="onesmtp-panel onesmtp-metric"><h3>' . esc_html($label) . '</h3><strong>' . esc_html($this->formatCount($value)) . '</strong><p>' . esc_html($context) . '</p></section>';
        }
        echo '</div></div>';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function renderProviderContribution(array $providers, string $windowLabel): void
    {
        $total = array_sum(array_column($providers, 'sent_count'));
        echo '<section class="onesmtp-panel"><h3>' . esc_html__('Provider contribution', 'onesmtp') . '</h3><p>' . esc_html($windowLabel) . ' · ' . esc_html__('Share of accepted attempts', 'onesmtp') . '</p>';
        if ($providers === []) {
            echo '<p>' . esc_html__('No provider-level activity has been recorded for this window.', 'onesmtp') . '</p>';
        }
        foreach ($providers as $provider) {
            $sent = max(0, (int) ($provider['sent_count'] ?? 0));
            $percent = $total > 0 ? (int) round(($sent / $total) * 100) : 0;
            echo '<div class="onesmtp-provider-contribution"><div><strong>' . esc_html($this->shorten( (string) ($provider['provider_name'] ?? ''))) . '</strong><span>' . esc_html($this->formatCount($sent)) . ' · ' . esc_html( (string) $percent) . '%</span></div><meter min="0" max="100" value="' . esc_attr( (string) $percent) . '" aria-label="' . esc_attr( (string) ($provider['provider_name'] ?? '')) . '">' . esc_html( (string) $percent) . '%</meter></div>';
        }
        echo '</section>';
    }

    /**
     * @return array<string,array{label:string,since:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}>
     */
    private function windowSummaries(): array
    {
        $now = max(0, (int) ($this->nowProvider)());
        $windows = [
            self::WINDOW_LAST_24_HOURS => [
                'label' => __('Last 24 hours', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS),
            ],
            self::WINDOW_LAST_7_DAYS => [
                'label' => __('Last 7 days', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - (7 * DAY_IN_SECONDS)),
            ],
            self::WINDOW_LAST_30_DAYS => [
                'label' => __('Last 30 days', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - (30 * DAY_IN_SECONDS)),
            ],
        ];

        foreach ($windows as $key => $window) {
            $windows[ $key ] += $this->metrics->getActivityWindowSummary( (string) $window['since']);
        }

        return $windows;
    }

    /**
     * @param array<string,array{label:string,since:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}> $windows
     */
    private function renderWindowTable(array $windows): void
    {
        echo '<section class="onesmtp-panel"><h3>' . esc_html__('Delivery activity', 'onesmtp') . '</h3>';
        echo '<table class="widefat onesmtp-responsive-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Window', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Sent attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failed attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Retry attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failovers', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($windows as $window) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html( (string) $window['label']) . '<br><span class="description">' . esc_html(sprintf(
                /* translators: %s: UTC datetime. */
                __('Since %s UTC', 'onesmtp'),
                (string) $window['since']
            )) . '</span></th>';
            echo '<td data-label="' . esc_attr__('Sent attempts', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $window['sent_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Failed attempts', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $window['failed_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Retry attempts', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $window['retry_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Failovers', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $window['failover_count'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></section>';
    }

    /**
     * @param array{queued_count:int,retry_scheduled_count:int,retrying_count:int,total_pending_count:int} $pending
     */
    private function renderPendingSummary(array $pending): void
    {
        echo '<section class="onesmtp-panel onesmtp-queue-overview"><div class="onesmtp-panel-heading"><h3>' . esc_html__('Current queue', 'onesmtp') . '</h3><a class="button" href="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-activity#onesmtp-activity')) . '">' . esc_html__('Review messages', 'onesmtp') . '</a></div>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: queued messages, 2: scheduled retries, 3: running retries. */
            __('%1$d queued · %2$d scheduled · %3$d running', 'onesmtp'),
            (int) $pending['queued_count'],
            (int) $pending['retry_scheduled_count'],
            (int) $pending['retrying_count']
        )) . '</p></section>';
    }

    /**
     * @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int,switch_out_count:int,total_activity:int}> $providers
     */
    private function renderProviderTable(array $providers, string $windowLabel): void
    {
        echo '<section class="onesmtp-panel onesmtp-provider-performance"><h3>' . esc_html__('Provider performance', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: activity window label. */
            __('Provider breakdown for %s.', 'onesmtp'),
            $windowLabel
        )) . '</p>';

        if ($providers === []) {
            echo '<p>' . esc_html__('No provider-level activity has been recorded for this window.', 'onesmtp') . '</p>';
            echo '<p>' . esc_html__('Try a longer reporting window, or review Activity to check whether messages are still queued.', 'onesmtp') . '</p>';
            echo '</section>';

            return;
        }

        $this->renderProviderDataViews($providers);
        echo '<div class="onesmtp-provider-performance-fallback"><table class="widefat onesmtp-responsive-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Type', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Sent', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failed', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Retries', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Switched to', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Switched away', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($providers as $provider) {
            $fullName = (string) $provider['provider_name'];
            echo '<tr>';
            echo '<th scope="row" title="' . esc_attr($fullName) . '">' . esc_html($this->shorten($fullName)) . '</th>';
            echo '<td data-label="' . esc_attr__('Type', 'onesmtp') . '">' . esc_html($this->formatProviderType( (string) $provider['adapter_type'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Sent', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $provider['sent_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Failed', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $provider['failed_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Retries', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $provider['retry_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Switched to', 'onesmtp') . '">' . esc_html($this->formatCount( (int) $provider['failover_count'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Switched away', 'onesmtp') . '">' . esc_html($this->formatCount( (int) ($provider['switch_out_count'] ?? 0))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></section>';
    }

    /** @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int,switch_out_count:int,total_activity:int}> $providers */
    private function renderProviderDataViews(array $providers): void
    {
        $data = array_map(static function (array $provider): array {
            return [
				'id' => (int) $provider['provider_id'],
				'provider' => (string) $provider['provider_name'],
				'type' => (string) $provider['adapter_type'],
				'sent' => (int) $provider['sent_count'],
				'failed' => (int) $provider['failed_count'],
				'retries' => (int) $provider['retry_count'],
				'failovers' => (int) $provider['failover_count'],
				'switchedAway' => (int) $provider['switch_out_count'],
			];
        }, $providers);
        $payload = [
			'data' => $data,
			'fields' => [
				[
					'id' => 'provider',
					'type' => 'text',
					'label' => __('Provider', 'onesmtp'),
					'enableHiding' => false,
				],
				[
					'id' => 'type',
					'type' => 'text',
					'label' => __('Type', 'onesmtp'),
				],
				[
					'id' => 'sent',
					'type' => 'integer',
					'label' => __('Sent', 'onesmtp'),
				],
				[
					'id' => 'failed',
					'type' => 'integer',
					'label' => __('Failed', 'onesmtp'),
				],
				[
					'id' => 'retries',
					'type' => 'integer',
					'label' => __('Retries', 'onesmtp'),
				],
				[
					'id' => 'failovers',
					'type' => 'integer',
					'label' => __('Failovers', 'onesmtp'),
				],
				[
					'id' => 'switchedAway',
					'type' => 'integer',
					'label' => __('Switched away', 'onesmtp'),
				],
			],
        ];
        echo '<div class="onesmtp-dataviews-mount" data-onesmtp-dataviews="analytics-providers"></div>';
        echo '<script type="application/json" data-onesmtp-dataviews-config="analytics-providers">' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    }

    /**
     * @param array<string,array{sent_count:int,failed_count:int,retry_count:int,failover_count:int}> $windows
     * @param array{total_pending_count:int} $pending
     * @param array<int,array{total_activity:int}> $providers
     */
    private function isEmpty(array $windows, array $pending, array $providers): bool
    {
        foreach ($windows as $window) {
            if ( (int) $window['sent_count'] > 0 || (int) $window['failed_count'] > 0 || (int) $window['retry_count'] > 0 || (int) $window['failover_count'] > 0) {
                return false;
            }
        }

        if ( (int) $pending['total_pending_count'] > 0) {
            return false;
        }

        foreach ($providers as $provider) {
            if ( (int) $provider['total_activity'] > 0) {
                return false;
            }
        }

        return true;
    }

    private function formatCount(int $value): string
    {
        return number_format(max(0, $value));
    }

    private function formatProviderType(string $type): string
    {
        $type = sanitize_key($type);

        return $type !== '' ? str_replace('_', ' ', $type) : __('unknown', 'onesmtp');
    }

    private function shorten(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return __('Unknown provider', 'onesmtp');
        }

        if (strlen($value) <= self::PROVIDER_NAME_LIMIT) {
            return $value;
        }

        return substr($value, 0, self::PROVIDER_NAME_LIMIT - 3) . '...';
    }
}
