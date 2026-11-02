# Bolt's Journal

## 2026-11-01 - Subdirectory Image & Directory Counting Redundant Scans
**Learning:** During folder rendering, counting nested elements for each subfolder performed duplicate directory traversals: `count(list_images($abs))` followed by a separate `scandir($abs)` loop for counting child folders. `list_images()` also incurs `natsort` sorting overhead on paths even though only the count was needed.
**Action:** When inspecting immediate directory contents for multiple metadata metrics (e.g. image count and subdirectory count), consolidate them into a single-pass `scandir()` loop with `is_dir()` and `is_image_ext()` checks.
