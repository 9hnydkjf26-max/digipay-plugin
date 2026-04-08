## Summary

<!-- 1-3 sentences describing what this PR changes and why. -->

## Closes

<!-- List support tickets / issues this PR resolves, e.g. "Closes #123". -->

## Catalog update (required if this PR closes a support issue)

The Digipay support model relies on the issue catalog
(`secure_plugin/support/class-issue-catalog.php`) being the single source of
truth for known merchant problems. Every fix that resolves a real merchant
issue must produce a reusable artifact.

Pick **one** of the following and check it:

- [ ] **New catalog entry added** — `class-issue-catalog.php` and
  `tests/IssueCatalogTest.php` both updated. The new entry has at least one
  positive detector test.
- [ ] **Existing catalog entry marked `fixed_in`** — the entry that
  corresponded to the bug now has `'fixed_in' => '<this release version>'` so
  it auto-suppresses on installs running this version or later.
- [ ] **No catalog entry needed** — explain below using the
  `no-catalog-entry:` line. Use this only for pure refactors, doc-only
  changes, or fixes with no merchant-visible behavior change.

```
no-catalog-entry: <reason>
```

## Test plan

- [ ] `cd secure_plugin && composer test` passes locally
- [ ] New tests cover the fix
- [ ] Manual verification steps:
  - <!-- list -->

## Risk / rollback

<!-- What's the blast radius? How do we roll back if this breaks production? -->
