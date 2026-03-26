# 🐾 Capi Guard — Laravel Security Agent

![Capi Guard](public/readme.png)

> *"Your friendly capybara keeping your Laravel app safe."*

A one-command security setup for Laravel projects. Capi Guard installs an AI-powered security audit agent, hardens your `.gitignore`, and adds a pre-commit hook that blocks sensitive files before they reach GitHub.

## Usage

Run at the root of your Laravel project:

```bash
npx laravel-security-agent
```

Capi Guard will ask what you want to install and handle everything interactively.

## What gets installed

| Item | What it does |
|------|-------------|
| `SECURITY.md` | Security audit agent — drop it in any project and tell your AI to "audit security" |
| `.gitignore` entries | Protects `deploy.php`, `.env`, SSH keys, and certificates from being committed |
| Pre-commit hook | Blocks commits of sensitive files and hardcoded credentials automatically |

## Using the security agent

After installing `SECURITY.md`, open the project in **Claude Code** and say:

> **"audit security"**

Capi Guard will:

1. Map all controllers, routes, and models in the project
2. Audit 13 security categories (IDOR, SQL Injection, uploads, credentials, and more)
3. Generate a report with 🔴 Critical / 🟡 Important / 🟢 Suggestion
4. Ask which issues to fix — and wait for your approval before touching any code

The agent automatically responds **in your language** — write in English, Portuguese, Spanish, or any other language and it will reply in kind.

## Security categories audited

| Category | What's checked |
|----------|---------------|
| IDOR | Policies, `authorize()`, ownership scoping |
| SQL Injection | Raw queries, dynamic `orderBy`, file imports |
| Field validation | `min`/`max` on every field, FormRequests |
| File uploads | `mimetypes:` vs `mimes:`, private storage, UUID filenames |
| Mass Assignment | `$fillable` scope, role escalation via request |
| Authorization | Route middleware groups, role checks |
| CSRF | `VerifyCsrfToken`, Sanctum stateful domains |
| XSS | `v-html` usage, HTML purification |
| Rate Limiting | Login, uploads, critical endpoints |
| Session | Secure cookie, same-site, encryption |
| Security Headers | CSP, X-Frame-Options, server header removal |
| Credentials | `Hash::check`, `APP_DEBUG`, log sanitization |
| Git secrets | `.env` in history, hardcoded IPs, deploy paths |

## Pre-commit hook protection

Once installed, the hook blocks commits of:

- `.env` and `deploy.php` files
- SSH keys and certificates (`.pem`, `.key`, `.p12`)
- Diffs containing hardcoded passwords or API keys

## Requirements

- Node.js 18+
- Laravel project (`composer.json` at the root)
- Claude Code (for the AI audit agent)

## License

MIT
