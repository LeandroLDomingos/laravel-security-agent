# SECURITY.md — Laravel Application Defense Guide

<!--
╔══════════════════════════════════════════════════════════════════╗
║                    AI AGENT INSTRUCTIONS                         ║
╚══════════════════════════════════════════════════════════════════╝

You are a security audit agent for Laravel projects.
This file is your reference manual and execution script.

## LANGUAGE

Detect the language of the user's message and respond entirely in that language.
If the user writes in Portuguese, respond in Portuguese.
If the user writes in English, respond in English.
If the user writes in Spanish, respond in Spanish.
Apply this rule to every message: reports, questions, confirmations, and code comments.

## REQUIRED BEHAVIOR

1. NEVER modify code without asking the user first.
2. For each issue found, present:
   - File and line where the problem is
   - Why it is a risk (category: IDOR, SQL Injection, etc.)
   - What you intend to do to fix it
   - Wait for explicit approval ("yes", "go ahead", "ok") before editing.
3. If you find multiple issues, list ALL of them first, then ask
   which ones the user wants to fix and in what order.
4. After each fix, show the diff and confirm with the user.

## HOW TO RUN THE AUDIT

When the user says "audit security", "run SECURITY.md",
"check security" or similar, execute this script in order:

### STEP 1 — Project reconnaissance
Before any analysis, map:
- Stack: Laravel version, auth packages (Fortify, Sanctum, Breeze)
- Routes: `routes/web.php` and `routes/api.php`
- Controllers: list all in `app/Http/Controllers/`
- Models: list all in `app/Models/`
- Deploy files: `deploy.php`, `deployer.php`, `.env`, `.env.*`

### STEP 2 — Audit by category (in this order)
Run each check and record findings before moving to the next:

[ ] 1. GIT / EXPOSED SECRETS
    - Is `deploy.php` in .gitignore?
    - Is `.env` in .gitignore?
    - Search for hardcoded IPs: grep -r "host\(" deploy.php
    - Search history: git log --all -- .env deploy.php
    - Keys/certificates (.pem, .key) in the repository?

[ ] 2. IDOR
    - Does every resource controller use `$this->authorize()` or a Policy?
    - Do queries filter by the authenticated user?
    - Are sequential IDs exposed in public routes?
    - Is the `role` field accepted via $request->all()?

[ ] 3. SQL INJECTION
    - Is `DB::unprepared()` used with user input?
    - Are `orderBy` / `groupBy` using dynamic values without a whitelist?
    - Do file imports execute SQL directly?

[ ] 4. FIELD VALIDATION
    - Do all FormRequests have `max:` on text fields?
    - Is `$request->all()` used without prior validation?
    - Do numeric fields have `min:` and `max:`?

[ ] 5. FILE UPLOADS
    - Do rules use `mimetypes:` (real bytes) or only `mimes:` (extension)?
    - Are files stored on the `private` disk or in `public`?
    - Is the filename generated server-side (UUID) or taken from the client?
    - Are external image URLs validated against a domain whitelist?

[ ] 6. MASS ASSIGNMENT
    - Are there Models with `$guarded = []`?
    - Does `$fillable` include sensitive fields (role, is_admin)?

[ ] 7. AUTHORIZATION & ROUTES
    - Are admin routes protected with role middleware?
    - Are there Controllers missing `$this->authorize()` on destructive methods?

[ ] 8. CSRF
    - Does `VerifyCsrfToken` have improper exclusions (non-webhooks)?
    - Is `SANCTUM_STATEFUL_DOMAINS` configured correctly?

[ ] 9. SESSION & HEADERS
    - Are `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_ENCRYPT` in .env?
    - Is the `SecurityHeaders` middleware registered globally?

[ ] 10. CREDENTIALS IN CODE
    - Are passwords compared with `Hash::check()` or with `===`?
    - Are `APP_DEBUG` and `APP_ENV` correct for production?
    - Do logs record sensitive fields (password, token)?

### STEP 3 — Findings report
After completing all checks, present a report in this format:

```
## Security Report — [Project Name]

### 🔴 Critical (fix immediately)
- [file:line] Issue description

### 🟡 Important (fix before next deploy)
- [file:line] Issue description

### 🟢 Suggestion (recommended improvement)
- [file:line] Issue description

Total: X critical | Y important | Z suggestions
```

### STEP 4 — Wait for approval before fixing
Ask: "Which items would you like me to fix now?"
Wait for the response. Fix one at a time, showing the diff for each change.

## GOLDEN RULES
- Never edit two files at the same time without approval
- Never force push without explicit user confirmation
- If you find a credential already committed, warn immediately and do not touch the code before the user responds
- Whenever you remove something, explain what was removed and why
-->

> **Core principle: Never trust the frontend. All validation, authorization, and sanitization happens on the server.**

---

## Table of Contents

1. [IDOR — Insecure Direct Object Reference](#1-idor--insecure-direct-object-reference)
2. [SQL Injection](#2-sql-injection)
3. [Field and Size Validation](#3-field-and-size-validation)
4. [Image and File Uploads](#4-image-and-file-uploads)
5. [Mass Assignment](#5-mass-assignment)
6. [Authorization and Roles](#6-authorization-and-roles)
7. [CSRF](#7-csrf)
8. [XSS — Cross-Site Scripting (Inertia/Vue)](#8-xss--cross-site-scripting-inertiavue)
9. [Rate Limiting](#9-rate-limiting)
10. [Session](#10-session)
11. [Security Headers](#11-security-headers)
12. [Passwords and Credentials](#12-passwords-and-credentials)
13. [What Never Goes to Git](#13-what-never-goes-to-git)
14. [Pre-Deploy Checklist](#14-pre-deploy-checklist)

---

## 1. IDOR — Insecure Direct Object Reference

**Problem:** The user manipulates IDs in the URL/request to access other users' resources.

### Rule: Always scope queries to the authenticated user

```php
// ❌ WRONG — any logged-in user can access any order
public function show(Order $order)
{
    return $order;
}

// ✅ CORRECT — enforces ownership via Policy
public function show(Order $order)
{
    $this->authorize('view', $order);
    return $order;
}

// ✅ ALTERNATIVE — direct query scoping
public function show(int $id)
{
    $order = auth()->user()->orders()->findOrFail($id);
    return $order;
}
```

### Create Policies for every sensitive resource

```bash
php artisan make:policy OrderPolicy --model=Order
```

```php
// app/Policies/OrderPolicy.php
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            || $user->hasRole(['admin', 'super_admin']);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->hasRole('admin');
    }
}
```

```php
// Register in AppServiceProvider
Gate::policy(Order::class, OrderPolicy::class);
```

### Call authorize() at the top of every controller method

```php
public function update(Request $request, Order $order)
{
    // Always at the top — before any business logic
    $this->authorize('update', $order); // throws 403 automatically
    // ...
}
```

### Prevent role escalation via request

```php
// ❌ WRONG — user can send role=admin in the body
$user->update($request->all());

// ✅ CORRECT — never accept role from the request
$user->update($request->only(['name', 'email', 'phone']));
// role is only changed by super_admin via a dedicated flow
```

---

## 2. SQL Injection

**Problem:** User input is interpolated directly into SQL queries.

### Always use Eloquent or Query Builder with bindings

```php
// ❌ WRONG — direct SQL injection
DB::select("SELECT * FROM users WHERE email = '{$request->email}'");

// ✅ CORRECT — parameterized binding
DB::select('SELECT * FROM users WHERE email = ?', [$request->email]);

// ✅ BEST — use Eloquent
User::where('email', $request->email)->first();
```

### Never execute raw SQL from user-uploaded files

```php
// ❌ CRITICAL — allows execution of arbitrary SQL
$content = file_get_contents($request->file('import'));
DB::unprepared($content);

// ✅ CORRECT — parse CSV with a field whitelist
$rows = array_map('str_getcsv', file($request->file('import')->path()));
$allowed = ['name', 'email', 'phone']; // explicit whitelist
$allowedCount = count($allowed);

foreach ($rows as $row) {
    // Skip rows with the wrong number of columns
    if (count($row) !== $allowedCount) {
        continue;
    }

    $data = array_combine($allowed, $row);
    User::create($data); // $fillable protects the rest
}
```

### Dynamic orderBy and LIKE — always use a whitelist

```php
// ❌ WRONG — orderBy with unsanitized dynamic column
$query->orderBy($request->sort_by);

// ✅ CORRECT — whitelist of allowed columns
$allowedSorts = ['name', 'created_at', 'price'];
$sortBy = in_array($request->sort_by, $allowedSorts, true)
    ? $request->sort_by
    : 'created_at';
$query->orderBy($sortBy);

// For LIKE, the Query Builder already escapes automatically:
$query->where('name', 'like', '%' . $request->search . '%');
```

---

## 3. Field and Size Validation

**Rule: Validate on the server. Frontend validation is UX, not security.**

### Explicit limits on every text field

```php
// app/Http/Requests/StoreUserRequest.php
public function rules(): array
{
    return [
        'name'     => ['required', 'string', 'min:2', 'max:100'],
        // Note: 'email:rfc,dns' performs a DNS lookup at validation time.
        // In environments without external DNS (CI, isolated staging), use 'email:rfc' only.
        'email'    => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
        'phone'    => ['nullable', 'string', 'max:20'],
        'bio'      => ['nullable', 'string', 'max:1000'],
        // bcrypt silently truncates above 72 bytes — max:72 is the real limit
        'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        // Numeric fields always need min/max
        'age'      => ['required', 'integer', 'min:0', 'max:150'],
        'amount'   => ['required', 'numeric', 'min:0', 'max:999999.99'],
    ];
}
```

### Never use $request->all() without prior validation

```php
// ❌ WRONG
$data = $request->all();
User::create($data);

// ✅ CORRECT — FormRequest with explicit rules
public function store(StoreUserRequest $request)
{
    User::create($request->validated());
}
```

### Sanitize inputs when necessary

```php
// For HTML fields (rich text) — use a purifier
// composer require mews/purifier
$clean = Purifier::clean($request->content);

// For plain strings — strip_tags is enough
$name = strip_tags($request->name);

// For slugs/identifiers
$slug = Str::slug($request->slug);
```

---

## 4. Image and File Uploads

**Rule: Never trust the MIME type sent by the client. Validate the actual file bytes.**

### The difference between `mimes:` and `mimetypes:`

```
mimes:jpeg,png       → validates the file EXTENSION (easy to bypass by renaming)
mimetypes:image/jpeg → validates the actual file BYTES via finfo (secure)
```

Always use `mimetypes:` for security validation:

```php
public function rules(): array
{
    return [
        'avatar' => [
            'required',
            'file',
            // mimetypes: uses finfo internally — reads real bytes, not the extension
            'mimetypes:image/jpeg,image/png,image/webp',
            // Maximum size in KB (2MB = 2048)
            'max:2048',
            // Minimum and maximum dimensions in pixels
            'dimensions:min_width=50,min_height=50,max_width=2000,max_height=2000',
        ],
    ];
}
```

### Additional finfo check (double guarantee)

```php
use Illuminate\Http\UploadedFile;

public function validateImageMime(UploadedFile $file): bool
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    // finfo reads real bytes, independent of the request Content-Type
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file->getPathname());

    return in_array($realMime, $allowedMimes, true);
}
```

### Never store in a publicly accessible folder

```php
// ❌ WRONG — an executable file can be served by the web server
$path = $request->file('avatar')->store('avatars', 'public');

// ✅ CORRECT — store outside public, serve via controller with auth check
$path = $request->file('avatar')->store('avatars', 'private');

// Controller to serve with authorization check
public function serveAvatar(User $user)
{
    $this->authorize('view', $user);
    return Storage::disk('private')->response($user->avatar_path);
}
```

### Rename the file (never use the original name)

```php
// ❌ WRONG — original name may contain path traversal or overwrite existing files
$name = $request->file('avatar')->getClientOriginalName();

// ✅ CORRECT — generate a unique name server-side
// extension() detects the extension from the real MIME type, not the filename
$extension = $request->file('avatar')->extension();
$path = $request->file('avatar')->storeAs(
    'avatars/' . auth()->id(),
    Str::uuid() . '.' . $extension,
    'private'
);
```

### Validate that an image belongs to the current domain (for external URLs)

```php
// When accepting external image URLs (e.g., avatar via URL)
public function validateImageUrl(string $url): bool
{
    $parsed = parse_url($url);

    if (!isset($parsed['host'])) {
        return false;
    }

    // Whitelist of allowed domains — define in .env or config
    $allowedDomains = [
        parse_url(config('app.url'), PHP_URL_HOST), // own domain
        'storage.googleapis.com',
        'your-bucket.s3.amazonaws.com',
    ];

    // str_ends_with prevents subdomain bypass (e.g., evil.storage.googleapis.com.evil.com)
    foreach ($allowedDomains as $domain) {
        if ($parsed['host'] === $domain || str_ends_with($parsed['host'], '.' . $domain)) {
            return true;
        }
    }

    return false;
}

// In the controller — download and re-validate before saving
public function updateAvatarUrl(Request $request)
{
    $request->validate(['avatar_url' => 'required|url|max:2048']);

    if (!$this->validateImageUrl($request->avatar_url)) {
        abort(422, 'Image URL not allowed.');
    }

    // Check Content-Length before downloading (if available)
    $maxBytes = 2 * 1024 * 1024; // 2MB
    $head = Http::timeout(5)->head($request->avatar_url);
    $contentLength = (int) $head->header('Content-Length');
    if ($contentLength > $maxBytes) {
        abort(422, 'Image too large.');
    }

    // Download with timeout — stream to temp file and enforce size limit
    $tmpPath = tempnam(sys_get_temp_dir(), 'img_');
    $response = Http::timeout(10)->withOptions([
        'sink' => $tmpPath,
        'stream' => true,
    ])->get($request->avatar_url);

    if (!$response->successful() || filesize($tmpPath) > $maxBytes) {
        @unlink($tmpPath);
        abort(422, 'Could not download the image or size exceeded.');
    }

    $body = file_get_contents($tmpPath);
    @unlink($tmpPath);

    // Validate real MIME of downloaded bytes — never trust the external server's Content-Type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->buffer($body);

    if (!in_array($realMime, $allowedMimes, true)) {
        abort(422, 'The downloaded file is not a valid image.');
    }

    // Save with a server-generated filename
    $extension = match ($realMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    };

    $path = 'avatars/' . auth()->id() . '/' . Str::uuid() . '.' . $extension;
    Storage::disk('private')->put($path, $body);

    auth()->user()->update(['avatar_path' => $path]);
}
```

### Limit size at the server level (nginx/php.ini)

```nginx
# nginx.conf
client_max_body_size 10M;
```

```ini
; php.ini
upload_max_filesize = 10M
post_max_size = 12M
```

---

## 5. Mass Assignment

**Problem:** An overly broad `$fillable` allows users to write fields they shouldn't.

```php
// ❌ DANGEROUS — empty $guarded accepts any field
class User extends Model
{
    protected $guarded = [];
}

// ✅ CORRECT — explicit $fillable with only user-writable fields
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        // ❌ NEVER include: 'role', 'is_admin', 'email_verified_at'
    ];
}

// Sensitive fields are updated explicitly via dedicated flows
public function promoteToAdmin(User $user): void
{
    // Only super_admin can call this — protected by Policy
    $user->update(['role' => 'admin']);
}
```

---

## 6. Authorization and Roles

### Protect routes in groups with middleware

```php
// routes/web.php
Route::middleware(['auth', 'verified'])->group(function () {

    // Admin routes
    Route::middleware(['role:admin,super_admin'])->group(function () {
        Route::resource('users', UserController::class);
        Route::resource('settings', SettingController::class);
    });

    // Regular user routes
    Route::resource('orders', OrderController::class);
});
```

### Check authorization at the top of every controller method

```php
public function update(Request $request, Post $post)
{
    $this->authorize('update', $post); // automatic 403 if unauthorized
    $post->update($request->validated());
}
```

### Never expose sequential IDs in sensitive resources

```php
// ❌ BAD — enumeration attack: /orders/1, /orders/2...
Route::get('/orders/{order}', [OrderController::class, 'show']);

// ✅ GOOD — use UUID
// Migration:
$table->uuid('id')->primary();

// Model — required when using UUID as PK:
class Order extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($model) => $model->id ??= (string) Str::uuid());
    }
}
```

---

## 7. CSRF

**Problem:** Forged requests from other domains execute authenticated actions on behalf of the user.

Laravel protects automatically via the `VerifyCsrfToken` middleware for web routes. **Never remove this middleware.**

### Inertia.js — automatic protection via cookie

Laravel writes the `XSRF-TOKEN` cookie with `http_only: false` automatically (via `VerifyCsrfToken`). Inertia reads this cookie and sends the `X-XSRF-TOKEN` header on every request. **No additional configuration is needed** — do not touch `config/session.php` for this.

The session cookie (`laravel_session`) stays `http_only: true` by default and must never be changed.

### Check middleware exclusions

```php
// app/Http/Middleware/VerifyCsrfToken.php
class VerifyCsrfToken extends Middleware
{
    protected $except = [
        // ⚠️ Only exclude webhook routes with their own verification (e.g., Stripe signature)
        'webhooks/stripe',
        // ❌ NEVER exclude routes used by authenticated users
    ];
}
```

### REST APIs — use Sanctum with stateful authentication

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    env('APP_URL') ? ',' . parse_url(env('APP_URL'), PHP_URL_HOST) : ''
))),
```

---

## 8. XSS — Cross-Site Scripting (Inertia/Vue)

**Inertia-specific problem:** PHP props are passed as JSON to Vue. If rendered with `v-html`, they execute malicious scripts.

```vue
<!-- ❌ NEVER use v-html with user data -->
<div v-html="user.bio"></div>

<!-- ✅ CORRECT — default Vue interpolation escapes automatically -->
<div>{{ user.bio }}</div>

<!-- If you need to render HTML (e.g., rich text) — sanitize on the server first -->
<!-- And use a component with an allowed tags whitelist -->
```

### Sanitize rich HTML before saving (not only when displaying)

```php
// composer require mews/purifier
// Configure allowed tags in config/purifier.php

$post->content = Purifier::clean($request->content);
$post->save();
```

### Content Security Policy reinforces the defense

The CSP configured in the headers section limits which scripts can execute even if XSS occurs.

---

## 9. Rate Limiting

```php
// bootstrap/app.php (Laravel 11+) or RouteServiceProvider

RateLimiter::for('login', function (Request $request) {
    return [
        Limit::perMinute(5)->by($request->ip()),
        Limit::perMinute(10)->by($request->input('email')),
    ];
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('uploads', function (Request $request) {
    // Use ?-> and IP fallback in case middleware runs before auth
    return Limit::perHour(20)->by($request->user()?->id ?: $request->ip());
});
```

```php
// Apply to routes
Route::post('/login', ...)->middleware('throttle:login');
Route::post('/avatars', ...)->middleware(['auth', 'throttle:uploads']);
```

---

## 10. Session

```php
// .env — production
SESSION_DRIVER=database        # or redis — never 'file' on multi-server setups
SESSION_LIFETIME=120           # minutes of inactivity
SESSION_ENCRYPT=true           # encrypt session contents
SESSION_SECURE_COOKIE=true     # HTTPS only
SESSION_SAME_SITE=lax          # protects against cross-origin CSRF
```

```php
// config/session.php — verify
'http_only' => true,           // session cookie not accessible via JS
'secure'    => env('SESSION_SECURE_COOKIE', true),
'same_site' => env('SESSION_SAME_SITE', 'lax'),
```

### Regenerate session ID after login

Laravel/Fortify does this automatically. If implementing manual authentication:

```php
$request->session()->regenerate(); // after authenticating the user
```

---

## 11. Security Headers

```php
// app/Http/Middleware/SecurityHeaders.php
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // 'unsafe-inline' in style-src is required for Vue with scoped styles.
        // For stricter environments, use nonces or style hashes.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;"
        );

        // Remove headers that expose server information
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Note: X-XSS-Protection was removed from the W3C spec and can introduce bugs
        // in older browsers. The correct XSS mitigation is the CSP above.

        return $response;
    }
}
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(SecurityHeaders::class);
})
```

---

## 12. Passwords and Credentials

```php
// ❌ WRONG — plain text comparison
if ($request->password === config('app.master_password')) { ... }

// ✅ CORRECT — always use Hash::check()
if (!Hash::check($request->password, $user->password)) {
    abort(403, 'Incorrect password.');
}

// ✅ Generate a secure temporary password (never hardcode)
$temporaryPassword = Str::password(length: 12, symbols: false);
// Show ONCE in the admin UI, never save to logs

// ❌ NEVER commit to git — .env must be in .gitignore
// Use environment variables for all credentials
```

### APP_DEBUG in production — critical risk

`APP_DEBUG=true` in production exposes full stack traces, environment variables, and database credentials directly in HTTP responses. Any error becomes an information leak.

```php
// .env — required in production
APP_DEBUG=false
APP_ENV=production
```

### Never expose config() in the API

```php
// ❌ WRONG — exposes all configs including credentials
return response()->json(config()->all());

// ✅ CORRECT — return only what the client needs
return response()->json([
    'app_name' => config('app.name'),
    'timezone' => config('app.timezone'),
]);
```

### Logs — never record sensitive data

```php
// ❌ WRONG — password appears in the log
Log::info('Login attempt', $request->all());

// ✅ CORRECT — exclude sensitive fields
Log::info('Login attempt', $request->except(['password', 'password_confirmation', 'token']));
```

---

## 13. What Never Goes to Git

**Rule: If it has an IP, password, server path, or key — it stays out of the repository.**

### Required .gitignore entries

```gitignore
# Environment variables — NEVER commit
.env
.env.*
!.env.example      # only the example without real values goes in git

# Deploy — contains server IP and folder path
deploy.php
deployer.php
deploy/
.deployer/

# SSH keys and certificates
*.pem
*.key
*.p12
*.pfx
id_rsa
id_ed25519

# Local IDE / editor settings
.idea/
.vscode/settings.json
*.local

# Logs — may contain sensitive data
storage/logs/
*.log

# Dependencies — never commit
/vendor/
/node_modules/
```

### .env — never commit, always use .env.example

```bash
# .env.example — goes in git, without real values
APP_NAME=
APP_ENV=local
APP_KEY=
APP_DEBUG=false
APP_URL=

DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
```

```bash
# On the production server — generate key without committing
php artisan key:generate
```

### deploy.php — server IP and folder

`deploy.php` (Deployer) contains the server IP and deploy directory path. **It must never enter the repository.**

```php
// deploy.php — OUT OF GIT (.gitignore)

host('YOUR_IP_HERE')                             // ❌ real server IP
    ->set('deploy_path', '/var/www/project')     // ❌ real path
    ->set('remote_user', 'deploy');
```

**Safe alternative:** keep a `deploy.example.php` in the repository with placeholders:

```php
// deploy.example.php — CAN go in git (no real data)

host(env('DEPLOY_HOST', 'YOUR_IP_HERE'))
    ->set('deploy_path', env('DEPLOY_PATH', '/var/www/project'))
    ->set('remote_user', env('DEPLOY_USER', 'deploy'));
```

```bash
# .env.deploy (local, outside git)
DEPLOY_HOST=192.168.1.100
DEPLOY_PATH=/var/www/my-project
DEPLOY_USER=deploy
```

### Check if anything sensitive was already pushed

```bash
# Search git history for possible leaks
git log --all --full-history -- .env
git log --all --full-history -- deploy.php

# Search for suspicious strings in history (passwords, IPs)
git grep -i "password\s*=" $(git rev-list --all)
git grep -i "DB_PASSWORD" $(git rev-list --all)

# List all files that ever existed in the repository
git log --all --name-only --format="" | sort -u | grep -E "\.(env|pem|key)$"
```

> **If a secret was already committed**, consider it compromised even after removal.
> Required actions:
> 1. Revoke/rotate the credential immediately
> 2. Remove from history with `git filter-repo` (not just `git rm`)
> 3. Force all collaborators to re-clone the repository

### Automatic verification with git hooks

```bash
# .git/hooks/pre-commit — block commits of sensitive files
#!/bin/sh

BLOCKED=".env deploy.php *.pem *.key"

for pattern in $BLOCKED; do
    if git diff --cached --name-only | grep -qE "$pattern"; then
        echo "❌ BLOCKED: attempt to commit sensitive file ($pattern)"
        echo "   Add to .gitignore and run 'git rm --cached <file>'"
        exit 1
    fi
done

# Block hardcoded password/IP strings
if git diff --cached | grep -iE "(password|secret|api_key)\s*=\s*['\"][^'\"]{4,}"; then
    echo "❌ BLOCKED: possible hardcoded credential detected in diff"
    exit 1
fi
```

```bash
# Make the hook executable
chmod +x .git/hooks/pre-commit
```

---

## 14. Pre-Deploy Checklist

### Authorization / IDOR
- [ ] All resource controllers have `$this->authorize()`
- [ ] Routes grouped with role/permission middleware
- [ ] Policies registered for every sensitive Model
- [ ] `role` field never accepted via mass assignment
- [ ] Sequential IDs in public routes replaced with UUID

### SQL / Queries
- [ ] Zero use of `DB::unprepared()` with user input
- [ ] Dynamic `orderBy` and `groupBy` use column whitelists
- [ ] CSV/JSON imports use `$fillable` whitelist with size check

### Validation
- [ ] Every field has `min` and `max` defined
- [ ] FormRequests used instead of `$request->all()`
- [ ] `email:rfc` (or `email:rfc,dns` if the environment has external DNS)
- [ ] `password` limited to `max:72` (real bcrypt limit)

### Upload / Images
- [ ] `mimetypes:` (not `mimes:`) to validate real file bytes
- [ ] `max:` in KB defined for every upload
- [ ] Files stored outside `public/` (private disk)
- [ ] Filename generated server-side with `Str::uuid()`
- [ ] External image URLs validated against domain whitelist
- [ ] MIME of downloaded bytes re-validated with `finfo` before saving
- [ ] `client_max_body_size` configured in nginx

### CSRF / Session
- [ ] `VerifyCsrfToken` middleware active (no improper exclusions)
- [ ] `SANCTUM_STATEFUL_DOMAINS` configured correctly
- [ ] `SESSION_SECURE_COOKIE=true` in production
- [ ] `SESSION_SAME_SITE=lax` in production
- [ ] `SESSION_ENCRYPT=true` in production

### XSS
- [ ] No `v-html` with user data in the frontend
- [ ] Rich HTML sanitized with Purifier before saving to the database

### Rate Limiting
- [ ] Rate limit on login by IP and by email
- [ ] Rate limit on uploads by user
- [ ] Rate limit on critical endpoints (passwords, payments)

### Credentials / Config
- [ ] `APP_DEBUG=false` in production
- [ ] `APP_ENV=production` in production
- [ ] `.env` in `.gitignore`
- [ ] Passwords compared with `Hash::check()`, never `===`
- [ ] No `config()->all()` exposed via API
- [ ] Logs do not record sensitive fields (password, token)

### Git — Secrets and Sensitive Variables
- [ ] `.env` in `.gitignore` (and all `.env.*`)
- [ ] `deploy.php` in `.gitignore` (contains server IP and path)
- [ ] `.env.example` exists in the repository without real values
- [ ] `deploy.example.php` exists with placeholders instead of real data
- [ ] No server IP hardcoded in any committed file
- [ ] No absolute server path in any committed file (`/var/www/...`)
- [ ] SSH keys and certificates (`.pem`, `.key`) in `.gitignore`
- [ ] `pre-commit` hook configured to block sensitive files
- [ ] Git history verified: `git log --all -- .env` returns empty

---

> **Project-specific security decisions:**
>
> - Allowed image domains: `[ ]`
> - Existing roles: `[ ]`
> - Maximum upload size: `[ ]`
> - Custom rate limits: `[ ]`
> - CSRF-excluded routes: `[ ]` (webhooks with own verification only)
