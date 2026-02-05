# Hostfully → MotoPress Importer

Import Hostfully properties into MotoPress Hotel Booking with a guided admin UI, safe re-imports, and detailed logging.

## Overview
This plugin pulls Hostfully properties into MotoPress Hotel Booking (WordPress) and creates the required MotoPress data for you: accommodations, rates, units, amenities, attributes, galleries, and services. It’s designed for full initial migrations and repeatable imports without manual cleanup.

## Features
- Imports Hostfully properties as MotoPress Accommodation Types.
- Creates Rates and Accommodation Units per property.
- Syncs and assigns amenities to the MotoPress amenities taxonomy.
- Maps categories/tags based on Hostfully property metadata.
- Creates and assigns Room Attributes (beds, bedrooms, bathrooms, guests).
- Downloads featured images and gallery photos with a configurable limit.
- Ensures an “All Year” season exists and writes season prices so rates display in the UI.
- Bulk import via AJAX with progress logging, verbose mode, and summary.
- Single import for testing with the same log panel and spinner.

## Requirements
- WordPress 6.x
- MotoPress Hotel Booking plugin (active)
- Hostfully API key + Agency UID

## Installation
1. Download or clone this repository.
2. Copy the plugin folder into `wp-content/plugins/`.
3. Activate **Hostfully → MotoPress Importer** in WordPress Admin.

## Configuration
Go to **Hostfully Import** in WP Admin and set:
- **API Key**: Your Hostfully API key.
- **Agency UID**: Your Hostfully agency UID.
- **Max photos per property**: Limit downloads per property. Set to `0` for unlimited.
- **Bulk import limit**: Number of properties processed per bulk run.
- **API enrichment**: Allows extra Hostfully calls for missing data.
- **Amenities cache hours**: Cache duration for per-property amenities.
- **Verbose logging**: Adds detailed API and processing steps to the log.

## Usage
1. **Save Settings** after entering API Key and Agency UID.
2. **Sync Amenities Catalog** once (recommended).
3. **Import One** to verify configuration and output.
4. **Bulk Import** to process all remaining properties.

## How Pricing Is Stored
MotoPress rates use **season prices** to display in the UI. If no seasons exist, the importer auto-creates an **All Year** season and writes a base price there. This ensures rates show up immediately in the Rates screen without manual setup.

## Image Handling
- Downloads featured image + gallery images.
- Respects **Max photos per property**.
- If you plan to import all images for large portfolios, expect longer imports.
- Post-import image optimization is recommended via a dedicated optimizer plugin or batch process.

## Notes
- Import is **idempotent**: re-running will update existing items unless you choose otherwise.
- iCal sync is not required for importing properties and rates.
- Availability sync can be handled separately via MotoPress + external iCal setup.

## Troubleshooting
If something looks off:
1. Enable **Verbose logging**.
2. Run a **Single Import** and review the log panel.
3. Check the “Last error” section in the admin screen.

## License
MIT — see `LICENSE`.

