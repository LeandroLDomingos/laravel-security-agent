# Contributing to Capi Guard

## Version Bump Checklist

Every time `version` in `package.json` is changed, the following steps are **required**:

### 1. Update CHANGELOG.md

Follow the [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) format already used in this project.

**Move items from `[Unreleased]` to a new versioned section:**

```markdown
## [1.x.x] - YYYY-MM-DD

### Added
- ...

### Changed
- ...

### Fixed
- ...
```

**Update the diff links at the bottom of CHANGELOG.md:**

```markdown
[Unreleased]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.x.x...HEAD
[1.x.x]: https://github.com/LeandroLDomingos/laravel-security-agent/compare/v1.x.y...v1.x.x
```

### 2. Semantic Versioning Rules

| Change type | Bump |
|---|---|
| New feature, new skill, new scan category | `MINOR` — e.g. `1.3.x` → `1.4.0` |
| Bug fix, behavior correction, regex update | `PATCH` — e.g. `1.3.4` → `1.3.5` |
| Breaking change (API, CLI flags, schema) | `MAJOR` — e.g. `1.x.x` → `2.0.0` |

### 3. Section Reference

Use only the sections already established in this project:

- `Added` — new features, skills, or scan categories
- `Changed` — changes to existing behavior
- `Fixed` — bug fixes and corrections
- `Removed` — removed features or deprecated items

---

Adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).
