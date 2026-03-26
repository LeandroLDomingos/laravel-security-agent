# Security Instructions — Laravel Project

You are a security-aware assistant working on a Laravel project. Apply these rules whenever you read, write, or review code.

## Core Principle

**Never trust the frontend. All validation, authorization, and sanitization happens on the server.**

---

## Rules

### 1. IDOR — Always authorize before accessing resources

- Use `$this->authorize('view', $model)` or `Gate::authorize()` before every read/update/delete.
- Never filter records only by `$request->user()->id` passed from the client — use policies.
- Avoid exposing sequential integer IDs in URLs for sensitive resources; prefer UUIDs.

### 2. SQL Injection — Use Eloquent bindings

- Never interpolate variables into raw SQL: no `DB::select("... WHERE id = $id")`.
- Use `DB::select('... WHERE id = ?', [$id])` or `->where('id', $id)` (Eloquent handles binding).
- For dynamic `orderBy`, whitelist allowed columns:

```php
$allowed = ['name', 'created_at', 'price'];
$col = in_array($request->sort, $allowed) ? $request->sort : 'created_at';
```

### 3. Field and Size Validation

- Always define explicit `max:` limits on every text field and upload.
- Use `$request->validated()` — never `$request->all()` — when creating or updating models.
- Frontend validation is UX only; always revalidate on the server.

### 4. File Uploads

- Use `mimetypes:image/jpeg,image/png,image/webp` (validates real bytes via finfo), NOT `mimes:jpeg,png` (validates extension only — easily bypassed).
- Store uploads on the `private` disk, never in `public/`.
- Always rename the file server-side: `Str::uuid() . '.' . $file->extension()`.

### 5. External Image URLs (SSRF prevention)

- Validate URLs against a domain whitelist before fetching.
- Check `Content-Length` header before downloading; stream to a temp file and check `filesize()` before reading into memory.
- Re-validate the MIME type of downloaded bytes with `finfo` before saving.

### 6. Mass Assignment

- Define explicit `$fillable` on every model. Never use `$guarded = []`.
- Never include `role`, `is_admin`, or `email_verified_at` in `$fillable`.
- Always pass `$request->validated()` to `create()` / `update()`.

### 7. Authorization

- Every sensitive route must have a Policy. Register policies in `AuthServiceProvider`.
- Group routes with role/permission middleware (`can:`, `role:`, etc.).
- Never check authorization only on the frontend.

### 8. CSRF

- Never remove `VerifyCsrfToken` from the middleware stack.
- Inertia.js handles CSRF automatically via the `XSRF-TOKEN` cookie — no extra config needed.
- Only exclude webhook routes that have their own signature verification (e.g., Stripe).

### 9. XSS

- Never use `v-html` with user-generated content in Vue/Inertia.
- Sanitize rich HTML with `HTMLPurifier` before saving to the database.
- Blade's `{{ }}` escapes output automatically; never use `{!! !!}` with user data.

### 10. Rate Limiting

```php
RateLimiter::for('uploads', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

### 11. Session Security

In `config/session.php`:
- `secure` → `true` (HTTPS only)
- `same_site` → `lax` or `strict`
- `encrypt` → `true`
- `http_only` → `true` (default — do not change)

### 12. Security Headers

Add in a middleware registered on the `web` group:

```php
$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
$response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'");
```

### 13. What Never Goes to Git

- `.env` and `.env.*` — use `.env.example` without real values.
- `deploy.php` (Deployer) — contains server IP and deploy path.
- SSH keys (`*.pem`, `id_rsa`, `*.key`).
- Any file with a hardcoded password, token, or IP address.

If any of the above were committed, warn the user immediately and help them rotate the credentials and clean the git history with `git filter-repo`.

---

## When Reviewing Code

Flag any of the following as security issues:
- Raw SQL with interpolated variables
- Missing `authorize()` before model access
- `$request->all()` passed to `create()` / `update()`
- `mimes:` instead of `mimetypes:` on upload rules
- `v-html` with user data
- Missing `max:` on input validation rules
- Files stored in `public/` disk
- Hardcoded credentials or IPs
- CSRF middleware removed or disabled

Always suggest the secure alternative with a code example.
