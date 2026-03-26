# 🐾 Capi Guard — Laravel Security Agent

![Capi Guard](public/readme.png)

> *"Your friendly capybara keeping your Laravel app safe."*

A one-command security toolkit for Laravel projects. Run it once and Capi Guard automatically deploys AI-powered security agents into **Claude Code**, **GitHub Copilot**, and **Google Gemini/Antigravity** — plus hardens your project with a pre-commit hook and `.gitignore` rules.

## Usage

Run at the root of your Laravel project:

```bash
npx laravel-security-agent
```

No prompts. Everything installs automatically.

## What Gets Installed

### Global AI Agents (user-level, one-time)

| Platform | What installs | Where |
|---|---|---|
| **Claude Code** | `capi-guard` skill | `~/.claude/skills/capi-guard/SKILL.md` |
| **GitHub Copilot** | `.agent.md` sub-agents | `.github/agents/` |
| **Gemini / Google Antigravity** | Capi Guard instruction block | `~/.gemini/GEMINI.md` |

After install, just ask your AI to "audit security" — it knows the full workflow and all 4 callable skills.

### Project Files

| File | Purpose |
|---|---|
| `SECURITY.md` | Claude Code security agent rules |
| `.github/copilot-instructions.md` | Copilot security agent rules |
| `.gitignore` entries | Blocks `deploy.php`, `.env`, SSH keys |
| `.git/hooks/pre-commit` | Blocks commits of sensitive files |
| **Antigravity PHP Agent** | Full autonomous agent in `app/Agents/` |

---

## 🤖 The Antigravity Agent (PHP)

The flagship feature. Capi Guard scaffolds a production-ready, zero-trust AI agent directly into your Laravel `app/` directory. It exposes `/api/agent/invoke` — an endpoint that any AI can call to run **deterministic, local PHP analysis** without sending your codebase to the cloud.

### Available Skills

| Skill | What it does |
|---|---|
| 🔍 **vulnerabilityScan** | Regex scan across 13 security categories in any path |
| 🛡️ **analyzeAuthFlow** | PHP Reflection traces controllers, finds missing `authorize()` calls |
| 🩹 **applySecurityPatch** | Applies CVE patches with backup + runs Artisan commands post-patch |
| 🧹 **sanitizeGitHistory** | Generates a `git filter-repo` script to purge secrets from full git history |

### Copilot Sub-Agents

Four specialised agents are installed into `.github/agents/`:

- `@capi-guard` — Full-stack auditor. Runs scans, analyzes auth, patches files.
- `@patch-aplicado` — Surgical CVE patcher with backup and Artisan validation.
- `@auth-guard` — Read-only analyst. Finds authorization gaps and suggests Policies.
- `@git-sanitizer` — Git history auditor and scrubber.

### Agent Setup

1. Add the route to `routes/api.php`:
   ```php
   use App\Http\Controllers\AgentController;
   use App\Http\Middleware\ZeroTrustMiddleware;

   Route::post("/api/agent/invoke", AgentController::class)
        ->middleware(["auth:sanctum", ZeroTrustMiddleware::class]);
   ```
2. Issue a Sanctum token with the `agent:invoke` ability.
3. Register `.github/manifest.json` in your GitHub Copilot Extension settings.
4. *(Optional)* Publish the config to customize CVE patches and rate limits:
   ```bash
   php artisan vendor:publish --tag=security-agent-config
   ```

---

## Security Categories Audited

When you ask any AI to "audit security", it checks 13 categories:

| Category | What gets checked |
|---|---|
| **IDOR** | Policies, `authorize()`, ownership scoping |
| **SQL Injection** | Raw queries, dynamic `orderBy` whitelisting |
| **Mass Assignment** | Empty `$guarded`, role escalation via `$request->all()` |
| **XSS** | `{!! !!}` in Blade, `v-html` in Vue |
| **CSRF** | Exclusions in `VerifyCsrfToken`, unprotected POST routes |
| **Authorization** | Missing `Gate::authorize()` in controllers |
| **File Uploads** | `mimetypes:` vs `mimes:`, private storage, server-side naming |
| **Rate Limiting** | Public endpoints without throttle middleware |
| **Security Headers** | Missing CSP, X-Frame-Options, HSTS |
| **Credentials in Code** | Hardcoded secrets and API keys |
| **Git Secrets** | `.env`, `deploy.php`, SSH keys in git history |

## Requirements

- Node.js 18+
- Laravel project (`composer.json` at the root)
- *For the PHP Agent:* PHP 8.2+ and Laravel Sanctum

## License

MIT
