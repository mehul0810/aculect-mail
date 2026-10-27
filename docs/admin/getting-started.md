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

**Settings → General** controls sender defaults and individual header overrides. **Notifications** controls failure alerts and weekly summaries. Recipient and webhook fields disable when their channel is off; saving the disabled channel retains its previously saved values. Enabling a channel still requires usable recipients or an HTTPS endpoint before it can send.

**Advanced** groups delivery controls, privacy/logging, settings transfer, and diagnostics. Each form saves separately. On a narrow screen, use the current-section picker and the Advanced section selector. Legacy Delivery links still open Overview.
