# Omni Booking Manager — Stripe Payments Setup Guide

## Overview

The Payments integration connects your booking system to Stripe, allowing you to:

- Send deposit invoices to clients via email
- Send balance invoices showing the deposit already paid
- Accept credit card payments through Stripe Checkout
- Automatically update payment status when clients pay
- Process refunds from the admin dashboard
- Track full payment history per booking

---

## Step 1: Create a Stripe Account

1. Go to [https://dashboard.stripe.com/register](https://dashboard.stripe.com/register)
2. Create an account or sign in
3. Complete the business verification (required to accept live payments)

> **Tip:** You can use Stripe in test mode while setting up. No real charges will be made until you switch to live mode.

---

## Step 2: Get Your API Keys

1. In the Stripe Dashboard, click **Developers** in the left sidebar
2. Click **API keys**
3. You'll see two sets of keys:
   - **Test mode keys** (for testing — no real money)
   - **Live mode keys** (for real payments)

You need three values:

| Key | Where to Find | Looks Like |
|-----|--------------|------------|
| **Publishable Key** | Developers > API keys | `pk_test_...` or `pk_live_...` |
| **Secret Key** | Developers > API keys (click "Reveal") | `sk_test_...` or `sk_live_...` |
| **Webhook Secret** | Created in Step 3 below | `whsec_...` |

> **Important:** Start with test mode keys. Switch to live keys only after you've tested the full flow.

---

## Step 3: Set Up the Webhook

The webhook tells your booking system when a payment is completed. Without it, payment statuses won't update automatically.

1. In the Stripe Dashboard, go to **Developers > Webhooks**
2. Click **Add endpoint**
3. Enter your webhook URL:
   ```
   https://yoursite.com/wp-json/obm/v1/stripe-webhook
   ```
   Replace `yoursite.com` with your actual domain.

4. Under **Events to send**, select:
   - `checkout.session.completed`

5. Click **Add endpoint**
6. On the webhook detail page, click **Reveal** under "Signing secret"
7. Copy the `whsec_...` value — this is your Webhook Secret

---

## Step 4: Configure in WordPress

1. Go to **Bookings > Integrations** and make sure **Stripe Payments** is enabled
2. Go to **Bookings > Payments**
3. Enter your keys:
   - **Publishable Key** — paste your `pk_test_...` or `pk_live_...` key
   - **Secret Key** — paste your `sk_test_...` or `sk_live_...` key
   - **Webhook Secret** — paste your `whsec_...` value
   - **Default Deposit Amount** — the pre-filled deposit amount (e.g., `50.00`)
4. Click **Save Stripe Settings**

---

## Step 5: Test the Integration

Use Stripe's test card numbers to simulate payments without real money.

### Test Card Numbers

| Scenario | Card Number | Expiry | CVC |
|----------|------------|--------|-----|
| Successful payment | `4242 4242 4242 4242` | Any future date | Any 3 digits |
| Declined card | `4000 0000 0000 0002` | Any future date | Any 3 digits |
| Requires authentication | `4000 0025 0000 3155` | Any future date | Any 3 digits |

### Test Flow

1. Create or select a booking in **Bookings > All Leads**
2. Click **Details** to expand the lead
3. In the **Payments** section, enter a deposit amount and click **Send Deposit Invoice**
4. Check the client's email (or Mailpit on local) for the payment link
5. Click the link and pay using a test card number above
6. The webhook will fire and update the payment status to "Paid"
7. Back in the dashboard, the deposit will show as paid
8. Now enter a balance amount and click **Send Balance Invoice**
9. The balance email will show the deposit already paid with the remaining balance due

---

## How Payments Work

### Deposit Flow

```
Admin sends deposit invoice
    → Client receives email with Stripe Checkout link
    → Client pays with credit card
    → Stripe sends webhook to your site
    → Payment status updates to "Deposit"
    → Payment history shows "Deposit — Paid"
```

### Balance Flow

```
Admin sends balance invoice
    → Client receives email showing:
        Total: $300.00
        Deposit Paid: $50.00
        Balance Due: $250.00
    → Stripe Checkout shows deposit as $0 line item + balance charge
    → Client pays remaining balance
    → Webhook fires, status updates to "Full"
```

### Refund Flow

```
Admin clicks "Refund" on a paid payment
    → Stripe processes the refund
    → Payment status updates to "Refunded"
    → Client receives refund to their original payment method (3-5 business days)
```

---

## Going Live

When you're ready to accept real payments:

1. In Stripe Dashboard, toggle from **Test mode** to **Live mode**
2. Go to **Developers > API keys** and copy your live keys (`pk_live_...` and `sk_live_...`)
3. Create a new webhook endpoint in live mode with the same URL and events
4. Copy the new webhook signing secret
5. Update all three values in **Bookings > Payments**
6. Save settings

> **Important:** Double-check that your webhook URL is correct and accessible from the internet. Local development URLs (like `deepcreek-dev.local`) will not work with Stripe webhooks.

---

## Troubleshooting

### Payments not updating after client pays
- Check that your webhook is set up correctly in Stripe Dashboard
- Verify the webhook URL matches exactly: `https://yoursite.com/wp-json/obm/v1/stripe-webhook`
- Check that `checkout.session.completed` event is selected
- Verify the Webhook Secret is correct in Bookings > Payments

### "Failed to create invoice" error
- Check that your Secret Key is entered correctly
- Make sure you're using test keys in test mode or live keys in live mode
- Check that the lead has a valid email address

### Invoice email not received
- Check your WordPress email configuration
- On local development, check Mailpit instead of real email
- Make sure the lead has an email address

### Webhook returning 400 errors
- The webhook signing secret may be incorrect
- Make sure you copied the full `whsec_...` value

---

## Security Notes

- Never share your Secret Key or Webhook Secret publicly
- Your Secret Key should only be entered in the WordPress admin settings page
- All payment processing happens on Stripe's servers — no card data touches your website
- Stripe is PCI DSS Level 1 compliant

---

## Support

For Stripe account issues, contact [Stripe Support](https://support.stripe.com/).

For Omni Booking Manager issues, contact Omni Solutions Agency LLC.
