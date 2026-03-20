# Hostfully → MotoPress Importer

Import Hostfully properties into MotoPress Hotel Booking with a guided admin UI, safe re-imports, and detailed logging.

## Overview
This plugin pulls Hostfully properties into MotoPress Hotel Booking (WordPress) and creates the required MotoPress data for you: accommodations, rates, units, amenities, attributes, galleries, and services. It’s designed for full initial migrations and repeatable imports without manual cleanup.

## Features
- Imports Hostfully properties as MotoPress Accommodation Types.
- Creates Rates and Accommodation Units per property.
- Syncs and assigns amenities to the MotoPress amenities taxonomy.
- Maps categories/tags based on Hostfully property metadata, plus links `property_type` as category and `location` as tag for MotoPress templating.
- Creates and assigns Room Attributes (beds, bedrooms, bathrooms, guests).
- Downloads featured images and gallery photos with a configurable limit.
- Ensures an “All Year” season exists and writes season prices so rates display in the UI.
- Bulk import via AJAX with progress logging, verbose mode, and summary.
- One-click post-import sync to run fee sync and guest-capacity sync in the correct order.
- Single import for testing with the same log panel and spinner.
- Import by UID list when the Hostfully property list endpoint is incomplete.
- Compare pasted UIDs to already-imported ones and isolate missing entries.
- iCal audit report to compare channel links vs available iCal feeds.
- Link Hostfully iCal feeds into MotoPress external calendars (with safe skip/overwrite option).
- Fee sync drafts stale imported properties when Hostfully property detail fetches return 404.

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
2. **Bulk Import / Update** for the main import job.
3. Enable **Update existing imports too** when you want to refresh properties that are already imported.
4. Run **Post-Import Sync** after a bulk refresh. This updates property fees, native extra guest pricing, guest-capacity fields, and drafts stale properties when Hostfully returns 404 for property detail fetches.
5. Use **Sync Amenities Catalog** only when Hostfully amenities have changed or amenity mapping needs to be refreshed.
6. Use **Import One** to validate one property before running a full bulk job.
7. Use **Import by UID List** only when Hostfully’s property list is incomplete. Use “Compare & Show Missing” to isolate missing UIDs.
8. Use **iCal Links Audit** to see which properties have channel links but no iCal feeds.
9. Use **Link iCal Feeds** to write Hostfully iCal URLs into MotoPress external calendars (skips rooms that already have calendars unless overwrite is checked).

## Meta Fields Added
These meta keys are stored on the Accommodation Type (mphb_room_type) and can be used in Elementor or custom templates.

| Field | Meta Key |
| --- | --- |
| Hostfully property UID | _hostfully_property_uid |
| Summary | _hostfully_desc_summary |
| Short summary | _hostfully_desc_short_summary |
| Access | _hostfully_desc_access |
| Transit | _hostfully_desc_transit |
| Interaction | _hostfully_desc_interaction |
| Neighbourhood | _hostfully_desc_neighbourhood |
| Space | _hostfully_desc_space |
| House manual | _hostfully_desc_house_manual |
| Notes | _hostfully_desc_notes |
| Address line 1 | _hostfully_address_line1 |
| Address line 2 | _hostfully_address_line2 |
| City | _hostfully_city |
| State/Region | _hostfully_state |
| Postal code | _hostfully_postcode |
| Country code | _hostfully_country_code |
| Full address | _hostfully_full_address |
| Latitude | _hostfully_lat |
| Longitude | _hostfully_lng |
| Max guests | _hostfully_max_guests |
| Base guests | _hostfully_base_guests |
| Beds | _hostfully_beds |

Additional internal meta used by the importer:
- _hostfully_photo_map (tracks Hostfully image UID to attachment mapping)
- _hostfully_service_key (stored on imported services)
- _hostfully_amenity_uid (stored on amenity terms)

Usage note: The meta keys in the table above are intended for Elementor Dynamic Tags or custom templates. The internal meta keys listed here are used for import bookkeeping and should not be displayed.

## How Pricing Is Stored
MotoPress rates use **season prices** to display in the UI. If no seasons exist, the importer auto-creates an **All Year** season and writes a base price there. This ensures rates show up immediately in the Rates screen without manual setup.

## Image Handling
- Downloads featured image + gallery images.
- Respects **Max photos per property**.
- If you plan to import all images for large portfolios, expect longer imports.
- Post-import image optimization is recommended via a dedicated optimizer plugin or batch process.

## Notes
- Import is **idempotent**: re-running will update existing items unless you choose otherwise.
- The normal operator flow is: **Bulk Import / Update**, then **Post-Import Sync**.
- iCal sync is not required for importing properties and rates.
- Availability sync can be handled separately via MotoPress + external iCal setup.
- If Hostfully’s `/properties` list does not return all properties, use the **Import by UID List** tool to fill the gaps.
- External calendar syncing relies on MotoPress’s sync queue/cron, so verify it on your live domain if localhost appears stuck.
- Location attribute terms now normalize common South African aliases during import to reduce duplicate filter values.
- The normalized `location` attribute is also linked to room type tags, and `property_type` is linked to room type categories.

## Troubleshooting
If something looks off:
1. Enable **Verbose logging**.
2. Run a **Single Import** and review the log panel.
3. Check the “Last error” section in the admin screen.

## License
MIT — see `LICENSE`.
