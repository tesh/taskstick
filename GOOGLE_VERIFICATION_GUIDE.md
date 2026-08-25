> **Resolved, 2026-08-22:** Google approved verification. The
> **`your-project-id`** Cloud project's OAuth consent screen (client id
> `<YOUR_CLIENT_ID>.apps.googleusercontent.com`) is now in
> **Production** — the `tasks` scope is verified and the Testing-mode
> 7-day refresh-token cap no longer applies. Nothing left to do from this
> guide; kept below for reference if verification ever needs redoing
> (e.g. a scope change triggers re-review).
>
> **Update, 2026-08-20 (superseded by the above):** This guide originally
> walked through creating a *brand-new* Cloud project from scratch. That
> project was abandoned — the OAuth client actually live in `config.php`
> belongs to the `your-project-id` Cloud project instead. Part A (create
> project) and Part C (wire new credentials) were skipped entirely;
> verification was completed starting from Part A3 through Part D.

# TaskStick — Google Cloud Setup & OAuth Verification Guide

**App:** TaskStick (by Purple Pill Solutions)
**Domain:** tasks.tesh.ai (subdomain of tesh.ai)
**Redirect URI:** https://tasks.tesh.ai/auth/callback.php
**Scopes requested (4):**
1. `openid`
2. `email` — `https://www.googleapis.com/auth/userinfo.email`
3. `profile` — `https://www.googleapis.com/auth/userinfo.profile`
4. `https://www.googleapis.com/auth/tasks` *(sensitive — triggers verification)*

> **Analogy:** Think of Google verification like getting a building permit. The Cloud Console is the application form, domain verification is proving you own the lot, the demo video is the blueprints showing what you're building, and the scope justifications are the contractor's notes explaining *why* each piece of equipment is needed. You'll be asked to redo any part that doesn't clearly demonstrate ownership and intent.

---

## Part A — Create the new Google Cloud project

### A1. Create the project

1. Go to https://console.cloud.google.com/
2. Top bar → project dropdown → **New Project**
3. Settings:
   - **Project name:** `TaskStick`
   - **Organization / Location:** No organization (unless you have a Workspace org)
4. Click **Create**, wait ~10 seconds, then select the new project from the dropdown.
5. **Write down the Project ID and Project Number** that appear in the dashboard — you'll need both.

### A2. Enable the Google Tasks API

1. Left menu → **APIs & Services → Library**
2. Search **"Tasks API"** → click the result → **Enable**

*(You do NOT need to enable People API, Contacts API, Drive API, or Calendar API. TaskStick only uses Tasks + OpenID userinfo. The userinfo endpoints come built-in with the OAuth client — no separate API to enable.)*

### A3. Configure the OAuth consent screen

Left menu → **APIs & Services → OAuth consent screen**

**Screen 1 — User Type:**
- Choose **External**, click **Create**

**Screen 2 — App information:**
| Field | Value |
|---|---|
| App name | `TaskStick` |
| User support email | `you@example.com` |
| App logo | Upload `/icons/icon-512.png` (must be 120×120 min, square, PNG/JPG, ≤1 MB — resize if needed) |
| App domain — Application home page | `https://tasks.tesh.ai/` |
| App domain — Application privacy policy link | `https://tasks.tesh.ai/privacy.html` |
| App domain — Application terms of service link | `https://tasks.tesh.ai/terms.html` |
| Authorized domains | `tesh.ai` *(only the apex — Google auto-allows subdomains. You MUST verify this in Part B before saving works cleanly.)* |
| Developer contact information | `you@example.com` |

Click **Save and Continue**.

**Screen 3 — Scopes:**
Click **Add or Remove Scopes**, then tick / paste each of these four:

```
openid
.../auth/userinfo.email
.../auth/userinfo.profile
https://www.googleapis.com/auth/tasks
```

The first three appear under "Non-sensitive scopes" (no verification needed). The `tasks` scope appears under **"Your sensitive scopes"** — this is the one that triggers the verification review.

Click **Update**, then **Save and Continue**.

**Screen 4 — Test users:**
Add at least your own email (`you@example.com`) plus 1–2 others you can sign in as during the demo video.

Click **Save and Continue**, then **Back to Dashboard**.

> **Don't click "Publish App" yet.** Keep it in **Testing** mode until your config is wired up and verified end-to-end. You'll switch to **In Production** and submit for verification at the end of Part D.

### A4. Create the OAuth Client ID

Left menu → **APIs & Services → Credentials → Create Credentials → OAuth client ID**

| Field | Value |
|---|---|
| Application type | **Web application** |
| Name | `TaskStick Web Client` |
| Authorized JavaScript origins | `https://tasks.tesh.ai` |
| Authorized redirect URIs | `https://tasks.tesh.ai/auth/callback.php` |

Click **Create**. A modal pops up with the new **Client ID** and **Client secret** — copy both. You'll paste them into `config.php` and `mcp/config.php` in Part C.

---

## Part B — Verify ownership of tesh.ai

This is the fix for *"the website you provided as your homepage is not registered to you."* You verify the apex domain (`tesh.ai`) and Google accepts all subdomains (including `tasks.tesh.ai`).

### B1. Add the property in Google Search Console

1. Go to https://search.google.com/search-console
2. Sign in as `you@example.com` (must be the same account you're using in Cloud Console)
3. **Add property → Domain** (left option, not URL prefix)
4. Enter `tesh.ai`
5. Search Console will show a **TXT record** like:
   `google-site-verification=AbCdEfG...` (the actual value will be unique to you)
6. **Leave that page open.**

### B2. Add the TXT record at your DNS host

1. Sign in to wherever tesh.ai DNS is managed (Cloudflare, Namecheap, IONOS DNS, etc.)
2. Add a new record:
   | Field | Value |
   |---|---|
   | Type | `TXT` |
   | Name / Host | `@` (or leave blank — depends on host; this means "the apex `tesh.ai`") |
   | Value | `google-site-verification=<paste the value from B1>` |
   | TTL | Default (1 hour is fine) |
3. Save.

### B3. Verify

1. Back in Search Console, click **Verify**.
2. If it fails, wait 5–10 minutes for DNS to propagate, then retry. You can sanity-check propagation with `dig TXT tesh.ai +short` (or any online DNS lookup).
3. Once verified, **keep the TXT record in place forever** — Google re-checks periodically and will revoke verification if it disappears.

### B4. Bind the verified domain to your Cloud project

1. Back to https://console.cloud.google.com/ → make sure **TaskStick** project is selected
2. **APIs & Services → OAuth consent screen → Edit App**
3. In **Authorized domains**, type `tesh.ai` and press Enter. It should accept it cleanly now that you've verified.
4. **Save**.

---

## Part C — Wire the new client into your app

Two files reference the OAuth credentials. You're replacing the existing client (from an older, unrelated project) with the new TaskStick client.

### C1. `config.php` (root)

Lines 3–4 — replace:
```php
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '<NEW_CLIENT_ID>');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '<NEW_CLIENT_SECRET>');
```

### C2. `mcp/config.php`

Lines 16–17 — replace with the same new values:
```php
define('MCP_GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '<NEW_CLIENT_ID>');
define('MCP_GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '<NEW_CLIENT_SECRET>');
```

> **Security note:** You currently have a client secret hardcoded in source (`GOCSPX-…`). Once you swap to the new client, **immediately revoke the old client** in the old GCP project's Credentials page so the leaked secret can't be reused. For the new one, prefer setting `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` as environment variables on IONOS rather than committing them — the `getenv()` fallback is already in your code, you just need to populate the env vars on the host. (Not blocking for verification, but worth doing.)

### C3. Deploy and smoke-test

1. Run `deploy.sh` (and `deploy-mcp.sh` if you're going to demo the MCP path).
2. While the app is still in **Testing** mode in Cloud Console, sign in at https://tasks.tesh.ai/ with one of your test-user accounts.
3. Confirm the consent screen now says:
   - App name: **TaskStick**
   - Logo visible
   - All four scopes listed (openid is implicit; the screen will show "See, edit, create, and permanently delete your Google Tasks lists" plus the email/profile lines)
4. Confirm callback completes and you can create/edit a task.

**If any of that fails, do not proceed to verification.** Fix it first — Google's reviewer will repeat your steps.

---

## Part D — Submit for verification

### D1. Switch publishing status to Production

1. **OAuth consent screen → Publishing status: Testing → Publish App** → confirm
2. The page will show **Verification status: Needs verification** for the `tasks` scope
3. Click **Prepare for verification**

### D2. Fill in the verification form

Google asks for justifications per sensitive scope, the demo video link, and a couple of attestations. Here's exact text you can paste — adapt as you like.

#### Scope justification — `https://www.googleapis.com/auth/tasks`

> TaskStick is a Google Tasks client (web app at https://tasks.tesh.ai/). The full `tasks` scope is required because the core function of the app is letting users view, create, edit, complete, delete, reorder, and organise their existing Google Tasks lists and items. The narrower `tasks.readonly` scope would not allow create/edit/delete/reorder, which are the primary user actions. No task data is stored on our servers beyond an ephemeral session token; all reads and writes are proxied directly to https://tasks.googleapis.com/tasks/v1 on behalf of the signed-in user. Source: `config.php` (`googleApiRequest()`), `api/tasks.php`, `api/lists.php`.

#### Scope justification — `userinfo.email`, `userinfo.profile`, `openid`

> Standard sign-in scopes. We use the user's email to identify their session and their name/profile picture to render the signed-in UI header. We do not share, sell, or transmit this data to any third party.

#### How are scopes being used? (Limited Use disclosure)

> User data accessed via the `tasks` scope is used solely to provide the in-product features the user is interacting with (viewing and editing their tasks in real time). It is not transferred to any third party, not used for advertising, not used for any kind of model training, and not read by humans except where strictly necessary for support with the user's explicit consent or for security/abuse investigation. This matches Google's Limited Use requirements.

#### Demo video URL

Paste a YouTube (unlisted is fine) or Google Drive link to the video described in Part E.

### D3. Submit

Click **Submit for verification**. Google replies in 4–6 business days typically (longer if they ask follow-ups).

---

## Part E — The demo video (the part Google rejected last time)

> **Analogy:** The reviewer has 90 seconds and no context. Pretend you're filming a how-to for someone who has never seen your app. Narrate every click. Show URLs in the address bar. Don't cut away from the consent screen until the reviewer has had time to read every line.

### E1. What Google explicitly requires

Their two rejection reasons were:
1. *"Demo video does not show the OAuth consent screen workflow"* — they need to see the scopes the user is consenting to, on screen, readable.
2. *"Does not sufficiently demonstrate the functionality"* — they need to see the app actually using each scope it requested.

A video that satisfies both is roughly **2–4 minutes**, screen-recorded, with **narration or on-screen captions** (narration is better — reviewers tend to mute), and uploaded to YouTube as **Unlisted**.

### E2. Video script (use this as a literal storyboard)

**(0:00–0:15) — Title card / app identity**
- On screen text: **"TaskStick — tasks.tesh.ai — OAuth verification demo"**
- Voiceover: *"This is TaskStick, a web app at tasks.tesh.ai that lets users manage their Google Tasks. This video demonstrates the OAuth consent flow and the core app functionality for verification of the Google Tasks scope."*

**(0:15–0:35) — Homepage / pre-login state**
- Navigate to `https://tasks.tesh.ai/` in an incognito window. **Show the full URL in the address bar.**
- Stay on the login screen for 5+ seconds.
- Voiceover: *"This is the public homepage. It identifies the app, explains what data we access, and links to our Privacy Policy and Terms of Service. No login is required to read this page."*
- Briefly scroll/hover over the feature list, the "data access" notice, and the Privacy / Terms links.

**(0:35–1:10) — OAuth consent screen (THE CRITICAL PART)**
- Click **Sign in with Google**.
- When the Google account chooser appears, pick a test account. Show the email.
- **STOP on the consent screen.** Do not click Allow yet.
- Slowly scroll/zoom so every scope line is readable:
  - "TaskStick wants access to your Google Account"
  - "Associate you with your personal info on Google"
  - "See your primary Google Account email address"
  - "See your personal info, including any personal info you've made publicly available"
  - **"See, edit, create, and permanently delete all your tasks"** ← the sensitive one
- Voiceover: *"This is the Google consent screen. The user can see exactly which scopes TaskStick is requesting, including the Google Tasks scope, before granting access. They can deny consent or grant only some scopes."*
- Hold for at least 5 seconds.
- Click **Continue / Allow**.

**(1:10–1:30) — Post-consent landing**
- Show the user is now signed in (avatar + name in header).
- Voiceover: *"After consent, the app loads the user's Google Tasks lists directly from the Google Tasks API. No task data is stored on our servers."*

**(1:30–2:45) — Functionality demonstrating EACH scope**

Run through these so the reviewer sees *why* each scope was needed:

| Action | Scope it justifies |
|---|---|
| Header shows your name + profile photo from Google | `profile`, `email` |
| **Create** a new task — type "Demo task" and press Enter | `tasks` (write) |
| **Edit** a task — double-click, change text, save | `tasks` (write) |
| **Complete** a task — click the checkbox | `tasks` (write) |
| **Reorder** within a list — drag a task up or down | `tasks` (move) |
| **Drag across lists** — drag a task to a different list | `tasks` (move) |
| **Star + Follow-up** — star a task; click the ◑ partial-complete button to move it to Follow-up | `tasks` (write/move) |
| **Delete** a task — click the trash icon | `tasks` (delete) |
| Open https://tasks.google.com in another tab briefly to show the same task appears there | confirms it's the user's real Google Tasks data, not a sandbox |

Narrate each one. Keep clicks deliberate and slow.

**(2:45–3:00) — Sign out + close**
- Open the profile dropdown → **Sign out**.
- Voiceover: *"Sign out clears the session. The user can also revoke access at any time at myaccount.google.com/permissions. Thank you for reviewing TaskStick."*

### E3. Upload settings

- **YouTube → Upload → Visibility: Unlisted**
- Title: `TaskStick — OAuth verification demo (Google Tasks scope)`
- Description: Paste the same summary you used in the scope justification.
- Copy the URL — you'll paste it into the Cloud Console verification form and into your email reply.

---

## Part F — Reply email template (for the existing project, if useful)

You asked specifically about the *new* project, but if you also want to formally close out an older, unrelated project's verification thread, here's a reply you can adapt — paste this into a reply to the Google review email **only if you intend to keep the old project**. If you're abandoning it in favour of the new TaskStick project, reply with the cancellation line at the bottom instead.

```
Hello Google verification team,

Thank you for the feedback. I have addressed each item below.

1. Homepage domain ownership
   I have verified ownership of tesh.ai (the registrable domain for my
   homepage https://tasks.tesh.ai/) via DNS TXT record in Google Search
   Console under the same account as this Cloud project. The verified
   domain has been added to the OAuth consent screen's Authorized
   domains.

2. Demo video showing the OAuth consent screen workflow
   New demo video (unlisted): <YOUTUBE_URL>
   The first ~70 seconds walk through the full OAuth flow: visiting the
   public homepage, clicking Sign in with Google, the account chooser,
   and a held shot of the consent screen showing all four scopes
   (openid, userinfo.email, userinfo.profile,
   https://www.googleapis.com/auth/tasks) before the user clicks Allow.

3. Demo video sufficiently demonstrating functionality
   The same video (timestamp 1:30 onward) demonstrates each user action
   that requires the requested scopes: creating, editing, completing,
   reordering, moving across lists, starring, partial-completing, and
   deleting Google Tasks items. It also shows the same items appearing
   in tasks.google.com to confirm we are operating on the user's real
   Google Tasks data.

Scopes requested:
- openid                                            (sign-in identity)
- https://www.googleapis.com/auth/userinfo.email    (sign-in identity)
- https://www.googleapis.com/auth/userinfo.profile  (header avatar/name)
- https://www.googleapis.com/auth/tasks             (core app function)

The Tasks scope is required because TaskStick's core function is letting
the user create, edit, reorder and delete their own Google Tasks. The
read-only variant would not support these primary user actions. No task
data is persisted on our servers; all calls are proxied to
tasks.googleapis.com on behalf of the signed-in user.

Please let me know if anything else is needed.

Thanks,
Your Name
you@example.com
```

**Or, to cancel the old verification** (recommended since you're starting fresh):

```
Hello Google verification team,

I am no longer pursuing verification for project <OLD_PROJECT_NUMBER>
(an older, unrelated project) and am submitting a fresh project for TaskStick
instead. Please cancel this verification request.

Thanks,
Your Name
```

---

## Quick reference — what goes where

| Console field | Value |
|---|---|
| Project name | TaskStick |
| App name | TaskStick |
| Publisher / support email | you@example.com |
| Homepage | https://tasks.tesh.ai/ |
| Privacy policy | https://tasks.tesh.ai/privacy.html |
| Terms of service | https://tasks.tesh.ai/terms.html |
| Authorized domain | tesh.ai (verified in Search Console) |
| Authorized JS origin | https://tasks.tesh.ai |
| Authorized redirect URI | https://tasks.tesh.ai/auth/callback.php |
| Scopes | openid · userinfo.email · userinfo.profile · auth/tasks |

## Checklist before clicking "Submit for verification"

- [ ] tesh.ai is verified in Search Console under the same Google account
- [ ] tesh.ai appears under Authorized domains in the OAuth consent screen
- [ ] The consent screen has a logo, homepage URL, privacy URL, terms URL
- [ ] config.php and mcp/config.php point at the new Client ID + Secret
- [ ] Old client secret has been revoked in the old project
- [ ] You can sign in at tasks.tesh.ai and see the consent screen showing "TaskStick"
- [ ] Demo video is uploaded as Unlisted on YouTube and the URL works in incognito
- [ ] Demo video shows the consent screen for 5+ seconds with all scopes readable
- [ ] Demo video shows at least one user action per scope
- [ ] Publishing status is set to **In production**
