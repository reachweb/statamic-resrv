You are running a QUALITY pass on code that was just written — you are NOT implementing new work.
Do not start, pick, or check off any browser-testing.md tasks. Only improve the files listed at the end.

Work through these two steps in order, applied to the listed PHP files:

1. **code-review skill** — invoke `/code-review` scoped to the changed files: correctness bugs,
   missing/incorrect type declarations, validation, authorization & security, cross-driver SQL
   (SQLite / MySQL / Postgres), Eloquent & casting patterns, and Statamic-addon idioms. Apply the
   high-confidence fixes directly.

2. **simplify skill** — then invoke `/simplify` on the same files: remove duplication and dead
   code, reuse existing helpers / traits / facades (`Price`, `Availability`) instead of reinventing,
   cut needless complexity, and keep each piece at the right altitude. Quality only — do **NOT**
   change behaviour.

Follow the conventions in **CLAUDE.md** (PHP 8.4 explicit return/param types + PHPDoc array shapes,
traits over inheritance, money in integer cents via the `PriceClass` cast, cross-driver migrations
& queries, PHPUnit + Orchestra Testbench). Use `git diff <range> HEAD` (range given below) to see
exactly what changed.

After applying changes, verify everything is still green — fix anything you break:
    vendor/bin/pint --dirty
    composer test        # or a targeted `vendor/bin/phpunit tests/<Dir>` for the files you touched
                         # (the full run has known order-dependent flakes — judge by the relevant suite)

If you changed anything, stage new + modified files and commit it:
    git add -A && git commit -m "chore: quality pass (code-review + simplify)"
If nothing needed changing, make no commit and simply stop. Never edit browser-testing.md in this pass.
