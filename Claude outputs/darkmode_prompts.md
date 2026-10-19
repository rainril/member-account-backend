# Dark Mode Fix Prompts

## Prompt 1 — Member App (primefit-frontend)

```
Fix dark mode so it actually applies consistently across the entire member app, not just individual screens I've already asked about.

First, audit the root cause before touching any screen:
1. Find where the dark mode toggle lives in Settings and trace exactly how it sets ThemeMode on the app's ThemeController / MaterialApp. Confirm the toggle's state is actually being read by every screen (not just stored and ignored).
2. List every screen and widget file in lib/screens and lib/widgets. For each one, check whether it uses Theme.of(context).brightness (or a dark-aware color pattern) or whether it uses hardcoded Color(0x...) / Colors.black / Colors.white / Colors.grey values that never change regardless of the toggle.
3. Produce a full list of every screen/widget that has hardcoded, non-dark-aware colors — not just the ones I've flagged before (Profile Settings, chat panel, dashboard). Include the sidebar/nav drawer, all dashboard cards, Progress screen, Check-In screen, Membership screen, Program screen, and any modal/dialog/bottom sheet in the app.

Then fix every one of them:
4. Apply the same dark-aware pattern already used in profile_settings.dart (a synced `_dark` bool per build, with color-returning getters) to every screen/widget found in step 3 — don't skip any because it seems minor.
5. Pay special attention to: sidebar/nav background and icon/text colors, dashboard stat cards and charts, form fields (background, border, hint text, input text), buttons, dialogs/pickers (e.g. date pickers, dropdowns), badges/chips, and any icon that uses a fixed color regardless of theme.
6. After each file edit, verify bracket/brace/paren balance AND read the surrounding method to confirm no code got corrupted or spliced — don't rely on balance checks alone.
7. At the end, do an independent review pass (fresh eyes, not trusting your own edit descriptions) confirming: (a) every hardcoded light-only color found in the audit was actually fixed, (b) no file was broken structurally, (c) toggling dark mode in Settings visibly changes every screen, not just some.

Give me a final list of every file changed and a one-line summary of what was wrong with each.
```

## Prompt 2 — Admin App (Admin_Frontend)

```
Fix dark mode so it actually applies consistently across the entire admin app, not just individual screens I've already asked about.

First, audit the root cause before touching any screen:
1. Find where the dark mode toggle lives (Settings or wherever it's controlled) and trace exactly how it sets ThemeMode via the ThemeController / MaterialApp. Confirm the toggle's state is actually being read app-wide.
2. Review theme/app_theme.dart fully — list every existing dark-aware helper (AppTheme.textMuted(context), AppTheme.heading(context), AppTheme.surface(context), AppTheme.border(context), AppTheme.subtleFill(context), AppTheme.pageBackground(context), AppTheme.accentFill(context, color), etc.) so you reuse these instead of inventing new patterns.
3. List every screen and widget file in lib/screens and lib/widgets. For each one, check whether it uses the AppTheme.*(context) helpers / Theme.of(context) correctly, or whether it has hardcoded AppColors.xxx (light-only constants, e.g. textMuted, cardBorder, bg) or raw Color(0x...) / Colors.black / Colors.white values that never adapt to the toggle.
4. Produce a full list of every screen/widget with hardcoded, non-dark-aware colors — not just the ones already flagged (dashboard, deletion requests panel, pending merch sales). Include the sidebar, every dashboard/analytics card and chart, all table/list rows, all dialogs and pickers, the QR scanner and check-in screens, and every panel/widget under lib/widgets.

Then fix every one of them:
5. Replace every hardcoded light-only color with the matching AppTheme.*(context) helper where one already exists; only add a new AppTheme helper (matching the existing naming/style) if nothing existing fits.
6. Watch for `const` constructors that can no longer be const once a color argument becomes a context-dependent method call — remove `const` wherever needed, and check this at every nesting level, not just the immediate wrapper.
7. Pay special attention to: sidebar background/icons/text, dashboard stat cards and charts, table headers/rows, form fields, buttons, badges/status chips, dialogs, and date/time pickers (Material date pickers can silently force light mode via colorScheme.light — check for and fix this pattern anywhere it appears).
8. After each file edit, verify bracket/brace/paren balance AND read the surrounding method to confirm no code got corrupted or spliced — don't rely on balance checks alone.
9. At the end, do an independent review pass (fresh eyes, not trusting your own edit descriptions) confirming: (a) every hardcoded light-only color found in the audit was actually fixed, (b) no file was broken structurally, (c) toggling dark mode visibly changes every screen, not just some.

Give me a final list of every file changed and a one-line summary of what was wrong with each.
```
