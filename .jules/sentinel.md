# Sentinel's Security Journal

## 2026-11-06 - Authorization Bypass and Path Traversal on Excluded Subdirectories
**Vulnerability:** The helper function `is_excluded_dir($entryName, $relativePath)` only compared the basename or entire relative path against `exclude_dirs` as an exact match (`strcasecmp($rel, $e) === 0`). Passing subdirectories inside excluded or hidden folders (e.g. `log/sub` or `.Magic-Photo-Gallery/sub`) evaded detection. Furthermore, endpoints `gif_progress`, `purge_cache`, and `sync_cache` failed to validate user-supplied directory arguments with `is_excluded_dir`, allowing attackers to probe progress, trigger GIF generation, synchronize, or purge files in excluded directories.
**Learning:** Checking directory access by exact string matching against a target path fails to protect child items unless every individual path segment is checked against the exclusion list and hidden path rule.
**Prevention:** In hierarchical file path validation, decompose paths into discrete segments and verify each segment against exclusion rules, and enforce validation uniformly on all route parameters accepting directory paths.

## 2026-11-01 - Authentication Bypass on Asynchronous Endpoints in Gated Auth Mode
**Vulnerability:** In full authentication mode (`auth_mode === 2`), the application redirects or gates HTML requests with a password login form. However, asynchronous endpoints (`?make_thumb=`, `?make_gif=`, `?gif_progress=`, and `?exif=`) lacked or commented out authentication checks (`// if (!auth_ok())`), allowing unauthenticated attackers to trigger thumbnail/GIF processing (DoS/resource exhaustion) and read image EXIF metadata without authenticating.
**Learning:** Monolithic single-script web apps often separate the page-render gate from helper or asynchronous AJAX endpoints, leaving asynchronous routes exposed if authentication checks are commented out or assumed to be handled by the frontend UI gate.
**Prevention:** Enforce authorization checks at each endpoint or route handler, or reject unauthenticated requests early in the request lifecycle before routing to sub-handlers when the application requires full authentication.
