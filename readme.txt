=== Fettle ===
Contributors: lukystile
Tags: accessibility, accessibility checker, wcag, alt text, contrast checker
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 
Find the accessibility problems in your posts and pages, then fix them in the content itself – with optional AI help. No overlay widget.
 
== Description ==
 
Most accessibility plugins bolt a JavaScript widget onto your front end and patch the page every time someone loads it. Your actual content stays exactly as broken as it was.
 
**Fettle works the other way around.** It reads your posts and pages from the admin side, tells you in plain language exactly what's wrong, and when you decide to fix something, the fix goes into the post itself. Nothing is added to your front end and nothing runs for your visitors, so your pages load exactly as fast as before.
 
= What Fettle checks =
 
* **Missing alt text** – finds images with no alt attribute at all. Images you deliberately marked as decorative (`alt=""`) are respected, not flagged.
* **Colour contrast** – uses the standard W3C relative-luminance formula and resolves colours from your active theme's palette (theme.json), so results reflect what visitors actually see rather than a guess.
* **Heading order** – catches skipped levels, multiple H1s and empty headings that break screen reader navigation.
* **Form labels** – flags `<input>`, `<select>` and `<textarea>` fields that a screen reader can't name.
* **Link text** – spots "click here", bare URLs and other link text that doesn't say where the link goes.
* **Theme landmarks** – checks your active theme's front page once for a main content area, navigation, banner and footer region.
 
= Let AI draft the fix – you stay in control =
 
Add your own API key for Gemini, Claude, OpenAI, Grok or OpenRouter, and Fettle can suggest a fix for any single finding:
 
* **Alt text** written from the image itself
* **A replacement text colour** that passes contrast against its background
* **Descriptive link text** based on where the link actually points
 
Every suggestion is shown to you first. You apply it or discard it – nothing is ever changed automatically, and none of your content is sent until you click the button for that specific finding.
 
= Built to stay out of your way =
 
* **Zero front-end footprint** – no scripts, styles or widgets on your public site.
* **Smart caching** – a page is re-scanned only when its content actually changes.
* **Per-finding dismissal** – mark a single result as "Not an issue" without switching off the whole check.
* **Plain-language digest** – a short summary at the top of your results tells you where to start.
* **Site Health integration** – see which AI provider is configured at a glance, plus an explicit "Test connection" button when you want it.
* **Works fully without AI** – every check runs locally. No key, no account, no signup.
 
= Honest by design =
 
No automated tool can certify a website against WCAG, and Fettle never pretends to. It checks a clearly defined set of WCAG 2.1 AA rules, shows you exactly what it found, and helps you fix it for real – in your content, where it matters. Every release is checked against a list of accessibility claims this plugin refuses to make.
 
= Who it's for =
 
* **Site owners** who want to fix real problems instead of hiding them behind a widget.
* **Agencies and freelancers** who want a quick, reliable accessibility pass before handing a site over to a client.
* **Editors and content teams** who want to catch missing alt text, vague links and broken heading structure across the whole site in one place.
 
== External services ==
 
Every check in this plugin works entirely locally and sends nothing anywhere. The one exception, and it is entirely optional, is the AI-suggested fix feature: if you add your own API key for an AI provider (Gemini, Claude, OpenAI, Grok, or OpenRouter) in Fettle's settings, clicking "Generate AI fix" on a specific finding sends that finding's data to whichever provider you configured, to receive a suggestion back.
 
* For a missing-alt-text finding, that one image - and, for a published public page, its public URL - is sent. For a draft, private, or password-protected post, the image is read from your server and sent directly instead, so its URL is never transmitted.
* For a contrast finding, only the two colour hex codes involved (background and failing text colour) are sent - no image, no content.
* For a link-text finding, only the link's current visible text and its destination URL are sent - no image, no other page content.
 
None of your content is ever sent without you clicking that button for that specific finding, and nothing is applied to your content until you separately click Apply. Without an API key configured, no such request is ever made, and every other check in this plugin is unaffected either way.

Once a key is saved, two other requests can go to that same provider, and neither sends any of your content: opening Fettle → Settings fetches the provider's list of available models (only your API key is sent; the list is cached for 24 hours so this doesn't happen on every page load), and the "Test connection" button sends one minimal, text-only request to confirm the key and model work.

Depending on which provider you select in Settings, the plugin talks to one of the following:

**Google Gemini**
* Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/`
* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

**Anthropic Claude**
* Endpoint: `https://api.anthropic.com/v1/messages`
* Terms of Service: https://www.anthropic.com/legal/commercial-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

**OpenAI**
* Endpoint: `https://api.openai.com/v1/chat/completions`
* Terms of Service: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

**xAI (Grok)**
* Endpoint: `https://api.x.ai/v1/chat/completions`
* Terms of Service: https://x.ai/legal/terms-of-service
* Privacy Policy: https://x.ai/legal/privacy-policy

**OpenRouter**
* Endpoint: `https://openrouter.ai/api/v1/chat/completions`
* Terms of Service: https://openrouter.ai/terms
* Privacy Policy: https://openrouter.ai/privacy
* Note: OpenRouter itself routes your request to one of many underlying model providers (OpenAI, Anthropic, Google, Meta, and others) depending on the model you select - see OpenRouter's own policies for how it handles data passed to those upstream providers.
 
== Installation ==
 
1. Upload the `fettle` folder to `/wp-content/plugins/`, or install it from **Plugins → Add New**.
2. Activate the plugin through the **Plugins** screen.
3. Open **Fettle** in the admin menu and click **Scan now**.
4. Optional: open **Fettle → Settings** and add an AI provider API key to enable AI-suggested fixes.
 
== Frequently Asked Questions ==
 
= Does this add a widget or overlay to my site? =
 
No. Fettle never adds anything to your site's front end. It only looks at your content from the admin side.
 
= Will it slow down my site? =
 
No. Fettle loads nothing for your visitors. All scanning happens in the admin, and pages whose content hasn't changed aren't scanned again.
 
= Does this make my site meet accessibility standards? =
 
No single automated tool can promise that, and Fettle will never claim otherwise. It checks a specific, honestly-scoped set of rules and tells you exactly what it found — meeting a standard like WCAG is a broader, ongoing effort than any one tool can settle.
 
= Do I have to use the AI features? =
 
No. Every rule-based check works with no AI provider configured at all. AI-suggested fixes are an entirely optional add-on you turn on yourself by adding an API key.
 
= How much do the AI fixes cost? =
 
Fettle doesn't charge anything for them. You use your own API key, so any usage is billed by your chosen provider at their normal rates. Each suggestion is a single small request for one finding.
 
= Will the AI change my content without asking? =
 
Never. A suggestion is only generated when you click "Generate AI fix", and it is only written into your post when you click Apply.
 
= What exactly is sent to the AI provider? =
 
Only the data for the one finding you clicked – see the External services section above for the full breakdown.
 
= Does this work with page builders like Elementor or Divi? =
 
Not in this version. Fettle currently checks Gutenberg block content and classic-editor HTML. Page builder support is planned.
 
== Screenshots ==
 
1. Scan results: a plain-language digest at the top, then per-finding rows with severity, an explanation, and one-click actions.
2. An AI-suggested fix, generated and shown for review before you decide to apply or discard it.
3. Findings list showing the Apply / Discard / Not an issue actions for contrast, link text, and missing alt text.
4. Theme landmarks check: results for the active theme's front page (main content area, navigation, footer regions).
5. Settings page: pick an AI provider and paste your own API key - nothing is sent until you explicitly generate a fix.
6. Site Health: shows which AI provider is configured, without making a live API call.
7. Site Health "Test connection": an explicit, one-off check that your configured API key actually works.
 
== Changelog ==
 
= 1.0.0 =
* First public release.
* Rule-based checks: missing alt text, contrast, heading order, form labels, link text, theme landmarks.
* Content-hash-cached scanning, per-instance false-positive dismissal, admin page with a plain-language digest.
* Optional AI-suggested fixes, one finding at a time: alt text, contrast (replacement text colour), link text. Bring your own key for Gemini, Claude, OpenAI, Grok, or OpenRouter; always previewed and explicitly confirmed before anything is applied.
* Site Health check for AI provider configuration (never makes a live API call automatically); a separate "Test connection" button for an explicit, one-off check.
* `wp fettle check-phrases`: a release-time check against a list of accessibility-compliance claims this plugin never makes.