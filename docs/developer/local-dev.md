# Local Development

## Recommended Setup

- WordPress local install
- Debug logging enabled
- Action Scheduler UI/inspection access
- PHP CLI for Composer scripts, including translation template generation

## Action Scheduler prerequisite

Action Scheduler is supplied by the WordPress site; it is not a Composer runtime dependency of Aculect Mail. Load a compatible official standalone Action Scheduler plugin or an existing site's integration before testing retries and background delivery. The isolated cloud development setup has verified the official standalone 3.9.3 release on WordPress 7.1 / PHP 8.4.26. This is one tested combination, not a claim about other versions.

Scheduler availability and execution are separate checks. If `DISABLE_WP_CRON` is enabled, provide an independent local runner instead of relying on page visits. The Mailpit-only cloud setup uses a five-second worker that claims persisted `onesmtp_process_background_send` and `onesmtp_process_retry` actions in the `onesmtp` group through Action Scheduler's queue runner. It does not run unrelated maintenance or weekly-summary hooks. Keep the harness network isolation and mail-capture guard enabled.

Inspect completed action logs, linked message attempts, and exactly one captured message for each successful delivery. Verify real retry delays and rate-limit windows without retiming jobs. Also test a request with Action Scheduler absent: scheduling must fail visibly without sending, and an explicit scheduling action after restoring the dependency must recover the queued message. Restore temporary rate-limit and background settings after testing; retain the jobs and logs as evidence.

Retention scheduling runs on `init` after Action Scheduler's store initialization. Its WordPress cron fallback remains available when Action Scheduler is absent. Neither the fallback event nor an available scheduler proves that a runner is executing maintenance jobs.

## Validation Flow

1. Configure at least 2 providers.
2. Trigger a test send.
3. Simulate provider failure.
4. Verify retry scheduling + provider switching.
5. Confirm Action Scheduler is loaded in admin requests and inspect Queue Diagnostics for scheduler health.
6. Verify log records and retention behavior.

## Internationalization

- Confirm plugin metadata declares `Text Domain: onesmtp` and `Domain Path: /languages`.
- Regenerate the translation template before release packaging:
  - `composer i18n:make-pot`
- Review `languages/aculect-mail.pot` when adding or changing user-facing strings.

The plugin entry file is now `aculect-mail.php`. Existing installations using the previous entry filename must reactivate Aculect Mail after replacing the files. Preserve existing options and tables; do not uninstall the plugin as part of this rename.
