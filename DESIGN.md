---
name: MigrateKit
description: One-click WordPress migration, backup and restore — every feature included, free, forever.
colors:
  surface: "#ffffff"
  panel: "#f8fafc"
  ink: "#0f172a"
  engine-slate: "#64748b"
  hairline: "#e2e8f0"
  signal-blue: "#2563eb"
  signal-blue-soft: "#dbeafe"
  signal-blue-strong: "#1d4ed8"
  alarm-red: "#b91c1c"
  go-green: "#15803d"
  caution-amber: "#b45309"
typography:
  display:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "26px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  display-mobile:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "22px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  stat-lead:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "32px"
    fontWeight: 700
    lineHeight: 1.1
  title:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "18px"
    fontWeight: 600
  subtitle:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "15px"
    fontWeight: 600
  body:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "14px"
    fontWeight: 400
  label:
    fontFamily: "inherit (WordPress admin system stack)"
    fontSize: "12px"
    fontWeight: 700
    letterSpacing: "0.04em"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"
    fontSize: "13px"
    fontWeight: 400
rounded:
  sm: "7px"
  md: "8px"
  lg: "10px"
  xl: "12px"
  card: "14px"
  pill: "999px"
spacing:
  xs: "6px"
  sm: "10px"
  md: "12px"
  lg: "16px"
  xl: "20px"
  xxl: "24px"
components:
  button-primary:
    backgroundColor: "{colors.signal-blue}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "10px 18px"
    typography: "{typography.body}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.signal-blue}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  button-danger:
    backgroundColor: "{colors.alarm-red}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "9px 11px"
    typography: "{typography.body}"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.card}"
    padding: "24px"
  stat-tile:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.xl}"
    padding: "16px 18px"
  badge:
    backgroundColor: "{colors.signal-blue-soft}"
    textColor: "{colors.signal-blue-strong}"
    rounded: "{rounded.pill}"
    padding: "4px 10px"
    typography: "{typography.label}"
  nav-pill:
    rounded: "{rounded.md}"
    padding: "8px 14px"
  nav-pill-active:
    backgroundColor: "{colors.signal-blue-soft}"
    textColor: "{colors.signal-blue-strong}"
  tab:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "8px 14px"
  tab-active:
    backgroundColor: "{colors.signal-blue-soft}"
    textColor: "{colors.ink}"
  progress-track:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.pill}"
    height: "10px"
---

# Design System: MigrateKit

## Overview

**Creative North Star: "The Workshop Bench"**

MigrateKit's admin is an organized technician's bench: every tool visible, in reach, and honestly labeled. Competence you can see. The atmosphere is calm and sturdy — no-nonsense surfaces that sit still until used, with lightness earned from whitespace and precision rather than decoration. Nothing animates unless work is actually happening, and when it does (a job's progress bar), it is the single honest signal on the bench.

The system lives inside the WordPress admin but deliberately reads as its own room: a token-driven slate-and-blue world (`--mk-*` custom properties) with a full automatic dark scheme (`prefers-color-scheme`), card-based pages, and one accent doing all the talking. Density is comfortable-admin, not dashboard-crisp: 14px body, generous card padding, hairline borders instead of shadows.

It serves two confirmed audiences at once — DIY site owners following guided flows and professionals reaching for precise controls — so clarity of state (what is running, what is safe, what is connected) outrank visual expression everywhere.

**Key Characteristics:**

- Flat, hairline-bordered surfaces; one whisper of a shadow on cards and nothing else
- Signal Blue as the only expressive color: primary action, active state, links
- Semantic state colors (red / green / amber) appear only for real danger / success / warning
- System font stack inherited from WordPress admin; hierarchy by size and weight, not family
- Automatic dark scheme via token swap — no separate dark layout
- Comfortable density: 14px base, 24px card padding, auto-fit grids that collapse gracefully

## Colors

A cool slate bench with one instrument-blue signal — quiet neutrals, saturated accents held in reserve.

All values are CSS custom properties on `.mk-app` (`resources/app/styles.css`); the light scheme is canonical and the dark scheme swaps every token automatically via `prefers-color-scheme`.

### Primary

- **Signal Blue** (#2563eb): the system's one voice. Primary buttons, active nav pills and tabs, links, focus rings, progress fill, dropzone-active border. Its rarity is the point.
- **Signal Blue Soft** (#dbeafe): the tint that marks active states — badge background, active nav/tab fill. Always paired with Signal Blue Strong text (5.5:1).
- **Signal Blue Strong** (#1d4ed8): accent text sitting on Signal Blue Soft — nav pills, badges, segmented-control active text — where Signal Blue itself measures below the 4.5:1 AA floor at those sizes.

### Neutral

- **Surface** (#ffffff): card and input background — the bench top.
- **Panel** (#f8fafc): the tonal layer beneath — tiles, tab bodies, dropzones, confirm panels, progress track. Depth without shadow.
- **Ink** (#0f172a): primary text.
- **Engine Slate** (#64748b): secondary text, descriptions, table headers, step lists, progress meta.
- **Hairline** (#e2e8f0): every border and divider in the system — the structural workhorse that replaces elevation.

### Tertiary

- **Alarm Red** (#b91c1c): destructive buttons, error text, danger links. Reserved for real danger.
- **Go Green** (#15803d): success text and status dots.
- **Caution Amber** (#b45309): small inline warnings — cron health, first-run nudges. Never a large surface.

### Dark Scheme

`prefers-color-scheme: dark` swaps the tokens: Surface #0f172a, Panel #111c33, Ink #e2e8f0, Engine Slate #94a3b8, Hairline #1e293b, Signal Blue #60a5fa, Signal Blue Soft #1e3a8a, Signal Blue Strong #93c5fd, Alarm Red #f87171, Go Green #4ade80, Caution Amber #fbbf24. Roles and rules are identical; only values move.

### Named Rules

**The One Signal Rule.** Signal Blue marks primary actions, active state, and links — nothing else. If everything is blue, nothing is.

**State Earns Its Color.** Red, green, and amber appear only for genuine danger, success, and warning states. They are never used decoratively or as a second brand accent.

## Typography

**Display Font:** inherited (WordPress admin system stack — `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`)
**Body Font:** inherited (same stack)
**Label/Mono Font:** `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`

**Character:** No custom family anywhere — hierarchy is built entirely from size, weight, and case. The system deliberately borrows the host admin's voice so it reads as native; distinctiveness lives in color, shape, and spacing instead.

### Hierarchy

- **Display** (700, 26px, line-height 1.2, tracking −0.02em): the page brand title "MigrateKit" in the header only. Drops to 22px below 782px.
- **Stat Lead** (700, 32px, line-height 1.1): the lead stat tile's figure — the last-backup age on the dashboard — one step above the standard tile figure so the safety headline dominates.
- **Title** (600, 18px): card headings (`mk-card h2`).
- **Subtitle** (600, 15px): card section headings, action-card titles (`h3`).
- **Body** (400, 14px): everything. The app sets one base font-size (14px) and lets it cascade.
- **Label** (700, 12px, uppercase, tracking 0.04–0.05em): tile headers, table headers, badges, status captions — the small "engraved" layer of the bench.
- **Mono** (400, 13px): file names, archive names, technical values; `word-break: break-all` because archives have long names.
- Supporting sizes: 13px for secondary/meta text, 12px for inline warnings.

### Named Rules

**The Uppercase Ledger Rule.** Uppercase 12px labels are for identifying metadata (tile headers, table columns, badges) — never for buttons, nav, or sentences.

## Layout

Single column, max-width 1440px, with 24px top / 48px bottom margins inside the wp-admin body. Each page is a stack of full-width cards; cards hold either `mk-grid` auto-fit field grids (min 240px), `mk-tiles` stat rows (min 210px), `mk-checks` checkbox fieldsets (min 190px), or `mk-action-grid` quick-start cards (min 280px) — all `repeat(auto-fit, minmax(Npx, 1fr))` with 10–16px gaps, so responsive collapse is token-driven and needs no per-page media queries.

The header is a flex row: brand block left, nav pill row right, wrapping to a column below 782px. Two breakpoints total: 782px (the WordPress admin breakpoint — smaller margins, stacked header, single-column checks, tighter card radius and padding, table drops its second column) and 480px (tiles go single column, action buttons go full width, progress meta stacks). Spacing rhythm runs on a small ladder: 6 / 10 / 12 / 16 / 20 / 24.

## Elevation & Depth

The system is flat by doctrine. Structure comes from 1px hairline borders and the tonal step between Surface and Panel; the only shadow in the entire stylesheet is a near-invisible card whisper (`box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04)`) that does no real lifting. Modals are inline panels below their trigger, not floating overlays — nothing floats above the bench.

### Named Rules

**The Flat Bench Rule.** Surfaces sit flat at rest. Depth is tonal (Panel vs Surface) and bordered (Hairline); never add shadows to communicate hierarchy.

## Shapes

A consistent corner ladder, small to large: controls 8px (buttons, inputs, nav pills, tabs, filter fields), inner segmented buttons 7px inside their 10px container, contained groups 10px (checkbox fieldsets, confirm panels, inline modals, textareas), tiles and dropzones 12px, cards 14px (10px below 782px). Pills (999px) are reserved for badges and the progress track. Distinctive silhouettes: the 2px dashed dropzone border (the one intentionally "open" shape — an empty spot on the bench waiting for a crate), and the left-accent note (3px solid Signal Blue border, square left corners, 8px right corners) — an annotation pinned to the bench edge.

## Components

Component feel in one phrase: **tactile and confident** — solid, grippable, obvious tools with weight where you press.

### Buttons

- **Shape:** gently rounded (8px radius)
- **Primary:** Signal Blue fill, white text, weight 700, padding 10px 18px; the confident grip.
- **Ghost:** transparent fill, Signal Blue text, 1px Hairline border — the secondary reach.
- **Danger:** Alarm Red fill, white text — reserved for destructive actions.
- **Hover / Focus:** none defined beyond browser defaults in the current system; disabled dims to 0.6 opacity with `not-allowed` cursor. Buttons go full-width below 480px inside action rows.

### Navigation

Header nav pills: 8px radius, 8px 14px padding, Engine Slate text at rest (weight 600); hover fills Panel and darkens text to Ink; active fills Signal Blue Soft with Signal Blue Strong text. Full page reloads via `?page=` links — no SPA routing.

### Tabs

Outlined pills (1px Hairline, Panel fill, Ink text, 13px); active gets Signal Blue border + Soft fill + weight 600. Used for in-page provider/connection switching on the Cloud page, often with a status dot.

### Status Dots

8px circles: Hairline (idle), Go Green (ready/connected).

### Cards / Containers

- **Card:** Surface fill, 1px Hairline border, 14px radius, 24px padding, the whisper shadow; `overflow-x: auto` so wide tables scroll inside.
- **Stat Tile:** Panel fill, 12px radius, uppercase Ledger header, 26px weight-700 figure, 13px Engine Slate sub.
- **Action Card:** Surface fill, 12px radius, 18px padding, description, actions pinned to the bottom via `margin-top: auto`.
- **Badge:** Signal Blue Soft pill, 12px weight-700 text, sits top-right of a card head.

### Inputs / Fields

- **Style:** 1px Hairline border, Surface fill, 8px radius, 9px 11px padding, 14px text. Labels weight 600 with optional muted `<em>` hints.
- **Focus:** 2px Signal Blue outline, offset 1px, border goes transparent — the tactile "click" of a tool seating.
- **Checkbox groups** sit in bordered 10px-radius fieldsets with auto-fit columns. **Textarea** (key paste) is mono 12px/1.5 with a 10px radius.

### Dropzone

Panel fill, 2px dashed Hairline border, 12px radius, centered content; the active/hover state swaps to a Signal Blue border with Soft fill.

### Progress Bar

10px pill track (Panel fill, Hairline border) with Signal Blue fill, `width 0.25s ease` — the only motion token in the system. Meta row beneath: message left, percent right, Engine Slate 13px; `role="status" aria-live="polite"`.

### Table

Full-width, collapsed borders, 11px 12px cell padding, Hairline row dividers, uppercase Ledger headers, mono for file/archive names, right-aligned numeric columns. Second column hides below 782px.

### Notes & Messages

Left-accent note (3px Signal Blue bar, Panel fill, Engine Slate text) for contextual guidance; plain weight-600 colored text for success/error; small amber 12px inline warnings.

## Do's and Don'ts

### Do:

- **Do** reach for the token ladder first: any new surface uses `--mk-*` custom properties so the dark scheme comes free.
- **Do** give every view exactly one Signal Blue primary action; secondaries go ghost.
- **Do** use the auto-fit `minmax()` grid patterns (`mk-grid`, `mk-tiles`, `mk-checks`, `mk-action-grid`) for any new grouped layout.
- **Do** keep long technical values (archive names, keys, paths) in mono with `break-all`.
- **Do** attach live progress (`mk-bar` + `aria-live="polite"` meta) to any long-running job, and a state color only when state is real.

### Don't:

- **Don't** add shadows for hierarchy — the bench is flat; borders and the Panel tone carry structure.
- **Don't** introduce a second brand accent or use Signal Blue decoratively.
- **Don't** uppercase anything that isn't metadata (the Ledger Rule) or set a custom font family.
- **Don't** hardcode light-scheme colors — they break the automatic dark scheme.
- **Don't** float modals over the page; dialogs expand inline as bordered panels.
