=== Usher ===
Contributors: lukystile
Tags: accessibility, wcag, contrast checker, ada, ai
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real WCAG 2.1 AA checks for your published content, not another overlay widget.

== Description ==

**Usher** scans your published posts and pages for real accessibility problems: colour contrast, heading order, unlabelled form fields, and uninformative link text ("click here"). No JavaScript overlay is added to your site - findings are backend-only, and any fix this plugin applies goes into your actual post content, not a runtime patch layered on top of it at every page view.

* **Colour contrast** — the standard W3C relative-luminance formula, resolved against your active theme's colour palette (theme.json), not a guess.
* **Heading order** — skipped levels, multiple H1s, empty headings.
* **Form labels** — flags `<input>`/`<select>`/`<textarea>` fields with no associated label.
* **Link text** — flags "click here", bare URLs, and other link text that doesn't describe its destination.
* **Mark as false positive** — dismiss any single finding without hiding the whole check.

This is an early, evolving plugin — the name itself may still change before a public release. AI-assisted fix suggestions (for example, a proposed contrast-safe colour or a better link description) are planned but not in this build yet: every check above works today without any external service.

== External services ==

This version of Usher does not connect to any external service. No data leaves your site. Colour contrast, heading order, form labels, and link text are all computed locally from your post content and your theme's own colour palette.

== Installation ==

1. Upload the `usher` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **Usher** in the admin menu and click **Scan now**.

== Frequently Asked Questions ==

= Does this add a widget or overlay to my site? =

No. Usher never adds anything to your site's front end. It only looks at your content from the admin side.

= Does this make my site meet accessibility standards? =

No single automated tool can promise that, and Usher will never claim otherwise. It checks a specific, honestly-scoped set of rules and tells you exactly what it found — meeting a standard like WCAG is a broader, ongoing effort than any one tool can settle.

= Does this work with page builders like Elementor or Divi? =

Not in this version. Usher currently checks Gutenberg block content and classic-editor HTML. Page builder support is a planned addition, not implemented yet.

== Changelog ==

= 0.1.0 =
* Initial skeleton: contrast, heading-order, form-label, and link-text checks; content-hash-cached scanning; per-instance false-positive dismissal; admin page.
