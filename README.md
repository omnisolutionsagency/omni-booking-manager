# Omni Booking Manager

Configurable WordPress booking plugin by Omni Solutions Agency LLC.

Lead management with Google Calendar, payments, waivers, email sequences, and mobile PWA.

## Features

- Lead/booking dashboard with status workflow (New > Confirmed > Completed)
- Google Calendar sync (two-way)
- Stripe payment invoices and tracking
- Automated email sequences (welcome, reminder, thank-you)
- Digital liability waivers with e-signature
- SMS notifications via Twilio
- Post-trip review collection (Google Reviews)
- Client portal (view booking, sign waiver, pay)
- CSV import for existing bookings
- Staff assignment and blocked dates
- Mobile PWA for field use
- Daily digest emails

## Local Development Setup

### Requirements

- [LocalWP](https://localwp.com/) (free)
- [Git](https://git-scm.com/)
- A GitHub account with access to this repo

### 1. Create a LocalWP site

1. Open LocalWP > "Create a new site"
2. Name it (e.g., `deepcreek-dev`)
3. Use default/preferred environment settings
4. Set any admin username/password (it's local only)

### 2. Clone the plugin

```bash
cd "C:/Users/YOUR_USER/Local Sites/YOUR_SITE/app/public/wp-content/plugins"
git clone https://github.com/omnisolutionsagency/omni-booking-manager.git
```

### 3. Activate and configure

1. Open the local site's WP Admin
2. Plugins > Activate "Omni Booking Manager"
3. Run the Setup Wizard (3 steps)
4. Phase 1 integrations (Stripe, Emails, Waivers) auto-enable on completion

### 4. Development workflow

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full git workflow.

**Short version:**

```bash
git pull origin main              # Get latest code
git checkout -b fix/description   # Create a branch for your change
# ... make edits, test in browser ...
git add -A
git commit -m "Fix: description of what you changed"
git push origin fix/description   # Push branch to GitHub
# Create a Pull Request on GitHub, review, then merge
```

## Deployment

After merging to `main`, deploy to live sites by pulling the latest code on the server:

```bash
cd /path/to/wp-content/plugins/omni-booking-manager
git pull origin main
```

## Plugin Structure

```
omni-booking-manager.php     -- Main plugin entry point
includes/
  class-db.php               -- Database schema (v1)
  class-db-v2.php            -- Database migrations (v2)
  class-role.php             -- WordPress roles/capabilities
  class-google-calendar.php  -- Google Calendar API integration
  class-form-handler.php     -- Elementor form capture
  class-integrations.php     -- Integration toggle system
  class-rest-api.php         -- REST API endpoints (for PWA)
  class-admin-dashboard.php  -- Main admin bookings list
  class-admin-add-booking.php-- Manual booking creation
  class-admin-import.php     -- CSV import
  class-admin-staff.php      -- Staff management
  class-admin-blocked-dates.php -- Blocked dates
  class-admin-settings.php   -- Settings page (Google creds, etc.)
  class-admin-wizard.php     -- First-run setup wizard
  class-ajax-handler.php     -- AJAX endpoints
  class-digest-email.php     -- Scheduled digest emails
  class-pwa.php              -- PWA registration and routing
  integrations/
    class-stripe.php         -- Stripe payments
    class-emails.php         -- Email sequences
    class-waivers.php        -- Liability waivers
    class-sms.php            -- Twilio SMS
    class-reviews.php        -- Review collection
    class-portal.php         -- Client portal
app/
  index.php                  -- PWA app shell (mobile UI)
  manifest.php               -- PWA manifest
  sw.js                      -- Service worker
templates/
  client-portal.php          -- Client portal template
  waiver-form.php            -- Waiver signing page
  email-digest.php           -- Digest email template
assets/
  css/admin.css              -- Admin styles
  js/admin.js                -- Admin JavaScript
```
