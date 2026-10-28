# Getting Started

## Prerequisites

- WordPress with plugin activation access
- At least one supported provider account
- Recommended: DNS access for SPF/DKIM/DMARC setup

## Quick Setup

1. Install and activate Aculect Mail.
2. Open **Overview**, enter your sender name and address, and save. This step preserves other saved header settings.
3. Open **Providers**, choose a service, enter its required credentials, and save the connection. Saving does not verify delivery.
4. Send an explicit test from the connection editor or Overview, check provider acceptance, and check the recipient inbox.
5. Review **Activity** for attempts and safe error context. Add a backup provider when needed and review **Routing** for priority, weight, and health behavior.

## What Happens After Activation

- Aculect Mail prepares required custom database tables.
- Retry workers rely on Action Scheduler.
- Log retention defaults to 30 days (filter-extendable up to 120).


## Settings and navigation

**Providers** keeps the guided connection catalog as the starting point. Choose **Open manual setup** for the dedicated manual configuration screen, then **Back to connections** to return. Manual setup remains available when the guided editor cannot load.

**Activity** displays the current queue and a single message list. Select failed messages for bulk resend, or choose **Inspect** to review attempt history and safe metadata. Resending and forwarding a safe summary are separate visible actions on the message screen.

**Advanced** shows one task at a time: delivery, privacy and logs, import/export, diagnostics, alert history, or provider tools. Diagnostics starts with queue status and recovery actions; choose **View report** for technical details or download the report. Alert history keeps summaries and acknowledgement controls visible; choose **View context** for safe event details.

**Analytics** shows provider acceptance, provider performance, the current queue, and activity across reporting windows. Provider performance supports search and sorting when records are available, with a readable table if the interactive view cannot load. **Review messages** opens Activity for message inspection; queue counts always represent the current state rather than the selected reporting window.

From the WordPress Plugins screen, select **Settings** in the Aculect Mail row to open its settings directly.

**Settings → General** controls sender defaults and individual header overrides. **Notifications** controls failure alerts and weekly summaries. Recipient and webhook fields disable when their channel is off; saving the disabled channel retains its previously saved values. Enabling a channel still requires usable recipients or an HTTPS endpoint before it can send.

**Advanced** separates delivery controls, privacy/logging, settings transfer, diagnostics, alert history, and provider tools. Each form saves separately. On a narrow screen, use the current-section picker and the Advanced section selector. Legacy Delivery links still open Overview.

Analytics starts with the selected period’s headline metrics and provider performance. Choose **Compare periods** for reporting-window comparisons and provider contribution details. Activity keeps message identifiers and attachment metadata in **Inspect**.
