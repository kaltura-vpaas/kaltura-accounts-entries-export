<?php
set_time_limit(0);
ini_set('memory_limit', '2048M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Los_Angeles'); //make sure to set the expected timezone
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\ILogger;
use Kaltura\Client\Enum\{SessionType, PartnerStatus, EntryStatus, MediaType, PartnerGroupType};
use Kaltura\Client\ApiException;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin;
use Kaltura\Client\Plugin\Metadata\Type\{MetadataProfile, MetadataFilter};
use Kaltura\Client\Plugin\Metadata\Enum\MetadataObjectType;
use Kaltura\Client\Type\{BaseEntryFilter, FilterPager, PartnerFilter, CategoryFilter, MediaEntryFilter, CategoryEntryFilter, AssetFilter};
use Kaltura\Client\Plugin\Caption\CaptionPlugin;
use Kaltura\Client\Plugin\Schedule\Enum\LiveStreamScheduleEventOrderBy;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventStatus;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;

class KalturaContentAnalytics implements ILogger
{
	const PARENT_PARTNER_IDS = array(
		00000000 => 'jhdsfag348gf78924y783t4r87g', //get it from https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings
		11111111 => '86f23478dgasiufgaisuhiuyg78'
	);
	const SERVICE_URL = 'https://www.kaltura.com'; //The base URL to the Kaltura server API endpoint
	const KS_EXPIRY_TIME = 86000; // Kaltura session length. Please note the script may run for a while so it mustn't be too short.
	const DEBUG_PRINTS = true; //Set to true if you'd like the script to output logging to the console (this is different from the KalturaLogger)
	const CYCLE_SIZES = 500; // Determines how many entries will be processed in each multi-request call - set it to whatever number works best for your server.
	const ERROR_LOG_FILE = 'kaltura_logger.txt'; //The name of the KalturaLogger export file

	const STOP_DATE_FOR_EXPORT = null; //e.g. '10 minutes ago'; '100 days ago'; //Defines a stop date for the entries iteration loop. Any time string supported by strtotime can be passed. 
	//If this is set to null or -1, it will be ignored and the script will run through the entire library until it reaches the first created entry. e.g. '45 days ago' or '01/01/2017', etc. formats supported by strtotime()

	const ENTRY_STATUS_IN = array(EntryStatus::READY, EntryStatus::NO_CONTENT, EntryStatus::IMPORT, EntryStatus::PRECONVERT); //defines the entry statuses to retrieve. Add EntryStatus::DELETED to include deleted entries. 
	const ENTRY_TYPE_IN = array(MediaType::VIDEO, MediaType::IMAGE, MediaType::AUDIO, MediaType::LIVE_STREAM_FLASH); //defines the entry types to retrieve 
	const ENTRY_FIELDS = array('referenceId', 'name', 'userId', 'msDuration', 'groupId', 'redirectEntryId', 'startDate', 'templateEntryId', 'createdAt', 'updatedAt', 'tags', 'status', 'mediaType', 'description');  //the list of entry fields to export (only base metadata, no custom fields here), entryId, captions and categories will be added to this list

	const PARENT_CATEGORIES = null; // The IDs of the Kaltura Categories you'd like to export, set to `null` to export all.
	const GET_CATEGORIES = true; // if false will not name the categories

	const FILTER_TAGS = null; // Tags to filter by (tagsMultiLikeOr)

	const METADATA_PROFILE_ID = null; // The profile id of the custom metadata profile to get its fields per entry, null to ignore

	const GET_CAPTIONS = true; //if set to false will not bring captions, and will ignore the above configs too
	const ONLY_CAPTIONED_ENTRIES = false; // Should only entries that have caption assets be included in the output?
	const GET_CAPTION_URLS = false; // Should the excel include URLs to download caption assets?

	const GET_ENTRY_USAGE = false; //if true, each entry live will also include the usage of that particular entry
	const REPORT_USAGE_DATE_START = '20070101'; //the date to get storage usage per entry for
	const REPORT_USAGE_DATE_END = '20201104'; //the date to get storage usage per entry for
	const REPORT_TIMEZONE_OFFSET = 480; //timezone offset for getting usage storage report for, e.g. 480 is pacific US, negative 180 is Israel timezone. make sure this is aligned with your choice of timezone above in date_default_timezone_set

	const GET_ENTRY_SCHEDULED_EVENTS = true; //if true, will also export all scheduled events for this entry (scheduledEvents of type live with templateEntryId being that entry)
	const ENTRY_SCHEDULED_EVENTS_IN_COLUMNS = true; //if true, it will be assumed that each live entry only has a single scheduled event, and instead of being added in single multi-line cell as string, the scheduled events will be added into 3 new columns: scheduled_event_source_id, scheduled_event_start_time, scheduled_event_end_time

	// excel cell formats for the exported Kaltura fields
	// note that fields that were not stated here will be auto interpreted by Excel when opened as General
	// common formats: 
	// 		'' General / Text
	//		'0' integer
	//		'#,##0' number with thousands seperator
	//		'#,##0.00' with 2 after decimal point
	//		'[$-en-US]mmmm d, yyyy;@' text date
	//		'[$-en-US]m/d/yy h:mm AM/PM;@' //date and time
	// The letters correspond to the Excel columns
	// learn more about excel cell formats here:
	//https://support.microsoft.com/en-us/office/number-format-codes-5026bbd6-04bc-48cd-bf33-80f18b4eae68
	//https://support.microsoft.com/en-us/office/display-numbers-as-postal-codes-social-security-numbers-or-phone-numbers-f0890463-f387-46bd-abac-df3c1bd01ceb
	//https://support.microsoft.com/en-us/office/display-or-hide-zero-values-in-excel-for-mac-98d447d1-0ab5-4c3f-b13b-abc550891f98	
	private $excelFieldFormats = array(
		'A' => '',
		'B' => '',
		'C' => '',
		'D' => '#,##0',
		'E' => '',
		'F' => '',
		'G' => '[$-en-US]mmmm d, yyyy;@',
		'H' => '',
		'I' => '[$-en-US]mmmm d, yyyy;@',
		'J' => '[$-en-US]mmmm d, yyyy;@',
		'K' => '',
		'L' => '',
		'M' => '',
		'N' => '',
		'O' => '',
		'P' => '',
		'Q' => '[$-en-US]m/d/yy h:mm AM/PM;@',
		'R' => '[$-en-US]m/d/yy h:mm AM/PM;@',
		'S' => '',
		'T' => '',
		'U' => '',
		'V' => ''
	);

	private $exportFileNameTemplate = 'kaltura-entries-all'; //This sets the name of the output excel file (without .xsl extension)
	private $exportFileName = 'null'; // do not toouch this

	private $stopDateForCreatedAtFilter = null;
	private $captionLanguages = array();
	private $ks = null;
	private $client = null;
	private $kConfig = null;
	private $allEntries = array();

	public function run($pid, $secret)
	{
		//Reset the log file:
		$errline = "Here you'll find the log form the Kaltura Client library, in case issues occur you can use this file to investigate and report errors.";
		file_put_contents(KalturaContentAnalytics::ERROR_LOG_FILE, $errline);
		//This sets how far back we'd like to export entries (list is ordered in descending order from today backward)
		if (KalturaContentAnalytics::STOP_DATE_FOR_EXPORT != null && KalturaContentAnalytics::STOP_DATE_FOR_EXPORT != -1) {
			$this->stopDateForCreatedAtFilter = strtotime(KalturaContentAnalytics::STOP_DATE_FOR_EXPORT);
			echo 'Exporting Kaltura entries since: ' . KalturaContentAnalytics::STOP_DATE_FOR_EXPORT . ' (timestamp: ' . $this->stopDateForCreatedAtFilter . ')' . PHP_EOL;
		}

		$kConfig = new KalturaConfiguration($pid);
		$kConfig->setServiceUrl(KalturaContentAnalytics::SERVICE_URL);
		$kConfig->setLogger($this);
		$this->client = new KalturaClient($kConfig);

		$this->ks = $this->client->session->start($secret, 'entries-xls-export', SessionType::ADMIN, $pid, KalturaContentAnalytics::KS_EXPIRY_TIME, 'list:*,disableentitlement,*');
		$this->client->setKs($this->ks);

		//This sets the name of the output excel file (without .xls extension)
		$this->exportFileName = $pid . '-' . $this->exportFileNameTemplate;

		// get all sub accounts of this parent kaltura account:
		$allSubAccounts = $this->getAllSubAccounts($this->client);
		//get the account info; (in case it's not a parent account)
		//$parentPartnerAccount = $this->client->partner->getInfo();
		//$parentActFields = [$parentPartnerAccount->id, $parentPartnerAccount->name, $parentPartnerAccount->adminSecret, $parentPartnerAccount->referenceId, $parentPartnerAccount->createdAt, $parentPartnerAccount->status];
		//$allSubAccounts = [$parentActFields];

		// get all entries for each sub-account:
		$totalAllAccounts = count($allSubAccounts);
		$counter =  0;
		foreach ($allSubAccounts as $subpartner) {
			++$counter;
			$perc = min(100, $counter / $totalAllAccounts * 100);
			if ($perc < 100) $perc = number_format($perc, 2);
			$this->clearConsoleLines(1);
			echo 'Progress: ' . $perc . '% (Account ' . $counter . ' out of: ' . $totalAllAccounts . ')' . PHP_EOL;
			$actEntries = $this->getEntriesForSubAccount($subpartner['id'], $subpartner['name'], $subpartner['adminSecret']);
			$this->allEntries = array_merge($this->allEntries, $actEntries);
			$this->clearConsoleLines(7, true);
		}

		//create the excel file
		$header = array();
		$header[] = "entry_id";
		foreach (KalturaContentAnalytics::ENTRY_FIELDS as $entryField) {
			$header[] = $entryField;
		}

		if (KalturaContentAnalytics::GET_ENTRY_USAGE == true) {
			$header[] = "storage_mb";
		}

		if (KalturaContentAnalytics::GET_ENTRY_SCHEDULED_EVENTS == true) {
			if (KalturaContentAnalytics::ENTRY_SCHEDULED_EVENTS_IN_COLUMNS == true) {
				$header[] = "scheduled_event_source_id";
				$header[] = "scheduled_event_start_time";
				$header[] = "scheduled_event_end_time";
			} else {
				$header[] = "scheduled_events";
			}
		}

		if (KalturaContentAnalytics::GET_CATEGORIES == true) {
			$header[] = "categories_ids";
			$header[] = "categories_names";
		}

		if (KalturaContentAnalytics::GET_CAPTIONS == true) {
			$header[] = "captions-languages";
			if (KalturaContentAnalytics::GET_CAPTION_URLS == true) {
				foreach ($this->captionLanguages as $language => $exists) {
					$header[] = 'caption-url-' . $language;
				}
			}
		}

		if (KalturaContentAnalytics::METADATA_PROFILE_ID != null) {
			$metadataPlugin = MetadataPlugin::get($this->client);
			$metadataTemplate = $this->getMetadataTemplate(KalturaContentAnalytics::METADATA_PROFILE_ID, $metadataPlugin);
			foreach ($metadataTemplate->children() as $metadataField) {
				$header[] = "metadata_" . $metadataField->getName();
			}
		}

		$data = array();

		foreach ($this->allEntries as $entry_id => $entry) {
			$row = array();
			$row[] = $entry_id;
			foreach (KalturaContentAnalytics::ENTRY_FIELDS as $entryField) {
				if ($entryField == 'lastPlayedAt') {
					// special handling is required here since, unlike 'createdAt' and 'updatedAt', this
					// value can be empty if no plays occurred.
					if (empty($entry['views']) || !isset($entry['views']) || !isset($entry['lastPlayedAt'])) {
						$entry['lastPlayedAt'] = null;
						$row[] = $entry[$entryField];
					} else {
						$row[] = $this->convertTimestamp2Excel($entry['lastPlayedAt']);
					}
				} else {
					if (in_array($entryField, array('createdAt', 'updatedAt'))) {
						if (isset($entry[$entryField]))
							$row[] = $this->convertTimestamp2Excel($entry[$entryField]);
						else
							$row[] = '';
					} else {
						if (isset($entry[$entryField]))
							$row[] = $entry[$entryField];
						else
							$row[] = '';
					}
				}
			}

			if (KalturaContentAnalytics::GET_ENTRY_USAGE == true) {
				if (isset($entry["storage_mb"]))
					$row[] = $entry["storage_mb"];
				else
					$row[] = 0;
			}

			if (KalturaContentAnalytics::GET_ENTRY_SCHEDULED_EVENTS == true) {
				if (KalturaContentAnalytics::ENTRY_SCHEDULED_EVENTS_IN_COLUMNS == true) {
					if (
						isset($entry["scheduledevents"]) && isset($entry['scheduledevents']['scheduledEventSourceId'])
						&& isset($entry['scheduledevents']['scheduledEventStartTime']) && isset($entry['scheduledevents']['scheduledEventEndTime'])
					) {
						$row[] = $entry['scheduledevents']['scheduledEventSourceId'];
						$row[] = $this->convertTimestamp2Excel($entry['scheduledevents']['scheduledEventStartTime']);
						$row[] = $this->convertTimestamp2Excel($entry['scheduledevents']['scheduledEventEndTime']);
					} else {
						$row[] = '';
						$row[] = '';
						$row[] = '';
					}
				} else {
					if (isset($entry["scheduledevents"])) {
						$row[] = $entry["scheduledevents"];
					} else {
						$row[] = '';
					}
				}
			}

			if (KalturaContentAnalytics::GET_CATEGORIES == true) {
				$catIds = '';
				$catNames = '';
				if (isset($entry['categories'])) {
					foreach ($entry['categories'] as $catId => $catName) {
						if ($catIds != '') {
							$catIds .= ',';
						}
						$catIds .= $catId;
						if ($catNames != '') {
							$catNames .= ',';
						}
						$catNames .= $catName['name'];
					}
				}
				$row[] = $catIds;
				$row[] = $catNames;
			}

			if (KalturaContentAnalytics::GET_CAPTIONS == true) {
				$capLangs = '';
				if (isset($entry['captions'])) {
					foreach ($entry['captions'] as $captionLanguage) {
						if ($capLangs != '') {
							$capLangs .= ',';
						}
						$capLangs .= $captionLanguage;
					}
				}
				$row[] = $capLangs;
			}

			if (KalturaContentAnalytics::GET_CAPTION_URLS == true) {
				foreach ($this->captionLanguages as $language => $exists) {
					$captionUrl = '';
					if (isset($entry['captions-url-' . $language])) {
						$captionUrl = $entry['captions-url-' . $language];
					}
					$row[] = $captionUrl;
				}
			}

			if (isset($entry['metadata'])) {
				foreach ($metadataTemplate->children() as $mdfield) {
					if (isset($entry['metadata'][$mdfield->getName()])) {
						$row[] = $entry['metadata'][$mdfield->getName()];
					} else {
						$row[] = '';
					}
				}
			}

			if (KalturaContentAnalytics::ONLY_CAPTIONED_ENTRIES == false || (KalturaContentAnalytics::ONLY_CAPTIONED_ENTRIES == true && $capLangs != ''))
				array_push($data, $row);
		}

		$this->writeXLSX($this->exportFileName . '.xlsx', $data, $header, $this->excelFieldFormats);

		echo 'Successfully exported data!' . PHP_EOL;
		echo 'File name: ' . $this->exportFileName . '.xls' . PHP_EOL;
	}

	private function convertTimestamp2Excel($input)
	{
		$output = 25569 + (($input + date('Z', $input)) / 86400);
		return $output;
	}

	private function writeXLSX($filename, $rows, $keys = [], $formats = [])
	{
		// instantiate the class
		$doc = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		\PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\AdvancedValueBinder());
		$locale = 'en-US';
		$validLocale = \PhpOffice\PhpSpreadsheet\Settings::setLocale($locale);
		$sheet = $doc->getActiveSheet();

		// $keys are for the header row.  If they are supplied we start writing at row 2
		if ($keys) {
			$offset = 2;
		} else {
			$offset = 1;
		}

		// write the rows
		$i = 0;
		foreach ($rows as $row) {
			$doc->getActiveSheet()->fromArray($row, null, 'A' . ($i++ + $offset));
		}

		// write the header row from the $keys
		if ($keys) {
			$doc->setActiveSheetIndex(0);
			$doc->getActiveSheet()->fromArray($keys, null, 'A1');
		}

		// get last row and column for formatting
		$last_column = $doc->getActiveSheet()->getHighestColumn();
		$last_row = $doc->getActiveSheet()->getHighestRow();

		// autosize all columns to content width
		for ($i = 'A'; $i <= $last_column; $i++) {
			$doc->getActiveSheet()->getColumnDimension($i)->setAutoSize(true);
		}

		// if $keys, freeze the header row and make it bold
		if ($keys) {
			$doc->getActiveSheet()->freezePane('A2');
			$doc->getActiveSheet()->getStyle('A1:' . $last_column . '1')->getFont()->setBold(true);
		}

		// format all columns as text
		$doc->getActiveSheet()->getStyle('A2:' . $last_column . $last_row)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
		if ($formats) {
			// if there are user supplied formats, set each column format accordingly
			// $formats should be an array with column letter as key and one of the PhpOffice constants as value
			// https://phpoffice.github.io/PhpSpreadsheet/1.2.1/PhpOffice/PhpSpreadsheet/Style/NumberFormat.html
			// EXAMPLE:
			// ['C' => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_00, 'D' => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_00]
			foreach ($formats as $col => $format) {
				$doc->getActiveSheet()->getStyle($col . $offset . ':' . $col . $last_row)->getNumberFormat()->setFormatCode($format);
			}
		}

		// write and save the file
		$writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($doc);
		$writer->save($filename);
	}

	public function log($message)
	{
		$errline = date('Y-m-d H:i:s') . ' ' .  $message . "\n";
		file_put_contents(KalturaContentAnalytics::ERROR_LOG_FILE, $errline, FILE_APPEND);
	}

	public static function getENUMString($enumName, $value2search)
	{
		$oClass = new ReflectionClass('Kaltura\Client\Enum\\' . $enumName);
		$statuses = $oClass->getConstants();
		foreach ($statuses as $key => $value) {
			if ($value == $value2search)
				return $key;
		}
	}

	private function getAllSubAccounts($parentClient)
	{
		$actFilter = new PartnerFilter();
		$actFilter->statusEqual = PartnerStatus::ACTIVE;
		$actFilter->partnerGroupTypeEqual = PartnerGroupType::PUBLISHER;
		$subAccounts = $this->getFullListOfKalturaObject($actFilter, $parentClient->getPartnerService(), 'id', array('id', 'name', 'adminSecret', 'referenceId', 'createdAt', 'status'), KalturaContentAnalytics::DEBUG_PRINTS, false, 'Partner');
		return $subAccounts;
	}

	private function getEntriesForSubAccount($partnerId, $partnerName, $apiSecret)
	{
		$kConfig = new KalturaConfiguration($partnerId);
		$kConfig->setServiceUrl(KalturaContentAnalytics::SERVICE_URL);
		$kConfig->setLogger($this);
		$this->client = new KalturaClient($kConfig);

		$this->ks = $this->client->session->start($apiSecret, 'entries-xls-export', SessionType::ADMIN, $partnerId, KalturaContentAnalytics::KS_EXPIRY_TIME, 'disableentitlement,list:*');
		$this->client->setKs($this->ks);

		echo 'for partner: ' . $partnerName . ', id: ' . $partnerId . '. ' . PHP_EOL;

		//get all entry objects
		$entfilter = new MediaEntryFilter();
		if (KalturaContentAnalytics::FILTER_TAGS != null && KalturaContentAnalytics::FILTER_TAGS != '')
			$entfilter->tagsMultiLikeOr = KalturaContentAnalytics::FILTER_TAGS;
		if (KalturaContentAnalytics::PARENT_CATEGORIES != null && KalturaContentAnalytics::PARENT_CATEGORIES != '')
			$entfilter->categoryAncestorIdIn = KalturaContentAnalytics::PARENT_CATEGORIES;
		if (KalturaContentAnalytics::ENTRY_STATUS_IN != null) {
			if (count(KalturaContentAnalytics::ENTRY_STATUS_IN) == 1)
				$entfilter->statusEqual = KalturaContentAnalytics::ENTRY_STATUS_IN[0];
			else
				$entfilter->statusIn = implode(',', KalturaContentAnalytics::ENTRY_STATUS_IN);
		}
		if (KalturaContentAnalytics::ENTRY_TYPE_IN != null) {
			if (count(KalturaContentAnalytics::ENTRY_TYPE_IN) == 1)
				$entfilter->mediaTypeEqual = KalturaContentAnalytics::ENTRY_TYPE_IN[0];
			else
				$entfilter->mediaTypeIn = implode(',', KalturaContentAnalytics::ENTRY_TYPE_IN);
		}
		$entries = $this->getFullListOfKalturaObject($entfilter, $this->client->getBaseEntryService(), 'id', KalturaContentAnalytics::ENTRY_FIELDS, KalturaContentAnalytics::DEBUG_PRINTS, true, 'Entry');
		$this->clearConsoleLines(1);
		echo 'Total entries to export: ' . count($entries) . ', ';

		$totalMsDuration = 0;
		foreach ($entries as $entry) {
			if (isset($entry['msDuration']))
				$totalMsDuration += $entry['msDuration'];
		}
		echo 'Total minutes of entries exported: ' . number_format($totalMsDuration / 1000 / 60, 2) . PHP_EOL;


		//get all categoryEntry objects
		if (KalturaContentAnalytics::GET_CATEGORIES) {
			$categories = array();
			$entriesToCategorize = '';
			$catfilter = new CategoryEntryFilter();
			$N = count($entries);
			reset($entries);
			$eid = key($entries);
			if (KalturaContentAnalytics::DEBUG_PRINTS) $this->clearConsoleLines(1);
			for ($i = 0; $i < $N; $i++) {
				if ($entriesToCategorize != '') $entriesToCategorize .= ',';
				$entriesToCategorize .= $eid;
				if (($i % KalturaContentAnalytics::CYCLE_SIZES == 0) || ($i == $N - 1)) {
					if (KalturaContentAnalytics::DEBUG_PRINTS) {
						echo 'Categorizing: ' . ($i + 1) . ' entries of ' . $N . ' total entries...';
					}
					$catfilter->entryIdIn = $entriesToCategorize;
					$catents = $this->getFullListOfKalturaObject($catfilter, $this->client->getCategoryEntryService(), 'categoryId', 'entryId*', KalturaContentAnalytics::DEBUG_PRINTS, false, 'Category');
					foreach ($catents as $catId => $entryIds) {
						$categories[$catId] = true;
						foreach ($entryIds as $entryId) {
							if (!isset($entries[$entryId]['categories'])) $entries[$entryId]['categories'] = array();
							$entries[$entryId]['categories'][$catId] = array();
						}
					}
					$entriesToCategorize = '';
					if (KalturaContentAnalytics::DEBUG_PRINTS) $this->clearConsoleLines(1, true);
				}
				next($entries);
				$eid = key($entries);
			}

			//get all category objects, and map category names to entry objects
			reset($entries);
			$catfilter = new CategoryFilter();
			$catsToName = '';
			$N = count($categories);
			reset($categories);
			$categoryId = key($categories);
			if (KalturaContentAnalytics::DEBUG_PRINTS) $this->clearConsoleLines(1);
			for ($i = 0; $i < $N; $i++) {
				if ($catsToName != '') $catsToName .= ',';
				$catsToName .= $categoryId;
				if (($i % KalturaContentAnalytics::CYCLE_SIZES == 0) || ($i == $N - 1)) {
					if (KalturaContentAnalytics::DEBUG_PRINTS) {
						echo 'Naming categories: ' . ($i + 1) . ' categories of ' . $N . ' total categories...';
					}
					$catfilter->idIn = $catsToName;
					$catnames = $this->getFullListOfKalturaObject($catfilter, $this->client->getCategoryService(), 'id', ['name', 'fullName'], KalturaContentAnalytics::DEBUG_PRINTS, false, 'Category');
					foreach ($catnames as $catId => $catInfo) {
						$categories[$catId] = $catInfo;
						foreach ($entries as $entryId => $entry) {
							if (isset($entries[$entryId]['categories'][$catId]))
								$entries[$entryId]['categories'][$catId] = $catInfo;
						}
					}
					$catsToName = '';
					if (KalturaContentAnalytics::DEBUG_PRINTS) $this->clearConsoleLines(1, true);
				}
				next($categories);
				$categoryId = key($categories);
			}

			if (KalturaContentAnalytics::DEBUG_PRINTS) echo 'Testing entry categories...' . PHP_EOL;
			// verify categories - we shouldn't be missing any if we're starting from a parent category
			if (KalturaContentAnalytics::PARENT_CATEGORIES != '') {
				foreach ($entries as $eid => $ent) {
					if (!isset($ent['categories']))
						echo ('Something broke, check entryId: ' . $eid . PHP_EOL);
				}
			}
			if (KalturaContentAnalytics::DEBUG_PRINTS) {
				echo 'Finished categorizing entries' . PHP_EOL;
			}
		}

		if (KalturaContentAnalytics::GET_CAPTIONS) {
			$captionPlugin = CaptionPlugin::get($this->client);
			if (KalturaContentAnalytics::DEBUG_PRINTS) echo 'Getting caption assets for the entries...' . PHP_EOL;
			//get captions per entries
			$assetFilter = new AssetFilter();
			$pager = new FilterPager();
			$N = count($entries);
			reset($entries);
			$eid = key($entries);
			$entryIdsInCycle = '';
			$entriesCaptions = null;
			$this->clearConsoleLines(1);
			for ($i = 0; $i < $N; $i++) {
				if ($entryIdsInCycle != '') $entryIdsInCycle .= ',';
				$entryIdsInCycle .= $eid;
				if (($i % KalturaContentAnalytics::CYCLE_SIZES == 0) || ($i == $N - 1)) {
					if (KalturaContentAnalytics::DEBUG_PRINTS) {
						echo 'Getting captions: ' . ($i + 1) . ' entries of ' . $N . ' total entries...' . PHP_EOL;
					}
					$assetFilter->entryIdIn = $entryIdsInCycle;
					$pager->pageSize = KalturaContentAnalytics::CYCLE_SIZES;
					$pager->pageIndex = 1;
					$entriesCaptions = $this->presistantApiRequest($captionPlugin->captionAsset, 'listAction', array($assetFilter, $pager), 5);
					while (count($entriesCaptions->objects) > 0) {
						foreach ($entriesCaptions->objects as $capAsset) {
							if (!isset($entries[$capAsset->entryId]['captions']))
								$entries[$capAsset->entryId]['captions'] = array();
							if (KalturaContentAnalytics::GET_CAPTION_URLS == true) {
								$entries[$capAsset->entryId]['captions-url-' . $capAsset->language] = KalturaContentAnalytics::SERVICE_URL . '/api_v3/service/caption_captionasset/action/serve/captionAssetId/' . $capAsset->id . '/ks/' . $this->ks;
							}
							$entries[$capAsset->entryId]['captions'][] = $capAsset->language;
							$this->captionLanguages[$capAsset->language] = true;
						}
						++$pager->pageIndex;
						$entriesCaptions = $this->presistantApiRequest($captionPlugin->captionAsset, 'listAction', array($assetFilter, $pager), 5);
					}
					$entryIdsInCycle = '';
					if (KalturaContentAnalytics::DEBUG_PRINTS) $this->clearConsoleLines(1, true);
				}
				next($entries);
				$eid = key($entries);
			}
			if (KalturaContentAnalytics::DEBUG_PRINTS) {
				$this->clearConsoleLines(1, true);
				echo 'Finished getting captions' . PHP_EOL;
			}
		}

		if (KalturaContentAnalytics::GET_ENTRY_USAGE == true) {
			if (KalturaContentAnalytics::DEBUG_PRINTS) echo 'Getting usage reports for the entries...' . PHP_EOL;
			$reportId = 1400; // storage usage per entry report id
			$params = 'from_date_id=' . KalturaContentAnalytics::REPORT_USAGE_DATE_START . ';to_date_id=' . KalturaContentAnalytics::REPORT_USAGE_DATE_END . ';timezone_offset=' . KalturaContentAnalytics::REPORT_TIMEZONE_OFFSET;
			$csvReportUrl = $this->client->report->getCsvFromStringParams($reportId, $params);
			$csvReportData = file_get_contents($csvReportUrl);
			$csvReport = $this->parse_csv($csvReportData);
			foreach ($csvReport as $repRow) {
				if (count($repRow) == 0) continue; //skip empty rows
				if (!isset($repRow['entry_id'])) continue;
				if (!isset($repRow['total_storage_mb'])) continue; //skip rows without flavor size
				$entid = $repRow['entry_id'];
				if (!isset($entries[$entid])) continue; // skip entries that were filtered out of the list
				$flavorMB = floatval($repRow['total_storage_mb']);
				if (!isset($entries[$entid]['storage_mb']))
					$entries[$entid]['storage_mb'] = 0;
				$entries[$entid]['storage_mb'] += $flavorMB;
			}
			if (KalturaContentAnalytics::DEBUG_PRINTS) {
				echo 'Finished getting storage usage' . PHP_EOL;
			}
		}

		if (KalturaContentAnalytics::GET_ENTRY_SCHEDULED_EVENTS == true) {
			if (KalturaContentAnalytics::DEBUG_PRINTS) echo 'Getting all scheduled events for the entries...' . PHP_EOL;
			$schedulePlugin = SchedulePlugin::get($this->client);
			$sefilter = new LiveStreamScheduleEventFilter();
			$pager = new FilterPager();
			$sefilter->orderBy = LiveStreamScheduleEventOrderBy::START_DATE_ASC;
			$sefilter->statusEqual = ScheduleEventStatus::ACTIVE;
			$N = count($entries);
			reset($entries);
			$eid = key($entries);
			for ($i = 0; $i < $N; $i++) {
				if (isset($entries[$eid]['mediaType']) && $entries[$eid]['mediaType'] != 'LIVE_STREAM')
					continue;
				if (KalturaContentAnalytics::DEBUG_PRINTS) {
					$this->clearConsoleLines(1);
					echo 'Getting scheduled events for entry Id: ' . $eid;
				}
				$sefilter->templateEntryIdEqual = $eid;
				$pager->pageSize = KalturaContentAnalytics::CYCLE_SIZES;
				$pager->pageIndex = 1;
				$entriesSchedules = $this->presistantApiRequest($schedulePlugin->scheduleEvent, 'listAction', array($sefilter, $pager), 5);
				while (count($entriesSchedules->objects) > 0) {
					foreach ($entriesSchedules->objects as $scheduledEntry) {
						if (KalturaContentAnalytics::ENTRY_SCHEDULED_EVENTS_IN_COLUMNS == true) {
							if (!isset($entries[$eid]['scheduledevents']))
								$entries[$eid]['scheduledevents'] = array();
							else
								echo PHP_EOL . 'Warning! this live entry has more than a single scheduled event ' . $eid . ' prev: ' . $entries[$eid]['scheduledevents']['scheduledEventSourceId'] . ', and now: ' . $scheduledEntry->sourceEntryId . PHP_EOL . PHP_EOL;
							$entries[$eid]['scheduledevents']['scheduledEventSourceId'] = $scheduledEntry->sourceEntryId;
							$entries[$eid]['scheduledevents']['scheduledEventStartTime'] = $scheduledEntry->startDate;
							$entries[$eid]['scheduledevents']['scheduledEventEndTime'] = $scheduledEntry->endDate;
						} else {
							if (!isset($entries[$eid]['scheduledevents']))
								$entries[$eid]['scheduledevents'] = '';
							else
								$entries[$eid]['scheduledevents'] .= PHP_EOL;

							$currentTimezone = date_default_timezone_get();
							$seventStartDate = DateTime::createFromFormat('U', $scheduledEntry->startDate, new DateTimeZone($currentTimezone));
							$seventEndDate = DateTime::createFromFormat('U', $scheduledEntry->endDate, new DateTimeZone($currentTimezone));
							$eStartDate = $seventStartDate->format('Y-m-d G:i:s T');
							$eEndDate = $seventEndDate->format('Y-m-d G:i:s T');
							$entries[$eid]['scheduledevents'] .= 'source: ' . $scheduledEntry->sourceEntryId . ', start: ' . $eStartDate . ', end: ' . $eEndDate;
						}
					}
					++$pager->pageIndex;
					$entriesSchedules = $this->presistantApiRequest($schedulePlugin->scheduleEvent, 'listAction', array($sefilter, $pager), 5);
				}
				next($entries);
				$eid = key($entries);
			}
			if (KalturaContentAnalytics::DEBUG_PRINTS) {
				$this->clearConsoleLines(1);
				echo 'Finished getting scheduled events' . PHP_EOL;
			}
		}

		if (KalturaContentAnalytics::METADATA_PROFILE_ID != null) {
			if (KalturaContentAnalytics::DEBUG_PRINTS)
				echo 'Getting metadata for the entries...' . PHP_EOL;
			//get metadata per entries
			$metadatafilter = new MetadataFilter();
			$metadatafilter->metadataProfileIdEqual = KalturaContentAnalytics::METADATA_PROFILE_ID;
			$metadatafilter->metadataObjectTypeEqual = MetadataObjectType::ENTRY;
			$pager = new FilterPager();
			$metadataPlugin = MetadataPlugin::get($this->client);
			$N = count($entries);
			reset($entries);
			$eid = key($entries);
			$entryIdsInCycle = '';
			$entriesMetadata = null;
			$metadataXml = null;
			for ($i = 0; $i < $N; $i++) {
				if ($entryIdsInCycle != '') $entryIdsInCycle .= ',';
				$entryIdsInCycle .= $eid;
				if (($i % KalturaContentAnalytics::CYCLE_SIZES == 0) || ($i == $N - 1)) {
					if (KalturaContentAnalytics::DEBUG_PRINTS) {
						echo 'Getting metadata: ' . ($i + 1) . ' entries of ' . $N . ' total entries...' . PHP_EOL;
					}
					$metadatafilter->objectIdIn = $entryIdsInCycle;
					$pager->pageSize = KalturaContentAnalytics::CYCLE_SIZES;
					$pager->pageIndex = 1;
					$entriesMetadata = $metadataPlugin->metadata->listAction($metadatafilter, $pager);
					while (count($entriesMetadata->objects) > 0) {
						foreach ($entriesMetadata->objects as $metadataInstance) {
							if (!isset($entries[$metadataInstance->objectId]['metadata'])) {
								$entries[$metadataInstance->objectId]['metadata'] = array();
							}
							$metadataXml = simplexml_load_string($metadataInstance->xml);
							foreach ($metadataXml->children() as $metadataField) {
								// handle multi choice fields
								if (isset($entries[$metadataInstance->objectId]['metadata'][$metadataField->getName()])) {
									$entries[$metadataInstance->objectId]['metadata'][$metadataField->getName()] .= ' + ' . (string)$metadataField;
								} else {
									$entries[$metadataInstance->objectId]['metadata'][$metadataField->getName()] = (string)$metadataField;
								}
							}
						}
						++$pager->pageIndex;
						$entriesMetadata = $metadataPlugin->metadata->listAction($metadatafilter, $pager);
					}
					$entryIdsInCycle = '';
					if (KalturaContentAnalytics::DEBUG_PRINTS)
						$this->clearConsoleLines(1, true);
				}
				next($entries);
				$eid = key($entries);
			}
			if (KalturaContentAnalytics::DEBUG_PRINTS) {
				$this->clearConsoleLines(1);
				echo 'Finished getting custom metadata fields' . PHP_EOL;
			}
		}

		return $entries;
	}

	private function parse_csv($csv_string)
	{
		$lines = explode("\n", $csv_string);
		$headers = str_getcsv(array_shift($lines));
		$data = array();
		foreach ($lines as $line) {
			$row = array();
			foreach (str_getcsv($line) as $key => $field)
				$row[$headers[$key]] = $field;
			$row = array_filter($row);
			$data[] = $row;
		}
		return $data;
	}

	public function getFullListOfKalturaObject($filter, $listService, $idField = 'id', $valueFields = null, $printProgress = false, $stopOnCreatedAtDate = false, $objectName = null)
	{
		$serviceName = get_class($listService);
		$filter->orderBy = '+createdAt';
		$filter->createdAtGreaterThanOrEqual = null;
		$pager = new FilterPager();
		$pager->pageSize = KalturaContentAnalytics::CYCLE_SIZES;
		$pager->pageIndex = 1;
		$lastCreatedAt = 0;
		$lastObjectIds = '';
		$reachedLastObject = false;
		$allObjects = array();
		$count = 0;
		$totalCount = 0;

		$countAvailable = method_exists($listService, 'count');
		if ($countAvailable) {
			if ($stopOnCreatedAtDate && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1) {
				$filter->createdAtGreaterThanOrEqual = $this->stopDateForCreatedAtFilter;
			}
			$totalCount = $this->presistantApiRequest($listService, 'count', array($filter), 5);
			$filter->createdAtGreaterThanOrEqual = null;
		}

		// if this filter doesn't have idNotIn - we need to find the highest totalCount
		// this is a workaround hack due to a bug in how categoryEntry list action calculates totalCount
		if (!property_exists($filter, 'idNotIn')) {
			$temppager = new FilterPager();
			$temppager->pageSize = KalturaContentAnalytics::CYCLE_SIZES;
			$temppager->pageIndex = 1;
			$result = $this->presistantApiRequest($listService, 'listAction', array($filter, $temppager), 5);
			while (count($result->objects) > 0) {
				$totalCount = max($totalCount, $result->totalCount);
				++$temppager->pageIndex;
				$result = $this->presistantApiRequest($listService, 'listAction', array($filter, $temppager), 5);
			}
		}
		if ($printProgress && $totalCount > 0) {
			echo $serviceName . ' Progress (total: ' . $totalCount . '):      ';
			echo PHP_EOL;
		}
		$totalObjects2Get = $totalCount;
		while (!$reachedLastObject) {
			if ($lastCreatedAt != 0) {
				$filter->createdAtGreaterThanOrEqual = $lastCreatedAt;
			}
			if (
				$stopOnCreatedAtDate == true && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1 &&
				$totalObjects2Get <= KalturaContentAnalytics::CYCLE_SIZES
			) {
				$filter->createdAtGreaterThanOrEqual = $this->stopDateForCreatedAtFilter;
			}

			if ($lastObjectIds != '' && property_exists($filter, 'idNotIn'))
				$filter->idNotIn = $lastObjectIds;

			$filteredListResult = $this->presistantApiRequest($listService, 'listAction', array($filter, $pager), 5);

			if ($totalCount == 0) $totalCount = $filteredListResult->totalCount;

			$resultsCount = count($filteredListResult->objects);

			if ($resultsCount == 0 || $totalCount <= $count) {
				$reachedLastObject = true;
				break;
			}

			foreach ($filteredListResult->objects as $obj) {
				if ($count < $totalCount) {
					if ($valueFields == null) {
						$allObjects[$obj->{$idField}] = $obj;
					} elseif (is_string($valueFields)) {
						if (substr($valueFields, -1) == '*') {
							$valfield = substr($valueFields, 0, -1);
							if (!isset($allObjects[$obj->{$idField}]))
								$allObjects[$obj->{$idField}] = array();
							$allObjects[$obj->{$idField}][] = $obj->{$valfield};
						} else {
							$allObjects[$obj->{$idField}] = $obj->{$valueFields};
						}
					} elseif (is_array($valueFields)) {
						if (isset($allObjects[$obj->{$idField}])) echo $obj->{$idField} . ',' . PHP_EOL;
						if (!isset($allObjects[$obj->{$idField}]))
							$allObjects[$obj->{$idField}] = array();
						foreach ($valueFields as $field) {
							switch ($field) {
								case 'objectType':
									$allObjects[$obj->{$idField}]['objectType'] = get_class($obj);
									break;
								case 'status':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['status'] = KalturaContentAnalytics::getENUMString($objectName . 'Status', $obj->{$field});
									break;
								case 'mediaType':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['mediaType'] = KalturaContentAnalytics::getENUMString('MediaType', $obj->{$field});
									if ($allObjects[$obj->{$idField}]['mediaType'] == 'LIVE_STREAM_FLASH')
										$allObjects[$obj->{$idField}]['mediaType'] = 'LIVE_STREAM';
									break;
								case 'type':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['type'] = KalturaContentAnalytics::getENUMString($objectName . 'Type', $obj->{$field});
									break;
								default:
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}][$field] = $obj->{$field};
							}
						}
					}

					if ($lastCreatedAt < $obj->createdAt) $lastObjectIds = '';

					$lastCreatedAt = $obj->createdAt;

					if (
						$stopOnCreatedAtDate && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1 &&
						$lastCreatedAt < $this->stopDateForCreatedAtFilter
					) {
						$reachedLastObject = true;
						echo 'break on stop date with: ' . count($allObjects) . ' objects' . PHP_EOL;
						break;
					}

					if ($lastObjectIds != '') $lastObjectIds .= ',';
					$lastObjectIds .= $obj->{$idField};
				} else {
					$reachedLastObject = true;
					break;
				}
			}

			$count += $resultsCount;

			if ($printProgress && $totalCount > 0) {
				$perc = min(100, $count / $totalCount * 100);
				if ($perc < 100) $perc = number_format($perc, 2);
				$this->clearConsoleLines(1);
				echo $perc . '%';
			}
		}

		return $allObjects;
	}

	private function presistantApiRequest($service, $actionName, $paramsArray, $numOfAttempts)
	{
		$attempts = 0;
		$lastError = null;
		do {
			try {
				$response = call_user_func_array(
					array(
						$service,
						$actionName
					),
					$paramsArray
				);
				if ($response === false) {
					$this->log("Error Processing API Action: " . $actionName);
					throw new Exception("Error Processing API Action: " . $actionName, 1);
				}
			} catch (Exception $e) {
				$lastError = $e;
				++$attempts;
				sleep(10);
				continue;
			}
			break;
		} while ($attempts < $numOfAttempts);
		if ($attempts >= $numOfAttempts) {
			$this->log('======= API BREAKE =======' . PHP_EOL);
			$this->log('Message: ' . $lastError->getMessage() . PHP_EOL);
			$this->log('Last Kaltura client headers:' . PHP_EOL);
			$this->log(
				print_r(
					$this
						->client
						->getResponseHeaders()
				)
			);
			$this->log('===============================');
		}
		return $response;
	}

	/**
	 * Converts a string to a valid UNIX filename.
	 * @param $string The filename to be converted
	 * @return $string The filename converted
	 */
	private function convert_to_filename($string)
	{

		// Replace spaces with underscores and makes the string lowercase
		$string = str_replace(" ", "_", $string);
		$string = str_replace("..", ".", $string);
		$string = strtolower($string);

		// Match any character that is not in our whitelist
		preg_match_all("/[^0-9^a-z^_^.]/", $string, $matches);

		// Loop through the matches with foreach
		foreach ($matches[0] as $value) {
			$string = str_replace($value, "", $string);
		}
		return $string;
	}

	/**
	 * show a status bar in the console
	 * 
	 * <code>
	 * for($x=1;$x<=100;$x++){
	 * 
	 *     show_status($x, 100);
	 * 
	 *     usleep(100000);
	 *                           
	 * }
	 * </code>
	 *
	 * @param   int     $done   how many items are completed
	 * @param   int     $total  how many items are to be done total
	 * @param   int     $size   optional size of the status bar
	 * @return  void
	 *
	 */

	private function show_status($done, $total, $size = 30)
	{

		static $start_time;

		// if we go over our bound, just ignore it
		if ($done > $total) return;

		if (empty($start_time)) $start_time = time();
		$now = time();

		$perc = (float)($done / $total);

		$bar = floor($perc * $size);

		$status_bar = "\r[";
		$status_bar .= str_repeat("=", $bar);
		if ($bar < $size) {
			$status_bar .= ">";
			$status_bar .= str_repeat(" ", $size - $bar);
		} else {
			$status_bar .= "=";
		}

		$disp = number_format($perc * 100, 0);

		$status_bar .= "] $disp%  $done/$total";

		$rate = ($now - $start_time) / $done;
		$left = $total - $done;
		$eta = round($rate * $left, 2);

		$elapsed = $now - $start_time;

		$status_bar .= " remaining: " . number_format($eta) . " sec.  elapsed: " . number_format($elapsed) . " sec.";

		echo "$status_bar  ";

		flush();

		// when done, send a newline
		if ($done == $total) {
			echo "\n";
		}
	}

	public function getMetadataTemplate($metadataProfileId, $metadataPlugin)
	{

		// if no valid profile id was provided, return an empty metadata
		if ($metadataProfileId <= 0) {
			$metadataTemplate = '<metadata>'; //Kaltura metadata XML is always wrapped in <metadata>
			$metadataTemplate .= '</metadata>';
			$metadataXmlTemplate = simplexml_load_string($metadataTemplate);
			return $metadataXmlTemplate;
		}

		$schemaUrl = $metadataPlugin->metadataProfile->serve($metadataProfileId); //returns a URL
		//or can also use: $metadataPlugin->metadataProfile->get($metadataProfileId)->xsd
		$schemaXSDFile = file_get_contents($schemaUrl); //download the XSD file from Kaltura

		//Build a <metadata> template:
		$schema = new DOMDocument();
		$schema->loadXML(str_replace('&', '&amp;', $schemaXSDFile)); //load and parse the XSD as an XML
		$fieldsList = $schema->getElementsByTagName('element'); //get all elements of the XSD
		$metadataTemplate = '<metadata>'; //Kaltura metadata XML is always wrapped in <metadata>
		foreach ($fieldsList as $element) {
			if ($element->hasAttribute('name') === false) {
				continue; //valid fields will always have name
			}
			$key = $element->getAttribute('name'); //systemName is the element's name, not key nor id
			if ($key != 'metadata') { //exclude the parent node ‘metadata' as we're manually creating it
				if ($element->getAttribute('type') != 'textType') {
					$options = $element->getElementsByTagName('enumeration');
					if ($options != null && ($options->length > 0)) {
						$defaultOption = $options->item(0)->nodeValue;
						$metadataTemplate .= '<' . $key . '>' . $defaultOption . '</' . $key . '>';
					} else {
						$metadataTemplate .= '<' . $key . '>' . '</' . $key . '>';
					}
				} else {
					$metadataTemplate .= '<' . $key . '>' . '</' . $key . '>';
				}
			}
		}
		$metadataTemplate .= '</metadata>';
		$metadataXmlTemplate = simplexml_load_string($metadataTemplate);
		return $metadataXmlTemplate;
	}

	private function clearConsoleLines($last_lines, $move_up = false)
	{
		for ($i = 0; $i < $last_lines; $i++) {
			echo "\r"; // Return to the beginning of the line
			echo "\033[K"; // Erase to the end of the line
			if ($move_up) echo "\033[1A"; // Move cursor Up a line
			if ($move_up) echo "\r"; // Return to the beginning of the line
			if ($move_up) echo "\033[K"; // Erase to the end of the line
			if ($move_up) echo "\r"; // Return to the beginning of the line
		}
	}
}
class ExecutionTime
{
	//credit: https://stackoverflow.com/a/22885011
	private $startTime;
	private $endTime;

	private $time_start     =   0;
	private $time_end       =   0;
	private $time           =   0;

	public function start()
	{
		$this->startTime = getrusage();
		$this->time_start = microtime(true);
	}

	public function end()
	{
		$this->endTime = getrusage();
		$this->time_end = microtime(true);
	}

	public function totalRunTime()
	{
		$this->time = round($this->time_end - $this->time_start);
		$minutes = floor($this->time / 60); //only minutes
		$seconds = $this->time % 60;//remaining seconds, using modulo operator
		return "Total script execution time: minutes:$minutes, seconds:$seconds";
	}

	private function runTime($ru, $rus, $index)
	{
		return ($ru["ru_$index.tv_sec"] * 1000 + intval($ru["ru_$index.tv_usec"] / 1000))
			-  ($rus["ru_$index.tv_sec"] * 1000 + intval($rus["ru_$index.tv_usec"] / 1000));
	}

	public function __toString()
	{
		return $this->totalRunTime() . PHP_EOL . "This process used " . $this->runTime($this->endTime, $this->startTime, "utime") .
			" ms for its computations\nIt spent " . $this->runTime($this->endTime, $this->startTime, "stime") .
			" ms in system calls\n";
	}
}
$executionTime = new ExecutionTime();
$executionTime->start();
foreach (KalturaContentAnalytics::PARENT_PARTNER_IDS as $pid => $secret) {
	$instance = new KalturaContentAnalytics();
	$instance->run($pid, $secret);
	unset($instance);
}
$executionTime->end();
echo $executionTime;
