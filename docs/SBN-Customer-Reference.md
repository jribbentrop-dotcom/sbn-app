# SBN Customer Backend & Community — Reference

The "logged-in student area" of SBN: customer dashboard, messaging with the
instructor, community channel, and the surfaces in `/admin` for the instructor
to manage all of it.

Shipped 2026-05-28 across phases A–E. This doc replaces the planning docs
([Customer-Backend-Plan.md](Customer-Backend-Plan.md) and
[Ops-Reverb.md](Ops-Reverb.md)) — it's the as-built reference. Update this
file when behavior changes.

---

## 1. Roles & landing pages

Two roles, distinguished by the `users.is_instructor` boolean column:

| Role | Login lands on | Sees |
|---|---|---|
| Customer (`is_instructor=false`) | `/account` | `/account/*`, `/community`, public surfaces, `/learn/{slug}` for owned courses |
| Instructor (`is_instructor=true`) | `/admin` | All `/admin/*`, including messaging + community + course grants. `/account` redirects messaging/community surfaces to the admin equivalents. |

The current production model is **one instructor, many customers** (Lucas =
the instructor; everyone else = customer). The schema and policies support
multiple instructors, but UI surfaces ("Message the instructor") pick the
first `is_instructor=true` user.

**Login redirect lives in** [LoginController::landingFor()](../app/Http/Controllers/Auth/LoginController.php).

**`/admin/*` is gated by** [EnsureIsInstructor](../app/Http/Middleware/EnsureIsInstructor.php) middleware (alias `instructor`, registered in [bootstrap/app.php](../bootstrap/app.php)). Non-instructors hitting `/admin/*` get redirected to `/account`.

---

## 2. Schema

Four migrations dated `2026_05_28_*`:

### 2.1 `course_user` — ownership pivot
Single source of truth for "does this user own this course?"
```
id, user_id (fk), course_id (fk → sbn_courses),
source ENUM('purchase','manual_grant','bundle','promo'),
order_id (fk → sbn_orders, nullable),
granted_at, expires_at (nullable),
last_accessed_at (nullable),
timestamps
UNIQUE(user_id, course_id)
```
- Reads via `User::owns(Course)` — checks `is_free` first, then pivot existence with `expires_at` clause.
- Writes go through [CourseAccessService](../app/Services/CourseAccessService.php) — `grantManual()`, `grantPurchase()`, `revokePurchase()`. The Lemon Squeezy webhook in Phase 12 will call `grantPurchase()`.

### 2.2 `user_profiles`
```
user_id (pk, fk), display_name, avatar_path, bio, public, last_seen_at
```
1-to-1 with users. Created lazily on first `/account/profile` visit. Avatars stored on `storage/app/public/avatars/` (served via `storage:link` symlink at `public/storage`).

### 2.3 Messaging — three tables
```
conversations (id, type ENUM('dm','channel'), title, read_only, last_message_at)
conversation_participants (conversation_id, user_id, joined_at, last_read_at, muted)
messages (id, conversation_id, user_id, body, edited_at, deleted_at[soft], timestamps)
```
- DMs: `type='dm'` with exactly two participants.
- Community channel: `type='channel'` with title "The Practice Room". Every authenticated user is auto-added (backfill + lazy-add in [CommunityController](../app/Http/Controllers/CommunityController.php)).
- `messages` uses soft deletes — moderators see "message removed" placeholders.

### 2.4 `users.is_instructor`
Boolean flag on the existing `users` table. Drives both the login redirect and `/admin` access gate.

### 2.5 `jobs` table
Added during Reverb install for queue infrastructure. Currently unused for messaging (see §6).

---

## 3. Models, policies, services

### Models
- [CourseUser](../app/Models/CourseUser.php) — pivot model with `isActive()` helper.
- [UserProfile](../app/Models/UserProfile.php) — primary key `user_id`, no auto-increment.
- [Conversation](../app/Models/Conversation.php), [ConversationParticipant](../app/Models/ConversationParticipant.php), [Message](../app/Models/Message.php).
- [User](../app/Models/User.php) gained: `profile()`, `courses()`, `conversations()`, `owns(Course)`, `isInstructor()`.
- [Course](../app/Models/Course.php) gained: `owners()`.

### Policies
- [CoursePolicy](../app/Policies/CoursePolicy.php) — `view`, `viewLessons`, `grant`. Auto-discovered (Course ↔ CoursePolicy naming match).
- [MessagePolicy](../app/Policies/MessagePolicy.php) — `viewConversation`, `createInConversation`, `createDmTo`, `delete`, `moderate`.
  - **Important quirk:** `MessagePolicy` is NOT auto-discovered by Laravel because its methods take heterogeneous subjects (Conversation, Message, User). Controllers MUST call it directly: `app(MessagePolicy::class)->viewConversation($user, $conv)` — NOT `$user->can('viewConversation', $conv)` which silently returns false.

### Services
- [CourseAccessService](../app/Services/CourseAccessService.php) — single writer for `course_user`. Three entry points: `grantManual`, `grantPurchase`, `revokePurchase`. Plus `bumpLastAccessed` (called from `CourseController::player`).
- [AccountService](../app/Services/AccountService.php) — `unreadCountFor(User)` with 60s cache. Used by Inertia shared props (`account.unread_count`) and the admin view composer (`adminUnreadCount`). Cache invalidated on read/send via `invalidateUnread()`.

---

## 4. Routes

### Customer (Inertia)
```
/account                         AccountController@dashboard
/account/courses                 AccountController@courses
/account/orders                  AccountController@orders
/account/orders/{token}          AccountController@order
/account/profile                 AccountController@profile (GET) / @updateProfile (PATCH) / @uploadAvatar (POST)
/account/messages                Account\MessageController@index    [redirects to /admin/messages if instructor]
/account/messages/start-dm       Account\MessageController@startDm
/account/messages/{conv}         Account\MessageController@show     [redirects to /admin/messages?conversation=X if instructor]
/account/messages/{conv}/fetch   Account\MessageController@fetch    (JSON, incremental poll endpoint)
/account/messages/{conv}         Account\MessageController@store    (POST)
/account/messages/{conv}/{msg}   Account\MessageController@destroy  (DELETE, soft delete)
/account/messages/{conv}/read    Account\MessageController@markRead (PATCH, bumps last_read_at)
/community                       CommunityController@show           [redirects to /admin/community if instructor]
/community/read-only             CommunityController@toggleReadOnly
/community/mute                  CommunityController@toggleMute
```

### Admin (Blade)
```
/admin/course-grants             Admin\CourseGrantController — manual grant management
/admin/messages                  Admin\MessageController@index — full inbox + reply
/admin/messages/{conv}           Admin\MessageController@store / @destroy
/admin/community                 Admin\CommunityController@show — post + moderation
/admin/community/read-only       Admin\CommunityController@toggleReadOnly
/admin/community/messages/{msg}  Admin\CommunityController@destroyMessage
```

---

## 5. Frontend

### Layouts
- [PublicLayout.vue](../resources/js/Layouts/PublicLayout.vue) — site chrome (mega menu + footer). Persistent across page navigation.
- [AccountLayout.vue](../resources/js/Layouts/AccountLayout.vue) — sidebar + main column. Does NOT wrap PublicLayout; both are stacked via Inertia's persistent-layout array form: `defineOptions({ layout: [PublicLayout, AccountLayout] })`. **Do not re-wrap from inside the page template** — that re-mounts PublicLayout and produces double-headers (the bug fixed during testing).

### Customer Vue pages
- [Pages/Account/Dashboard.vue](../resources/js/Pages/Account/Dashboard.vue), [Courses.vue](../resources/js/Pages/Account/Courses.vue), [Orders/Index.vue](../resources/js/Pages/Account/Orders/Index.vue), [Orders/Show.vue](../resources/js/Pages/Account/Orders/Show.vue), [Profile.vue](../resources/js/Pages/Account/Profile.vue), [Messages/Index.vue](../resources/js/Pages/Account/Messages/Index.vue)
- [Pages/Community/Show.vue](../resources/js/Pages/Community/Show.vue)

### Chat components
- [Components/Chat/ConversationList.vue](../resources/js/Components/Chat/ConversationList.vue)
- [Components/Chat/MessageList.vue](../resources/js/Components/Chat/MessageList.vue)
- [Components/Chat/MessageBubble.vue](../resources/js/Components/Chat/MessageBubble.vue) — `showAuthor`, `canDelete` props
- [Components/Chat/MessageComposer.vue](../resources/js/Components/Chat/MessageComposer.vue) — Enter to send, Shift+Enter newline

### Composables
- [composables/useChat.ts](../resources/js/composables/useChat.ts) — single-conversation state. Per-id upsert (so soft deletes propagate). Lazy-detects `window.Echo`: if present, listens to `.MessageSent` and slows polling to 60s heartbeat; if absent, polls every 8s. Uses native `fetch` + CSRF meta tag — NO `window.axios` dependency.
- [composables/useFaviconDot.ts](../resources/js/composables/useFaviconDot.ts) — composes an SVG dot onto the favicon when `unread > 0`. Pulls accent from CSS var `--clr-accent`.

### Admin Blade views
- [admin/messages/index.blade.php](../resources/views/admin/messages/index.blade.php) — inbox + reply (form submit, no realtime)
- [admin/community/show.blade.php](../resources/views/admin/community/show.blade.php) — post + moderation
- [admin/course-grants/index.blade.php](../resources/views/admin/course-grants/index.blade.php) — manual grant form + table

### CSS
- [public/css/account.css](../public/css/account.css) — customer layout, sidebar, chat bubbles, profile form
- Admin chat styles appended to [public/css/admin2.css](../public/css/admin2.css) under "Admin chat" comment block
- Both files use design tokens only — no hex literals. Some scoping debt remains (selectors duplicate patterns from `sbn-design-system.css`); flagged for a consolidation pass.

---

## 6. Real-time (Reverb)

Reverb is installed and wired. Messaging works **with or without** it.

### How it works
- `MessageSent` event implements `ShouldBroadcastNow` (not `ShouldBroadcast`) — synchronous dispatch, no queue dependency, broadcaster no-ops cleanly if misconfigured.
- Broadcaster picks up `BROADCAST_CONNECTION=reverb` from `.env`.
- Server: `php artisan reverb:start` listens on `:8080`. Run under supervisor in prod.
- Client: [resources/js/bootstrap.js](../resources/js/bootstrap.js) instantiates `window.Echo` using the `VITE_REVERB_*` env vars. Imported by [app.ts](../resources/js/app.ts).
- Channel auth: `Broadcast::routes(['middleware' => ['web', 'auth']])` in [routes/web.php](../routes/web.php) registers `/broadcasting/auth`. Channel handlers in [routes/channels.php](../routes/channels.php) check participant membership.

### Event payload
`MessageSent::broadcastWith()` emits **IDs only**:
```js
{ conversation_id: 12, message_id: 345 }
```
Clients pull the actual message via `fetch?after=lastId`. Keeps message edits/deletes consistent (single source of truth = the DB).

### Channels
- `private-conversations.{id}` — DM + channel posts. Gate = participant pivot membership.
- `private-users.{id}` — reserved for per-user notifications; not yet emitted.

### Failure modes
- **Reverb server down:** clients fall back to 8s polling. No UX break.
- **`/broadcasting/auth` returns 403:** user isn't a conversation participant. Check `conversation_participants` rows.
- **WebSocket connection failed:** Reverb not running, or firewall on port 8080. Check `reverb:start` output.

### Env / config files
```
config/broadcasting.php     (published via php artisan config:publish broadcasting)
config/reverb.php           (published by composer require laravel/reverb)
.env: BROADCAST_CONNECTION=reverb, REVERB_APP_*, REVERB_HOST, REVERB_PORT, REVERB_SCHEME,
      VITE_REVERB_*  (mirrors of the above for the Vite client)
```

### Production
```ini
[program:sbn-reverb]
command=php /var/www/sbn-app/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/sbn-reverb.log
```

---

## 7. Email notifications

[NewMessageNotification](../app/Notifications/NewMessageNotification.php) — mail channel. Sent on every DM **except** when the recipient is actively in the conversation (read within the last 10 minutes) or muted on that conversation. Active back-and-forth doesn't generate emails.

Idle check is per-recipient inside [Account\MessageController::store](../app/Http/Controllers/Account/MessageController.php) and [Admin\MessageController::store](../app/Http/Controllers/Admin/MessageController.php).

---

## 8. Moderation (v1 surface)

Instructor-only, all via [MessagePolicy](../app/Policies/MessagePolicy.php):
- **Soft-delete a message** — author OR instructor. Bodies are blanked out; bubble shows "message removed".
- **Toggle channel read-only** — instructor only. Customer composer disappears; instructor's stays.
- **Mute notifications** — per-participant, self-scoped (any user mutes for themselves).

Report/block deferred until student↔student DMs land.

---

## 9. Artisan commands

| Command | What it does |
|---|---|
| `sbn:list-users` | Show all users + instructor flag. |
| `sbn:make-instructor <email> [--password=] [--name=] [--demote]` | Create or flag a user as instructor; `--demote` strips the flag. Idempotent. |
| `sbn:backfill-customer-backend --instructor=<email> --commit` | One-shot setup: flag instructor, grant all courses, create profiles for existing users, seed community channel and add everyone to it. Dry-run by default. |

---

## 10. DM policy (v1)

Hard-coded in [MessagePolicy::createDmTo](../app/Policies/MessagePolicy.php#L29) AND the inlined check in [Account\MessageController::startDm](../app/Http/Controllers/Account/MessageController.php):

> One end of every DM must be the instructor.

Customers can DM the instructor. The instructor can DM any customer. Customer↔customer DMs are blocked. When student↔student lands later, this policy is the single point of relaxation.

---

## 11. Phase 12 — Lemon Squeezy handoff (not started)

Plan: [Frontend-Migration-Plan.md §Phase 12](Frontend-Migration-Plan.md#phase-12--auth--payments-lemon-squeezy).

What's already ready for it:
- `course_user` schema with `source='purchase'` and `order_id` slots.
- `CourseAccessService::grantPurchase(User, Order)` — the LS webhook handler's one-call entry point. Resolves order → `sbn_order_items` → `sbn_courses` via existing `product_id` link.
- `CourseAccessService::revokePurchase(Order)` — refund webhook.
- All `/account/courses` reads go through `User::owns()` → `course_user`. No new gate code needed when LS lands.
- The `users` table has no `lemon_squeezy_customer_id` yet — Phase 12 will add that.

---

## 12. Known limitations / followups

- **`/admin` messaging is form-submit-based** — no real-time refresh on the admin inbox. Fine for a single-instructor inbox; if it ever feels slow, the `Echo` bootstrap is already loaded for `/admin` (via `bootstrap.js`), just needs a small JS hook in the Blade view to subscribe to channels.
- **Avatar uploads aren't re-encoded** server-side. Max 2MB, MIME-validated, but no resize/WebP conversion. Cheap to add via Intervention if storage cost matters.
- **Account CSS scoping debt** — some `account.css` selectors duplicate patterns that already exist in `sbn-design-system.css`. Flagged for consolidation pass.
- **CSRF meta tag added** to [app.blade.php](../resources/views/app.blade.php) specifically for `useChat`'s `fetch` calls. The admin layout already had its own.
- **`MessagePolicy` auto-discovery quirk** — see §3. Easy to forget; don't reintroduce `$user->can('foo', $conv)` calls without testing the actual 403 path.
- **Channel `/community` is a single global channel.** Per-course channels were considered and explicitly deferred until there's signal on how the global one gets used.

---

## 13. Testing matrix (for future agents)

End-to-end smoke test requires two users (one instructor, one customer):
1. `sbn:make-instructor lucas@soulbossanova.com` (or whatever the instructor email is)
2. `sbn:make-instructor jribbentrop@googlemail.com --password=test --demote` (customer)
3. `sbn:backfill-customer-backend --instructor=lucas@soulbossanova.com --commit`

Then in two browsers:
- Customer at `/account/messages` → "Message Lucas" → send "hi" → reply from admin browser → customer sees reply within 8s (or instantly if Reverb is running).
- Customer at `/community` → post → admin sees in `/admin/community` → moderator delete shows "message removed" both sides.
- Customer sidebar shows unread badge + favicon dot when offline messages arrive.
