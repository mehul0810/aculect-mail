<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

/** Shared, escaped presentation primitives for the PHP admin screens. */
final class AdminUi
{
    private static int $submitId = 0;

    /** Keep WordPress nonce and referer behavior without repeated hidden IDs. */
    public static function nonce(string $action, string $name): void
    {
        $field = wp_nonce_field($action, $name, true, false);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates escaped nonce/referer inputs; only the unused duplicate ID is removed.
        echo str_replace(' id="' . esc_attr($name) . '"', '', $field);
    }

    public static function submit(string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        ++self::$submitId;
        submit_button($text, $type, $name, $wrap, ['id' => 'onesmtp-submit-' . self::$submitId]);
    }

    public static function icon(string $name): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons selects local allowlisted paths and escapes attributes.
        echo Heroicons::render($name);
    }

    public static function notice(string $message, string $type = 'info', string $detail = ''): void
    {
        $icons = [
			'info' => 'information',
			'success' => 'check-circle',
			'warning' => 'warning',
			'error' => 'warning',
		];
        $type = isset($icons[ $type ]) ? $type : 'info';
        echo '<div class="onesmtp-notice is-' . esc_attr($type) . '" role="status">';
        self::icon($icons[ $type ]);
        echo '<div><strong>' . esc_html($message) . '</strong>';
        if ($detail !== '') {
            echo '<p>' . esc_html($detail) . '</p>';
        }
        echo '</div></div>';
    }

    public static function badge(string $label, string $state = 'neutral'): void
    {
        $icons = [
			'neutral' => 'clock',
			'info' => 'information',
			'success' => 'check-circle',
			'warning' => 'warning',
			'error' => 'warning',
		];
        $state = isset($icons[ $state ]) ? $state : 'neutral';
        echo '<span class="onesmtp-badge is-' . esc_attr($state) . '">';
        self::icon($icons[ $state ]);
        echo esc_html($label) . '</span>';
    }

    public static function field(string $name, string $label, string $value, string $type = 'text', string $help = '', bool $required = false, string $controller = '', bool $disabled = false, string $idPrefix = 'onesmtp-field-'): void
    {
        $id = $idPrefix . $name;
        $type = in_array($type, ['text', 'email', 'url', 'number', 'password', 'textarea'], true) ? $type : 'text';
        echo '<div class="onesmtp-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '<span class="onesmtp-disabled-label"' . ($disabled ? '' : ' hidden') . '> ' . esc_html__('(disabled)', 'onesmtp') . '</span></label>';
        echo $type === 'textarea' ? '<textarea rows="3"' : '<input type="' . esc_attr($type) . '"';
        echo ' id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" aria-describedby="' . esc_attr($id . '-help') . '"';
        if ($type === 'url') {
            echo ' maxlength="2048"';
        }
        if ($type === 'number') {
            echo ' min="0" step="1"';
        }
        if ($controller !== '') {
            echo ' data-onesmtp-enabled-by="' . esc_attr($controller) . '"';
        }
        echo $required ? ' required' : '';
        echo $disabled ? ' disabled' : '';
        if ($type === 'textarea') {
            echo '>' . esc_textarea($value) . '</textarea>';
        } else {
            echo ' value="' . esc_attr($value) . '">';
        }
        echo '<p class="onesmtp-field-help" id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</p></div>';
    }

    public static function toggle(string $name, string $label, bool $checked, string $help = ''): void
    {
        echo '<label class="onesmtp-toggle" for="onesmtp-toggle-' . esc_attr($name) . '"><span><span class="onesmtp-toggle-label">' . esc_html($label) . '</span>';
        if ($help !== '') {
            echo '<span class="onesmtp-field-help">' . esc_html($help) . '</span>';
        }
        echo '</span><input type="checkbox" role="switch" id="onesmtp-toggle-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . '></label>';
    }

    public static function link(string $label, string $url, string $icon = 'arrow-right', bool $primary = false): void
    {
        echo '<a class="button' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '">';
        if ($icon === 'arrow-left' || $icon === 'plus') {
            self::icon($icon);
        }
        echo esc_html($label);
        if ($icon !== 'arrow-left' && $icon !== 'plus') {
            self::icon($icon);
        }
        echo '</a>';
    }
}
