# Kaltura Entries and Metadata Account Export Utility
Export all Kaltura entries, categories and chosen metadata profile (or filtered list of entries) in a specified Kaltura parents account and all their sub-accounts. It then saves the entry and metadata fields (Entry per line) into an Excel file.

# Configuration
Before running the script, follow these steps:

1. Edit `kaltura-entries-export-excel.php` and set the following parameters:  
	* `PARENT_PARTNER_IDS`: An array of parent kaltura partner IDs and their respective API ADMIN Secrets, to get entries export for. If the ID provided is not a parent account the entries of that account will be exported
	* `SERVICE_URL`: the full base URL to the Kaltura API endpoint (https://www.kaltura.com when using SaaS)
	* `KS_EXPIRY_TIME`: Session duration; since the execution time will vary based on the number of records, be sure to set the duration accordingly.
	* `ENTRY_STATUS_IN`: defines the entry statuses to retrieve  
	* `ENTRY_TYPE_IN`: defines the entry types to retrieve 
	* `ENTRY_FIELDS`: list of entry fields to export (excluding custom metadata, that is set in `METADATA_PROFILE_ID`), `entryId`, captions and categories will be added to this list
	* `PARENT_CATEGORIES`: optional; IDs of Kaltura Categories you'd like to limit the export to
	* `FILTER_TAGS`: tags to filter by (`tagsMultiLikeOr`)
	* `DEBUG_PRINTS`: set to true if you'd like the script to output logging to the console (this is different from the `KalturaLogger`)
	* `CYCLE_SIZES`: determines how many entries will be processed in each multi-request call
	* `METADATA_PROFILE_ID`: the profile id of the custom metadata profile to get its fields per entry
	* `ONLY_CAPTIONED_ENTRIES`: when set to `true` only entries with caption assets be included in the output
	* `GET_CAPTIONS`: if set to false will not bring captions, and will ignore the above configs too
	* `GET_CAPTION_URLS`: when set to `true`, caption download URLs will be included
	* `GET_ENTRY_USAGE`: if true, each entry live will also include the usage of that particular entry
	* `REPORT_USAGE_DATE_START`: the date to get storage usage per entry for
	* `REPORT_USAGE_DATE_END`: the date to get storage usage per entry for
	* `REPORT_TIMEZONE_OFFSET`: timezone offset for getting usage storage report for, negative 180 is Israel timezone
	* `GET_ENTRY_SCHEDULED_EVENTS`: if true, will also export all simulive events for this entry (scheduledEvents of type live with templateEntryId being that entry)
	* `ENTRY_SCHEDULED_EVENTS_IN_COLUMNS`: if true, it will be assumed that each live entry only has a single scheduled event, and instead of being added in single multi-line cell as string, the scheduled events will be added into 3 new columns: scheduled_event_source_id, scheduled_event_start_time, scheduled_event_end_time
	* `ERROR_LOG_FILE`: the name of the `KalturaLogger` export file
	* `STOP_DATE_FOR_EXPORT`: defines a stop date for the entries iteration loop. Any time string supported by `strtotime` can be passed. If this is set to null or -1, it will be ignored and the script will run through the entire library until it reaches the first created entry. e.g. '45 days ago' or '01/01/2017', etc. 
	* `LIMIT_TOTAL_ENTRIES`: limit the number of entries to export, if false or null will be ignored
	* `$excelFieldFormats`: array of excel cell formats for the exported Kaltura fields, learn more about [excel cell formats here](https://support.microsoft.com/en-us/office/number-format-codes-5026bbd6-04bc-48cd-bf33-80f18b4eae68).
	* `$exportFileNameTemplate`: sets the name of the output excel file (do not include the file extension).
2. Adjust `$excelFieldFormats` accordingly to get the desired cell formats in the excel file.  
3. After setting the values for the above parameters, run the script using PHP CLI:  
```bash
$ composer install
$ php kaltura-entries-export-excel.php
```

# How you can help (guidelines for contributors) 
Thank you for helping Kaltura grow! If you'd like to contribute please follow these steps:
* Use the repository issues tracker to report bugs or feature requests
* If you extend or fix anything in the code, please submit your patch as a GitHub pull-request
* Sign the [Kaltura Contributor License Agreement](https://agentcontribs.kaltura.org/)
* Read [Contributing Code to the Kaltura Platform](https://github.com/kaltura/platform-install-packages/blob/master/doc/Contributing-to-the-Kaltura-Platform.md)

# Where to get help
* Join the [Kaltura Community Forums](https://forum.kaltura.org/) to ask questions or start discussions
* Read the [Code of conduct](https://forum.kaltura.org/faq) and be patient and respectful

# Get in touch
You can learn more about Kaltura and start a free trial at: http://corp.kaltura.com    
Contact us via Twitter [@Kaltura](https://twitter.com/Kaltura) or email: community@kaltura.com  
We'd love to hear from you!

# License and Copyright Information
All code in this project is released under the [AGPLv3 license](http://www.gnu.org/licenses/agpl-3.0.html) unless a different license for a particular library is specified in the applicable library path.   

Copyright © Kaltura Inc. All rights reserved.   
Authors and contributors: See [GitHub contributors list](https://github.com/kaltura-vpaas/kaltura-accounts-entries-export/graphs/contributors).  

### Open Source Libraries
Review the [list of Open Source 3rd party libraries](open-source-libraries.md) used in this project.
