# Validated Issues

## 1) `reservationFromUri()` allows reservation enumeration by numeric ID
- Reason: `security`
- What is wrong: The tag now accepts only `res_id` and returns the confirmed reservation directly.
- Why it is wrong: This removes the previous integrity check and makes reservation lookup predictable (`/path?res_id=123`), enabling unauthorized disclosure of reservation data.
- Exact location: `src/Tags/Resrv.php:23`
- Concrete fix steps:
1. Require a signed token (or restore `ref` + `hash` validation) instead of plain numeric ID lookup.
2. Validate the signature with `hash_equals()` against a server-side HMAC secret.
3. Return `404` on failed signature validation to avoid ID probing.
4. Add a regression test that lookup without a valid signature is rejected.
- Alignment with TASKS scope: Supports secure checkout/reservation display behavior while migrating to rates; this is not a rate-model requirement and should not regress.

## 2) Multi-rate partial availability updates fail validation incorrectly
- Reason: `correctness`
- What is wrong: `AvailabilityCpRequest` validates `rate_ids`, but `ResrvAvailabilityExists` still reads legacy `advanced` data and falls back to `['none']`.
- Why it is wrong: For entries with multiple rates, fallback validation queries all rates and compares total row count to expected day count, so valid single-rate partial updates are rejected.
- Exact location: `src/Http/Requests/AvailabilityCpRequest.php:16`, `src/Rules/ResrvAvailabilityExists.php:24`
- Concrete fix steps:
1. Update `ResrvAvailabilityExists` to read `rate_ids` (and iterate each selected rate) instead of legacy `advanced`.
2. Pass each selected rate ID into `Availability::itemsExistAndHavePrices(...)`.
3. Keep a backward-compatibility branch only for legacy payloads, then remove once deprecated.
4. Add a regression test for a multi-rate entry where one selected rate is partially updated and should succeed.
- Alignment with TASKS scope: Task 6.2/7.1/11.1 fully migrate CP availability editing from property/advanced to rate IDs; validation must follow the new payload shape.
