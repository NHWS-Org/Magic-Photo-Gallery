# Bolt's Journal

## 2026-11-01 - Subdirectory Image & Directory Counting Redundant Scans
**Learning:** During folder rendering, counting nested elements for each subfolder performed duplicate directory traversals: `count(list_images($abs))` followed by a separate `scandir($abs)` loop for counting child folders. `list_images()` also incurs `natsort` sorting overhead on paths even though only the count was needed.
**Action:** When inspecting immediate directory contents for multiple metadata metrics (e.g. image count and subdirectory count), consolidate them into a single-pass `scandir()` loop with `is_dir()` and `is_image_ext()` checks.

## 2026-11-11 - Unfiltered EXIF Parsing Overhead in Mixed Image Galleries
**Learning:** In PHP, `exif_read_data()` only supports JPEG and TIFF formats. Calling it indiscriminately on non-JPEG images (PNG, HEIC, HEIF) forces disk I/O and header parsing failure on every file, causing severe latency spikes on image-rich galleries. Additionally, passing `null` as `$required_sections` causes PHP to parse heavy MakerNote binary structures, GPS tags, and embedded thumbnails, even when only timestamp tags are needed.
**Action:** Always filter by supported extensions (`jpg`, `jpeg`, `tif`, `tiff`) before invoking `exif_read_data()`, and explicitly specify only the required sections (e.g. `'EXIF,IFD0'`) to skip unnecessary payload parsing.
