=== Legible ===
Contributors: lukystile
Tags: accessibility, wcag, contrast checker, ada, ai
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real WCAG 2.1 AA checks for your published content, plus optional AI-suggested fixes - not another overlay widget.

== Description ==

**Legible** scans your published posts and pages for real accessibility problems: missing alt text, colour contrast, heading order, unlabelled form fields, uninformative link text ("click here"), and missing landmark regions on your theme's front page. No JavaScript overlay is added to your site - findings are backend-only, and any fix this plugin applies goes into your actual post content, not a runtime patch layered on top of it at every page view.

* **Missing alt text** — images with no alt attribute at all (a deliberate `alt=""` on a decorative image is left alone, correctly).
* **Colour contrast** — the standard W3C relative-luminance formula, resolved against your active theme's colour palette (theme.json), not a guess.
* **Heading order** — skipped levels, multiple H1s, empty headings.
* **Form labels** — flags `<input>`/`<select>`/`<textarea>` fields with no associated label.
* **Link text** — flags "click here", bare URLs, and other link text that doesn't describe its destination.
* **Theme landmarks** — checks your active theme's front page once for a main content area, navigation, banner, and footer region.
* **Mark as false positive** — dismiss any single finding without hiding the whole check.
* **AI-suggested fixes (optional)** — bring your own API key for Gemini, Claude, OpenAI, Grok, or OpenRouter, and Legible can suggest a fix for one finding at a time: alt text for an image, a passing replacement text colour for a contrast failure, or a descriptive replacement for uninformative link text. You always review and explicitly apply or discard the suggestion - nothing is ever applied automatically.

This is an early, evolving plugin — the name itself may still change before a public release.

== External services ==

Every check in this plugin works entirely locally and sends nothing anywhere. The one exception, and it is entirely optional, is the AI-suggested fix feature: if you add your own API key for an AI provider (Gemini, Claude, OpenAI, Grok, or OpenRouter) in Legible's settings, clicking "Generate AI fix" on a specific finding sends that finding's data to whichever provider you configured, to receive a suggestion back.

* For a missing-alt-text finding, that one image - and, for a published public page, its public URL - is sent. For a draft, private, or password-protected post, the image is read from your server and sent directly instead, so its URL is never transmitted.
* For a contrast finding, only the two colour hex codes involved (background and failing text colour) are sent - no image, no content.
* For a link-text finding, only the link's current visible text and its destination URL are sent - no image, no other page content.

No request is ever sent without you clicking that button for that specific finding, and nothing is applied to your content until you separately click Apply. See that provider's own privacy policy for how they handle a received request. Without an API key configured, no such request is ever made, and every other check in this plugin is unaffected either way.

== Installation ==

1. Upload the `legible` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **Legible** in the admin menu and click **Scan now**.
4. Optional: open **Legible → Settings** to add an AI provider API key if you want AI-suggested alt text.

== Screenshots ==

1. Scan results: a plain-language digest at the top, then per-finding rows with severity, an explanation, and one-click actions.
2. An AI-suggested fix, generated and shown for review before you decide to apply or discard it.
3. Findings list showing the Apply / Discard / Not an issue actions for contrast, link text, and missing alt text.
4. Theme landmarks check: results for the active theme's front page (main content area, navigation, footer regions).
5. Settings page: pick an AI provider and paste your own API key - nothing is sent until you explicitly generate a fix.
6. Site Health: shows which AI provider is configured, without making a live API call.
7. Site Health "Test connection": an explicit, one-off check that your configured API key actually works.

== Frequently Asked Questions ==

= Does this add a widget or overlay to my site? =

No. Legible never adds anything to your site's front end. It only looks at your content from the admin side.

= Does this make my site meet accessibility standards? =

No single automated tool can promise that, and Legible will never claim otherwise. It checks a specific, honestly-scoped set of rules and tells you exactly what it found — meeting a standard like WCAG is a broader, ongoing effort than any one tool can settle.

= Do I have to use the AI features? =

No. Every rule-based check works with no AI provider configured at all. AI-suggested fixes are an entirely optional add-on you turn on yourself by adding an API key.

= Does this work with page builders like Elementor or Divi? =

Not in this version. Legible currently checks Gutenberg block content and classic-editor HTML. Page builder support is a planned addition, not implemented yet.

== Changelog ==

= 1.0.0 =
* First public release.
* Rule-based checks: missing alt text, contrast, heading order, form labels, link text, theme landmarks.
* Content-hash-cached scanning, per-instance false-positive dismissal, admin page with a plain-language digest.
* Optional AI-suggested fixes, one finding at a time: alt text, contrast (replacement text colour), link text. Bring your own key for Gemini, Claude, OpenAI, Grok, or OpenRouter; always previewed and explicitly confirmed before anything is applied.
* Site Health check for AI provider configuration (never makes a live API call automatically); a separate "Test connection" button for an explicit, one-off check.
* `wp legible check-phrases`: a release-time check against a list of accessibility-compliance claims this plugin never makes.
