# Validated Issues

## 1) Editing a specific-entry rate silently detaches all entry assignments
- Reason: `correctness`
- What is wrong: The edit form depends on `data.entries` to prefill assigned entries, but the list endpoint used to open the editor does not include that relationship.
- Why it is wrong: For `apply_to_all = false`, opening and saving a rate without manually reselecting entries sends `entries: []`, and the controller syncs that empty array, permanently removing all assignments.
- Exact location: `src/Http/Controllers/RateCpController.php:29`, `resources/js/components/RatesList.vue:122`, `resources/js/components/RatePanel.vue:471`, `resources/js/components/RatePanel.vue:505`, `src/Http/Controllers/RateCpController.php:93`
- Concrete fix steps:
1. Return assigned entries with rate payloads used for editing (for example `with('entries:item_id,title')` in `index()` and/or load full rate on edit).
2. In the panel, avoid defaulting to `[]` for existing specific rates unless assignments were actually loaded.
3. Add a regression test: create `apply_to_all=false` rate with assignments, open/edit/save unchanged payload, assert pivot rows remain intact.
- Alignment with TASKS scope: Task 6.1 adds CRUD for collection/entry-scoped rates; edit flows must preserve assignment state.

## 2) Single-sided date restrictions are cleared when opening the edit panel
- Reason: `correctness`
- What is wrong: The panel only initializes range state when both `date_start` and `date_end` exist, then the `date` watcher writes both submit fields to `null` when range state is `null`.
- Why it is wrong: Rates that validly use only one bound (`date_start` only or `date_end` only) lose that restriction on save even if the user made no date changes.
- Exact location: `resources/js/components/RatePanel.vue:436`, `resources/js/components/RatePanel.vue:474`
- Concrete fix steps:
1. Preserve original `submit.date_start`/`submit.date_end` when editing and no range is selected.
2. Support partial ranges explicitly (or keep separate start/end inputs) instead of forcing both-or-none.
3. Add a regression test for editing a rate with only `date_start` set and asserting the value remains unchanged after save.
- Alignment with TASKS scope: Task 6.1 requires restriction fields to round-trip correctly in CP CRUD.
