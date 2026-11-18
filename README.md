# Orboni for XenForo

Crypto payments for XenForo 2.3, paid straight to your own wallets: USDT, USDC, BTC, ETH, SOL, TON, TRX and more, 17 coins on 16 networks. Orboni never holds your money and never has your keys.

The add-on adds Orboni as one of XenForo's payment providers. A member buying an account upgrade — or anything another add-on sells through XenForo's payment profiles — pays on Orboni's payment page, picking a coin and paying from any wallet or exchange. A signed webhook tells XenForo, and XenForo applies the upgrade, adds the member to its user groups and emails the receipt, as with any other provider.

## Install

1. Download `orboni-xenforo.zip` from the [latest release](../../releases/latest). In the admin control panel, open **Add-ons → Install/upgrade from archive** and choose it. XenForo offers that only with `$config['enableAddOnArchiveInstaller'] = true;` in `src/config.php`; without it, copy the contents of the zip's `upload/` folder into your forum's root and install **Orboni crypto payments** from the Add-ons page.
2. **Setup → Payment profiles → Add payment profile**, provider Orboni. Its display title is what members see, for example "Crypto (USDT, USDC, BTC and more)".
3. In the [Orboni dashboard](https://orboni.com), add the wallets you want to be paid to, and copy a secret key (Developers → API keys) into **Secret key**: a test key (`sk_test_…`) first.
4. Under Developers → Webhooks, add an endpoint at the **Webhook address** the profile shows (`…/payment_callback.php?_xfProvider=orboni`), with the `checkout.session.*` events, and paste its signing secret (`whsec_…`) into **Webhook signing secret**.
5. **Users → User upgrades**: tick the Orboni profile on an upgrade that is not recurring. Orboni takes one payment per payment page, so XenForo refuses it on a recurring upgrade.
6. Buy the upgrade as a member and, on the payment page, signed in to the dashboard, press *Simulate payment*. Then swap in a live key (`sk_live_…`) and the live endpoint's secret.

The full guide, with what happens to an upgrade for every payment state and everything the add-on sends to Orboni: https://orboni.com/docs/xenforo

## Requirements

XenForo 2.3 or later; PHP 7.2 or later, with cURL (both as XenForo 2.3 itself requires).

## Pricing

The add-on is free. Orboni charges 0.5% of each completed live payment: no monthly fee, no setup fee; test payments are never billed.

## Support

support@orboni.com

## License

MIT (`LICENSE`).
