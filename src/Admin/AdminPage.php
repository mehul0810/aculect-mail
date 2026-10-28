<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SimulationModeSettingsRepository;

final class AdminPage
{
    private const MENU_SLUG = 'onesmtp';
    private const ADMIN_STYLE_HANDLE = 'onesmtp-admin';
    private const ADMIN_STYLE_PATH = 'assets/admin.css';
    private const ADMIN_SCRIPT_HANDLE = 'onesmtp-admin';
    private const ADMIN_SCRIPT_PATH = 'assets/admin.js';
    private const COMPONENT_SCRIPT_PATH = 'build/index.js';
    private const COMPONENT_STYLE_PATH = 'build/index.css';
    private const DATAVIEWS_STYLE_PATH = 'build/dataviews.css';

    private DashboardAdmin $dashboard;
    private ProviderAdmin $providers;
    private SetupWizard $setupWizard;
    private LogAdmin $logs;
    private SettingsAdmin $settings;
    private QueueDiagnosticsAdmin $diagnostics;
    private AlertHistoryAdmin $alerts;
    private ProviderRepository $providerRepository;
    private SenderIdentityRepository $senderIdentityRepository;
    private AdminScreenRegistry $screenRegistry;
    private MailDeliveryOwnership $deliveryOwnership;
    private SimulationModeSettingsRepository $simulationMode;

    public function __construct(
        ?ProviderAdmin $providers = null,
        ?SetupWizard $setupWizard = null,
        ?LogAdmin $logs = null,
        ?SettingsAdmin $settings = null,
        ?QueueDiagnosticsAdmin $diagnostics = null,
        ?DashboardAdmin $dashboard = null,
        ?AlertHistoryAdmin $alerts = null,
        ?ProviderRepository $providerRepository = null,
        ?SenderIdentityRepository $senderIdentityRepository = null,
        ?MailDeliveryOwnership $deliveryOwnership = null,
        ?SimulationModeSettingsRepository $simulationMode = null
    ) {
        $this->providerRepository = $providerRepository ?? new ProviderRepository();
        $this->senderIdentityRepository = $senderIdentityRepository ?? new SenderIdentityRepository();
        $this->deliveryOwnership = $deliveryOwnership ?? new MailDeliveryOwnership();
        $this->simulationMode = $simulationMode ?? new SimulationModeSettingsRepository();
        $this->dashboard = $dashboard ?? new DashboardAdmin();
        $this->providers = $providers ?? new ProviderAdmin($this->providerRepository);
        $this->setupWizard = $setupWizard ?? new SetupWizard($this->providerRepository);
        $this->logs = $logs ?? new LogAdmin(new MessageRepository(), new AttemptRepository(), $this->providerRepository);
        $this->settings = $settings ?? new SettingsAdmin(
            simulationMode: $this->simulationMode,
            deliveryOwnership: $this->deliveryOwnership
        );
        $this->diagnostics = $diagnostics ?? new QueueDiagnosticsAdmin();
        $this->alerts = $alerts ?? new AlertHistoryAdmin();
    }

    public function registerHooks(): void
    {
        add_filter('plugin_action_links_' . plugin_basename(ONESMTP_FILE), [$this, 'addSettingsLink']);
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'suppressExternalNotices'], -PHP_INT_MAX);
        add_action('admin_init', [$this->setupWizard, 'handleRequest']);
        add_action('admin_init', [$this->providers, 'handleRequest']);
        add_action('admin_init', [$this->logs, 'handleRequest']);
        add_action('admin_init', [$this->settings, 'handleRequest']);
        add_action('admin_init', [$this->diagnostics, 'handleRequest']);
        add_action('admin_init', [$this->alerts, 'handleRequest']);
    }

    /**
     * @param array<array-key,string> $links Plugin action links.
     * @return array<array-key,string>
     */
    public function addSettingsLink(array $links): array
    {
        if ( ! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            return $links;
        }

        $url = admin_url('options-general.php?page=onesmtp&tab=onesmtp-settings');
        return ['settings' => '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'onesmtp') . '</a>'] + $links;
    }

    /**
     * Keep unrelated plugin notices from crowding the focused Aculect Mail workspace.
     *
     * This runs only while WordPress is rendering this plugin's settings page.
     * Inline notices rendered by Aculect Mail inside its own workspace are unaffected.
     */
    public function suppressExternalNotices(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash( (string) $_GET['page'])) : '';
        if ($page !== self::MENU_SLUG || ! function_exists('remove_all_actions')) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress action callback accepts hookSuffix for compatibility; page selection handles alternate admin routers.
    public function enqueueAssets(string $hookSuffix): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash( (string) $_GET['page'])) : '';
        // Studio and some WordPress admin routers use a different hook suffix
        // for Settings submenu pages. The page query is the stable boundary.
        if ($page !== self::MENU_SLUG) {
            return;
        }

        if ( ! function_exists('wp_enqueue_style')) {
            return;
        }

        $pluginPath = defined('ONESMTP_PATH') ? (string) constant('ONESMTP_PATH') : '';
        $pluginUrl = defined('ONESMTP_URL') ? (string) constant('ONESMTP_URL') : '';
        if ($pluginPath === '' || $pluginUrl === '') {
            return;
        }

        $path = rtrim($pluginPath, '/\\') . '/' . self::ADMIN_STYLE_PATH;
        if ( ! file_exists($path)) {
            return;
        }

        wp_enqueue_style(
            self::ADMIN_STYLE_HANDLE,
            rtrim($pluginUrl, '/\\') . '/' . self::ADMIN_STYLE_PATH,
            ['wp-components'],
            (string) filemtime($path)
        );

        if ( ! function_exists('wp_enqueue_script')) {
            return;
        }

        $scriptPath = rtrim($pluginPath, '/\\') . '/' . self::ADMIN_SCRIPT_PATH;
        if ( ! file_exists($scriptPath)) {
            return;
        }

        wp_enqueue_script(
            self::ADMIN_SCRIPT_HANDLE,
            rtrim($pluginUrl, '/\\') . '/' . self::ADMIN_SCRIPT_PATH,
            [],
            (string) filemtime($scriptPath),
            true
        );

        wp_enqueue_script('onesmtp-admin-forms', rtrim($pluginUrl, '/\\') . '/assets/admin-forms.js', [self::ADMIN_SCRIPT_HANDLE], (string) filemtime($pluginPath . 'assets/admin-forms.js'), true);

        $componentPath = rtrim($pluginPath, '/\\') . '/' . self::COMPONENT_SCRIPT_PATH;
        if (file_exists($componentPath)) {
            $componentAsset = $this->componentAsset($pluginPath, $componentPath);
            wp_enqueue_script(
                'onesmtp-components',
                rtrim($pluginUrl, '/\\') . '/' . self::COMPONENT_SCRIPT_PATH,
                $componentAsset['dependencies'],
                $componentAsset['version'],
                true
            );
        }

        $componentStylePath = rtrim($pluginPath, '/\\') . '/' . self::COMPONENT_STYLE_PATH;
        if (file_exists($componentStylePath)) {
            wp_enqueue_style(
                'onesmtp-components',
                rtrim($pluginUrl, '/\\') . '/' . self::COMPONENT_STYLE_PATH,
                ['wp-components'],
                (string) filemtime($componentStylePath)
            );
        }

        $dataViewsStylePath = rtrim($pluginPath, '/\\') . '/' . self::DATAVIEWS_STYLE_PATH;
        if (file_exists($dataViewsStylePath)) {
            wp_enqueue_style(
                'onesmtp-dataviews',
                rtrim($pluginUrl, '/\\') . '/' . self::DATAVIEWS_STYLE_PATH,
                ['wp-components'],
                (string) filemtime($dataViewsStylePath)
            );
        }
    }

    /**
     * Read the dependency manifest generated by @wordpress/scripts.
     *
     * @return array{dependencies:array<int,string>,version:string}
     */
    private function componentAsset(string $pluginPath, string $componentPath): array
    {
        $fallback = [
            'dependencies' => ['wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n'],
            'version' => (string) filemtime($componentPath),
        ];
        $assetPath = rtrim($pluginPath, '/\\') . '/build/index.asset.php';
        if ( ! file_exists($assetPath)) {
            return $fallback;
        }

        $asset = require $assetPath;
        if ( ! is_array($asset)) {
            return $fallback;
        }

        $dependencies = isset($asset['dependencies']) && is_array($asset['dependencies'])
            ? array_values(array_filter($asset['dependencies'], 'is_string'))
            : $fallback['dependencies'];
        $version = isset($asset['version']) && is_string($asset['version']) && $asset['version'] !== ''
            ? $asset['version']
            : $fallback['version'];

        return [
            'dependencies' => $dependencies,
            'version' => $version,
        ];
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            esc_html__('Aculect Mail', 'onesmtp'),
            esc_html__('Aculect Mail', 'onesmtp'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to access Aculect Mail settings.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        // Plugin construction runs before init; translate labels only when rendering admin UI.
        $this->screenRegistry = $this->buildScreenRegistry();
        $sections = $this->sections();
        $activeScreen = $this->screenRegistry->resolve($this->requestedScreenId()) ?? $this->screenRegistry->resolve('onesmtp-overview');
        $activeProviders = $this->providerRepository->getActiveProviders();
        $senderIdentity = $this->senderIdentityRepository->get()->toArray();

        echo '<div class="wrap onesmtp-admin" data-onesmtp-workspaces data-onesmtp-invalid-message="' . esc_attr__('Changes have not been saved. Review the highlighted fields.', 'onesmtp') . '">';
        $this->renderHeader($sections, $activeScreen?->id() ?? 'onesmtp-overview');
        echo '<hr class="wp-header-end">';
        echo '<div class="onesmtp-admin-shell">';

        foreach ($this->screenRegistry->all() as $screen) {
            $this->renderSection($screen, $activeProviders, $senderIdentity, $screen->id() === ($activeScreen?->id() ?? 'onesmtp-overview'));
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array<int,array{id:string,title:string,description:string,href:string}>
     */
    private function sections(): array
    {
        return array_map(function (AdminScreenDefinition $screen): array {
            $id = $screen->id();
            $href = admin_url('options-general.php?page=' . self::MENU_SLUG . '&tab=' . rawurlencode($id) . '#' . $id);

            return [
                'id' => $id,
                'title' => esc_html($screen->title()),
                'description' => esc_html($screen->description()),
                'href' => $href,
            ];
        }, $this->screenRegistry->all());
    }

    /**
     * @param AdminScreenDefinition $screen
     */
    private function renderSection(AdminScreenDefinition $screen, array $activeProviders, array $senderIdentity, bool $isActive): void
    {
        $sectionId = $screen->id();
        $headingId = $sectionId . '-heading';

        echo '<section id="' . esc_attr($sectionId) . '" class="onesmtp-admin-section" data-onesmtp-workspace="' . esc_attr($sectionId) . '" aria-labelledby="' . esc_attr($headingId) . '"' . ($isActive ? '' : ' hidden') . '>';
        echo '<header class="onesmtp-admin-section-header">';
        echo '<div class="onesmtp-admin-section-heading">';
        echo '<h2 id="' . esc_attr($headingId) . '" tabindex="-1">' . esc_html($screen->title()) . '</h2>';
        echo '<p class="onesmtp-admin-section-description">' . esc_html($screen->description()) . '</p>';
        echo '</div>';
        echo '</header>';
        echo '<div class="onesmtp-admin-workspace-layout is-full-width' . ($sectionId === 'onesmtp-overview' ? ' is-overview' : '') . '">';
        echo '<div class="onesmtp-admin-section-body">';
        $screen->render($activeProviders, $senderIdentity);

        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<int,array{id:string,title:string,description:string,href:string}> $sections
     */
    private function renderHeader(array $sections, string $activeScreenId): void
    {
        $pluginUrl = defined('ONESMTP_URL') ? (string) constant('ONESMTP_URL') : '';

        echo '<header class="onesmtp-admin-header">';
        echo '<div class="onesmtp-admin-header-top">';
        echo '<div class="onesmtp-admin-brand">';
        echo '<div class="onesmtp-admin-brand-mark" aria-hidden="true"><img src="' . esc_url($pluginUrl . 'assets/images/aculect-icon-light.svg') . '" alt="" width="44" height="44"></div>';
        echo '<div class="onesmtp-admin-brand-copy">';
        echo '<h1>' . esc_html__('Mail', 'onesmtp') . '</h1>';
        echo '</div>';
        echo '<span class="onesmtp-admin-version">' . esc_html('v' . ONESMTP_VERSION) . '</span>';
        echo '</div>';
        echo '<a class="onesmtp-admin-documentation" href="' . esc_url('https://github.com/mehul0810/aculect-mail/blob/main/docs/admin/getting-started.md') . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__('Documentation (opens in a new tab)', 'onesmtp') . '">' . esc_html__('Documentation', 'onesmtp');
        echo '</a>';
        echo '</div>';
        echo '<div class="onesmtp-section-picker"><label class="screen-reader-text" for="onesmtp-section-picker">' . esc_html__('Aculect Mail sections', 'onesmtp') . '</label><select id="onesmtp-section-picker" data-onesmtp-section-picker>';
        foreach ($sections as $section) {
            echo '<option value="' . esc_attr($section['id']) . '"' . ($section['id'] === $activeScreenId ? ' selected' : '') . '>' . esc_html($section['title']) . '</option>';
        }
        echo '</select>';
        AdminUi::icon('chevron-down');
        echo '</div><div class="onesmtp-admin-nav">';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Aculect Mail sections', 'onesmtp') . '">';

        $icons = [
			'onesmtp-overview' => 'squares',
			'onesmtp-providers' => 'server-stack',
			'onesmtp-routing' => 'arrows',
			'onesmtp-activity' => 'inbox',
			'onesmtp-analytics' => 'chart',
			'onesmtp-settings' => 'settings',
			'onesmtp-advanced' => 'adjustments',
		];
        foreach ($sections as $section) {
            $isActive = $section['id'] === $activeScreenId;
            $className = $isActive ? 'nav-tab nav-tab-active' : 'nav-tab';
            $ariaCurrent = $isActive ? 'page' : '';
            echo '<a class="' . esc_attr($className) . '" data-onesmtp-workspace-link="' . esc_attr($section['id']) . '" href="' . esc_url($section['href']) . '"' . ($ariaCurrent !== '' ? ' aria-current="' . esc_attr($ariaCurrent) . '"' : '') . '>';
            AdminUi::icon($icons[ $section['id'] ] ?? 'squares');
            echo esc_html($section['title']) . '</a>';
        }

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderRoutingOverview(array $activeProviders): void
    {
        RoutingView::render($activeProviders);
    }


    private function requestedScreenId(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        if ( ! isset($_GET['tab'])) {
            return 'onesmtp-overview';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameter; sanitized here and escaped at output, with no state change.
        return sanitize_key(wp_unslash( (string) $_GET['tab']));
    }

    private function buildScreenRegistry(): AdminScreenRegistry
    {
        $registry = new AdminScreenRegistry();
        $registry->register(new AdminScreenDefinition(
            'onesmtp-overview',
            __('Overview', 'onesmtp'),
            __('A clear path from your first connection to a tested sending setup.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-general');
                $this->renderAnchorAlias('onesmtp-setup');
                $this->setupWizard->render();
            },
            ['onesmtp-general', 'onesmtp-setup', 'onesmtp-delivery']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-providers',
            __('Providers', 'onesmtp'),
            __('Connect email services, check credentials, and control delivery priority.', 'onesmtp'),
            function (): void {
                $this->providers->render();
            }
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-routing',
            __('Routing', 'onesmtp'),
            __('Understand which provider handles each message and what happens if it fails.', 'onesmtp'),
            function (array $activeProviders): void {
                $this->renderRoutingOverview($activeProviders);
            }
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-activity',
            __('Activity', 'onesmtp'),
            __('Find a message, understand each attempt, and take the next safe action.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-logs');
                $this->logs->render();
            },
            ['onesmtp-logs']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-analytics',
            __('Analytics', 'onesmtp'),
            __('See provider acceptance, failures, and recovery without losing the detail.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-dashboard');
                $this->dashboard->render();
            },
            ['onesmtp-dashboard']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-settings',
            __('Settings', 'onesmtp'),
            __('Control who your emails come from, and how your team stays informed.', 'onesmtp'),
            function (): void {
                $this->settings->render();
            }
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-advanced',
            __('Advanced', 'onesmtp'),
            __('Tune delivery behavior and inspect operational health.', 'onesmtp'),
            function (): void {
                $this->renderAdvancedWorkspace();
            },
            ['onesmtp-tools', 'onesmtp-diagnostics', 'onesmtp-alerts', 'onesmtp-settings-advanced', 'onesmtp-provider-tools']
        ));

        return $registry;
    }

    private function renderAdvancedWorkspace(): void
    {
        $groups = [
			'delivery' => __('Delivery controls', 'onesmtp'),
			'privacy' => __('Privacy and logs', 'onesmtp'),
			'transfer' => __('Import / export', 'onesmtp'),
			'diagnostics' => __('Diagnostics', 'onesmtp'),
            'alerts' => __('Alert history', 'onesmtp'),
            'providers' => __('Provider tools', 'onesmtp'),
		];
        echo '<div class="onesmtp-advanced-layout" data-title-delivery="' . esc_attr__('Advanced', 'onesmtp') . '" data-title-privacy="' . esc_attr__('Privacy and logs', 'onesmtp') . '" data-title-transfer="' . esc_attr__('Import / export', 'onesmtp') . '" data-title-alerts="' . esc_attr__('Alert history', 'onesmtp') . '" data-title-providers="' . esc_attr__('Provider tools', 'onesmtp') . '" data-title-diagnostics="' . esc_attr__('Diagnostics', 'onesmtp') . '"><div class="onesmtp-advanced-nav" data-onesmtp-subnav="advanced"><nav aria-label="' . esc_attr__('Advanced sections', 'onesmtp') . '">';
        foreach ($groups as $key => $label) {
            echo '<a href="#onesmtp-advanced-' . esc_attr($key) . '" data-onesmtp-subtab="' . esc_attr($key) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><div class="onesmtp-advanced-picker"><label for="onesmtp-advanced-picker">' . esc_html__('Advanced section', 'onesmtp') . '</label><select id="onesmtp-advanced-picker" data-onesmtp-subpicker="advanced">';
        foreach ($groups as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div></div><div class="onesmtp-advanced-content">';
        $this->settings->renderAdvanced();
        echo '<div class="onesmtp-diagnostics-layout" data-onesmtp-advanced-group="diagnostics"><section id="onesmtp-diagnostics" class="onesmtp-panel"><h3>' . esc_html__('Queue diagnostics', 'onesmtp') . '</h3>';
        $this->diagnostics->render();
        echo '</section></div><div data-onesmtp-advanced-group="alerts"><section id="onesmtp-alerts" class="onesmtp-panel"><h3>' . esc_html__('Alert history', 'onesmtp') . '</h3>';
        $this->alerts->render();
        echo '</section></div><div data-onesmtp-advanced-group="providers"><section id="onesmtp-provider-tools" class="onesmtp-admin-secondary-panel"><h3>' . esc_html__('Provider administration', 'onesmtp') . '</h3><p>' . esc_html__('Review connection status and delivery priority. Use Providers to add or edit connection credentials.', 'onesmtp') . '</p>';
        $this->providers->renderAdvancedTools();
        echo '</section></div></div></div>';
    }

    private function renderAnchorAlias(string $id): void
    {
        echo '<span id="' . esc_attr($id) . '" class="screen-reader-text" aria-hidden="true"></span>';
    }
}
