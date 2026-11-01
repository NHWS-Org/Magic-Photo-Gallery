# Sentinel's Security Journal

## 2026-11-01 - Authentication Bypass on Asynchronous Endpoints in Gated Auth Mode
**Vulnerability:** In full authentication mode (`auth_mode === 2`), the application redirects or gates HTML requests with a password login form. However, asynchronous endpoints (`?make_thumb=`, `?make_gif=`, `?gif_progress=`, and `?exif=`) lacked or commented out authentication checks (`// if (!auth_ok())`), allowing unauthenticated attackers to trigger thumbnail/GIF processing (DoS/resource exhaustion) and read image EXIF metadata without authenticating.
**Learning:** Monolithic single-script web apps often separate the page-render gate from helper or asynchronous AJAX endpoints, leaving asynchronous routes exposed if authentication checks are commented out or assumed to be handled by the frontend UI gate.
**Prevention:** Enforce authorization checks at each endpoint or route handler, or reject unauthenticated requests early in the request lifecycle before routing to sub-handlers when the application requires full authentication.
