# Aculect Mail Design System Adoption

## Source of truth

Use the [WordPress Design System community file](https://www.figma.com/community/file/1436359662053949167/wordpress-design-system) for component intent, interaction patterns, states, and layout guidance. Use [`DESIGN.md`](../../DESIGN.md) for Aculect Mail’s product posture, Aculect tokens, information architecture, and copy rules.

## Package policy

When a client-side interface is introduced, prefer the smallest set of official WordPress packages that covers the interaction:

- `@wordpress/components` for controls and layout primitives.
- `@wordpress/element` for rendering and lifecycle behavior.
- `@wordpress/i18n` for translatable client-side strings.
- `@wordpress/icons` for accessible WordPress iconography.
- `@wordpress/dataviews` for admin listings with search, sorting, filters, layout controls, and pagination. Plugin builds import the WordPress bundle from `@wordpress/dataviews/wp`.
- Heroicons outline paths for Aculect Mail-specific product iconography via the shared PHP helper.
- `@wordpress/data` only for shared state that cannot remain local to a component or server-rendered request.

Do not add a competing component system, icon library, or ad hoc design-token layer. If a required component is not available, document the gap and keep the fallback visually and behaviorally aligned with WordPress admin conventions.

## Adoption strategy

Aculect Mail currently has a PHP-rendered admin surface with a small JavaScript workspace controller. Keep that architecture stable while introducing the design system in bounded areas:

1. Use WordPress core markup and classes for server-rendered forms, tables, notices, and navigation.
2. Use `@wordpress/components` for new interactive islands or a deliberately migrated screen.
3. Keep data permissions, nonces, REST contracts, and server-side validation independent of the rendering layer.
4. Do not migrate a screen only for visual novelty; migrate when component behavior, accessibility, or interaction complexity justifies it.
5. Remove obsolete custom styles and dependencies after a migrated surface has equivalent test coverage.

## Visual contract

Apply the Aculect tokens in [`DESIGN.md`](../../DESIGN.md) as a restrained layer over WordPress patterns. Primary actions, focus states, status badges, and links must remain legible in default, hover, focus, disabled, warning, and error states. Never encode meaning through color alone.

## Figma implementation evidence

For a Figma-driven implementation, record the exact frame or node URL, capture the reference screenshot, identify the WordPress component equivalents, and verify the rendered result at desktop and narrow admin widths. If the Figma source cannot be fetched, do not claim pixel-level parity; use the documented WordPress and Aculect contracts and record the limitation.


## Approved Pen implementation (October 2026)

The implementation follows the 21 approved native PNG frames (14 desktop/foundation/state references and seven mobile references) from the saved Aculect Mail Pen handoff. WordPress continues to own the toolbar, sidebar and admin routing. The plugin does not reproduce the mock site's chrome, fixture values or review footers.

`AdminUi`, `SettingsView` and `RoutingView` share escaped PHP presentation primitives. Existing repositories, permissions, nonces, provider adapters and handlers remain authoritative. Native forms and selectors handle the simple settings flows. The existing WordPress component island supplies the full-width provider editor through a portal; a PHP form remains available if the module cannot load. The optional provider analytics DataView loads when its disclosure opens and applies the package's sorting/filtering/pagination pipeline.

The workspace loader tracks pending and mounted roots, allows failed imports to retry on re-entry, clears stale import-error text after recovery, and avoids duplicate mounting during interrupted navigation. Returning from a saved provider editor refreshes the PHP connection summary and restores focus to its catalog trigger. Forms retain actual disabled states, visible validation feedback, and explicit channel controls. The guided sender form updates only the name/address; the full Settings form owns the separate header overrides. Resend confirmation adds duplicate-delivery and attachment-limit guidance without changing server-side eligibility or nonce enforcement.

The 1440px and 390px design anchors are also checked at 900px. Mobile tables become labeled cards, sender preview precedes its fields, and Advanced and main navigation use native selects. CSS remains scoped to Aculect classes and semantic tokens; no component library or dependency lock was added. Added outline paths come from Heroicons 2.2.0 under `assets/licenses/heroicons-MIT.txt`.

Inter and IBM Plex Mono are named first in the font stacks. The handoff contains no font binaries; this implementation uses the existing system fallbacks without contacting a font CDN. This is a remaining typography difference from the design, not a claim of exact pixel parity.
