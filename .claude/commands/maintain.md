---
description: Run maintenance tasks - check issues, PRs, dependencies, PHP versions, and competitor libraries
---

# Serializor Maintenance

Perform maintenance tasks for the Serializor project.

## 1. Check CI Status First

```bash
gh run list --limit 5
```

If any recent runs failed, investigate and fix before proceeding.

## 2. Check PHP Version Support

Current support: PHP 8.2+ (tested on 8.2, 8.3, 8.4, 8.5)

Check www.php.net for:
- New PHP versions released or in RC
- PHP versions reaching end-of-life

If a new PHP version is available:
1. Add it to `.github/workflows/tests.yml` matrix
2. Create test fixtures for new features if needed
3. Run tests locally first
4. Update composer.json if minimum version changes
5. Update README.md version badge

## 3. Check Serializor Issues & PRs

```bash
gh issue list --repo frodeborli/serializor --state open
gh pr list --repo frodeborli/serializor --state open
```

For issues: suggest fixes or draft responses
For PRs: review and provide feedback

## 4. Check Competitor Libraries for Test Ideas

```bash
gh issue list --repo opis/closure --state all --limit 10
gh issue list --repo laravel/serializable-closure --state all --limit 10
```

For each interesting issue:
- Check if Serializor handles the case
- Add test if we do handle it (documents our coverage)
- Flag if we don't handle it (potential improvement)

## 5. Check Dependencies

Review composer.json dev dependencies for updates.

## 6. Publishing Checklist

Before any release:
1. Run full test suite locally: `vendor/bin/pest`
2. Commit and push changes
3. Wait for CI to complete: `gh run watch`
4. Only tag after CI passes
5. Verify tag pushed correctly

NEVER publish if tests fail locally or in CI.

## Summary Format

Provide:
- **CI Status**: Current build status (must be green)
- **PHP Versions**: Any new versions to add
- **Issues**: Open issues needing attention
- **PRs**: Open PRs needing review
- **Test Ideas**: Edge cases from competitor issues
- **Dependencies**: Updates available
- **Action Items**: Prioritized list
