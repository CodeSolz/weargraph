=== WearGraph – AI Virtual Try-On & Product Studio for WooCommerce ===
Contributors: codesolz, m.tuhin
Tags: virtual try-on, woocommerce, ai product images, fitting room, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let shoppers preview clothes on themselves with an AI fitting room, and create on-model product images with your own AI provider key.

== Description ==

Shoppers want to know how a garment will look on **them**, not only on a model. WearGraph adds an AI fitting room to your WooCommerce product pages: the shopper adds a photo, picks a colour or option, and gets an **AI appearance preview** of the product on themselves, with an Add to Cart button right beside it. For larger stores, the optional [WearGraph Pro](https://codesolz.net/our-products/wordpress-plugin/weargraph/) add-on adds brand kits, video, saved looks and more.

WearGraph also gives you a **Product Studio** inside the product editor, to turn your existing product photos into on-model and lifestyle images, and a **Catalog** screen that tells you which of your products are ready for virtual try-on and what to fix for the rest.

WearGraph runs **inside your WordPress site with your own AI provider key**. Choose **Google Gemini**, **OpenAI**, **OpenRouter** (one key for 50+ image models), **Black Forest Labs FLUX** or **xAI Grok**, connect one or several, and pick which provider makes product images, try-on previews and alt text. There is no WearGraph account, no subscription and no WearGraph server in between: you pay your provider directly for what you generate.

[youtube https://www.youtube.com/watch?v=sCHrTqI8S64]

### AI fitting room (virtual try-on)

* A **Try On** button on Try-On Ready products: placed automatically, or with the **Try On button** block or the `[weargraph_try_on]` shortcode.
* Upload a photo or use the camera, with on-screen tips for a good photo.
* Shoppers must **agree before any photo is uploaded**. Location data and other hidden information are removed from the photo before it is stored.
* Switch colours and options without uploading the photo again.
* **Add to Cart** from the preview, into the shopper's own cart.
* **Delete my photos** at any time, straight from the fitting room.
* Limits per visit, per connection and per day protect your AI bill, and a hook lets you add a CAPTCHA.
* **Preview mode**: show the button only to store managers while you try it yourself.

### Product Studio

* Generate on-model, flat-lay and lifestyle images from your own product photos, with scenes, backgrounds, styles, reference images and "avoid" instructions.
* Choose the aspect ratio and resolution (1K, 2K or 4K).
* Save a result to the Media Library, set it as the product image, or add it to the gallery. **Your original images are never overwritten.**
* Suggested alt text for product images.
* A history of every image you created, with its settings and cost.

### Catalog readiness

* Checks every product's type, category, attributes and images, **without any AI and at no cost**.
* "N of M products are Try-On Ready", with a plain reason for every product that is not.
* Per-product and bulk overrides: switch try-on on or off, set the category, choose the source image.
* Large catalogs are checked in small background batches.

### Dashboard

* Try-ons today, shoppers who tried on, add-to-cart, checkouts, orders and sales after try-on, images generated, estimated AI cost and failed generations.
* A 14-day try-on chart, the most-tried products and recent activity.

### Privacy by default

* Shopper photos and previews are deleted when the visit ends (or after 24 hours, if you choose). An hourly clean-up enforces this.
* Photos are private files served only to the shopper's own browser, through links that expire after 10 minutes.
* Logged-in customers' fitting-room data is included in WordPress's **Export** and **Erase Personal Data** tools.
* Suggested privacy-policy text is added to **Settings → Privacy → Policy guide**.
* A "Delete all shopper photos now" tool.

### Cost control

* A maximum cost per request and a daily spending cap (US$10 by default).
* Failed and refused generations never count toward your limits.
* Repeated identical requests reuse the earlier result instead of paying again.

### WearGraph Pro

[WearGraph Pro](https://codesolz.net/our-products/wordpress-plugin/weargraph/) is a separate, optional add-on with features for larger stores (brand kits, video, saved looks and more). Everything described above works fully without it.

== Installation ==

1. Install and activate WooCommerce.
2. Install WearGraph from **Plugins → Add New**, or upload the plugin folder to `/wp-content/plugins/`, then activate it.
3. Create a Google Gemini API key in Google AI Studio (https://aistudio.google.com/apikey) and **turn on billing** for its Google Cloud project. Keys on the free tier cannot create images.
4. Go to **WearGraph → Settings → AI Provider**, paste the key and click **Test connection**.
5. Go to **WearGraph → Catalog** and click **Scan catalog**.
6. Turn the fitting room on under **WearGraph → Settings → Try-On** (use **Preview mode** first to try it yourself).

== Frequently Asked Questions ==

= Do I need a WearGraph account or subscription? =

No. WearGraph runs in your WordPress site with your own API key from Google Gemini, OpenAI, OpenRouter, Black Forest Labs or xAI. You pay that provider directly for what you generate.

= How much does it cost to run? =

Each image costs what your provider charges for it: for example around US$0.07 with Gemini's default model, from US$0.014 with FLUX.2 [klein], US$0.02–0.05 with xAI Grok. OpenRouter and OpenAI report the exact cost of every image, which WearGraph records. The catalog check uses no AI and is free. You can set a maximum cost per request and a daily cap.

= Is the preview an exact fit? =

No. WearGraph creates an **AI appearance preview** of how the product may look on the shopper. It is not a size or fit guarantee; keep your size guide on the product page.

= What happens to shopper photos? =

A photo is uploaded only after the shopper agrees. It is stored as a private file, with location data removed, and deleted automatically when the visit ends (or after 24 hours if you choose that). It is sent only to Google Gemini, only to create the preview the shopper asked for. The shopper can delete it at any time.

= Which products can be tried on? =

Clothing and shoes: tops, shirts, jackets, dresses, trousers, skirts, footwear and similar. Accessories such as bags and jewellery are marked "needs review" because results vary; you can switch them on yourself. Swimwear, underwear and socks are never offered for try-on. The Catalog screen shows the status and the reason for every product.

= Does WearGraph slow down my product pages? =

No. Only a small script loads with the page; the fitting room itself loads when a shopper clicks Try On. Generation runs in the background.

= Which AI provider should I choose? =

Any of them works for product images and try-on. Gemini and OpenAI can also write alt text. OpenRouter gives you 50+ image models with one key, FLUX has a dedicated virtual try-on model, and xAI Grok is the lowest-cost option. You can connect several and choose, in WearGraph → Settings → AI Provider, which one makes product images, try-on previews and alt text.

= Why does Test connection say my key is on the free tier? =

Google's free tier has no quota for image models, and on the free tier Google may use what you send to improve its products. Turn on billing for the Google Cloud project your key belongs to, wait a few minutes, then test again.

= Does it work with my theme? =

WearGraph places the button with standard WooCommerce hooks and has been checked with Twenty Twenty-Five, Storefront and Astra. If your theme moves things around, use the block or the `[weargraph_try_on]` shortcode, or choose another position under **Settings → Try-On**.

= What is removed when I delete the plugin? =

Shopper photos and previews are always removed. Your settings, history and statistics are removed only if you tick **Remove all WearGraph settings, history and statistics when the plugin is deleted** under **Settings → General**. Images you saved to the Media Library stay.

== Screenshots ==

1. Dashboard: the try-on funnel, sales, orders and checkouts after try-on, images generated, estimated AI cost and the most-tried products.
2. Catalog: which products are Try-On Ready, with the reason and a fix for every other product.
3. Settings → AI Provider: connect Google Gemini, OpenAI, OpenRouter, Black Forest Labs FLUX or xAI Grok with your own key, and choose a provider for each task.
4. Settings → Try-On: button text and position, shop-page buttons, shopper consent and limits per shopper.
5. The Try On button on shop and category pages, next to Add to cart.
6. The fitting room: the shopper picks the colour or option to try on.
7. An AI appearance preview on the shopper's own photo, with Add to cart, Try again, Compare with my photo and Delete my photos.
8. Switching to another colour reuses the same photo, with no new upload.
9. Another product tried on with the same photo.
10. My account → My fitting room: shoppers see their try-on images and can delete them at any time.

== Changelog ==

= 1.0.0 =

* Initial release.
* Your own AI provider key: Google Gemini, OpenAI, OpenRouter, Black Forest Labs FLUX or xAI, with a provider per task.
* AI fitting room with photo upload or camera, consent, option switching, Add to Cart and photo deletion.
* Try On button: automatic placement, block and shortcode; preview mode for store managers.
* Product Studio: on-model and lifestyle image generation, alt text suggestions, save to the Media Library, product image or gallery.
* Catalog readiness scan with reasons, overrides and bulk actions.
* Dashboard with usage, sales after try-on and estimated AI cost.
* Privacy: retention modes, hourly clean-up, personal-data export and erase, suggested policy text.
* Cost control: per-request and daily caps, limits per visit and per day.

== Upgrade Notice ==

= 1.0.0 =

First release.

== External services ==

WearGraph connects only to the **AI provider(s) you choose** in WearGraph → Settings → AI Provider, only after you have added your own API key for that provider, and only when a request runs. Providers without a saved key are never contacted. WearGraph itself has no server: it sends nothing to WearGraph or CodeSolz, and performs no license check, analytics or tracking.

**What is sent, and when** (to the provider set for that job):

* **When you click "Test connection" or "Refresh models":** your API key, sent to that provider to check the key or list its models. Gemini also receives the name of the image model and a short fixed text prompt ("Reply with the single word OK."). Nothing is generated and no credits are used, except the few tokens of that Gemini check.
* **When you generate or edit an image in the Product Studio:** your API key, the product images you selected, and the prompt built from your choices (scene, style, your own instructions).
* **When you ask for alt text:** your API key, the product image and a prompt that includes the product name.
* **When a shopper requests a try-on preview:** your API key, the shopper's photo (after they agreed, with location and other hidden data removed), the product image, and a prompt describing the garment. No name, email address, IP address or account detail of the shopper is sent.

Nothing is sent when the catalog is checked, when pages load, or while no API key is saved.

To start a queued image or try-on preview without waiting for WP-Cron, WearGraph also sends a request to **your own site** (`wp-admin/admin-ajax.php`), signed so only WearGraph can trigger it. That request stays on your own server and is not an external service.

**Google Gemini API** (Google), at `https://generativelanguage.googleapis.com/`:

* Gemini API Additional Terms of Service: https://ai.google.dev/gemini-api/terms
* Google Privacy Policy: https://policies.google.com/privacy
* Note: on Google's unpaid tier, Google may use submitted content to improve its products, and human reviewers may read it. Use a key from a Google Cloud project with billing enabled.

**OpenAI API** (OpenAI), at `https://api.openai.com/`:

* Services agreement: https://openai.com/policies/services-agreement/
* Privacy policy: https://openai.com/policies/privacy-policy/

**OpenRouter** (OpenRouter, Inc.), at `https://openrouter.ai/api/`. OpenRouter passes the request to the company that runs the model you chose (for example Google, OpenAI, Black Forest Labs or ByteDance):

* Terms of service: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

**Black Forest Labs API** (FLUX), at `https://api.bfl.ai/`. The finished image is downloaded right away from Black Forest Labs' delivery servers (`*.bfl.ai`):

* Terms of service: https://bfl.ai/legal/terms-of-service
* Privacy policy: https://bfl.ai/legal/privacy-policy

**xAI API** (Grok Imagine), at `https://api.x.ai/`:

* Terms of service: https://x.ai/legal/terms-of-service-enterprise
* Privacy policy: https://x.ai/legal/privacy-policy
