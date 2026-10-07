=== Fettle ===
Contributors: lukystile
Tags: accessibility, accessibility checker, wcag, alt text, contrast checker
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 
Find the accessibility problems in your posts and pages, then fix them in the content itself – with optional AI help. No overlay widget.
 
== Description ==
 
**Accessibility problems live in your content – so that's where Fettle fixes them.**
 
Most accessibility plugins bolt a JavaScript widget onto your front end and patch the page every time someone loads it. Your actual content stays exactly as broken as it was, and overlay widgets are widely criticised by accessibility practitioners for exactly that reason.
 
**Fettle works the other way around.** It reads your published posts and pages from the admin side, tells you in plain language exactly what's wrong and why it matters, and when you decide to fix something, the fix goes into the post itself. Nothing is added to your front end and nothing runs for your visitors, so your pages load exactly as fast as before.
 
**[Visit the Fettle plugin page](https://cognitolab.net/products/fettle)** – screenshots, a feature-by-feature comparison of Free and Pro, and a live demo you can try in your browser without installing anything.
 
= Why site owners choose Fettle =
 
* **Real fixes, not a mask.** Every fix is written into your content – where screen readers, search engines and every visitor actually meet it – instead of being patched over on each page load.
* **Minutes, not an audit project.** Install, click "Scan now" and get a plain-language list of what to fix first. No account, no API key, no setup.
* **AI does the tedious part.** Alt text, a passing text colour or clearer link text, drafted in one click – and you review every suggestion before it touches your content.
* **Nothing on your front end.** No widget, no script, no slowdown.
* **Honest results.** Fettle tells you exactly which rules it checked and what it found. It never pretends a scan alone settles accessibility.
 
= Try it in your browser first =
 
Not ready to install on a real site? **[Open the live demo](https://founder.cognitolab.net/fettle-demo/index.html)** – a complete WordPress site running right in your browser, with sample pages full of real accessibility issues for you to scan, review and fix. Nothing to install, nothing to clean up afterwards.
 
= What Fettle checks =
 
* **Missing alt text** – finds images with no alt attribute at all.
* **Decorative or meaningful images** – an empty alt (`alt=""`) is the right way to mark a purely decorative image, and Fettle respects it – except where there is clear evidence the image carries meaning: it is the only content of a link (so the link has no name at all), or its Media Library entry has alt text. It also flags alt text that only repeats the text right next to it (the link's own text, or the image's caption), so screen readers don't read the same words twice.
* **Meaningless alt text** – alt text that is really a file name (`IMG_2041.jpg`) or a placeholder like "image" or "photo".
* **Table headers** – data tables with no header cells, a bold first row that only looks like a header, `headers` attributes that point nowhere, and two-way tables whose header cells don't say whether they head a row or a column.
* **Colour contrast** – uses the standard W3C relative-luminance formula on blocks where both the text colour and the background colour are set, including colours picked from your theme's palette (theme.json). Blocks with only one of the two set, or with a gradient or image background, are skipped rather than guessed at.
* **Heading order** – catches skipped levels, multiple H1s and empty headings that break screen reader navigation.
* **Form labels** – flags `<input>`, `<select>` and `<textarea>` fields that a screen reader can't name.
* **Link text** – spots "click here", bare URLs and other link text that doesn't say where the link goes.
* **Theme landmarks** – checks your active theme's front page once for a main content area, navigation, banner and footer region.
 
= Let AI draft the fix – you stay in control =
 
On WordPress 7.0 or later, Fettle can use the AI provider you've already set up for your whole site under Settings > Connectors – no extra key needed. Or add your own API key for Gemini, Claude, OpenAI, Grok or OpenRouter. Either way, Fettle can suggest a fix for any single finding:
 
* **Alt text** written from the image itself – or, for an image that is purely decorative, a suggestion to mark it as such. For an image that is a link's only content, the alt text names where the link goes.
* **A replacement text colour** that passes contrast against its background
* **Descriptive link text** based on where the link actually points
 
Some fixes don't need AI at all: marking an image whose alt text repeats nearby text as decorative, or reusing the alt text already saved for an image in your Media Library. They go through the same preview-and-confirm steps.

Every suggestion is shown to you first. You apply it or discard it – nothing is ever changed automatically, and none of your content is sent until you click the button for that specific finding.
 
= Need it across the whole site? Meet Fettle Pro =
 
Free Fettle fixes one finding at a time, which is perfect for a small site. **[Fettle Pro](https://cognitolab.net/products/fettle)** is built for sites with hundreds of pages, and for agencies and freelancers looking after many of them:
 
* **Bulk AI-fix review** – suggestions for every fixable finding are generated in the background (safe to close the browser), then one screen lets you tick and apply as many as you like. Nothing is applied without that review.
* **Scheduled rescans with regression alerts** – weekly or monthly, and an email only when something genuinely new turns up, like a later edit that stripped an image's alt text. Never a repeat notice for something you already know about.
* **Scan history and trends** – see whether each page, and the site as a whole, is getting better or worse over time.
* **Accessibility audit summary** – a printable report organised by WCAG 2.1 success criteria, citing the actual findings behind each one. Ready to hand to a client or a manager. (An automated summary in a VPAT-inspired format – not an official VPAT, which needs expert manual review.)
 
Fettle Pro comes with a **7-day free trial, no card required**. [Try the Pro demo](https://founder.cognitolab.net/fettle-pro-demo/index.html) or **[explore Fettle Pro and start your trial](https://cognitolab.net/products/fettle)**.
 
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
 
Every check in this plugin works entirely locally and sends nothing anywhere. The one exception, and it is entirely optional, is the AI-suggested fix feature: clicking "Generate AI fix" on a specific finding sends that finding's data to the AI provider you configured, to receive a suggestion back. That provider is either the one set up for your whole site in WordPress under Settings > Connectors (WordPress 7.0+; the request then goes through WordPress's own AI Client, to whichever service that connector uses, under that service's terms), or – if you add your own API key in Fettle's settings – Gemini, Claude, OpenAI, Grok, or OpenRouter, called directly.
 
* For an alt-text finding (a missing alt, or alt text that is a file name or placeholder), that one image - and, for a published public page, its public URL - is sent, together with up to 300 characters of the paragraph that follows the image in the post, so the suggestion fits its context. If the image is the only content of a link, the link's destination URL is sent too, so the suggested alt text can name where the link goes. For a password-protected post, the image is read from your server and sent directly instead, so its URL is never transmitted.
* "Mark as decorative" and "Use Media Library alt text" make no external request at all.
* For a contrast finding, only the two colour hex codes involved (background and failing text colour) are sent - no image, no content.
* For a link-text finding, only the link's current visible text and its destination URL are sent - no image, no other page content.
 
None of your content is ever sent without you clicking that button for that specific finding, and nothing is applied to your content until you separately click Apply. Without a provider configured, no such request is ever made, and every other check in this plugin is unaffected either way.

Once a key is saved for a direct provider, two other requests can go to that same provider, and neither sends any of your content: opening Fettle → Settings fetches the provider's list of available models (only your API key is sent; the list is cached for 24 hours so this doesn't happen on every page load), and the "Test connection" button sends one minimal, text-only request to confirm the key and model work.

When you use your own API key, depending on which provider you select in Settings, the plugin talks to one of the following:

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
4. Optional: open **Fettle → Settings** and pick an AI provider – WordPress AI (Settings > Connectors, WordPress 7.0+) or your own API key – to enable AI-suggested fixes.
 
== Frequently Asked Questions ==
 
= Does this add a widget or overlay to my site? =
 
No. Fettle never adds anything to your site's front end. It only looks at your content from the admin side.
 
= Will it slow down my site? =
 
No. Fettle loads nothing for your visitors. All scanning happens in the admin, and pages whose content hasn't changed aren't scanned again.
 
= Does this make my site meet accessibility standards? =
 
No single automated tool can promise that, and Fettle will never claim otherwise. It checks a specific, honestly-scoped set of rules and tells you exactly what it found — meeting a standard like WCAG is a broader, ongoing effort than any one tool can settle.
 
= Do I have to use the AI features? =
 
No. Every rule-based check works with no AI provider configured at all. AI-suggested fixes are an entirely optional add-on you turn on yourself by choosing an AI provider.
 
= How much do the AI fixes cost? =
 
Fettle doesn't charge anything for them. You use your own AI provider account – through WordPress's Connectors or your own API key – so any usage is billed by that provider at their normal rates. Each suggestion is a single small request for one finding.
 
= Will the AI change my content without asking? =
 
Never. A suggestion is only generated when you click "Generate AI fix", and it is only written into your post when you click Apply.
 
= Does Fettle decide which images are decorative? =

No. Whether an image carries meaning is a judgement about your content, and a scanner can't make it reliably. Fettle only flags images where there is clear evidence one way or the other, and when an AI suggestion says an image looks purely decorative, that is still only a suggestion you can apply or discard. Images with ordinary-looking alt text are never second-guessed.

= Why doesn't Fettle fix table headers for me? =

A table's structure lives in the block itself, and rewriting it from outside the editor risks breaking the block. Each table finding tells you exactly where to fix it instead – for a Table block, that is one "Header section" toggle in its settings.

= What's the difference between Fettle and Fettle Pro? =
 
Free Fettle runs every check and lets you fix findings one at a time, with or without AI. Fettle Pro adds bulk AI-fix review, scheduled rescans with regression-only email alerts, scan history and trends, and a printable WCAG 2.1 audit summary. See the full comparison and pricing on the [Fettle plugin page](https://cognitolab.net/products/fettle).
 
= Can I try Fettle before installing it? =
 
Yes. The [live demo](https://founder.cognitolab.net/fettle-demo/index.html) runs a complete WordPress site with Fettle in your browser, with sample content ready to scan and fix. There is a [Pro demo](https://founder.cognitolab.net/fettle-pro-demo/index.html) too.
 
= What exactly is sent to the AI provider? =
 
Only the data for the one finding you clicked – see the External services section above for the full breakdown.
 
= Which content does Fettle check? =
 
Published posts and pages. Drafts, private posts and other post types (such as WooCommerce products) are not scanned in this version.
 
= Does this work with page builders like Elementor or Divi? =
 
Not in this version. Fettle currently checks Gutenberg block content and classic-editor HTML. Page builder support is planned.
 
== Screenshots ==
 
1. Scan results: a plain-language digest at the top, then per-finding rows with severity, an explanation, and one-click actions.
2. An AI-suggested fix, generated and shown for review before you decide to apply or discard it.
3. Findings across several posts: review a suggestion, generate a new one, or mark a result as "Not an issue".
4. Theme landmarks check: results for the active theme's front page (main content area, navigation, footer regions).
5. Settings page: use the AI provider set up under Settings > Connectors, or pick one and paste your own API key - nothing is sent until you explicitly generate a fix.
6. Site Health: shows which AI provider is configured, without making a live API call.
7. "Test connection" on the settings page: an explicit, one-off check that your configured AI provider actually works.
 
== Changelog ==

= 1.1.0 =
* New: decorative-vs-meaningful image checks – an empty alt on an image that is a link's only content or has Media Library alt text, and alt text that only repeats the link text or caption next to it.
* New: alt text that is a file name or a placeholder ("IMG_2041.jpg", "image", "photo") is flagged.
* New: table header checks – no header cells, a bold first row that only looks like a header, broken `headers` references, missing `scope` on two-way tables, empty header cells, and layout tables using data-table markup. Each finding includes how to fix it.
* New: AI alt-text suggestions can say an image is purely decorative and propose an empty alt instead; for an image that is a link's only content, the suggestion names where the link goes.
* New: "Mark as decorative" and "Use Media Library alt text" fixes that work without an AI provider.
* Improved: the Check column shows a readable name for each check.
* Improved: after a plugin update that adds checks, existing posts are rescanned automatically instead of waiting for their content to change.
* Fixed: AI context for an image could come from the paragraph after an earlier image on the same page.
 
= 1.0.0 =
* First public release.
* Rule-based checks: missing alt text, contrast, heading order, form labels, link text, theme landmarks.
* Content-hash-cached scanning, per-instance false-positive dismissal, admin page with a plain-language digest.
* Optional AI-suggested fixes, one finding at a time: alt text, contrast (replacement text colour), link text. Uses the WordPress AI Client (Settings > Connectors) on WordPress 7.0+, or bring your own key for Gemini, Claude, OpenAI, Grok, or OpenRouter; always previewed and explicitly confirmed before anything is applied.
* Site Health check for AI provider configuration (never makes a live API call automatically); a separate "Test connection" button for an explicit, one-off check.
* `wp fettle check-phrases`: a release-time check against a list of accessibility-compliance claims this plugin never makes.