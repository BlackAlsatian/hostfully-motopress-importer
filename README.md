# Hostfully → MotoPress Importer

One-time importer that pulls Hostfully properties into MotoPress Hotel Booking.

## What It Does
- Imports Hostfully properties into MotoPress Accommodation Types
- Creates Rates, Units, Amenities, Categories/Tags, Attributes, Services
- Downloads featured images and galleries (up to your configured limit)
- Adds a default “All Year” season if none exists, and writes season prices so the UI shows pricing

## Quick Start
1. Install and activate the plugin.
2. Go to **Hostfully Import** in WP Admin.
3. Enter **API Key** and **Agency UID**, then **Save Settings**.
4. Click **Sync Amenities Catalog** once (recommended).
5. Run **Import One** to validate.
6. Run **Bulk Import** to bring in everything.

## Settings
- **API Key**: Hostfully API key.
- **Agency UID**: Hostfully agency UID.
- **Max photos per property**: Cap image downloads per property (set to `0` for unlimited).
- **Bulk import limit**: How many properties a single bulk run will import.
- **API enrichment**: Enables extra Hostfully calls for missing data.
- **Amenities cache hours**: How long to cache per‑property amenities data.
- **Verbose logging**: Adds extra debug information to the import log.

## Notes
- If you need all images, set **Max photos per property** to `0` (unlimited), but expect slower imports.
- Image optimization is best handled **after** import using an optimizer plugin or batch process.
- iCal links are not required for import; availability sync can be configured separately in MotoPress.

## License
MIT — see `LICENSE`.
