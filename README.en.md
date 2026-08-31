# Commerce to Telegram

[![Drupal](https://img.shields.io/badge/Drupal-10%20%7C%2011-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![Drupal Commerce](https://img.shields.io/badge/Commerce-2.x-0678BE)](https://www.drupal.org/project/commerce)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Release](https://img.shields.io/badge/Release-v1.0.0-brightgreen)](../../releases)

English | **[Русский](README.md)**

---

A module for Drupal 10/11 that **automatically sends new Drupal Commerce
orders to Telegram** — into a group or channel where your bot is a member.
Optionally, it can also deliver Webform submissions.

Every placed order appears in your team's Telegram group instantly: order
number, items with quantities and totals, customer, address and grand
total. No need to watch the admin panel or email — orders are visible
right after checkout.

## Features

- **Drupal Commerce (primary integration)**: a notification is sent the
  moment an order is placed — the transition to the "Completed" status at
  checkout (the `ORDER_PLACE` event).
- **Webform (optional)**: if the Webform module is installed, you can
  deliver submissions of selected forms to the same chat.
- **Admin settings page** — Configuration → System → "Telegram
  notifications: orders and submissions"
  (`/admin/config/system/commerce-to-telegram`):
  - bot token (stored value is hidden; leave the field empty to keep it);
  - chat or group ID;
  - message format: HTML or plain text;
  - order message template and submission message template;
  - checkboxes for the Webform forms to deliver;
  - **"Send test message"** button for instant validation.
- **Tokens**: standard Commerce and Webform tokens via the Token module
  **plus extra tokens provided by this module**:
  - `[commerce_order:items_table]` — all order items, one per line
    ("2 × T-shirt — 2,400.00 ₽");
  - `[commerce_order:billing_address]` — billing address;
  - `[commerce_order:shipping_address]` — shipping address (needs
    Commerce Shipping).
- **HTML format**: bold headers, clean line breaks; if the markup is
  invalid the module automatically retries with plain text — the message
  is always delivered.
- **Reliability**: a Telegram failure never interrupts the checkout or
  submission saving; errors are logged to Drupal watchdog (dblog,
  channel `commerce_to_telegram`).
- **Telegram 4096-character limit** is handled automatically.

## Requirements

| Component | Version | Required |
|---|---|---|
| Drupal | ^10 \|\| ^11 | yes |
| PHP | >= 8.1 | yes |
| [Drupal Commerce](https://www.drupal.org/project/commerce) | ^2.0 | yes (installed by Composer) |
| [Token](https://www.drupal.org/project/token) | ^1.0 | yes (installed by Composer) |
| [Webform](https://www.drupal.org/project/webform) | ^6.0 | optional |

Integrations are detected automatically: if Commerce is missing, the
"Orders" section is hidden in the admin UI; if Webform is missing, the
forms section is hidden.

## Installation

### Via Composer (recommended)

```bash
# 1. Register the repository in your project's composer.json
composer config repositories.commerce-to-telegram vcs https://github.com/Mmitekk/drupal-commerce-to-telegram

# 2. Install the latest module version (it lands in web/modules/custom/)
composer require drupal/commerce_to_telegram

# 3. Enable the module
drush en commerce_to_telegram -y
```

Without a version constraint Composer installs the **latest stable
release**. No access tokens or Packagist registration are required — the
repository is public, Composer pulls the package straight from GitHub.

> To make `composer require drupal/commerce_to_telegram` work completely
> "classic" (without registering the repository), submit the package once
> on [packagist.org](https://packagist.org): Submit → paste this
> repository URL. After that you no longer need the repositories entry in
> composer.json.

### Manually

1. Download the archive: **Code → Download ZIP** or the
   `commerce_to_telegram-1.0.0.zip` file from the
   [Releases](../../releases) page.
2. Unpack it into `web/modules/custom/` so that the path becomes
   `web/modules/custom/commerce_to_telegram/commerce_to_telegram.info.yml`.
3. Clear caches (`drush cr` or `/admin/config/development/performance`).
4. Enable the module: **Manage → Extend** (`/admin/modules`).

## Configuration

### 1. Create a bot

1. Find **@BotFather** in Telegram → send `/newbot`.
2. Set the bot name and receive a token like
   `123456789:AAE1b2c3D4e5F6g7H8i9J0k1L2m3N4o5p6q`.

### 2. Add the bot to a group and get the chat_id

1. Add the bot to the group (for **channels** — as an administrator with
   posting rights).
2. Get the group ID in any of these ways:
   - add **@getidsbot** to the group — it will show the `chat id`;
   - forward any message from the group to **@getmyid_bot**;
   - post anything to the group and open
     `https://api.telegram.org/bot<TOKEN>/getUpdates` — look for
     `"chat":{"id":-100…`.
3. Supergroups and channels have a negative ID like `-1001234567890`.

### 3. Configure the module

Open **Configuration → System → Telegram notifications: orders and
submissions**:

1. Paste the **bot token**.
2. Enter the **chat/group ID**.
3. Choose the **format** (HTML by default).
4. In the **"Drupal Commerce orders"** section enable sending and adjust
   the template if needed.
5. Click **"Send test message"** — a test will arrive in the group if
   everything is configured correctly.
6. Save the settings.

## How it works

```mermaid
flowchart LR
    A["Customer completes checkout"] --> B["Order transitions to Completed"]
    B --> C["ORDER_PLACE event"]
    C --> D{"Commerce integration<br>enabled?"}
    D -- "no" --> E["Exit"]
    D -- "yes" --> F["Token replacement in template<br>(items, totals, address)"]
    F --> G["Telegram Bot API: sendMessage"]
    G -- "HTML parse error" --> H["Retry as plain text"]
    G -- "success" --> I["Message appears in the group"]
```

1. A customer completes checkout — the order transitions to "Completed".
2. The module listens to the order placement event (`ORDER_PLACE`).
3. Template tokens are replaced with order data; the item list and
   addresses are produced by the module's built-in tokens.
4. The message is sent via the Telegram Bot API
   (`https://api.telegram.org/bot…/sendMessage`) to the configured chat.
5. If Telegram rejects the HTML markup, the message is resent as plain
   text. The 4096-character limit is respected.
6. Any network or API error **never interrupts the checkout** — it is
   logged under **Reports → Recent log messages**
   (`/admin/reports/dblog`), channel `commerce_to_telegram`.

All communication happens synchronously with 10 s (request) and 5 s
(connection) timeouts. Webform submissions are handled the same way — on
the submission insert event (`hook_webform_submission_insert`); drafts
and test submissions are not sent.

## Order template tokens

| Token | Substitutes |
|---|---|
| `[commerce_order:order_number]` | Order number |
| `[commerce_order:items_table]` | All items: "qty × title — total" |
| `[commerce_order:total_price]` | Grand total with currency |
| `[commerce_order:mail]` | Customer e-mail from the order |
| `[commerce_order:state]` | Order state |
| `[commerce_order:billing_address]` | Billing address |
| `[commerce_order:shipping_address]` | Shipping address (Commerce Shipping) |
| `[site:name]` | Site name |
| `[current-date:medium]` | Date/time |

Standard Commerce tokens (`[commerce_order:…]`, `[commerce_store:…]`) and
global Token module tokens are available too — the full list is shown via
the "Browse available tokens" link below the template field.

### Submission template tokens (Webform)

| Token | Substitutes |
|---|---|
| `[webform_submission:values]` | All submitted fields |
| `[webform_submission:values:phone]` | Value of a specific field (element machine name) |
| `[webform_submission:sid]` | Submission ID |
| `[webform_submission:webform:title]` | Form title |

### HTML tags allowed by Telegram

`<b>`, `<i>`, `<u>`, `<s>`, `<a href="…">`, `<code>`, `<pre>`,
`<blockquote>`. Line break is a plain Enter. `<br>` tags produced by token
substitution are automatically converted to line breaks.

## Troubleshooting

| Telegram error | Cause and fix |
|---|---|
| `Unauthorized` | Wrong bot token — verify it with @BotFather |
| `Bad Request: chat not found` | Wrong chat ID or the bot is not a group member |
| `Forbidden: bot was kicked…` | The bot was removed from the group — add it again |
| `Bad Request: can't parse entities` | Invalid HTML markup (the module retries as plain text) |
| `Too Many Requests` | Telegram rate limit hit — retry shortly |

All errors are also recorded in the Drupal log with the order or
submission ID.

## Security

- Settings access requires the `administer commerce to telegram`
  permission (administrators only by default).
- The bot token is stored in the site configuration and is **never
  displayed** on the settings page: leave the field empty to keep the
  current token.
- On configuration export (`drush cex`) the token ends up in the exported
  files — never publish or commit them to public repositories.

## Module structure

```
commerce_to_telegram/
├── composer.json                            — Composer package (drupal-custom-module)
├── commerce_to_telegram.info.yml            — module definition
├── commerce_to_telegram.module              — Webform hook + order tokens
├── commerce_to_telegram.services.yml        — services (sender, event subscriber)
├── commerce_to_telegram.routing.yml         — settings page route
├── commerce_to_telegram.links.menu.yml      — configuration menu item
├── commerce_to_telegram.permissions.yml     — permission
├── config/install/commerce_to_telegram.settings.yml — default settings
├── config/schema/commerce_to_telegram.schema.yml    — config schema
├── src/Service/TelegramSender.php           — Telegram Bot API sender
├── src/EventSubscriber/OrderEventSubscriber.php — order placement event
└── src/Form/SettingsForm.php                — settings page
```

## License

[GPL-2.0-or-later](LICENSE) — same as Drupal core itself.
