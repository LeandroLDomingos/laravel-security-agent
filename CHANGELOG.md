# Changelog

All notable changes to Capi Guard will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

## [1.3.3] - 2026-03-27

### Fixed
- Pre-commit hook regex no longer blocks commits that mention `APP_KEY=` or `DB_PASSWORD=` without a real value (e.g. comments, documentation, `.env.example`). The `DB_PASSWORD` pattern now requires an alphanumeric value of at least 4 characters, preventing false positives on strings like `# (APP_KEY=, DB_PASSWORD=)`.

### Added
- Regression test that asserts the hook regex uses the precise credential pattern and does not contain the old over-broad alternative.

## [1.3.2] - 2026-03-27

### Fixed
- Pre-commit hook no longer blocks commits that reference `DB_PASSWORD` or `APP_KEY` as bare variable names (e.g. in PHP regex strings, documentation, or Skill descriptions). Patterns now require a real value after `=`.

### Changed
- `APP_KEY` pattern requires the Laravel `base64:` prefix (40+ chars) to match.
- `DB_PASSWORD` pattern requires at least one non-whitespace character after `=`.

## [1.3.1] - 2026-03-27

### Added
- `GitHistorySanitizationSkill`: extended audit to detect `deploy.php` files and associated credentials in git history.

## [1.3.0] - 2026-03-26

### Added
- **Claude Code integration**: automatically installs the `capi-guard` skill to `~/.claude/skills/capi-guard/SKILL.md` so Claude can audit security without any manual setup.
- **Gemini / Google Antigravity integration**: appends Capi Guard instructions to `~/.gemini/GEMINI.md`.
- **GitHub Copilot agents**: installs `.github/agents/` templates (auth-guard, capi-guard, git-sanitizer, patch-aplicado) and `.github/copilot-instructions.md`.
- Multi-platform deploy — one `npx laravel-security-agent` installs everything across Claude Code, Gemini CLI, and Copilot.
- Expanded `.gitignore` rules covering IP-leak patterns.

### Fixed
- Comment deduplication in `.gitignore` update logic.
- Missing `security-headers` category and scope guards in Gemini template.

## [1.2.1] - 2026-03-26

### Changed
- Agent instructions translated to English for broader accessibility.

## [1.2.0] - 2026-03-26

### Added
- Security skill set with PHP classes: `VulnerabilityScanSkill`, `PatchApplySkill`, `AuthFlowSkill`, `GitHistorySanitizationSkill`, `SecuritySkillSet`.
- `workflow_dispatch` trigger on publish workflow to allow manual npm releases.

## [1.1.0] - 2026-03-26

### Added
- GitHub Copilot support via `.github/copilot-instructions.md`.
- Automated npm publish workflow on `v*` tag push.
- Interactive CLI with Capi Guard branding and capybara mascot.
- `SECURITY.md` and pre-commit hook templates.
- Core modules: `copy-security`, `update-gitignore`, `install-hook` — each with unit tests.

### Fixed
- Incorrect `bin` path in `package.json`.
- Stray leading newline in fresh `.gitignore` output.

---

[Unreleased]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.3.3...HEAD
[1.3.3]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.3.2...v1.3.3
[1.3.2]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/LeandroLDomingos/laravel-security-agent/releases/tag/v1.1.0
