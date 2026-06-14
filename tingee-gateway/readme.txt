=== HENO Tingee Gateway for WooCommerce ===
Contributors: heno, cuongnguyenba
Tags: payment, woocommerce, bank transfer, qr code, vietqr
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.0.0
WC requires at least: 5.0
WC tested up to: 9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate Tingee (by HENO) payment gateway into WooCommerce. Supports VietQR, bank transfer auto-confirmation via Webhook IPN, and Checkout Blocks.

== Description ==

**HENO Tingee Gateway for WooCommerce** connects your WooCommerce store to the [Tingee](https://tingee.vn) payment infrastructure by HENO, allowing customers to pay via bank transfer (VietQR) with **automatic order confirmation** — no manual reconciliation needed.

= How it works =

**Mode A — QR + Webhook (default)**

1. Customer selects "Tingee" at checkout.
2. Plugin generates a QR code linked to the order.
3. Thank-you page displays the QR code, account number, amount, and transfer reference.
4. Customer scans and transfers → Tingee sends a Webhook (IPN) to your site.
5. Plugin verifies the signature, matches the transaction, and automatically marks the order as paid — **within 5–15 seconds**.

**Mode B — Redirect**

1. Customer selects Tingee → plugin creates a Checkout URL via Tingee API.
2. Customer is redirected to Tingee's hosted payment page.
3. After payment → redirected back to your site; Webhook confirms the order.

= Key Features =

* **Automatic order confirmation** via Webhook IPN — no manual checking.
* **VietQR display** on the thank-you page with one-click copy for account number, amount, and transfer reference.
* **HMAC-SHA512 signature verification** for every webhook — forged requests are rejected with 401.
* **Idempotency protection** — retried webhooks (up to 5 times) never double-charge an order.
* **Dual integration modes** — admin selects QR+Webhook or Redirect in settings.
* **Checkout Blocks compatible** — works with both classic shortcode and the new WooCommerce Checkout Blocks.
* **HPOS compatible** — supports WooCommerce High-Performance Order Storage.
* **WC Logger integration** — webhook activity logged under WooCommerce > Status > Logs (`tingee-webhook`), secrets masked.
* **Connection test button** — verify your Client ID and Secret Token before going live.
* **Vietnamese and English** translations included.

= Requirements =

* WordPress 5.6 or higher
* WooCommerce 5.0 or higher
* PHP 7.2 or higher
* A [Tingee](https://app.tingee.vn) merchant account with Client ID and Secret Token

== Installation ==

= Automatic installation =

1. Log in to your WordPress admin panel.
2. Go to **Plugins > Add New**.
3. Search for **HENO Tingee Gateway for WooCommerce**.
4. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the plugin zip file.
2. Go to **Plugins > Add New > Upload Plugin**.
3. Choose the zip file and click **Install Now**, then **Activate**.

= Configuration =

1. Go to **WooCommerce > Settings > Payments**.
2. Find **Tingee Gateway** and click **Manage**.
3. Fill in the following fields:
   * **Environment** — choose Sandbox for testing or Production for live.
   * **Client ID** — from your Tingee developer dashboard.
   * **Secret Token** — from your Tingee developer dashboard.
   * **VA Account Number** — your merchant virtual account number.
   * **Bank BIN** — the BIN code of the bank that holds your VA.
   * **Integration Mode** — Mode A (QR + Webhook) or Mode B (Redirect).
4. Click **Test Connection** to verify your credentials.
5. Copy the **Webhook URL** displayed in the settings and paste it into your Tingee developer dashboard under Webhook configuration.
6. Save settings.

== Frequently Asked Questions ==

= Where do I get my Client ID and Secret Token? =

Log in to your Tingee merchant account at [app.tingee.vn](https://app.tingee.vn), navigate to the **Developers** section, and copy your Client ID and Secret Token.

= What is the Webhook URL and where do I put it? =

The Webhook URL is shown in the plugin's settings page (read-only field). Copy it and paste it into your Tingee developer dashboard under the Webhook / IPN configuration section. Tingee will send payment notifications to this URL.

= Do I need ngrok or a public URL to receive webhooks? =

Yes, during local development you need a tool like [ngrok](https://ngrok.com) to create a public URL that Tingee can reach. On a live server with a real domain name, no extra setup is needed.

= The QR code says "Dynamic QR not supported" — what do I do? =

Some Tingee accounts have dynamic QR disabled by default. The plugin automatically falls back to **Static QR** (VietQR standard). In this mode, order matching relies on the transfer reference (order number) in the payment description rather than a bill ID. Contact Tingee support to enable dynamic QR for your account.

= What happens if a customer sends the wrong amount? =

If the transferred amount is less than the order total, the plugin keeps the order in **On-Hold** status and adds an admin note with the received amount. You can review and handle partial payments manually. Over-payments follow the same flow — the order is marked paid and the discrepancy is noted.

= Is it safe? Can someone fake a webhook? =

Every webhook is verified using **HMAC-SHA512** with your Secret Token. Any request with an invalid or missing signature receives a `401 Unauthorized` response and the order is never modified. Replay attacks are also prevented: each transaction ID is stored after processing, so resending the same webhook has no effect.

= Does it work with the new WooCommerce Checkout Blocks? =

Yes. The plugin registers a Blocks-compatible payment method via `AbstractPaymentMethodType`, so it appears correctly in both the classic shortcode checkout and the new block-based checkout.

= Is it compatible with WooCommerce High-Performance Order Storage (HPOS)? =

Yes. All order data is read and written using `wc_get_order()`, `$order->get_meta()`, and `$order->update_meta_data()` — fully compatible with HPOS.

= Can I use both Sandbox and Production at the same time? =

No. The **Environment** setting applies globally. Switch to Production only when you are ready to accept real payments.

= Will my settings be deleted if I deactivate the plugin? =

No. Settings are preserved when you deactivate. They are only removed when you **delete** the plugin, and only if the "Delete data on uninstall" option is enabled in settings.

== External Services ==

This plugin connects to the **Tingee** payment API (operated by HENO) to generate QR codes, create payment links, and verify Webhook (IPN) notifications when a payment is completed.

**Data sent to Tingee:**

* Order amount, order ID, and transfer reference — sent when creating a payment QR or checkout URL.
* No customer personal data (name, email, address) is forwarded to the Tingee API by this plugin.

**When data is sent:**

* When the customer places an order and clicks "Pay" — the plugin calls the Tingee API to generate a QR code or hosted payment URL.
* When Tingee sends a Webhook to your site (this is *incoming* data from Tingee, not outgoing).

**Tingee API endpoints used:**

* Production: `https://open-api.tingee.vn`
* Sandbox (UAT): `https://uat-open-api.tingee.vn`

By using this plugin, your store connects to Tingee's infrastructure. Please review Tingee's policies before going live:

* [Terms of Service](https://drive.google.com/file/d/1snw0yOyz6hmanARQDWAX8DEMiG4r6Vmp/view)
* [Privacy Policy](https://drive.google.com/file/d/1baa2tZPZvq9HK6w1Tzi2okAjdeo7bJhx/view)

For developer documentation, visit [https://developers.tingee.vn](https://developers.tingee.vn).

== Screenshots ==

1. **Payment method at checkout** — Tingee option appears with title and description.
2. **QR code on thank-you page** — shows QR image, account number, amount, and transfer reference with copy buttons.
3. **Plugin settings page** — Client ID, Secret Token, environment, VA account number, integration mode, and connection test button.
4. **Webhook URL field** — read-only URL to copy into your Tingee developer dashboard.
5. **Order confirmed** — thank-you page automatically updates to "Payment successful" after Webhook IPN is received.

== Changelog ==

= 1.0.0 =
* Initial release.
* Mode A: VietQR display on thank-you page with JS polling for automatic confirmation.
* Mode B: Redirect to Tingee hosted payment page.
* Webhook IPN handler with HMAC-SHA512 signature verification and idempotency protection.
* HPOS and Checkout Blocks compatibility.
* WC Logger integration with masked secrets.
* Vietnamese and English translations.
* Connection test button in settings.
* Static QR fallback when dynamic QR is not enabled on the merchant account.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
