# Salawat Counter & Pledge System

Custom WordPress plugin for collecting Salawat pledges and displaying aggregate totals.

## Installation

1. Copy this folder to `wp-content/plugins/Salawat Counter & Pledge System`.
2. In WordPress admin, go to **Plugins** and activate **Salawat Counter & Pledge System**.
3. Activation creates the custom table `{prefix}_salawat_pledges`.

## Shortcodes

- `[salawat_form]` renders the fallback pledge form.
- `[salawat_nonce]` outputs a nonce value for a Bricks hidden field.
- `[salawat_total]` displays total pledged Salawat.
- `[salawat_today]` displays today's total.
- `[salawat_week]` displays this week's total.
- `[salawat_month]` displays this month's total.
- `[salawat_leaderboard limit="10"]` displays top contributors without showing anonymous names.
- `[salawat_latest_pledges limit="5"]` displays the latest submitted pledges.

Totals shortcodes auto-refresh every 10 seconds through AJAX.

## REST API

Public stats endpoint:

`/wp-json/salawat/v1/stats`

## Bricks Builder Integration

Create a Bricks form with fields for:

- Full Name
- Email
- Salawat Amount
- Message
- Anonymous checkbox

Set the form action to **Custom** so Bricks fires `bricks/form/custom_action`.

If Bricks generates field IDs that you cannot edit:

1. Copy each generated Bricks field ID.
2. Go to **Salawat Stats > Settings**.
3. Paste the generated IDs into the matching field mapping inputs.
4. Save settings.

You may paste either `abc123`, `form-field-abc123`, or `#form-field-abc123`; the plugin normalizes these formats. You can also paste multiple possible IDs separated by commas or new lines.

Recommended nonce field:

- Add a hidden field if Bricks lets you.
- Populate it with `[salawat_nonce]` if your Bricks setup processes shortcodes in hidden field defaults.
- Alternatively use `<?php echo esc_attr( wp_create_nonce( 'salawat_counter_submit' ) ); ?>` if your setup allows PHP-rendered defaults.
- If Bricks generates its own hidden field ID, save that ID in **Salawat Stats > Settings** under **Nonce hidden field ID**.

If no nonce field is supplied by Bricks, the plugin still validates and stores sanitized submissions. The shortcode fallback always verifies a nonce.

## Bricks Dynamic Tags

The plugin registers:

- `{salawat_total}`
- `{salawat_today}`
- `{salawat_week}`
- `{salawat_month}`

## Bricks Element

The plugin registers a Bricks element named **Salawat Latest Pledges** under the general elements panel.

Element controls include:

- Number of pledges to fetch
- Newest or oldest first
- Show or hide title, date, message, and amount label
- Custom title, date format, amount label, anonymous label, and empty text
- Columns
- Title spacing, card gap, header spacing, footer gap, message spacing, and message padding
- Card background, padding, radius, border, and shadow
- Typography for title, name, date, message, amount label, and amount
- Colors for name, date, message, amount label, amount, and message accent

The element prints a small inline fallback stylesheet while rendering, so the card layout is visible inside the Bricks builder canvas as well as on the frontend.
Typography controls are available in the element Content tab.

## Admin

Go to **Salawat Stats** in WordPress admin to view:

- Total pledged Salawat
- Today, week, and month totals
- Participant count
- Daily chart
- Recent pledges
- Delete individual pledges
- Date range filter
- CSV export

Go to **Salawat Stats > Settings** to save Bricks generated field IDs and check whether the custom database table exists.
The settings page also shows the normalized keys from the last Bricks submission, which helps confirm the amount field is mapped.

## Privacy

Anonymous pledges are stored with their data for admin reporting, but public leaderboard and display helpers show the name as `Anonymous`.
