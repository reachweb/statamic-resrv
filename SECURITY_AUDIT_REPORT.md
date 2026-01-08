# Security Audit Report: statamic-resrv

**Date:** 2026-01-08
**Auditor:** Security Analysis
**Package:** reachweb/statamic-resrv
**Context:** Investigation of server compromise affecting three Statamic sites with Monero miner infection via web shell uploads

---

## Executive Summary

This security audit identified a **CRITICAL vulnerability chain** that could have enabled the web shell uploads observed in the compromised sites. The primary attack vector is **CVE-2025-54068 (Livewire RCE)** combined with exploitable patterns in the statamic-resrv package's Livewire components.

**Severity: CRITICAL**

---

## Critical Finding #1: Livewire RCE Vulnerability (CVE-2025-54068)

### Description
The package's `composer.json` requires `"livewire/livewire": "^3.6"`, which allows installation of Livewire versions 3.6.0 through 3.6.3 - **all of which are vulnerable** to CVE-2025-54068 (GHSA-29cq-5w36-x7w3).

### Vulnerability Details
- **CVE ID:** CVE-2025-54068
- **CVSS Score:** 9.2/10 (CRITICAL)
- **Patched Version:** Livewire 3.6.4+
- **Attack Requirements:**
  - No authentication required
  - No user interaction needed
  - Network access only
- **Impact:** Remote Code Execution - Attackers can execute arbitrary commands on the server

### Location
```
composer.json:35
"livewire/livewire": "^3.6"
```

### Recommendation
**IMMEDIATE ACTION REQUIRED:**
1. Update Livewire constraint to `"livewire/livewire": "^3.6.4"`
2. Run `composer update livewire/livewire` on all affected servers
3. Check for indicators of compromise in web logs

---

## Critical Finding #2: Unlocked `$view` Property in Livewire Components

### Description
All 7 Livewire components in this package have a `public string $view` property that is **NOT protected** with the `#[Locked]` attribute. This property is directly used to construct view paths in the `render()` method.

### Affected Files

| File | Line | Property |
|------|------|----------|
| `src/Livewire/AvailabilitySearch.php` | 15 | `public string $view = 'availability-search';` |
| `src/Livewire/AvailabilityResults.php` | 29 | `public string $view = 'availability-results';` |
| `src/Livewire/Checkout.php` | 29 | `public string $view = 'checkout';` |
| `src/Livewire/CheckoutForm.php` | 16 | `public string $view = 'checkout-form';` |
| `src/Livewire/CheckoutPayment.php` | 12 | `public string $view = 'checkout-payment';` |
| `src/Livewire/Extras.php` | 23 | `public string $view = 'extras';` |
| `src/Livewire/Options.php` | 23 | `public string $view = 'options';` |

### Vulnerable Pattern
```php
// Example from src/Livewire/Checkout.php:356-363
public function render()
{
    if ($this->reservationError) {
        return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
    }

    return view('statamic-resrv::livewire.'.$this->view);  // <-- Vulnerable: $view not locked
}
```

### Exploitation Scenario
Combined with CVE-2025-54068 (when exploiting component property hydration), an attacker could:
1. Modify the `$view` property to an arbitrary value
2. Trigger path traversal to load unintended blade templates
3. If combined with any file upload capability, potentially achieve code execution

### Recommendation
Add `#[Locked]` attribute to all `$view` properties:
```php
use Livewire\Attributes\Locked;

#[Locked]
public string $view = 'checkout';
```

---

## Medium Finding #3: Data Import File Upload (CP Protected)

### Description
The `DataImportCpController` accepts CSV file uploads for data import functionality.

### Location
`src/Http/Controllers/DataImportCpController.php:23-61`

### Analysis
```php
$validated = $request->validate([
    'file' => [
        'required',
        'file',
        'mimetypes:text/csv,text/plain',  // MIME type validation present
    ],
]);

$file = $validated['file'];
$path = $file->storeAs('resrv-data-import', 'resrv-data-import.csv');  // Fixed filename
```

### Risk Assessment: LOW-MEDIUM
- **Positive:** MIME type validation, fixed filename, stored outside public directory
- **Positive:** Protected by Statamic CP authentication
- **Risk:** If CP credentials are compromised, could be a secondary attack vector

### Recommendation
1. Add file extension validation in addition to MIME type
2. Consider adding virus/malware scanning for uploaded files
3. Ensure proper file permissions on storage directory

---

## Low Finding #4: Webhook CSRF Bypass (Expected Behavior)

### Description
The webhook routes bypass CSRF protection.

### Location
`routes/web.php:9-10`
```php
Route::get('/resrv/api/webhook', 'WebhookController@index')->withoutMiddleware([VerifyCsrfToken::class]);
Route::post('/resrv/api/webhook', 'WebhookController@store')->withoutMiddleware([VerifyCsrfToken::class]);
```

### Risk Assessment: LOW
This is expected behavior for external webhooks. The `StripePaymentGateway` properly validates Stripe signatures:
```php
// src/Http/Payment/StripePaymentGateway.php:171-194
$event = Webhook::constructEvent(
    $request->getContent(),
    $sig_header,
    $this->getWebhookSecret($reservation),
);
```

### Recommendation
No action required - signature verification is properly implemented.

---

## Items Reviewed - No Issues Found

| Category | Result |
|----------|--------|
| `eval()`, `exec()`, `shell_exec()`, `system()`, `passthru()` | No PHP usage found (JS build tools only) |
| `unserialize()` on user input | Not found |
| `request()->file()` or `file_put_contents` | Only in protected CP controller |
| Storage::put with user-controlled paths | Not found |
| ExpectsJson bypassing security | Not found |
| Unprotected routes | CP routes properly protected via Statamic |

---

## Attack Chain Analysis

Based on the investigation, the most likely attack chain for the Monero miner infection:

```
1. Attacker identifies vulnerable Livewire version (<3.6.4) via fingerprinting
                              ↓
2. Exploits CVE-2025-54068 on any public Livewire component
   (e.g., availability-search, checkout)
                              ↓
3. Achieves Remote Code Execution
                              ↓
4. Uploads web shell to public/ directory
                              ↓
5. Deploys Monero miner
```

---

## Immediate Remediation Steps

### Priority 1 (CRITICAL - Do Immediately)
1. **Update Livewire** on all servers:
   ```bash
   composer require livewire/livewire:^3.6.4
   ```

2. **Check for compromise indicators:**
   ```bash
   # Check for suspicious files in public/
   find public/ -name "*.php" -mtime -30

   # Check for unusual processes
   ps aux | grep -E "(minerd|xmrig|cryptonight)"

   # Review web server access logs for /livewire/update requests
   grep -r "livewire/update" /var/log/nginx/access.log
   ```

3. **Clean compromised servers** - Re-deploy from known-good backups

### Priority 2 (HIGH - Within 24 Hours)
4. Add `#[Locked]` attribute to all `$view` properties in Livewire components

5. Update `composer.json` minimum Livewire version:
   ```json
   "livewire/livewire": "^3.6.4"
   ```

### Priority 3 (MEDIUM - Within 1 Week)
6. Implement Web Application Firewall (WAF) rules
7. Enable file integrity monitoring on public/ directory
8. Review and rotate any potentially compromised credentials

---

## Conclusion

The **statamic-resrv package itself does not contain the vulnerability** that enabled the attack. However:

1. The package's Livewire dependency constraint allows vulnerable versions
2. The unlocked `$view` properties increase attack surface when combined with Livewire vulnerabilities
3. Sites running Livewire < 3.6.4 were directly vulnerable to CVE-2025-54068 RCE

The root cause of the compromise is **CVE-2025-54068 in Livewire**, not a vulnerability in statamic-resrv. However, statamic-resrv should update its dependency constraints and add defensive coding practices (Locked attributes).

---

## References

- [CVE-2025-54068 / GHSA-29cq-5w36-x7w3](https://github.com/livewire/livewire/security/advisories/GHSA-29cq-5w36-x7w3)
- [Livewire Security Releases](https://github.com/livewire/livewire/releases/tag/v3.6.4)
