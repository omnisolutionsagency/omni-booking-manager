# Contributing to Omni Booking Manager

## The Golden Rule

**Never edit code directly on the live site or directly on `main`.** Always work locally, test, then push.

## Git Workflow (Step by Step)

### Before you start any work

```bash
cd "C:/Users/jpoor/Local Sites/deepcreek-dev/app/public/wp-content/plugins/omni-booking-manager"
git checkout main
git pull origin main
```

This makes sure you have the latest code before making changes.

### Starting a new change

Create a branch. Branch names should describe what you're doing:

```bash
git checkout -b fix/broken-email-template     # Fixing a bug
git checkout -b feature/add-sms-reminders     # Adding something new
git checkout -b update/stripe-settings-page   # Improving something existing
```

### Making edits

1. Edit files in your code editor (VS Code recommended)
2. Save the file
3. Refresh your LocalWP site in the browser to see the change
4. Repeat until it works correctly

### Saving your work (committing)

```bash
git status                        # See what files you changed
git diff                          # See exactly what changed (line by line)
git add -A                        # Stage all changes
git commit -m "Fix: description"  # Save with a message
```

**Commit message prefixes:**
- `Fix:` — You fixed a bug
- `Add:` — You added a new feature
- `Update:` — You improved something existing
- `Remove:` — You removed something

**Good commit messages:**
- `Fix: email template not sending business name`
- `Add: SMS reminder 24 hours before booking`
- `Update: dashboard to show payment status column`

**Bad commit messages:**
- `fixed stuff`
- `updates`
- `asdfg`

### Pushing to GitHub

```bash
git push origin fix/broken-email-template
```

Then go to GitHub and create a Pull Request (PR). This lets you review changes before they go into `main`.

### After the PR is merged

```bash
git checkout main
git pull origin main
```

Now you're back on the latest code, ready for the next change.

## Working with Claude Code

You can work on files entirely through Claude Code using the local files. The typical flow:

1. Tell Claude what's broken or what you want to change
2. Claude reads the relevant files, makes edits locally
3. You test in your browser (refresh the LocalWP site)
4. If it works, tell Claude to commit and push
5. Claude creates a PR on GitHub
6. You review and merge

Claude can also read the files on the live server (via SSH) to compare what's deployed vs what's in the repo.

## Debugging Tips

### Something broke after an edit

1. Check the browser — is there a white screen or PHP error?
2. Enable WP debug mode in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', true);
   ```
3. Errors will show on screen and log to `wp-content/debug.log`
4. Tell Claude the error message — it can usually pinpoint the fix

### Want to undo your local changes

```bash
git status                    # See what's changed
git diff path/to/file.php    # See specific changes
git checkout -- path/to/file  # Undo changes to one file
git checkout -- .             # Undo ALL local changes (careful!)
```

### Want to throw away a branch and start over

```bash
git checkout main
git branch -D fix/my-broken-attempt
git pull origin main
```

## File Organization

- **`includes/`** — PHP classes (backend logic). One class per file.
- **`includes/integrations/`** — Toggle-able integration modules
- **`templates/`** — HTML/PHP templates rendered to users
- **`assets/css/`** — Stylesheets (how things look)
- **`assets/js/`** — JavaScript (interactive behavior)
- **`app/`** — Mobile PWA (the phone-friendly app view)

When fixing a bug, you usually only need to edit 1-2 files. Claude can help identify which ones.
