# 🐾 Capi Guard — Laravel Security Agent

![Capi Guard](public/readme.png)

> *"Your friendly capybara keeping your Laravel app safe."*

A one-command security toolkit for Laravel projects. Capi Guard installs AI-powered security audit agents, hardens your `.gitignore`, adds a pre-commit hook, and provides an **autonomous Antigravity PHP Agent** compatible with GitHub Copilot Skills.

## Usage

Run at the root of your Laravel project:

```bash
npx laravel-security-agent
```

Capi Guard will ask what you want to install and handle everything interactively.

## Installation Options

You can install any combination of the following:

| Option | What it installs | Best for |
|--------|-----------------|----------|
| **Claude Code rules** | `SECURITY.md` | Claude Code CLI users |
| **Copilot rules** | `.github/copilot-instructions.md` | Copilot Editor users |
| **Update .gitignore** | Enforces Laravel security patterns | Preventing secret leaks |
| **Pre-commit hook** | Blocks commits of sensitive keys | CI/CD discipline |
| **Antigravity Agent** | Complete PHP 8.3 Agent + Copilot Skills | **Advanced Autonomous Security** |

---

## 🤖 The Antigravity Agent (PHP)

This is the flagship feature. When you select the **Antigravity Agent**, Capi Guard scaffolds a production-ready, zero-trust AI agent directly into your Laravel `app/` directory.

It exposes an API endpoint (`/api/agent/invoke`) that GitHub Copilot can call to perform **deterministic, local code analysis** natively in PHP.

### Available Skills

Copilot uses these skills to analyze your code instantly without sending your entire codebase to the LLM:

1. 🔍 **vulnerabilityScan**: Uses Regex to scan 13 security categories across any directory.
2. 🛡️ **analyzeAuthFlow**: Uses PHP Reflection to trace controllers and detect missing `$this->authorize()` calls.
3. 🩹 **applySecurityPatch**: Applies surgical CVE patches and automatically runs Artisan commands afterwards (`optimize:clear`, `test`, etc).
4. 🧹 **sanitizeGitHistory**: Generates a `git filter-repo` script with a blob-callback to purge old `.env` files and redact naked passwords/IPs from your *entire* Git history commits.

### Copilot Sub-Agents Included

The installer also copies 3 specialised Copilot Agents into `.github/agents/`. You can invoke them in the Copilot Chat:

- `@capi-guard` — Full stack auditor. Runs scans, analyzes auth, and patches files following a strict 6-step workflow.
- `@patch-aplicado` — Surgical CVE patcher. Always creates a backup, runs the patch, and validates with Artisan.
- `@auth-guard` — Read-only analyst. Only reads code to find authorization gaps and suggest Policies.

### How to configure the Agent

1. Add the route to `routes/api.php`:
   ```php
   use App\Http\Controllers\AgentController;
   use App\Http\Middleware\ZeroTrustMiddleware;

   Route::post("/api/agent/invoke", AgentController::class)
        ->middleware(["auth:sanctum", ZeroTrustMiddleware::class]);
   ```
2. Issue a Sanctum token with the `agent:invoke` ability for Copilot.
3. Register `.github/manifest.json` in your GitHub Copilot Extension settings.
4. *(Optional)* Publish the config file to edit CVE patches and rate limits:
   ```bash
   php artisan vendor:publish --tag=security-agent-config
   ```

---

## The Passive Rules (Claude & Copilot)

If you just want instructions for your AI without the full PHP agent, install `SECURITY.md` or `.github/copilot-instructions.md`.

When you ask the AI to "audit security", it will check for 13 categories including:

| Category | What gets checked |
|----------|---------------|
| **IDOR** | Policies, `authorize()`, ownership scoping |
| **SQL Injection** | Raw queries, dynamic `orderBy` whitelisting |
| **Mass Assignment** | Empty `$guarded`, role escalation via `$request->all()` |
| **File Uploads** | `mimetypes:` vs `mimes:`, private storage, server-side naming |
| **CSRF & XSS** | Exclusions in `VerifyCsrfToken`, `v-html` usage |
| **Git secrets** | `APP_DEBUG`, `.env` in history, hardcoded credentials |

## Pre-commit hook & .gitignore

The `.gitignore` updater enforces patterns for `deploy.php`, `auth.json`, e `.phpunit.result.cache`.
The pre-commit hook automatically blocks attempts to `git commit` any `.env` file, `.pem`, `.p12`, or `.key` file.

## Requirements

- Node.js 18+ (to run the npx command)
- Laravel project (`composer.json` at the root)
- *For the Agent:* PHP 8.2+ and Laravel Sanctum

## License

MIT
