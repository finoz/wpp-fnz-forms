# FNZ Forms

Lightweight WordPress plugin that renders configurable contact forms and sends submissions by email.

**Zero external dependencies** — no jQuery, no extra plugins, no composer packages. Uses only WordPress core + PHPMailer (already bundled in WP). PHP 8.0+, WP 6.0+.

---

## Quick start

1. Upload `fnz-forms/` to `wp-content/plugins/` and activate.
2. Go to **Settings → FNZ Forms** and paste your JSON config.
3. Add the shortcode to any page: `[fnz_form id="contact"]`
4. Configure SMTP in the same settings page so emails actually arrive.

---

## Shortcode

```
[fnz_form id="your_form_id"]
```

The `id` must match a key in the `forms` object of your config.

---

## Form configuration (JSON)

The full config lives in **Settings → FNZ Forms → Form configuration** (stored in the database). Alternatively you can place a file at `wp-content/fnz-forms-config.json` — but the admin UI always takes priority.

### Minimal example

```json
{
  "forms": {
    "contact": {
      "to": "info@example.com",
      "from_email": "noreply@example.com",
      "from_name": "My Website",
      "subject": "New contact from {email}",
      "fields": [
        { "id": "firstname", "type": "text",     "label": "First name", "required": true },
        { "id": "email",     "type": "email",    "label": "Email",      "required": true },
        { "id": "message",   "type": "textarea", "label": "Message",    "required": true }
      ]
    }
  }
}
```

### Form-level keys

| Key               | Required | Default                     | Description                                      |
|-------------------|----------|-----------------------------|--------------------------------------------------|
| `to`              | **yes**  | —                           | Recipient email address                          |
| `from_email`      | no       | Site admin email            | Sender address                                   |
| `from_name`       | no       | Site name                   | Sender display name                              |
| `subject`         | no       | `"New form submission: id"` | Supports `{field_id}` tokens (see below)         |
| `submit_label`    | no       | `"Submit"`                  | Submit button text                               |
| `success_message` | no       | `"Thank you! …"`            | Shown to user on success                         |
| `error_message`   | no       | `"Something went wrong. …"` | Shown if wp_mail() fails                         |
| `fields`          | no       | firstname, lastname, email, message | Array of field objects                 |

### Subject tokens

Use `{field_id}` in the `subject` string to inject submitted values:

```json
"subject": "Contact from {firstname} {lastname} – {email}"
```

### Field object keys

| Key           | Required | Default      | Description                                        |
|---------------|----------|--------------|----------------------------------------------------|
| `id`          | **yes**  | —            | Unique within the form; becomes the input `name`   |
| `type`        | **yes**  | —            | See field types below                              |
| `label`       | no       | same as `id` | Visible label text                                 |
| `placeholder` | no       | `""`         | Input placeholder (not used for select / radio)    |
| `required`    | no       | `false`      | HTML `required` + server-side validation           |
| `options`     | no       | —            | Required for `select` and `radio` types            |

### Field types

| Type       | HTML element                    | Notes                                             |
|------------|---------------------------------|---------------------------------------------------|
| `text`     | `<input type="text">`           |                                                   |
| `email`    | `<input type="email">`          | Validated as email server-side                    |
| `number`   | `<input type="number">`         | Non-numeric values rejected server-side           |
| `textarea` | `<textarea>`                    |                                                   |
| `select`   | `<select>`                      | Requires `options` array                          |
| `radio`    | `<input type="radio">`          | Group; requires `options` array                   |
| `checkbox` | `<input type="checkbox">`       | Single checkbox; value in email is `Yes` / blank  |

**`options` format** (for `select` and `radio`):

```json
"options": [
  { "value": "tech",   "label": "Technology" },
  { "value": "design", "label": "Design" }
]
```

---

## Generated HTML

No CSS is added by the plugin — all styling is up to the theme.

```html
<!-- text / email / number -->
<div class="form-group">
  <input type="text" id="contact_firstname" name="contact_firstname" value="" placeholder="…" required>
  <label for="contact_firstname">First name</label>
</div>

<!-- textarea -->
<div class="form-group">
  <textarea id="contact_message" name="contact_message" placeholder="…" required></textarea>
  <label for="contact_message">Message</label>
</div>

<!-- select -->
<div class="form-group">
  <select id="contact_topic" name="contact_topic">
    <option value="">Topic of interest</option>
    <option value="tech">Technology</option>
  </select>
  <label for="contact_topic">Topic of interest</label>
</div>

<!-- checkbox -->
<div class="form-group form-group--boolean">
  <label for="contact_privacy">
    <input type="checkbox" id="contact_privacy" name="contact_privacy" required>
    <span>I accept the privacy policy.</span>
  </label>
</div>

<!-- radio group -->
<div class="form-group form-group--radio" role="group" aria-label="Format">
  <span class="form-group__legend">Format</span>
  <label for="contact_format_0">
    <input type="radio" id="contact_format_0" name="contact_format" value="weekly" required>
    <span>Weekly digest</span>
  </label>
</div>
```

Note: `<label>` is placed **after** the input for text-like fields (to enable the CSS floating-label pattern), and **wraps** the input for boolean fields (checkbox / radio).

---

## SMTP

### Why PHP mail() often doesn't work

WordPress sends email via PHP's `mail()` by default. On most modern hosting and with all major providers (Gmail, Outlook, etc.) these emails are **blocked or spam-flagged** because the server IP is not authorised to send for your domain (SPF/DKIM fail). This is not a plugin problem — it affects the whole WordPress installation.

### Configure SMTP

Go to **Settings → FNZ Forms → SMTP settings** and fill in the credentials from your mail provider.

Recommended services with a free tier:

| Service               | Free tier      | Notes                       |
|-----------------------|----------------|-----------------------------|
| Brevo (ex-Sendinblue) | 300 emails/day | Easiest setup, recommended  |
| Mailgun               | 100 emails/day | Developer-friendly          |
| SendGrid              | 100 emails/day | Widely used                 |
| Gmail SMTP            | Personal use   | Requires an App Password    |

### Alternative: wp-config.php constants

If you prefer to keep credentials out of the database, add these lines to `wp-config.php` **above** `/* That's all, stop editing! */`:

```php
define( 'FNZ_SMTP_HOST',       'smtp.brevo.com' );
define( 'FNZ_SMTP_PORT',        587 );
define( 'FNZ_SMTP_ENCRYPTION', 'tls' ); // 'tls' (port 587) or 'ssl' (port 465)
define( 'FNZ_SMTP_USERNAME',   'your_login@example.com' );
define( 'FNZ_SMTP_PASSWORD',   'your_smtp_password' );
```

### Priority / fallback order

```
wp-config.php constants  ←  always wins if defined
        ↓
Admin UI (wp_options)    ←  used when no constants are set
        ↓
No SMTP                  ←  falls back to PHP mail() (unreliable)
```

The two methods can coexist on the same site: constants are useful on production servers where `wp-config.php` is managed by the dev team, while the Admin UI is more convenient for client-managed sites.

---

## Security

### JSON config file (if using file-based config)

If you use a `wp-content/fnz-forms-config.json` file instead of the Admin UI, protect it from direct browser access.

**Apache** — add to `wp-content/.htaccess`:

```apache
<Files "fnz-forms-config.json">
  Require all denied
</Files>
```

**Nginx** — add to the server block:

```nginx
location = /wp-content/fnz-forms-config.json {
    deny all;
}
```

Or move the file above the webroot and point to it via filter:

```php
// In functions.php or a mu-plugin:
add_filter( 'fnz_forms_config_path', fn() => ABSPATH . '../fnz-forms-config.json' );
```

### Submission security

- Every REST request is verified with a WordPress nonce. The nonce is refreshed from the server before each submit, so full-page caching doesn't break the form.
- A hidden honeypot field silently discards bot submissions.
- All field values are sanitised and validated server-side regardless of client-side HTML validation.

### JavaScript

The form uses the Fetch API (vanilla JS, no jQuery). JavaScript is required — there is no server-side no-JS fallback.

---

## Auto-updates from GitHub

The plugin checks its own GitHub repository for new releases and shows the standard WordPress update notification — no extra configuration needed.

**One-time setup** (before you publish the plugin): open `fnz-forms.php` and set the repo slug:

```php
define( 'FNZ_FORMS_GITHUB_REPO', 'finoz/wpp-fnz-forms' );
```

After that, the update flow is: bump `Version:` in `fnz-forms.php` → create a GitHub release with a matching tag (e.g. `v1.1.0`) → WordPress detects the new version within 12 hours and shows the update notification → one-click update from Dashboard → Updates.

**Private repos** — add a token with `contents:read` scope in `wp-config.php`:

```php
define( 'FNZ_FORMS_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
```

**Forks** — point to a different repo without touching plugin code:

```php
add_filter( 'fnz_forms_github_repo', fn() => 'other-user/my-fork' );
```

---

## Advanced

### Override config file path

```php
add_filter( 'fnz_forms_config_path', fn() => '/var/secrets/my-forms.json' );
```

---

## Changelog

### 1.0.0
Initial release.
