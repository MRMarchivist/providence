<?php
/* ----------------------------------------------------------------------
 * install/inc/Installer.php : class that wraps installer functionality
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2011-2021 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

namespace Installer;

require_once(__CA_LIB_DIR__.'/Media/MediaVolumes.php');
require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/ElasticSearch.php');
require_once(__CA_APP_DIR__.'/helpers/configurationHelpers.php');

class Installer {
	# --------------------------------------------------
	protected $errors;
	protected $debug;
	protected $profile_debug = "";
	# --------------------------------------------------
	protected $profile_dir;
	protected $profile_name;

	protected $admin_email;
	protected $overwrite;
	# --------------------------------------------------
	/** @var  bool */
	protected $logging_status = false;
	/** @var KLogger */
	protected $log;
	# --------------------------------------------------
	/** @var  SimpleXMLElement */
	protected $profile;
	/** @var  SimpleXMLElement */
	protected $base;
	/** @var  string */
	protected $base_name;
	# --------------------------------------------------
	/** @var array  */
	protected $locales;
	
	/** 
	 * Parsed profile data for insertion
	 */
	protected $parsed_data = [];
	# --------------------------------------------------
	/**
	 * @var Db
	 */
	protected $db;
	# --------------------------------------------------
	/**
	 * @var array
	 */
	protected $metadata_element_deferred_settings_processing = [];
	# --------------------------------------------------
	/**
	 * Constructor
	 *
	 * @param string $ps_profile_dir path to a directory containing profiles and XML schema
	 * @param string $ps_profile_name of the profile, as in <$ps_profile_dir>/<$ps_profile_name>.xml
	 * @param string $ps_admin_email e-mail address for the initial administrator account
	 * @param boolean $pb_overwrite overwrite existing install? optional, defaults to false
	 * @param boolean $pb_debug enable or disable debugging mode
	 * @param boolean $pb_skip_load dont actually load profile (useful if you want to fill in some gaps by hand)
	 * @param boolean $pb_log_output log output using Klogger
	 */
	public function  __construct(string $directory, string $profile, ?string $admin_email=null, ?bool $overwrite=false, ?bool $debug=false, ?bool $skip_load=false, ?bool $log_output=false) {
		$this->profile_dir = $directory;
		$this->profile_name = $profile;
		$this->admin_email = $admin_email;
		$this->overwrite = $overwrite;
		$this->debug = $debug;

		$this->locales = [];

		$this->db = new \Db();
		
		// Process selected profile into data structure for insertion
		$data = $this->parseProfile($directory, $profile);
		
		if(!$skip_load) {
			if(!is_array($data)) {
				// TODO: get error from parser
				$this->addError("Could not load profile.");
				return false;
			}
			$this->parsed_data = $data;
		}

		if($log_output) {
			require_once(__CA_LIB_DIR__.'/Logging/KLogger/KLogger.php');
			// @todo make this configurable or get from app.conf?
			$this->log = new \KLogger(__CA_BASE_DIR__ . '/app/log', \KLogger::DEBUG);
			$this->logging_status = true;
		}
	}
	# --------------------------------------------------
	/**
	 * Parse a profile, returning a data structure will profile content ready for insertion into the database
	 *
	 * @param string $directory path to a directory containing profiles
	 * @param string $profile Name of the profile, with or without file extension
	 *
	 * @return array
	 */
	public function parseProfile(string $directory, string $profile) : ?array {
		if(!($path = \caGetProfilePath($directory, $profile))) {
			return null;
		}
		
		if(!($parser = self::profileParser($path))) { return null; }
		
		return $parser->parse($directory, $profile);
	}
	# --------------------------------------------------
	/**
	 * Return metadata (name, description) for a profile
	 *
	 * @param string $directory path to a directory containing profiles
	 * @param string $profile Name of the profile, with or without file extension
	 *
	 * @return array
	 */
	static public function getProfileInfo(string $directory, string $profile) : ?array {
		if(!($path = \caGetProfilePath($directory, $profile))) {
			return null;
		}
		
		if(!($parser = self::profileParser($path))) { return null; }
		
		return $parser->profileInfo($path);
	}
	# --------------------------------------------------
	/**
	 * Return instance of profile parser for selected file
	 *
	 * @param string $profile_path path to a profile file
	 *
	 * @return array
	 */
	static public function profileParser(string $profile_path) : ?\Installer\Parsers\BaseProfileParser {
		$extension = strtolower(pathinfo($profile_path, PATHINFO_EXTENSION));
		
		switch($extension) {
			case 'xlsx':
				// noop - not supported yet
				break;
			case 'xml':
				require_once(__CA_BASE_DIR__.'/install/inc/Parsers/XMLProfileParser.php');
				return new \Installer\Parsers\XMLProfileParser();
				break;
		}
		return null;
	}
	# --------------------------------------------------
	/**
	 * TODO: Remove
	 */
// 	public function extractAndLoadBase() {
// 		$this->base_name = self::getAttribute($this->profile, "base");
// 		if(!($base_path = caGetProfilePath($this->profile_dir, $this->base_name))) {
// 			throw new \Exception("Could not find base profile.");
// 		}
// 		if($this->base_name) {
// 			$this->base = simplexml_load_file($base_path);
// 			$this->logStatus(_t('Successfully loaded base profile %1', $this->base_name));
// 		} else {
// 			$this->base = null;
// 		}
// 	}
	# --------------------------------------------------
	# ERROR HANDLING / DEBUGGING
	# --------------------------------------------------
	/**
	 *
	 */
	protected function addError($ps_error) {
		$this->logStatus($ps_error);
		$this->errors[] = $ps_error;
	}
	# --------------------------------------------------
	/**
	 * Returns number of errors that occurred while processing
	 *
	 * @return int number of errors
	 */
	public function numErrors() {
		return is_array($this->errors) ? sizeof($this->errors) : 0;
	}
	# --------------------------------------------------
	/**
	 * Returns array of error messages
	 *
	 * @return array errors
	 */
	public function getErrors() {
		return $this->errors;
	}
	# --------------------------------------------------
	/**
	 * Get profile debug info. Only has content if debug mode is enabled.
	 * WARNING: can lead to very verbose output, especially if the php
	 * extension xdebug is installed and enabled.
	 *
	 * @return string profile debug info
	 */
	public function getProfileDebugInfo() {
		return $this->profile_debug;
	}
	# --------------------------------------------------
	# UTILITIES
	# --------------------------------------------------
	/**
	 *
	 */
	private static function createDirectoryPath($ps_path) {
		if (!file_exists($ps_path)) {
			if (!@mkdir($ps_path, 0777, true)) {
				return false;
			} else {
				return true;
			}
		} else {
			return true;
		}
	}
	# --------------------------------------------------
	/**
	 * 
	 *
	 * @param LabelableBaseModelWithAttributes $t_instance
	 * @param bool $pb_force_preferred
	 * @return bool
	 */
	protected static function addLabels($t_instance, $labels, $force_preferred=false) {
		require_once(__CA_LIB_DIR__."/LabelableBaseModelWithAttributes.php");

		if(!($t_instance instanceof \LabelableBaseModelWithAttributes)) {
			return false;
		}
		
		/** @var LabelableBaseModelWithAttributes $t_instance */
		if (!$labels || !is_array($labels) || !sizeof($labels)) {
			$t_instance->addLabel(array($t_instance->getLabelDisplayField() => "???"), \ca_locales::getDefaultCataloguingLocaleID(), false, true);
			return true; 
		}

		$va_old_label_ids = array_flip($t_instance->getLabelIDs());

		foreach($labels as $label) {
			$va_label_values = [];
			$vs_locale = $label["locale"];
			$vn_locale_id = \ca_locales::codeToID($vs_locale);

			$vb_preferred = $label["preferred"];
			if($force_preferred || (bool)$vb_preferred || is_null($vb_preferred)) {
				$vb_preferred = true;
			} else {
				$vb_preferred = false;
			}

			foreach($label as $name => $value) {
				$va_label_values[$name] = (string) $value;
			}
			$va_existing_labels = $vb_preferred ? $t_instance->getPreferredLabels(array($vn_locale_id)) : $t_instance->getNonPreferredLabels(array($vn_locale_id));
			if(
				is_array($va_existing_labels) &&
				(sizeof($va_existing_labels) > 0) &&
				($vn_label_id = $va_existing_labels[(int)$t_instance->getPrimaryKey()][(int)$vn_locale_id][0]['label_id'])
			) {
				$vn_label_id = $t_instance->editLabel($vn_label_id, $va_label_values, $vn_locale_id, null, $vb_preferred);
			} else {
				$vn_label_id = $t_instance->addLabel($va_label_values, $vn_locale_id, false, $vb_preferred);
			}

			unset($va_old_label_ids[$vn_label_id]);
		}

		// remove all old labels that are not present in the XML!
		foreach($va_old_label_ids as $vn_label_id => $_) {
			$t_instance->removeLabel($vn_label_id);
		}

		return true;
	}
	# --------------------------------------------------
	/**
	 *
	 */
	public function performPreInstallTasks() {
		$o_config = \Configuration::load();
		\CompositeCache::flush(); // avoid stale cache

		// create tmp dir
		if (!file_exists($o_config->get('taskqueue_tmp_directory'))) {
			if (!self::createDirectoryPath($o_config->get('taskqueue_tmp_directory'))) {
				$this->addError("Couldn't create tmp directory at ".$o_config->get('taskqueue_tmp_directory'));
				return false;
			}
		} else {
			// if already exists then remove all contents to avoid stale cache
			caRemoveDirectory($o_config->get('taskqueue_tmp_directory'), false);
		}

		// Create media directories
		$o_media_volumes = new \MediaVolumes();
		$va_media_volumes = $o_media_volumes->getAllVolumeInformation();

		$vs_base_dir = $o_config->get('ca_base_dir');
		foreach($va_media_volumes as $vs_label => $va_volume_info) {
			if (preg_match('!^'.$vs_base_dir.'!', $va_volume_info['absolutePath'])) {
				if (!self::createDirectoryPath($va_volume_info['absolutePath'])) {
					$this->addError("Couldn't create directory for media volume {$vs_label}");
					return false;
				}
			}
		}

		if (($o_config->get('search_engine_plugin') == 'ElasticSearch') && (!$this->isAlreadyInstalled() || (defined('__CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__') && __CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__ && $this->overwrite))) {
			$o_es = new \WLPlugSearchEngineElasticSearch();
			try {
				$o_es->truncateIndex();
			} catch(DatabaseException $e) {
				// noop. this can happen when we operate on an empty database where ca_application_vars doesn't exist yet
			}
		}

		return true;
	}
	# --------------------------------------------------
	/**
	 *
	 */
	public function performPostInstallTasks() {
	    // process metadata element settings that couldn't be processed during install
	    // (Eg. those for hideIfSelected_*)
	    if (sizeof($this->metadata_element_deferred_settings_processing)) {
	        foreach($this->metadata_element_deferred_settings_processing as $vs_element_code => $va_settings) {
	            if (!($t_element = \ca_metadata_elements::getInstance($vs_element_code))) { continue; }
	            $va_available_settings = $t_element->getAvailableSettings();
	            foreach($va_settings as $vs_setting_name => $va_setting_values) {
	                if (!isset($va_available_settings[$vs_setting_name])) { continue; }
	                
	                if (isset($va_available_settings[$vs_setting_name]['multiple']) && $va_available_settings[$vs_setting_name]['multiple']) {
	                    $t_element->setSetting($vs_setting_name, $va_setting_values);
	                } else {
	                    $t_element->setSetting($vs_setting_name, array_shift($va_setting_values));
	                }
	            }
	        }
	        $t_element->update();
	    } 
	    
		// generate system GUID -- used to identify systems in data sync protocol
		$o_vars = new \ApplicationVars();
		$o_vars->setVar('system_guid', caGenerateGUID());
		$o_vars->save();

		// refresh mapping if ElasticSearch is used
		$o_config = \Configuration::load();
		if ($o_config->get('search_engine_plugin') == 'ElasticSearch') {
			$o_es = new \WLPlugSearchEngineElasticSearch();
			$o_es->refreshMapping(true);
			\CompositeCache::flush();
		}
	}
	# --------------------------------------------------
	/**
	 * Checks if CollectiveAccess tables already exist in the database
	 *
	 * @return boolean Returns true if CA is already installed
	 */
	public function isAlreadyInstalled() {
		$ca_tables = \Datamodel::getTableNames();

		$qr = $this->db->query("SHOW TABLES");

		while($qr->nextRow()) {
			$table = $qr->getFieldAtIndex(0);
			if (in_array($table, $ca_tables)) {
				return true;
			}
		}
		return false;
	}
	# --------------------------------------------------
	/**
	 * Loads CollectiveAccess schema into an empty database
	 *
	 * @param callable $f_callback Function to be called for each SQL statement in the schema. Function is passed four parameters: the SQL code of the statement, the table name, the number of the table being loaded and the total number of tables.
	 * @return boolean Returns true on success, false if an error occurred
	 */
	public function loadSchema($f_callback=null) {

		$vo_config = \Configuration::load();
		if (defined('__CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__') && __CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__ && ($this->overwrite)) {
			$this->db->query('DROP DATABASE IF EXISTS `'.__CA_DB_DATABASE__.'`');
			$this->db->query('CREATE DATABASE `'.__CA_DB_DATABASE__.'`');
			$this->db->query('USE `'.__CA_DB_DATABASE__.'`');
		}
		
		if($this->isAlreadyInstalled()) {
			throw new \Exception("Cannot install because an existing CollectiveAccess installation has been detected.");
		}
		
		// load schema
		if (!($vs_schema = file_get_contents(__CA_BASE_DIR__."/install/inc/schema_mysql.sql"))) {
			throw new \Exception("Could not open schema definition file");
		}
		$va_schema_statements = explode(';', $vs_schema);

		$vn_num_tables = 0;
		foreach($va_schema_statements as $vs_statement) {
			if (!trim($vs_statement)) { continue; }
			if (preg_match('!create table!i', $vs_statement)) {
				$vn_num_tables++;
			}
		}

		$vn_i = 0;
		foreach($va_schema_statements as $vs_statement) {
			if (!trim($vs_statement)) { continue; }

			if (is_callable($f_callback) && preg_match('!create[ ]+table[ ]+([A-Za-z0-9_]+)!i', $vs_statement, $va_matches)) {
				$vn_i++;
				if (file_exists(__CA_MODELS_DIR__.'/'.$va_matches[1].'.php')) {
					include_once(__CA_MODELS_DIR__.'/'.$va_matches[1].'.php');
					$vs_table = \BaseModel::$s_ca_models_definitions[$va_matches[1]]['NAME_PLURAL'];
				} else {
					$vs_table = $va_matches[1];
				}
				$f_callback($vs_statement, $vs_table, $vn_i, $vn_num_tables);
			}
			$this->db->query($vs_statement);
			if ($this->db->numErrors()) {
				throw new \Exception("Error while loading the database schema: ".join("; ",$this->db->getErrors()));
			}
		}
	}
	# --------------------------------------------------
	# PROFILE CONTENT PROCESSING
	# --------------------------------------------------
	public function processLocales() {
		global $g_ui_locale_id, $g_ui_locale;

		$t_locale = new \ca_locales();
		// Find any existing locales
		$locales = $t_locale->getLocaleList(array('index_by_code' => true));
		foreach($locales as $vs_code => $va_locale) {
			$this->locales[$vs_code] = $va_locale['locale_id'];
		}
		
		$locales = $this->parsed_data['locales'];

		foreach($locales as $locale) {
			$t_locale->clear();
			$name = $locale["name"];
			$language = $locale["language"];
			$dialect = $locale["dialect"];
			$country = $locale["country"];
			$dont_use_for_cataloguing = $locale["dontUseForCataloguing"];
			$locale_code = $dialect ? $language."_".$country.'_'.$dialect : $language."_".$country;

			if(isset($this->locales[$locale_code]) && ($locale_id = $this->locales[$locale_code])) { // don't insert duplicate locales
				$t_locale->load($locale_id); // load locale so that we can 'overwrite' any existing attributes/fields
			}
			$t_locale->set('name', $name);
			$t_locale->set('country', $country);
			$t_locale->set('language', $language);
			if($dialect) $t_locale->set('dialect', $dialect);
			
			if (!is_null($dont_use_for_cataloguing)) {
				$t_locale->set('dont_use_for_cataloguing', (bool)$dont_use_for_cataloguing);
			}
			($t_locale->getPrimaryKey() > 0) ? $t_locale->update() : $t_locale->insert();

			if ($t_locale->numErrors()) {
				$this->addError("There was an error while inserting locale {$locale_code}: ".join(" ",$t_locale->getErrors()));
			}
			if ($locale_code === $g_ui_locale && $t_locale->getPrimaryKey()){
				$g_ui_locale_id = $t_locale->getPrimaryKey();
			}
			$this->locales[$locale_code] = $t_locale->getPrimaryKey();
		}

		$locales = $t_locale->getAppConfig()->getList('locale_defaults');
		$locale_id = $t_locale->localeCodeToID($locales[0]);

		if(!$locale_id) {
			throw new \Exception("The locale default is set to a non-existing locale. Try adding '". $locales[0] . "' to your profile.");
		}
		// Ensure the default locale comes first.
		uksort($this->locales, function($a) use ( $locale_id ) {
			return $a === $locale_id;
		});

		return true;
	}
	# --------------------------------------------------
	/**
	 *
	 */
	public function processLists($f_callback=null) {
		$lists = $this->parsed_data['lists'];

		$i = 0;
		$num_lists = sizeof($lists);
		foreach($lists as $list) {
			$list_code = $list['code'];
			$this->logStatus(_t('Processing list with code %1', $list_code));
			if(!($t_list = \ca_lists::find(array('list_code' => $list_code), array('returnAs' => 'firstModelInstance')))) {
				$t_list = new \ca_lists();
			}

			if($t_list->getPrimaryKey()) {
				$this->logStatus(_t('List %1 already exists', $list_code));
			} else {
				$this->logStatus(_t('%1 is a new list', $list_code));
			}


			if($list["deleted"] && $t_list->getPrimaryKey()) {
				$this->logStatus(_t('Deleting list %1', $list_code));
				$t_list->delete(true);
				continue;
			}

			if (is_callable($f_callback)) {
				$vn_i++;

				$f_callback($list_code, $i, $num_lists);
			}

			$t_list->set("list_code", $list_code);
			$t_list->set("is_system_list", intval($list['system']));
			$t_list->set("is_hierarchical", $list['hierarchical']);
			$t_list->set("use_as_vocabulary", $list['vocabulary']);
			if((int)$list['defaultSort'] >= 0) $t_list->set("default_sort",(int)$list['defaultSort']);
			if($t_list->getPrimaryKey()) {
				$t_list->update();
			} else {
				$t_list->insert();
			}
			
			if ($t_list->numErrors()) {
				$this->addError("There was an error while inserting list {$list_code}: ".join(" ",$t_list->getErrors()));
			} else {
				$this->logStatus(_t('Successfully inserted or updated list %1', $list_code));
				
				$this->addLabels($t_list, $list['labels']);
				if ($t_list->numErrors()) {
					$this->addError("There was an error while inserting list label for {$vs_list_code}: ".join(" ",$t_list->getErrors()));
				}
				if($list['items']) {
					if(!$this->processListItems($t_list, $list['items'], null)) {
						return false;
					}
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	/**
	 * @param $t_list ca_lists
	 * @param $po_items SimpleXMLElement
	 * @param $pn_parent_id int
	 * @return bool
	 */
	protected  function processListItems($t_list, $items, $parent_id) {
		foreach($items as $item) {
			$item_value = $item["value"];
			$item_idno = $item["idno"];
			$type = $item["type"];
			$status = $item["status"];
			$access = $item["access"];
			$rank = $item["rank"];
			$vn_enabled = $item["enabled"];
			$vn_default = $item["default"];
			$color = $item["color"];

			$type_id = null;
			if ($type) {
				$type_id = $t_list->getItemIDFromList('list_item_types', $type);
			}

			if (!isset($status)) { $status = 0; }
			if (!isset($access)) { $access = 0; }
			if (!isset($rank)) { $rank = 0; }

			$this->logStatus(_t('Processing list item with idno %1', $item_idno));
			$deleted = $item["deleted"];
			
			if($item_id = caGetListItemID($t_list->get('list_code'), $item_idno, ['dontCache' => true])) {
				$this->logStatus(_t('List item with idno %1 already exists', $item_idno));
				if($deleted) {
					$this->logStatus(_t('Deleting list item with idno %1', $item_idno));
					$t_item = new \ca_list_items($item_id);
					$t_item->delete();
					continue;
				}
				$t_item = $t_list->editItem($item_id, $item_value, $enabled, $default, $parent_id, $item_idno, '', (int)$status, (int)$access, (int)$rank, $color);
			} else {
				$this->logStatus(_t('List item with idno %1 is a new item', $item_idno));
				if ($vb_deleted) {
					continue;
				} else {
					$t_item = $t_list->addItem($item_value, $enabled, $default, $parent_id, $type_id, $item_idno, '', (int)$status, (int)$access, (int)$rank, $color);
				}
			}

			if (($t_list->numErrors() > 0) || !is_object($t_item)) {
				$this->addError("There was an error while inserting list item {$item_idno}: ".join(" ",$t_list->getErrors()));
				return false;
			} else {
				$this->logStatus(_t('Successfully updated/inserted list item with idno %1', $item_idno));
				if($item->settings) {
					$this->_processSettings($t_item, $item['settings']);
					$t_item->update();
					if ($t_item->numErrors()) {
						$this->addError("There was an error while adding a setting for list item with idno {$item_idno}: ".join(" ",$t_item->getErrors()));
					}
				}
				self::addLabels($t_item, $item['labels']);
				if ($t_item->numErrors()) {
					$this->addError("There was an error while inserting list item label for {$item_idno}: ".join(" ",$t_item->getErrors()));
				}
			}

			if (isset($item['items'])) {
				if(!$this->processListItems($t_list, $item['items'], $t_item->getPrimaryKey())) {
					return false;
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	public function processMetadataElements() {
		require_once(__CA_MODELS_DIR__."/ca_lists.php");
		require_once(__CA_MODELS_DIR__."/ca_list_items.php");
		require_once(__CA_MODELS_DIR__."/ca_relationship_types.php");

		$t_rel_types = new \ca_relationship_types();
		$t_list = new \ca_lists();

		$elements = $this->parsed_data['metadataElements'];
		

		foreach($elements as $element_code => $element) {
			if($element_id = $this->processMetadataElement($element, null)) {
				// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of restrictions to include all restrictions!
				if(sizeof($element['typeRestrictions'])) {
					$this->db->query('DELETE FROM ca_metadata_type_restrictions WHERE element_id = ?', $element_id);
				}

				$this->logStatus(_t('Successfully nuked all type restrictions for element %1', $element_code));

				// handle restrictions
				foreach($element['typeRestrictions'] as $restriction) {
					$restriction_code = $restriction["code"];

					if (!($table_num = \Datamodel::getTableNum($restriction['table']))) {
						$this->addError("Invalid table specified for restriction $restriction_code in element $element_code");
						return false;
					}
					$t_instance = \Datamodel::getInstance((string)$restriction['table']);
					$type_id = null;
					$type = trim((string)$restriction['type']);

					// is this restriction further restricted on a specific type? -> get real id from code
					if (strlen($type)>0) {
						// interstitial with type restriction -> code is relationship type code
						if($t_instance instanceof \BaseRelationshipModel) {
							$type_id = $t_rel_types->getRelationshipTypeID($t_instance->tableName(),$type);
						} else { // "normal" type restriction -> code is from actual type list
							$type_list_name = $t_instance->getFieldListCode($t_instance->getTypeFieldName());
							$type_id = $t_list->getItemIDFromList($type_list_name, $type);
						}
					}

					// add restriction
					$t_restriction = new \ca_metadata_type_restrictions();
					$t_restriction->set('table_num', $table_num);
					$t_restriction->set('include_subtypes', (bool)$restriction['includeSubtypes'] ? 1 : 0);
					$t_restriction->set('type_id', $type_id);
					$t_restriction->set('element_id', $element_id);

					$this->_processSettings($t_restriction, $restriction['settings']);
					$t_restriction->insert();

					if ($t_restriction->numErrors()) {
						$this->addError("There was an error while inserting type restriction {$restriction_code} for metadata element {$element_code}: ".join("; ",$t_restriction->getErrors()));
					}

					$this->logStatus(_t('Successfully added type restriction %1 for element %2', $restriction_code, $element_code));
				}
			}
		}
		return true;
	}
	# --------------------------------------------------
	private function processMetadataElement($element, $parent_id) {
		require_once(__CA_MODELS_DIR__."/ca_metadata_elements.php");
		require_once(__CA_MODELS_DIR__."/ca_lists.php");

		$element_code = $element["code"];

		$this->logStatus(_t('Processing metadata element with code %1', $element_code));

		// try to load element by code for potential update. codes are unique, globally
		if(!($t_md_element = \ca_metadata_elements::getInstance($element_code))) {
			$t_md_element = new \ca_metadata_elements();
		}

		if($t_md_element->getPrimaryKey()) {
			$this->logStatus(_t('Metadata element with code %1 already exists', $element_code));
		} else {
			$this->logStatus(_t('Metadata element with code %1 is new', $element_code));
		}

		if($element['deleted'] && $t_md_element->getPrimaryKey()) {
			$this->logStatus(_t('Deleting metadata element with code %1', $element_code));
			$t_md_element->delete(true, ['hard' => true]);
			return false; // we don't want the postprocessing to kick in. our work here is done.
		}

		if (($datatype = \ca_metadata_elements::getAttributeTypeCode($element["datatype"])) === false) {
			return false; // should not happen due to XSD restrictions, but just in case
		}

		$t_lists = new \ca_lists();

		$t_md_element->set('element_code', $element_code);
		$t_md_element->set('parent_id', $parent_id);
		$t_md_element->set('documentation_url',$element['documentationUrl']);
		$t_md_element->set('datatype', $datatype);

		$vs_list = $element["list"];

		if (isset($list) && $list && $t_lists->load(['list_code' => $list])) {
			$list_id = $t_lists->getPrimaryKey();
		} else {
			$list_id = null;
		}
		$t_md_element->set('list_id', $list_id);
		$this->_processSettings($t_md_element, $element['settings'], ['settingsInfo' => $t_md_element->getAvailableSettings()]);

		if($t_md_element->getPrimaryKey()) {
			$t_md_element->update(['noFlush' => true]);
		}else{
			$t_md_element->insert(['noFlush' => true]);
		}

		if ($t_md_element->numErrors()) {
			$this->addError("There was an error while inserting metadata element {$element_code}: ".join(" ",$t_md_element->getErrors()));
			return false;
		}

		$this->logStatus(_t('Successfully inserted/updated metadata element with code %1', $element_code));

		$element_id = $t_md_element->getPrimaryKey();

		// add element labels
		self::addLabels($t_md_element, $element['labels']);

		if ($element['elements']) {
			foreach($element['elements'] as $child) {
				$this->processMetadataElement($child, $element_id);
			}
		}

		return $element_id;
	}
	# --------------------------------------------------
	public function processMetadataDictionary() {
		require_once(__CA_MODELS_DIR__.'/ca_metadata_dictionary_entries.php');

		if(!$this->profile->metadataDictionary) { return true; } // no dict specified. it's optional, so don't barf

		// dictionary entries don't have a code or any other attribute that could be used for
		// identification so we won't support setting them in a base profile, for now ...

		foreach($this->profile->metadataDictionary->children() as $vo_entry) {
			$vs_field = self::getAttribute($vo_entry, "bundle");
			$vs_table = self::getAttribute($vo_entry, "table");
			if(strlen($vs_field)<1) {
				$this->addError("No bundle specified in a metadata dictionary entry. Skipping row.");
				continue;
			}
			
			if(!($vn_table_num = \Datamodel::getTableNum($vs_table))) {
				$this->addError("Table {$vs_table} is invalid for metadata dictionary entry. Skipping row.");
				continue;
			}

			// insert dictionary entry
			$t_entry = new \ca_metadata_dictionary_entries();
			$t_entry->set('bundle_name', $vs_field);
			$t_entry->set('table_num', $vn_table_num);
			$this->_processSettings($t_entry, $vo_entry->settings);

			$t_entry->insert();

			if($t_entry->numErrors() > 0 || !($t_entry->getPrimaryKey()>0)) {
				$this->addError("There were errors while adding dictionary entry: " . join(';', $t_entry->getErrors()));
				return false;
			}

			if($vo_entry->rules) {
				foreach($vo_entry->rules->children() as $vo_rule) {
					$vs_code = self::getAttribute($vo_rule, "code");
					$vs_level = self::getAttribute($vo_rule, "level");

					$t_rule = new \ca_metadata_dictionary_rules();
					$t_rule->set('entry_id', $t_entry->getPrimaryKey());
					$t_rule->set('rule_code', $vs_code);
					$t_rule->set('rule_level', $vs_level);
					$t_rule->set('expression', (string) $vo_rule->expression);
					$this->_processSettings($t_rule, $vo_rule->settings);

					$t_rule->insert();
					if ($t_rule->numErrors()) {
						$this->addError("There were errors while adding dictionary rule: " . join(';', $t_rule->getErrors()));
						continue;
					}
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	public function processUserInterfaces() {
		require_once(__CA_MODELS_DIR__."/ca_editor_uis.php");
		require_once(__CA_MODELS_DIR__."/ca_editor_ui_screens.php");
		require_once(__CA_MODELS_DIR__."/ca_editor_ui_bundle_placements.php");
		require_once(__CA_MODELS_DIR__."/ca_lists.php");
		require_once(__CA_MODELS_DIR__."/ca_list_items.php");
		require_once(__CA_MODELS_DIR__."/ca_relationship_types.php");

		$o_annotation_type_conf = \Configuration::load(\Configuration::load()->get('annotation_type_config'));

		$t_placement = new \ca_editor_ui_bundle_placements();

		$t_list = new \ca_lists();
		$t_rel_types = new \ca_relationship_types();
		$va_uis = [];
		if($this->base_name) { // "merge" profile and its base
			foreach($this->base->userInterfaces->children() as $vo_ui) {
				$va_uis[self::getAttribute($vo_ui, "code")] = $vo_ui;
			}
			foreach($this->profile->userInterfaces->children() as $vo_ui) {
				$va_uis[self::getAttribute($vo_ui, "code")] = $vo_ui;
			}
		} else {
			foreach($this->profile->userInterfaces->children() as $vo_ui) {
				$va_uis[self::getAttribute($vo_ui, "code")] = $vo_ui;
			}
		}

		foreach($va_uis as $vs_ui_code => $vo_ui) {
			$vs_type = self::getAttribute($vo_ui, "type");
			if (!($vn_type = \Datamodel::getTableNum($vs_type))) {
				$this->addError("Invalid type {$vs_type} for UI code {$vs_ui_code}");
				return false;
			}

			$this->logStatus(_t('Processing user interface with code %1', $vs_ui_code));

			// model instance of UI type
			$t_instance = \Datamodel::getInstanceByTableNum($vn_type);

			// create ui row
			if(!($t_ui = \ca_editor_uis::find(array('editor_code' => $vs_ui_code, 'editor_type' =>  $vn_type), array('returnAs' => 'firstModelInstance')))) {
				$t_ui = new \ca_editor_uis();
				$this->logStatus(_t('User interface with code %1 is new', $vs_ui_code));
			} else {
				$this->logStatus(_t('User interface with code %1 already exists', $vs_ui_code));
			}

			if(self::getAttribute($vo_ui, 'deleted') && $t_ui->getPrimaryKey()) {
				$this->logStatus(_t('Deleting user interface with code %1', $vs_ui_code));
				$t_ui->delete(true, array('hard' => true));
				continue;
			}

			$t_ui->set('user_id', null);
			$t_ui->set('is_system_ui', 1);
			$t_ui->set('editor_code', $vs_ui_code);
			$t_ui->set('editor_type', $vn_type);
			if ($vs_color = self::getAttribute($vo_ui, "color")) { $t_ui->set('color', $vs_color); }

			if($t_ui->getPrimaryKey()) {
				$t_ui->update();
			}else{
				$t_ui->insert();
			}

			if ($t_ui->numErrors()) {
				$this->addError("Errors inserting UI {$vs_ui_code}: ".join("; ",$t_ui->getErrors()));
				return false;
			}

			$this->logStatus(_t('Successfully inserted/updated user interface with code %1', $vs_ui_code));

			$vn_ui_id = $t_ui->getPrimaryKey();

			self::addLabelsFromXMLElement($t_ui, $vo_ui->labels, $this->locales);

			$va_annotation_types = $o_annotation_type_conf->get('types');
			
			// create ui type restrictions
			if($vo_ui->typeRestrictions) {
				// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of restrictions to include all restrictions!
				if(sizeof($vo_ui->typeRestrictions->children())) {
					$this->db->query('DELETE FROM ca_editor_ui_type_restrictions WHERE ui_id=?', $vn_ui_id);
				}

				$this->logStatus(_t('Successfully nuked all type restrictions for user interface with code %1', $vs_ui_code));

				foreach($vo_ui->typeRestrictions->children() as $vo_restriction) {
					$vs_restriction_type = self::getAttribute($vo_restriction, "type");

					if (strlen($vs_restriction_type)>0) {
						// interstitial with type restriction -> code is relationship type code
						if($t_instance instanceof \BaseRelationshipModel) {
							$vn_type_id = $t_rel_types->getRelationshipTypeID($t_instance->tableName(),$vs_restriction_type);
						} elseif($t_instance instanceof \ca_representation_annotations) {
							// representation annotation -> code is annotation type from annotation_types.conf
							$vn_type_id = $va_annotation_types[$vs_restriction_type]['typeID'];
						} else { // "normal" type restriction -> code is from actual type list
							$vs_type_list_name = $t_instance->getFieldListCode($t_instance->getTypeFieldName());
							$vn_type_id = $t_list->getItemIDFromList($vs_type_list_name,$vs_restriction_type);
						}
						
						$t_ui->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_restriction, "includeSubtypes")]);
					
						$this->logStatus(_t('Successfully added type restriction %1 for user interface with code %2', $vs_restriction_type, $vs_ui_code));
					}
				}
			}
			
			if ($vs_type_restrictions = self::getAttribute($vo_ui, "typeRestrictions")) {
				// Copy type restrictions listed on the <placement> tag into numeric type_ids stored
				// as settings on the placement record.
				$va_codes = preg_split("![ ,;\|]!", $vs_type_restrictions);
				if ($t_instance instanceof \BaseRelationshipModel) {
					$va_ids = caMakeRelationshipTypeIDList($t_instance->tableNum(), $va_codes);
				} elseif($t_instance instanceof \ca_representation_annotations) {
					$va_ids = [];
					foreach($va_codes as $vs_annotation_type) {
						if(isset($va_annotation_types[$vs_annotation_type]['typeID'])) {
							$va_ids[] = $va_annotation_types[$vs_annotation_type]['typeID'];
						}
					}
				} else {
					$va_ids = caMakeTypeIDList($t_instance->tableNum(), $va_codes, ['dontIncludeSubtypesInTypeRestriction' => true]);
				}
				
				foreach($va_ids as $vn_i => $vn_type_id) {
					$t_ui->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_ui, "includeSubtypes")]);
					$this->logStatus(_t('Successfully added type restriction %1 for user interface with code %2', $va_codes[$vn_i], $vs_ui_code));
				}
			}

			// create ui screens
			foreach($vo_ui->screens->children() as $vo_screen) {
				$vs_screen_idno = self::getAttribute($vo_screen, "idno");
				$vn_is_default = self::getAttribute($vo_screen, "default");

				$this->logStatus(_t('Processing screen with code %1 for user interface with code %2', $vs_screen_idno, $vs_ui_code));

				$t_ui_screens = \ca_editor_ui_screens::find(array(
					'idno' => $vs_screen_idno,
					'ui_id' => $vn_ui_id
				), array('returnAs' => 'firstModelInstance'));

				$t_ui_screens = $t_ui_screens ? $t_ui_screens : new \ca_editor_ui_screens();

				if($t_ui_screens->getPrimaryKey()) {
					$this->logStatus(_t('Screen with code %1 for user interface with code %2 already exists', $vs_screen_idno, $vs_ui_code));
				} else {
					$this->logStatus(_t('Screen with code %1 for user interface with code %2 is new', $vs_screen_idno, $vs_ui_code));
				}

				if(self::getAttribute($vo_screen, 'deleted') && $t_ui_screens->getPrimaryKey()) {
					$this->logStatus(_t('Deleting screen with code %1 for user interface with code %2', $vs_screen_idno, $vs_ui_code));
					$t_ui_screens->delete(true, array('hard' => true));
					continue;
				}

				$t_ui_screens->set('idno',$vs_screen_idno);
				$t_ui_screens->set('ui_id', $vn_ui_id);
				$t_ui_screens->set('is_default', $vn_is_default);
				if ($vs_color = self::getAttribute($vo_screen, "color")) { $t_ui_screens->set('color', $vs_color); }

				if($t_ui_screens->getPrimaryKey()) {
					$t_ui_screens->update();
				}else{
					$t_ui_screens->set('parent_id', null);
					$t_ui_screens->insert();
				}

				if ($t_ui_screens->numErrors()) {
					$this->addError("Errors inserting UI screen {$vs_screen_idno} for UI {$vs_ui_code}: ".join("; ",$t_ui_screens->getErrors()));
					return false;
				}

				$this->logStatus(_t('Successfully updated/inserted screen with code %1 for user interface with code %2', $vs_screen_idno, $vs_ui_code));

				self::addLabelsFromXMLElement($t_ui_screens, $vo_screen->labels, $this->locales);
			
				$va_available_bundles = $t_ui_screens->getAvailableBundles(null,array('dontCache' => true));

				// nuke previous placements. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of placements to include all of them!
				if(sizeof($vo_screen->bundlePlacements->children())) {
					$this->db->query('DELETE FROM ca_editor_ui_bundle_placements WHERE screen_id=?', $t_ui_screens->getPrimaryKey());
				}

				$this->logStatus(_t('Successfully nuked all bundle placements for screen with code %1 for user interface with code %2', $vs_screen_idno, $vs_ui_code));

                // set user and group access
                if($vo_screen->userAccess) {
                    $t_user = new \ca_users();
                    $va_ui_screen_users = [];
                    foreach($vo_screen->userAccess->children() as $vo_permission) {
                        $vs_user = trim((string)self::getAttribute($vo_permission, "user"));
                        $vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

                        if(!$t_user->load(array('user_name' => $vs_user))) { continue; }
                        if($vn_access) {
                            $va_ui_screen_users[$t_user->getUserID()] = $vn_access;
                        } else {
                            $this->addError("User name or access value invalid for UI screen {$vs_screen_idno} (permission item with user name '{$vs_user}')");
                        }
                    }

                    if(sizeof($va_ui_screen_users)>0) {
                        $t_ui_screens->addUsers($va_ui_screen_users);
                    }
                }

                if($vo_screen->groupAccess) {
                    $t_group = new \ca_user_groups();
                    $va_ui_screen_groups = [];
                    foreach($vo_screen->groupAccess->children() as $vo_permission) {
                        $vs_group = trim((string)self::getAttribute($vo_permission, "group"));
                        $vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

                        if(!$t_group->load(array('code' => $vs_group))) { continue; }
                        if($vn_access) {
                            $va_ui_screen_groups[$t_group->getPrimaryKey()] = $vn_access;
                        } else {
                            $this->addError("Group code or access value invalid for UI screen {$vs_screen_idno} (permission item with group code '{$vs_group}')");
                        }
                    }

                    if(sizeof($va_ui_screen_groups)>0) {
                        $t_ui_screens->addUserGroups($va_ui_screen_groups);
                    }
                }
                
                if($vo_screen->roleAccess) {
                    $t_role = new \ca_user_roles();
                    $va_ui_screen_roles = [];
                    foreach($vo_screen->roleAccess->children() as $vo_permission) {
                        $vs_role = trim((string)self::getAttribute($vo_permission, "role"));
                        $vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

                        if(!$t_role->load(array('code' => $vs_role))) { continue; }
                        if(!is_null($vn_access)) {
                            $va_ui_screen_roles[$t_role->getPrimaryKey()] = $vn_access;
                        } else {
                            $this->addError("Role code or access value invalid for UI screen {$vs_screen_idno} (permission item with role code '{$vs_role}')");
                        }
                    }
                    if(sizeof($va_ui_screen_roles)>0) {   
						$all_roles = $t_role->getRoleList();
						foreach($all_roles as $role_id => $role_info) {
							if (!isset($va_ui_screen_roles[$role_id])) { $va_ui_screen_roles[$role_id] = 0; }
						}
                        $t_ui_screens->addUserRoles($va_ui_screen_roles);
                    }
                }

				// create ui bundle placements
				foreach($vo_screen->bundlePlacements->children() as $vo_placement) {
					$vs_placement_code = self::getAttribute($vo_placement, "code");
					$vs_bundle_type_restrictions = self::getAttribute($vo_placement, "typeRestrictions");
					$vs_bundle = trim((string)$vo_placement->bundle);

					if ($vs_bundle_type_restrictions) {
						// Copy type restrictions listed on the <placement> tag into numeric type_ids stored
						// as settings on the placement record.
						if ($t_instance instanceof \BaseRelationshipModel) {
							$va_ids = caMakeRelationshipTypeIDList($t_instance->tableNum(), preg_split("![ ,;\|]!", $vs_bundle_type_restrictions));
						} elseif($t_instance instanceof \ca_representation_annotations) {
							$va_ids = [];
							foreach(explode(',', $vs_bundle_type_restrictions) as $vs_annotation_type) {
								if(isset($va_annotation_types[$vs_annotation_type]['typeID'])) {
									$va_ids[] = $va_annotation_types[$vs_annotation_type]['typeID'];
								}
							}
						} else {
							$va_ids = caMakeTypeIDList($t_instance->tableNum(), preg_split("![ ,;\|]!", $vs_bundle_type_restrictions), ['dontIncludeSubtypesInTypeRestriction' => true]);
						}
						
						if (!$vo_placement->settings) { $vo_placement->addChild("settings"); }
						
						foreach($va_ids as $vn_id) {
							$o_setting = $vo_placement->settings->addChild('setting', $vn_id);
							$o_setting->addAttribute('name', 'bundleTypeRestrictions');
						}
						
						if ($vs_include_subtypes = (bool)self::getAttribute($vo_placement, "includeSubtypes")) {
							$o_setting = $vo_placement->settings->addChild('setting', 1);
							$o_setting->addAttribute('name', 'bundleTypeRestrictionsIncludeSubtypes');
						}
					}
					
					$va_settings = $this->_processSettings(null, $vo_placement->settings, ['settingsInfo' => array_merge($t_placement->getAvailableSettings(), is_array($va_available_bundles[$vs_bundle]['settings']) ? $va_available_bundles[$vs_bundle]['settings'] : [])]);
					$this->logStatus(_t('Adding bundle %1 with code %2 for screen with code %3 and user interface with code %4', $vs_bundle, $vs_placement_code, $vs_screen_idno, $vs_ui_code));

					if (!$t_ui_screens->addPlacement($vs_bundle, $vs_placement_code, $va_settings, null, array('additional_settings' => $va_available_bundles[$vs_bundle]['settings']))) {
						$this->logStatus(join("; ", $t_ui_screens->getErrors()));
					}
				}

				// create ui screen type restrictions
				if($vo_screen->typeRestrictions) {
					// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
					// if we're updating, we expect the list of restrictions to include all of them!
					if(sizeof($vo_screen->typeRestrictions->children())) {
						$this->db->query('DELETE FROM ca_editor_ui_screen_type_restrictions WHERE screen_id=?', $t_ui_screens->getPrimaryKey());
					}

					$this->logStatus(_t('Successfully nuked all type restrictions for screen with code %1 for user interface with code %2', $vs_screen_idno, $vs_ui_code));

					foreach($vo_screen->typeRestrictions->children() as $vo_restriction) {
						$vs_restriction_type = self::getAttribute($vo_restriction, "type");

						if (strlen($vs_restriction_type)>0) {
							// interstitial with type restriction -> code is relationship type code
							if($t_instance instanceof \BaseRelationshipModel) {
								$vn_type_id = $t_rel_types->getRelationshipTypeID($t_instance->tableName(),$vs_restriction_type);
							} elseif($t_instance instanceof \ca_representation_annotations) {
								// representation annotation -> code is annotation type from annotation_types.conf
								$vn_type_id = $va_annotation_types[$vs_restriction_type]['typeID'];
							} else { // "normal" type restriction -> code is from actual type list
								$vs_type_list_name = $t_instance->getFieldListCode($t_instance->getTypeFieldName());
								$vn_type_id = $t_list->getItemIDFromList($vs_type_list_name,$vs_restriction_type);
							}

							if($vn_type_id) {
								$t_ui_screens->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_restriction, "includeSubtypes")]);
							}

							$this->logStatus(_t('Successfully added type restriction %1 for screen with code %2 for user interface with code %3', $vs_restriction_type, $vs_screen_idno, $vs_ui_code));
						}
					}
				}
				
				if ($vs_type_restrictions = self::getAttribute($vo_screen, "typeRestrictions")) {
                    $va_codes = preg_split("![ ,;\|]!", $vs_type_restrictions);
                    if ($t_instance instanceof \BaseRelationshipModel) {
                        $va_ids = caMakeRelationshipTypeIDList($t_instance->tableNum(), $va_codes);
                    } elseif($t_instance instanceof \ca_representation_annotations) {
                        $va_ids = [];
                        foreach($va_codes as $vs_annotation_type) {
                            if(isset($va_annotation_types[$vs_annotation_type]['typeID'])) {
                                $va_ids[] = $va_annotation_types[$vs_annotation_type]['typeID'];
                            }
                        }
                    } else {
                        $va_ids = caMakeTypeIDList($t_instance->tableNum(), $va_codes, ['dontIncludeSubtypesInTypeRestriction' => true]);
                    }
                
                    foreach($va_ids as $vn_i => $vn_type_id) {
                        $t_ui_screens->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_screen, "includeSubtypes")]);
                        $this->logStatus(_t('Successfully added type restriction %1 for screen with code %2 for user interface with code %3', $va_codes[$vn_i], $vs_screen_idno, $vs_ui_code));
                    }
                }
			}

			// set user and group access
			if($vo_ui->userAccess) {
				$t_user = new \ca_users();
				$va_ui_users = [];
				foreach($vo_ui->userAccess->children() as $vo_permission) {
					$vs_user = trim((string)self::getAttribute($vo_permission, "user"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_user->load(array('user_name' => $vs_user))) { continue; }
					if($vn_access) {
						$va_ui_users[$t_user->getUserID()] = $vn_access;
					} else {
						$this->addError("User name or access value invalid for UI {$vs_ui_code} (permission item with user name '{$vs_user}')");
					}
				}

				if(sizeof($va_ui_users)>0) {
					$t_ui->addUsers($va_ui_users);
				}
			}

			if($vo_ui->groupAccess) {
				$t_group = new \ca_user_groups();
				$va_ui_groups = [];
				foreach($vo_ui->groupAccess->children() as $vo_permission) {
					$vs_group = trim((string)self::getAttribute($vo_permission, "group"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_group->load(array('code' => $vs_group))) { continue; }
					if($vn_access) {
						$va_ui_groups[$t_group->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Group code or access value invalid for UI {$vs_ui_code} (permission item with group code '{$vs_group}')");
					}
				}

				if(sizeof($va_ui_groups)>0) {
					$t_ui->addUserGroups($va_ui_groups);
				}
			}
			
			if($vo_ui->roleAccess) {
				$t_role = new \ca_user_roles();
				$va_ui_roles = [];
				foreach($vo_ui->roleAccess->children() as $vo_permission) {
					$vs_role = trim((string)self::getAttribute($vo_permission, "role"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->load(array('code' => $vs_role))) { continue; }
					if(!is_null($vn_access)) {
						$va_ui_roles[$t_role->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Role code or access value invalid for UI {$vs_ui_code} (permission item with role code '{$vs_role}')");
					}
				}

				if(sizeof($va_ui_roles)>0) {
					$all_roles = $t_role->getRoleList();
					foreach($all_roles as $role_id => $role_info) {
						if (!isset($va_ui_roles[$role_id])) { $va_ui_roles[$role_id] = 0; }
					}
					$t_ui->addUserRoles($va_ui_roles);
				}
			}
		}
		return true;
	}
	# --------------------------------------------------
	public function processRelationshipTypes() {
		require_once(__CA_MODELS_DIR__."/ca_relationship_types.php");

		$relationship_types = $this->parsed_data['relationshipTypes'];
		
		$qr_lists = $this->db->query("SELECT * FROM ca_lists");

		$list_names = $list_item_ids = [];
		while($qr_lists->nextRow()) {
			$list_names[$qr_lists->get('list_id')] = $qr_lists->get('list_code');
		}

		// get list items
		$qr_list_item_result = $this->db->query("
			SELECT * 
			FROM ca_list_items cli 
			INNER JOIN ca_list_item_labels AS clil ON clil.item_id = cli.item_id
		");
		while($qr_list_item_result->nextRow()) {
			$type_code = $list_names[$qr_list_item_result->get('list_id')];
			$list_item_ids[$type_code][$qr_list_item_result->get('item_value')] = $qr_list_item_result->get('item_id');
		}

		foreach($relationship_types as $rel_table => $types) {
			$table_num = \Datamodel::getTableNum($rel_table);
			$this->logStatus(_t('Processing relationship types for table %1', $rel_table));

			$t_rel_table = \Datamodel::getInstance($rel_table);

			if (!method_exists($t_rel_table, 'getLeftTableName')) {
				continue;
			}
			$left_table = $t_rel_table->getLeftTableName();
			$right_table = $t_rel_table->getRightTableName();


			$root_type_code = "root_for_{$table_num}";

			/** @var ca_relationship_types $t_rel_type */
			$t_rel_type = \ca_relationship_types::find(
				['type_code' => $root_type_code, 'table_num' => $table_num, 'parent_id' => null],
				['returnAs' => 'firstModelInstance']
			);

			$t_rel_type = $t_rel_type ? $t_rel_type : new \ca_relationship_types();
			// create relationship type root if necessary
			$t_rel_type->set('parent_id', null);
			$t_rel_type->set('type_code', $root_type_code);
			$t_rel_type->set('sub_type_left_id', null);
			$t_rel_type->set('sub_type_right_id', null);
			$t_rel_type->set('table_num', $table_num);
			$t_rel_type->set('rank', 10);
			$t_rel_type->set('is_default', 0);
			if(!$t_rel_type->getPrimaryKey()) { // do nothing if find() found that very root
				$t_rel_type->insert();
			}

			if ($t_rel_type->numErrors()) {
				$this->addError("Errors inserting relationship root for {$vs_table}: ".join("; ",$t_rel_type->getErrors()));
				return false;
			}

			$parent_id = $t_rel_type->getPrimaryKey();

			$this->processRelationshipTypesForTable($types, $table_num, $left_table, $right_table, $parent_id, $list_item_ids);
		}
		return true;
	}
	# --------------------------------------------------
	/** 
	 *
	 */
	private function processRelationshipTypesForTable($relationship_types, $table_num, $left_table, $right_table, $parent_id, $list_item_ids) {
		// nuke caches to be safe
		\ca_relationship_types::$s_relationship_type_id_cache = [];
		\ca_relationship_types::$s_relationship_type_table_cache = [];
		\ca_relationship_types::$s_relationship_type_id_to_code_cache = [];

		$t_rel_type = new \ca_relationship_types();

		$rank_default = (int)$t_rel_type->getFieldInfo('rank', 'DEFAULT');
		foreach($relationship_types as $type) {
			$type_code = $type["code"];
			$default = $type["default"];
			$rank = (int)$type["rank"];

			$this->logStatus(_t('Processing relationship type with code %1', $type_code));

			$t_rel_type = \ca_relationship_types::find(
				['type_code' => $type_code, 'table_num' => $table_num, 'parent_id' => $parent_id],
				['returnAs' => 'firstModelInstance']
			);

			$t_rel_type = $t_rel_type ? $t_rel_type : new \ca_relationship_types();

			if($t_rel_type->getPrimaryKey()) {
				$this->logStatus(_t('Relationship type with code %1 already exists', $type_code));
			} else {
				$this->logStatus(_t('Relationship type with code %1 is new', $type_code));
			}

			if($type["deleted"] && $t_rel_type->getPrimaryKey()) {
				$this->logStatus(_t('Deleting relationship type with code %1', $type_code));
				$t_rel_type->delete(true);
				continue;
			}

			$t_rel_type->set('table_num', $table_num);
			$t_rel_type->set('type_code', $type_code);
			$t_rel_type->set('parent_id', $parent_id);
			$t_rel_type->set('is_default', $default ? 1 : 0);

			if ($vn_rank > 0) {
				$t_rel_type->set("rank", $rank);
			} else {
				$t_rel_type->set("rank", $rank_default);
			}

			if($t_rel_type->getPrimaryKey()) {
				$t_rel_type->update();
			} else {
				$t_rel_type->insert();
			}

			// As of February 2017 "typeRestrictionLeft" is preferred over "subTypeLeft"
			if(
				($vs_left_subtype_code = $type["typeRestrictionLeft"])
			) {
				$t_obj = \Datamodel::getInstance($left_table);
				$vs_list_code = $t_obj->getFieldListCode($t_obj->getTypeFieldName());

				$this->logStatus(_t('Adding left type restriction %1 for relationship type with code %2', $left_subtype_code, $type_code));

				if (isset($list_item_ids[$list_code][$left_subtype_code])) {
					$t_rel_type->set('sub_type_left_id', $list_item_ids[$list_code][$left_subtype_code]);
					
					if(
						($include_subtypes = $type["includeSubtypesLeft"])
					) {
						$t_rel_type->set('include_subtypes_left', (bool)$include_subtypes ? 1 : 0);
					}
					$t_rel_type->update();
				}
			}
			
			if(
				($vs_right_subtype_code = $type["typeRestrictionRight"])
			) {
				$t_obj = \Datamodel::getInstance($right_table);
				$list_code = $t_obj->getFieldListCode($t_obj->getTypeFieldName());

				$this->logStatus(_t('Adding right type restriction %1 for relationship type with code %2', $right_subtype_code, $type_code));

				if (isset($list_item_ids[$list_code][$right_subtype_code])) {
					$t_rel_type->set('sub_type_right_id', $list_item_ids[$list_code][$right_subtype_code]);
					
					if(
						($include_subtypes = $type["includeSubtypesRight"])
					) {
						$t_rel_type->set('include_subtypes_right', (bool)$include_subtypes ? 1 : 0);
					}
					$t_rel_type->update();
				}
			}

			if ($t_rel_type->numErrors()) {
				$this->addError("Errors inserting relationship {$type_code}: ".join("; ",$t_rel_type->getErrors()));
				return false;
			}

			$this->logStatus(_t('Successfully updated/inserted relationship type with code %1', $type_code));

			self::addLabels($t_rel_type, $type['labels']);

			if ($type['types']) {
				$this->processRelationshipTypesForTable($type['types'], $table_num, $left_table, $right_table, $t_rel_type->getPrimaryKey(), $list_item_ids);
			}
		}
	}
	# --------------------------------------------------
	public function processRoles() {
		require_once(__CA_MODELS_DIR__."/ca_user_roles.php");
		$va_roles = [];
		if($this->base_name) { // "merge" profile and its base

			if($this->base->roles) {
				foreach($this->base->roles->children() as $vo_role) {
					$va_roles[self::getAttribute($vo_role, "code")] = $vo_role;
				}
			}
			if($this->profile->roles) {
				foreach($this->profile->roles->children() as $vo_role) {
					$va_roles[self::getAttribute($vo_role, "code")] = $vo_role;
				}
			}
		} else {
			if($this->profile->roles) {
				foreach($this->profile->roles->children() as $vo_role) {
					$va_roles[self::getAttribute($vo_role, "code")] = $vo_role;
				}
			}
		}

		foreach($va_roles as $vs_role_code => $vo_role) {
			$this->logStatus(_t('Processing user role with code %1', $vs_role_code));

			if(!($t_role = \ca_user_roles::find(array('code' => (string)$vs_role_code), array('returnAs' => 'firstModelInstance')))) {
				$this->logStatus(_t('User role with code %1 is new', $vs_role_code));
				$t_role = new \ca_user_roles();
			} else {
				$this->logStatus(_t('User role with code %1 already exists', $vs_role_code));
			}

			if(self::getAttribute($vo_role, "deleted") && $t_role->getPrimaryKey()) {
				$this->logStatus(_t('Deleting user role with code %1', $vs_role_code));
				$t_role->delete(true);
				continue;
			}

			$t_role->set('name', trim((string) $vo_role->name));
			$t_role->set('description', trim((string) $vo_role->description));
			$t_role->set('code', $vs_role_code);

			// add actions
			$va_actions = [];
			if($vo_role->actions) {
				foreach($vo_role->actions->children() as $vo_action) {
					$va_actions[] = trim((string) $vo_action);
				}
				$this->logStatus(_t('Role actions for user role with code %1 are: %2', $vs_role_code, join(',', $va_actions)));
			}
			$t_role->setRoleActions($va_actions);
			if($t_role->getPrimaryKey()) {
				$t_role->update();
			} else {
				$t_role->insert();
			}

			if ($t_role->numErrors()) {
				$this->addError("Errors inserting access role {$vs_role_code}: ".join("; ",$t_role->getErrors()));
				return false;
			}

			$this->logStatus(_t('Successfully updated/inserted user role with code %1', $vs_role_code));

			// add bundle level ACL items
			if($vo_role->bundleLevelAccessControl) {
				// nuke old items
				if(sizeof($vo_role->bundleLevelAccessControl->children()) > 0) {
					$t_role->removeAllBundleAccessSettings();
					$this->logStatus(_t('Successfully nuked all bundle level access control items for user role with code %1', $vs_role_code));
				}

				foreach($vo_role->bundleLevelAccessControl->children() as $vo_permission) {
					$vs_permission_table = self::getAttribute($vo_permission, 'table');
					$vs_permission_bundle = self::getAttribute($vo_permission, 'bundle');
					$vn_permission_access = $this->_convertACLStringToConstant(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->setAccessSettingForBundle($vs_permission_table, $vs_permission_bundle, $vn_permission_access)) {
						$this->addError("Could not add bundle level access control for table '{$vs_permission_table}' and bundle '{$vs_permission_bundle}'. Check the table and bundle names.");
						//return false;
					}

					$this->logStatus(_t('Added bundle level access control item for user role with code %1: table %2, bundle %3, access %4', $vs_role_code, $vs_permission_table, $vs_permission_bundle, $vn_permission_access));
				}
			}

			// add type level ACL items
			if($vo_role->typeLevelAccessControl) {
				// nuke old items
				if(sizeof($vo_role->typeLevelAccessControl->children()) > 0) {
					$t_role->removeAllTypeAccessSettings();
					$this->logStatus(_t('Successfully nuked all type level access control items for user role with code %1', $vs_role_code));
				}

				foreach($vo_role->typeLevelAccessControl->children() as $vo_permission) {
					$vs_permission_table = self::getAttribute($vo_permission, 'table');
					$vs_permission_type = self::getAttribute($vo_permission, 'type');
					$vn_permission_access = $this->_convertACLStringToConstant(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->setAccessSettingForType($vs_permission_table, $vs_permission_type, $vn_permission_access)) {
						$this->addError("Could not add type level access control for table '{$vs_permission_table}' and type '{$vs_permission_type}'. Check the table name and the type code.");
						//return false;
					}

					$this->logStatus(_t('Added type level access control item for user role with code %1: table %2, type %3, access %4', $vs_role_code, $vs_permission_table, $vs_permission_type, $vn_permission_access));
				}
			}

			// add source level ACL items
			if($vo_role->sourceLevelAccessControl) {
				// nuke old items
				if(sizeof($vo_role->sourceLevelAccessControl->children()) > 0) {
					$t_role->removeAllSourceAccessSettings();
					$this->logStatus(_t('Successfully nuked all source level access control items for user role with code %1', $vs_role_code));
				}

				foreach($vo_role->sourceLevelAccessControl->children() as $vo_permission) {
					$vs_permission_table = self::getAttribute($vo_permission, 'table');
					$vs_permission_source = self::getAttribute($vo_permission, 'source');
					$vs_permission_default = self::getAttribute($vo_permission, 'default');
					$vn_permission_access = $this->_convertACLStringToConstant(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->setAccessSettingForSource($vs_permission_table, $vs_permission_source, $vn_permission_access, (bool)$vs_permission_default)) {
						$this->addError("Could not add source level access control for table '{$vs_permission_table}' and source '{$vs_permission_source}'. Check the table name and the source code.");
						//return false;
					}

					$this->logStatus(_t('Added source level access control item for user role with code %1: table %2, source %3, access %4', $vs_role_code, $vs_permission_table, $vs_permission_source, $vn_permission_access));
				}
			}
		}
		return true;
	}
	# --------------------------------------------------
	public function processDisplays() {
		require_once(__CA_MODELS_DIR__."/ca_bundle_displays.php");
		require_once(__CA_MODELS_DIR__."/ca_bundle_display_placements.php");
		require_once(__CA_MODELS_DIR__."/ca_bundle_display_type_restrictions.php");

		$o_config = \Configuration::load();

		$va_displays = [];
		if($this->base_name) { // "merge" profile and its base
			if($this->base->displays) {
				foreach($this->base->displays->children() as $vo_display) {
					$va_displays[self::getAttribute($vo_display, "code")] = $vo_display;
				}
			}

			if($this->profile->displays) {
				foreach($this->profile->displays->children() as $vo_display) {
					$va_displays[self::getAttribute($vo_display, "code")] = $vo_display;
				}
			}
		} else {
			if($this->profile->displays) {
				foreach($this->profile->displays->children() as $vo_display) {
					$va_displays[self::getAttribute($vo_display, "code")] = $vo_display;
				}
			}
		}

		if(sizeof($va_displays) == 0) { return true; }

		foreach($va_displays as $vo_display) {
			$vs_display_code = self::getAttribute($vo_display, "code");
			$vb_system = self::getAttribute($vo_display, "system");
			$vs_table = self::getAttribute($vo_display, "type");
			$vn_table_num = \Datamodel::getTableNum($vs_table);

			$this->logStatus(_t('Processing display with code %1', $vs_display_code));

			if ($o_config->get($vs_table.'_disable')) { continue; }

			if(!($t_display = \ca_bundle_displays::find(array('display_code' => $vs_display_code), array('returnAs' => 'firstModelInstance')))) {
				$this->logStatus(_t('Display with code %1 is new', $vs_display_code));
				$t_display = new \ca_bundle_displays();
			} else {
				$this->logStatus(_t('Display with code %1 already exists', $vs_display_code));
			}

			if(self::getAttribute($vo_display, "deleted") && $t_display->getPrimaryKey()) {
				$t_display->delete(true);
				$this->logStatus(_t('Deleting display with code %1', $vs_display_code));
				continue;
			}

			$t_display->set("display_code", $vs_display_code);
			$t_display->set("is_system", $vb_system);
			$t_display->set("table_num",\Datamodel::getTableNum($vs_table));
			$t_display->set("user_id", 1);		// let administrative user own these

			$this->_processSettings($t_display, $vo_display->settings);

			if($t_display->getPrimaryKey()) {
				$t_display->update();
			} else {
				$t_display->insert();
			}

			if ($t_display->numErrors()) {
				$this->addError("There was an error while inserting display {$vs_display_code}: ".join(" ",$t_display->getErrors()));
			} else {
				$this->logStatus(_t('Successfully updated/inserted display with code %1', $vs_display_code));

				self::addLabelsFromXMLElement($t_display, $vo_display->labels, $this->locales);
				if ($t_display->numErrors()) {
					$this->addError("There was an error while inserting display label for {$vs_display_code}: ".join(" ",$t_display->getErrors()));
				}
				if(!$this->processDisplayPlacements($t_display, $vo_display->bundlePlacements, null)) {
					return false;
				}
			}

			if ($vo_display->typeRestrictions) {
				// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of restrictions to include all restrictions!
				if(sizeof($vo_display->typeRestrictions->children())) {
					$this->db->query('DELETE FROM ca_bundle_display_type_restrictions WHERE display_id=?', $t_display->getPrimaryKey());
					$this->logStatus(_t('Successfully nuked all type restrictions for display with code %1', $vs_display_code));
				}

				foreach($vo_display->typeRestrictions->children() as $vo_restriction) {
					$vs_restriction_code = trim((string)self::getAttribute($vo_restriction, "code"));
					$vs_type = trim((string)self::getAttribute($vo_restriction, "type"));
					
					$t_display->addTypeRestriction(array_pop(caMakeTypeIDList($vn_table_num, [$vs_type], ['dontIncludeSubtypesInTypeRestriction' => true])), ['includeSubtypes' => (bool)$vo_restriction->includeSubtypes ? 1 : 0]);
					
					if ($t_display->numErrors()) {
						$this->addError("There was an error while inserting type restriction {$vs_restriction_code} in display {$vs_display_code}: ".join("; ",$t_display->getErrors()));
					}

					$this->logStatus(_t('Added type restriction with code %1 and type %2 for display with code %3', $vs_restriction_code, $vs_type, $vs_display_code));
				}
			}
			if ($vs_type_restrictions = self::getAttribute($vo_display, "typeRestrictions")) {
				$va_codes = preg_split("![ ,;\|]!", $vs_type_restrictions);
				$va_ids = caMakeTypeIDList($vn_table_num, $va_codes, ['dontIncludeSubtypesInTypeRestriction' => true]);
				
				foreach($va_ids as $vn_i => $vn_type_id) {
					$t_display->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_display, "includeSubtypes")]);
					$this->logStatus(_t('Added type restriction with type %1 for display with code %2', $va_codes[$vn_i], $vs_display_code));
				}
			}

			if($vo_display->userAccess) {
				$t_user = new \ca_users();
				$va_display_users = [];
				foreach($vo_display->userAccess->children() as $vo_permission) {
					$vs_user = trim((string)self::getAttribute($vo_permission, "user"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_user->load(array('user_name' => $vs_user))) { continue; }
					if($vn_access) {
						$va_display_users[$t_user->getUserID()] = $vn_access;
					} else {
						$this->addError("User name or access value invalid for display {$vs_display_code} (permission item with user name '{$vs_user}')");
					}
				}

				if(sizeof($va_display_users)>0) {
					$t_display->addUsers($va_display_users);
				}
			}

			if($vo_display->groupAccess) {
				$t_group = new \ca_user_groups();
				$va_display_groups = [];
				foreach($vo_display->groupAccess->children() as $vo_permission) {
					$vs_group = trim((string)self::getAttribute($vo_permission, "group"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_group->load(array('code' => $vs_group))) { continue; }
					if($vn_access) {
						$va_display_groups[$t_group->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Group code or access value invalid for display {$vs_display_code} (permission item with group code '{$vs_group}')");
					}
				}

				if(sizeof($va_display_groups)>0) {
					$t_display->addUserGroups($va_display_groups);
				}
			}
			
			if($vo_display->roleAccess) {
				$t_role = new \ca_user_roles();
				$va_display_roles = [];
				foreach($vo_display->roleAccess->children() as $vo_permission) {
					$vs_role = trim((string)self::getAttribute($vo_permission, "role"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->load(array('code' => $vs_role))) { continue; }
					if(!is_null($vn_access)) {
						$va_display_roles[$t_role->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Role code or access value invalid for display {$vs_display_code} (permission item with role code '{$vs_role}')");
					}
				}

				if(sizeof($va_display_roles)>0) {
					$all_roles = $t_role->getRoleList();
					foreach($all_roles as $role_id => $role_info) {
						if (!isset($va_display_roles[$role_id])) { $va_display_roles[$role_id] = 0; }
					}
					$t_display->addUserRoles($va_display_roles);
				}
			}

		}

		return true;
	}
	# --------------------------------------------------
	private function processDisplayPlacements($t_display, $po_placements) {
		$va_available_bundles = $t_display->getAvailableBundles(null, array('no_cache' => true));

		// nuke previous placements. there shouldn't be any if we're installing from scratch.
		// if we're updating, we expect the list of restrictions to include all restrictions!
		if(sizeof($po_placements->children())) {
			$this->logStatus(_t('Successfully nuked all placements for display with code %1', $t_display->get('display_code')));
			$this->db->query('DELETE FROM ca_bundle_display_placements WHERE display_id=?', $t_display->getPrimaryKey());
		}

		$vn_i = 1;
		foreach($po_placements->children() as $vo_placement) {
			$vs_code = self::getAttribute($vo_placement, "code");
			$vs_bundle = (string)$vo_placement->bundle;

			$va_settings = $this->_processSettings(null, $vo_placement->settings);
			$t_display->addPlacement($vs_bundle, $va_settings, $vn_i, array('additional_settings' => $va_available_bundles[$vs_bundle]['settings']));
			if ($t_display->numErrors()) {
				$this->addError("There was an error while inserting display placement {$vs_code}: ".join(" ",$t_display->getErrors()));
				return false;
			}

			$this->logStatus(_t('Added bundle placement %1 with code %2 for display with code %3', $vs_bundle, $vs_code, $t_display->get('display_code')));
			$vn_i++;
		}

		return true;
	}
	# --------------------------------------------------
	public function processSearchForms() {
		require_once(__CA_MODELS_DIR__."/ca_search_forms.php");
		require_once(__CA_MODELS_DIR__."/ca_search_form_placements.php");

		$o_config = \Configuration::load();
		$va_forms = [];
		if($this->base_name) { // "merge" profile and its base
			if($this->base->searchForms) {
				foreach($this->base->searchForms->children() as $vo_form) {
					$va_forms[self::getAttribute($vo_form, "code")] = $vo_form;
				}
			}

			if($this->profile->searchForms) {
				foreach($this->profile->searchForms->children() as $vo_form) {
					$va_forms[self::getAttribute($vo_form, "code")] = $vo_form;
				}
			}
		} else {
			if($this->profile->searchForms) {
				foreach($this->profile->searchForms->children() as $vo_form) {
					$va_forms[self::getAttribute($vo_form, "code")] = $vo_form;
				}
			}
		}

		if(sizeof($va_forms) == 0) { return true; }

		foreach($va_forms as $vo_form) {
			$vs_form_code = self::getAttribute($vo_form, "code");
			$vb_system = self::getAttribute($vo_form, "system");
			$vs_table = self::getAttribute($vo_form, "type");
			if (!($t_instance = \Datamodel::getInstanceByTableName($vs_table, true))) { continue; }
			if (method_exists($t_instance, 'getTypeList') && !sizeof($t_instance->getTypeList())) { continue; } // no types configured
			if ($o_config->get($vs_table.'_disable')) { continue; }
			$vn_table_num = (int)\Datamodel::getTableNum($vs_table);

			$this->logStatus(_t('Processing search form with code %1', $vs_form_code));

			if(!($t_form = \ca_search_forms::find(array('form_code' => (string)$vs_form_code, 'table_num' => $vn_table_num), array('returnAs' => 'firstModelInstance')))) {
				$t_form = new \ca_search_forms();
				$this->logStatus(_t('Search form with code %1 is new', $vs_form_code));
			} else {
				$this->logStatus(_t('Search form with code %1 already exists', $vs_form_code));
			}

			if(self::getAttribute($vo_form, "deleted") && $t_form->getPrimaryKey()) {
				$this->logStatus(_t('Deleting search form with code %1', $vs_form_code));
				$t_form->delete(true);
				continue;
			}

			$t_form->set("form_code", (string)$vs_form_code);
			$t_form->set("is_system", (int)$vb_system);
			$t_form->set("table_num", $vn_table_num);

			$this->_processSettings($t_form, $vo_form->settings);

			if($t_form->getPrimaryKey()) {
				$t_form->update();
			} else {
				$t_form->set("user_id", 1);		// let administrative user own these
				$t_form->insert();
			}

			if ($t_form->numErrors()) {
				$this->addError("There was an error while inserting search form {$vs_form_code}: ".join(" ",$t_form->getErrors()));
			} else {
				$this->logStatus(_t('Successfully updated/inserted form with code %1', $vs_form_code));

				self::addLabelsFromXMLElement($t_form, $vo_form->labels, $this->locales);
				if ($t_form->numErrors()) {
					$this->addError("There was an error while inserting search form label for {$vs_form_code}: ".join(" ",$t_form->getErrors()));
				}
				if(!$this->processSearchFormPlacements($t_form, $vo_form->bundlePlacements)) {
					return false;
				}
			}
			
			if ($vo_form->typeRestrictions) {
				// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of restrictions to include all restrictions!
				if(sizeof($vo_form->typeRestrictions->children())) {
					$this->db->query('DELETE FROM ca_search_form_type_restrictions WHERE form_id=?', $t_form->getPrimaryKey());
					$this->logStatus(_t('Successfully nuked all type restrictions for form with code %1', $vs_form_code));
				}

				foreach($vo_form->typeRestrictions->children() as $vo_restriction) {
					$vs_restriction_code = trim((string)self::getAttribute($vo_restriction, "code"));
					$vs_type = trim((string)self::getAttribute($vo_restriction, "type"));
					
					$t_form->addTypeRestriction(array_pop(caMakeTypeIDList($vn_table_num, [$vs_type], ['dontIncludeSubtypesInTypeRestriction' => true])), ['includeSubtypes' => (bool)$vo_restriction->includeSubtypes ? 1 : 0]);

					if ($t_form->numErrors()) {
						$this->addError("There was an error while inserting type restriction {$vs_restriction_code} in form {$vs_form_code}: ".join("; ",$t_form->getErrors()));
					}

					$this->logStatus(_t('Added type restriction with code %1 and type %2 for form with code %3', $vs_restriction_code, $vs_type, $vs_form_code));
				}
			}
			if ($vs_type_restrictions = self::getAttribute($vo_form, "typeRestrictions")) {
				$va_codes = preg_split("![ ,;\|]!", $vs_type_restrictions);
				$va_ids = caMakeTypeIDList($vn_table_num, $va_codes, ['dontIncludeSubtypesInTypeRestriction' => true]);
				
				foreach($va_ids as $vn_i => $vn_type_id) {
					$t_form->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_form, "includeSubtypes")]);
					$this->logStatus(_t('Added type restriction with type %1 for form with code %2', $va_codes[$vn_i], $vs_form_code));
				}
			}

			// set user and group access
			if($vo_form->userAccess) {
				$t_user = new \ca_users();
				$va_form_users = [];
				foreach($vo_form->userAccess->children() as $vo_permission) {
					$vs_user = trim((string)self::getAttribute($vo_permission, "user"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_user->load(array('user_name' => $vs_user))) { continue; }
					if($vn_access) {
						$va_form_users[$t_user->getUserID()] = $vn_access;
					} else {
						$this->addError("User name or access value invalid for search form {$vs_form_code} (permission item with user name '{$vs_user}')");
					}
				}

				if(sizeof($va_form_users)>0) {
					$t_form->addUsers($va_form_users);
				}
			}

			if($vo_form->groupAccess) {
				$t_group = new \ca_user_groups();
				$va_form_groups = [];
				foreach($vo_form->groupAccess->children() as $vo_permission) {
					$vs_group = trim((string)self::getAttribute($vo_permission, "group"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_group->load(array('code' => $vs_group))) { continue; }
					if($vn_access) {
						$va_form_groups[$t_group->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Group code or access value invalid for search form {$vs_form_code} (permission item with group code '{$vs_group}')");
					}
				}

				if(sizeof($va_form_groups)>0) {
					$t_form->addUserGroups($va_form_groups);
				}
			}
			
			if($vo_form->roleAccess) {
				$t_role = new \ca_user_roles();
				$va_form_roles = [];
				foreach($vo_form->roleAccess->children() as $vo_permission) {
					$vs_role = trim((string)self::getAttribute($vo_permission, "role"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->load(array('code' => $vs_role))) { continue; }
					if(!is_null($vn_access)) {
						$va_form_roles[$t_role->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Role code or access value invalid for form {$vs_form_code} (permission item with role code '{$vs_role}')");
					}
				}

				if(sizeof($va_form_roles)>0) {
					$all_roles = $t_role->getRoleList();
					foreach($all_roles as $role_id => $role_info) {
						if (!isset($va_form_roles[$role_id])) { $va_form_roles[$role_id] = 0; }
					}
					$t_form->addUserRoles($va_form_roles);
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	private function processSearchFormPlacements($t_form, $po_placements) {
		$va_available_bundles = $t_form->getAvailableBundles();

		// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
		// if we're updating, we expect the list of restrictions to include all restrictions!
		if(sizeof($po_placements->children())) {
			$this->logStatus(_t('Successfully nuked all placements for form with code %1', $t_form->get('form_code')));
			$this->db->query('DELETE FROM ca_search_form_placements WHERE form_id=?', $t_form->getPrimaryKey());
		}

		$vn_i = 1;
		foreach($po_placements->children() as $vo_placement) {
			$vs_code = self::getAttribute($vo_placement, "code");
			$vs_bundle = (string)$vo_placement->bundle;

			$va_settings = $this->_processSettings(null, $vo_placement->settings);
			$t_form->addPlacement($vs_bundle, $va_settings, $vn_i, array('additional_settings' => $va_available_bundles[$vs_bundle]['settings']));
			if ($t_form->numErrors()) {
				$this->addError("There was an error while inserting search form placement {$vs_code}: ".join(" ",$t_form->getErrors()));
				return false;
			}

			$this->logStatus(_t('Added bundle placement %1 with code %2 for form with code %3', $vs_bundle, $vs_code, $t_form->get('form_code')));
			$vn_i++;
		}

		return true;
	}
	# --------------------------------------------------
	public function processGroups() {

		// Create root group
		$t_user_group = \ca_user_groups::find(array('code' => 'Root', 'parent_id' => null), array('returnAs' => 'firstModelInstance'));
		$t_user_group = $t_user_group ? $t_user_group : new \ca_user_groups();

		$t_user_group->set('name', 'Root');
		if($t_user_group->getPrimaryKey()) {
			$t_user_group->update();
		} else {
			$t_user_group->set('code', 'Root');
			$t_user_group->set('parent_id', null);
			$t_user_group->insert();
		}

		if ($t_user_group->numErrors()) {
			$this->addError("Errors creating root user group 'Root': ".join("; ",$t_user_group->getErrors()));
			return false;
		}
		if($this->base_name) { // "merge" profile and its base
			$va_groups = [];
			if($this->base->groups) {
				foreach($this->base->groups->children() as $vo_group) {
					$va_groups[self::getAttribute($vo_group, "code")] = $vo_group;
				}
			}
			if($this->profile->groups) {
				foreach($this->profile->groups->children() as $vo_group) {
					$va_groups[self::getAttribute($vo_group, "code")] = $vo_group;
				}
			}
		} else {
			if($this->profile->groups) {
				foreach($this->profile->groups->children() as $vo_group) {
					$va_groups[self::getAttribute($vo_group, "code")] = $vo_group;
				}
			}
		}

		if (is_array($va_groups)) {
			foreach($va_groups as $vs_group_code => $vo_group) {
				if(!($t_group = \ca_user_groups::find(array('code' => (string)$vs_group_code), array('returnAs' => 'firstModelInstance')))) {
					$t_group = new \ca_user_groups();
				}

				if(self::getAttribute($vo_group, "deleted") && $t_group->getPrimaryKey()) {
					$t_group->delete(true);
					continue;
				}

				$t_group->set('name', trim((string) $vo_group->name));
				$t_group->set('description', trim((string) $vo_group->description));
				if($t_group->getPrimaryKey()) {
					$t_group->update();
				} else {
					$t_group->set('code', $vs_group_code);
					$t_group->set('parent_id', null);
					$t_group->insert();
				}

				$va_roles = [];

				if($vo_group->roles) {
					foreach($vo_group->roles->children() as $vo_role) {
						$va_roles[] = trim((string) $vo_role);
					}
				}

				$t_group->addRoles($va_roles);

				if ($t_group->numErrors()) {
					$this->addError("Errors inserting user group {$vs_group_code}: ".join("; ",$t_group->getErrors()));
					return false;
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	public function processLogins($pb_create_admin_account=true) {
		$va_logins = [];
		if($this->base_name) { // "merge" profile and its base
			if($this->base->logins) {
				foreach($this->base->logins->children() as $vo_login) {
					$vs_logins[self::getAttribute($vo_login, "user_name")] = $vo_login;
				}
			}
			if($this->profile->logins) {
				foreach($this->profile->logins->children() as $vo_login) {
					$va_logins[self::getAttribute($vo_login, "user_name")] = $vo_login;
				}
			}
		} else {
			if($this->profile->logins) {
				foreach($this->profile->logins->children() as $vo_login) {
					$va_logins[self::getAttribute($vo_login, "user_name")] = $vo_login;
				}
			}
		}

		// If no logins are defined in the profile create an admin login with random password
		if (!sizeof($va_logins) && $pb_create_admin_account) {
			$vs_password = $this->createAdminAccount();
			return array('administrator' => $vs_password);
		}

		$va_login_info = [];

		foreach($va_logins as $vs_user_name => $vo_login) {
			if (!($vs_password = trim((string) self::getAttribute($vo_login, "password")))) {
				$vs_password = caGenerateRandomPassword(8);
			}

			$t_user = new \ca_users();
			$t_user->set('user_name', $vs_user_name = trim((string) self::getAttribute($vo_login, "user_name")));
			$t_user->set('password', $vs_password);
			$t_user->set('fname',  trim((string) self::getAttribute($vo_login, "fname")));
			$t_user->set('lname',  trim((string) self::getAttribute($vo_login, "lname")));
			$t_user->set('email',  trim((string) self::getAttribute($vo_login, "email")));
			$t_user->set('active', 1);
			$t_user->set('userclass', 0);
			$t_user->insert();

			$va_roles = [];
			if($vo_login->role) {
				foreach($vo_login->role as $vo_role) {
					$va_roles[] = trim((string) self::getAttribute($vo_role, "code"));
				}
			}
			if (sizeof($va_roles)) { $t_user->addRoles($va_roles); }


			$va_groups = [];
			if($vo_login->group) {
				foreach($vo_login->group as $vo_group) {
					$va_groups[] = trim((string) self::getAttribute($vo_group, "code"));
				}
			}
			if (sizeof($va_groups)) { $t_user->addToGroups($va_groups); }

			if ($t_user->numErrors()) {
				$this->addError("Errors adding login {$vs_user_name}: ".join("; ",$t_user->getErrors()));
				return false;
			}

			$va_login_info[$vs_user_name] = $vs_password;
		}

		return $va_login_info;
	}
	
	# --------------------------------------------------
	public function processMetadataAlerts() {
		require_once(__CA_MODELS_DIR__."/ca_metadata_alert_rules.php");
		require_once(__CA_MODELS_DIR__."/ca_metadata_alert_triggers.php");

		$o_config = \Configuration::load();
		$va_md_alerts = [];
		if($this->base_name) { // "merge" profile and its base
			if($this->base->metadataAlerts) {
				foreach($this->base->metadataAlerts->children() as $vo_md_alert) {
					$va_md_alerts[self::getAttribute($vo_md_alert, "code")] = $vo_md_alert;
				}
			}

			if($this->profile->metadataAlerts) {
				foreach($this->profile->metadataAlerts->children() as $vo_md_alert) {
					$va_md_alerts[self::getAttribute($vo_md_alert, "code")] = $vo_md_alert;
				}
			}
		} else {
			if($this->profile->metadataAlerts) {
				foreach($this->profile->metadataAlerts->children() as $vo_md_alert) {
					$va_md_alerts[self::getAttribute($vo_md_alert, "code")] = $vo_md_alert;
				}
			}
		}

		if(sizeof($va_md_alerts) == 0) { return true; }

		foreach($va_md_alerts as $vo_md_alert) {
			$vs_alert_code = self::getAttribute($vo_md_alert, "code");
			$vs_table = self::getAttribute($vo_md_alert, "type");
			if (!($t_instance = \Datamodel::getInstanceByTableName($vs_table, true))) { continue; }
			if (method_exists($t_instance, 'getTypeList') && !sizeof($t_instance->getTypeList())) { continue; } // no types configured
			if ($o_config->get($vs_table.'_disable')) { continue; }
			$vn_table_num = (int)\Datamodel::getTableNum($vs_table);

			$this->logStatus(_t('Processing metadata alert with code %1', $vs_alert_code));

			if(!($t_md_alert = \ca_metadata_alert_rules::find(array('code' => (string)$vs_alert_code, 'table_num' => $vn_table_num), array('returnAs' => 'firstModelInstance')))) {
				$t_md_alert = new \ca_metadata_alert_rules();
				$this->logStatus(_t('Metadata alert with code %1 is new', $vs_alert_code));
			} else {
				$this->logStatus(_t('Metadata alert with code %1 already exists', $vs_alert_code));
			}

			if(self::getAttribute($vo_md_alert, "deleted") && $t_md_alert->getPrimaryKey()) {
				$this->logStatus(_t('Deleting metadata alert with code %1', $vs_alert_code));
				$t_md_alert->delete(true);
				continue;
			}

			$t_md_alert->set("code", (string)$vs_alert_code);
			$t_md_alert->set("table_num", $vn_table_num);

			$this->_processSettings($t_md_alert, $vo_md_alert->settings);

			if($t_md_alert->getPrimaryKey()) {
				$t_md_alert->update();
			} else {
				$t_md_alert->set("user_id", 1);		// let administrative user own these
				$t_md_alert->insert();
			}
			
			if ($t_md_alert->numErrors()) {
				$this->addError("There was an error while inserting metadata alert {$vs_alert_code}: ".join(" ",$t_md_alert->getErrors()));
			} else {
				$this->logStatus(_t('Successfully updated/inserted metadata alert with code %1', $vs_alert_code));

				self::addLabelsFromXMLElement($t_md_alert, $vo_md_alert->labels, $this->locales);
				if ($t_md_alert->numErrors()) {
					$this->addError("There was an error while inserting metadata alert label for {$vs_alert_code}: ".join(" ",$t_md_alert->getErrors()));
				}
				if(!$this->processMetadataAlertTriggers($t_md_alert, $vo_md_alert->triggers)) {
					return false;
				}
			}
			
			if ($vo_md_alert->typeRestrictions) {
				// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
				// if we're updating, we expect the list of restrictions to include all restrictions!
				if(sizeof($vo_md_alert->typeRestrictions->children())) {
					$this->db->query('DELETE FROM ca_metadata_alert_rule_type_restrictions WHERE rule_id = ?', [$t_md_alert->getPrimaryKey()]);
					$this->logStatus(_t('Successfully nuked all type restrictions for metadata alert with code %1', $vs_alert_code));
				}

				foreach($vo_md_alert->typeRestrictions->children() as $vo_restriction) {
					$vs_restriction_code = trim((string)self::getAttribute($vo_restriction, "code"));
					$vs_type = trim((string)self::getAttribute($vo_restriction, "type"));
					
					$t_md_alert->addTypeRestriction(array_pop(caMakeTypeIDList($vn_table_num, [$vs_type], ['dontIncludeSubtypesInTypeRestriction' => true])), ['includeSubtypes' => (bool)$vo_restriction->includeSubtypes ? 1 : 0]);
					
					if ($t_md_alert->numErrors()) {
						$this->addError("There was an error while inserting type restriction {$vs_restriction_code} in metadata alert {$vs_alert_code}: ".join("; ",$t_md_alert->getErrors()));
					}

					$this->logStatus(_t('Added type restriction with code %1 and type %2 for metadata alert with code %3', $vs_restriction_code, $vs_type, $vs_alert_code));
				}
			}
			if ($vs_type_restrictions = self::getAttribute($vo_md_alert, "typeRestrictions")) {
				$va_codes = preg_split("![ ,;\|]!", $vs_type_restrictions);
				$va_ids = caMakeTypeIDList($vn_table_num, $va_codes, ['dontIncludeSubtypesInTypeRestriction' => true]);
				
				foreach($va_ids as $vn_i => $vn_type_id) {
					$t_md_alert->addTypeRestriction($vn_type_id, ['includeSubtypes' => self::getAttribute($vo_md_alert, "includeSubtypes")]);
					$this->logStatus(_t('Added type restriction with type %1 for metadata alert with code %2', $va_codes[$vn_i], $vs_alert_code));
				}
			}

			// set user and group access
			if($vo_md_alert->userAccess) {
				$t_user = new \ca_users();
				$va_form_users = [];
				foreach($vo_md_alert->userAccess->children() as $vo_permission) {
					$vs_user = trim((string)self::getAttribute($vo_permission, "user"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if($vn_access && $t_user->load(array('user_name' => $vs_user))) {
						$va_form_users[$t_user->getUserID()] = $vn_access;
					} else {
						$this->addError("User name or access value invalid for metadata alert {$vs_alert_code} (permission item with user name '{$vs_user}')");
					}
				}

				if(sizeof($va_form_users)>0) {
					$t_md_alert->addUsers($va_form_users);
				}
			}

			if($vo_md_alert->groupAccess) {
				$t_group = new \ca_user_groups();
				$va_form_groups = [];
				foreach($vo_md_alert->groupAccess->children() as $vo_permission) {
					$vs_group = trim((string)self::getAttribute($vo_permission, "group"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if($vn_access && $t_group->load(array('code' => $vs_group))) {
						$va_form_groups[$t_group->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Group code or access value invalid for metadata alert {$vs_alert_code} (permission item with group code '{$vs_group}')");
					}
				}

				if(sizeof($va_form_groups)>0) {
					$t_md_alert->addUserGroups($va_form_groups);
				}
			}
			
			if($vo_md_alert->roleAccess) {
				$t_role = new \ca_user_roles();
				$va_form_roles = [];
				foreach($vo_md_alert->roleAccess->children() as $vo_permission) {
					$vs_role = trim((string)self::getAttribute($vo_permission, "role"));
					$vn_access = $this->_convertUserGroupAccessStringToInt(self::getAttribute($vo_permission, 'access'));

					if(!$t_role->load(array('code' => $vs_role))) { continue; }
					if(!is_null($vn_access)) {
						$va_form_roles[$t_role->getPrimaryKey()] = $vn_access;
					} else {
						$this->addError("Role code or access value invalid for metadata alert {$vs_alert_code} (permission item with role code '{$vs_role}')");
					}
				}

				if(sizeof($va_form_roles)>0) {
					$all_roles = $t_role->getRoleList();
					foreach($all_roles as $role_id => $role_info) {
						if (!isset($va_form_roles[$role_id])) { $va_form_roles[$role_id] = 0; }
					}
					$t_md_alert->addUserRoles($va_form_roles);
				}
			}
		}

		return true;
	}
	# --------------------------------------------------
	private function processMetadataAlertTriggers($t_md_alert, $po_triggers) {
		$va_available_triggers = $t_md_alert->getTriggers();

		// nuke previous restrictions. there shouldn't be any if we're installing from scratch.
		// if we're updating, we expect the list of restrictions to include all restrictions!
		if(sizeof($po_triggers->children())) {
			$this->logStatus(_t('Successfully nuked all triggers for metadata alert with code %1', $t_md_alert->get('code')));
			$this->db->query('DELETE FROM ca_metadata_alert_triggers WHERE rule_id = ?', [$t_md_alert->getPrimaryKey()]);
		}

		$vn_i = 0;
		foreach($po_triggers->children() as $vo_trigger) {
			$vs_code = self::getAttribute($vo_trigger, "code");
			$vs_type = self::getAttribute($vo_trigger, "type");
			$vs_metadata_element = self::getAttribute($vo_trigger, "metadataElement");
			$vs_metadata_element_filter = self::getAttribute($vo_trigger, "metadataElementFilter");
			
			if (!($vn_element_id = \ca_metadata_elements::getElementID($vs_metadata_element))) { 
				$this->logStatus(_t('Skipped trigger %1 for metadata alert %2 because element code %3 is invalid', $vs_code, $t_md_alert->get('code'), $vs_metadata_element));
				continue; 
			}

			$va_settings = $this->_processSettings(null, $vo_trigger->settings);
			
			
			//<element code1>:<list item code>|<list item code>|...;<element code2>:<list item code>|<list item code>
			$va_metadata_element_filters = [];
			if(is_array($va_metadata_element_filters_raw = explode(';', $vs_metadata_element_filter)) && sizeof($va_metadata_element_filters_raw)) {
				foreach($va_metadata_element_filters_raw as $vs_metadata_element_filters_raw) {
					if(is_array($va_tmp = explode(":", $vs_metadata_element_filters_raw)) && (sizeof($va_tmp) > 1)) {
						if ($t_element = \ca_metadata_elements::getInstance($va_tmp[0])) {
							if (is_array($va_item_ids = \ca_lists::IDNOsToItemIDs(explode("|", $va_tmp[1]))) && sizeof($va_item_ids)) {
								$va_item_ids = array_keys($va_item_ids);
								$va_metadata_element_filters[$va_tmp[0]] = $va_item_ids;
							}
						}
					}
				}
			}
			$t_trigger = new \ca_metadata_alert_triggers();
			$t_trigger->set('rule_id', $t_md_alert->get('rule_id'));
			$t_trigger->set('element_id', $vn_element_id);
			$t_trigger->set('element_filters', $va_metadata_element_filters);
			$t_trigger->set('trigger_type', $vs_type);
			
			foreach($va_settings as $vs_setting => $vs_setting_value) {
				switch($vs_setting) {
					case 'notificationDeliveryOptions':
						$v = explode('|', $vs_setting_value);
						break;
					default:
						$v = $vs_setting_value;
						break;
				}
				if (!$t_trigger->setSetting($vs_setting, $v)) {
					$this->logStatus(_t('Skipped setting %1 for trigger %2 on metadata alert %3 because value %4 is invalid', $vs_setting, $vs_code, $t_md_alert->get('code'), $vs_setting_value));	
				}
			}
			
			$t_trigger->insert();
			
			if ($t_trigger->numErrors()) {
				$this->addError("There was an error while inserting metadata alert trigger {$vs_code}: ".join(" ",$t_trigger->getErrors()));
				return false;
			}

			$this->logStatus(_t('Added trigger %1 for metadata alert %2', $vs_code, $t_md_alert->get('code')));
			$vn_i++;
		}
		return true;
	}
	# --------------------------------------------------
	public function processMiscHierarchicalSetup() {
		require_once(__CA_MODELS_DIR__."/ca_storage_locations.php");

		#
		# Create roots for storage locations hierarchies
		#
		$t_storage_location = new \ca_storage_locations();
		$t_storage_location->set('status', 0);
		$t_storage_location->set('parent_id', null);
		$t_storage_location->insert();

		if ($t_storage_location->numErrors()) {
			$this->addError("Errors inserting the storage location root: ".join("; ",$t_storage_location->getErrors()));
			return;
		}
	}
	# --------------------------------------------------
	public function createAdminAccount() {

		$ps_password = caGenerateRandomPassword(8);
		$t_user = new \ca_users();
		$t_user->set("user_name", 'administrator');
		$t_user->set("password", $ps_password);
		$t_user->set("email", $this->admin_email);
		$t_user->set("fname", 'CollectiveAccess');
		$t_user->set("lname", 'Administrator');
		$t_user->set("userclass", 0);
		$t_user->set("active", 1);
		$t_user->insert();

		if ($t_user->numErrors()) {
			$this->addError("Errors while adding the default administrator account: ".join("; ",$t_user->getErrors()));
			return false;
		}
		
		if(!$t_user->addRoles(['admin'])) {
			$this->addError("Could not add the <em>admin</em> role to the default administrator account: ".join("; ",$t_user->getErrors()));
			return false;
		}

		return $ps_password;
	}
	# --------------------------------------------------
	private function _processSettings($pt_instance, $settings, $options=null) {
		$settings_list = [];
		
		$settings_info = caGetOption('settingsInfo', $options, []);

		if($settings) {
			foreach($settings as $setting_name => $values_by_locale) {
				foreach($values_by_locale as $locale => $values) {
					// some settings like 'label' or 'add_label' have 'locale' as sub-setting
					if($locale && isset($this->parsed_data['locales'][$locale])) {
						$locale_id = $this->locales[$vs_locale];
					} else {
						$locale_id = null;
					}

					foreach($values as $setting_value) {
						if (isset($settings_info[$setting_name]) && isset($settings_info[$setting_name]['deferred']) && $settings_info[$setting_name]['deferred']) {
							$this->metadata_element_deferred_settings_processing[$pt_instance->get('element_code')][$setting_name][] = $setting_value;
							continue;
						}

						if((strlen($setting_name)>0) && (strlen($setting_value)>0)) { // settings need at least name and value
							$datatype = (int)$pt_instance ? $pt_instance->get('datatype') : null;
							if ($setting_name === 'restrictToTypes' && $t_authority_instance = \AuthorityAttributeValue::elementTypeToInstance($datatype)){
								if ($t_authority_instance instanceof \BaseModelWithAttributes && is_string($setting_value)){
									$type_id = $t_authority_instance->getTypeIDForCode($setting_value);
									if ($type_id){
										$setting_value = $type_id;
									} else {
										$this->addError(
											_t('Failed to lookup type id for type restriction %1 in element %2 as could not retrieve type record. ',
												$setting_value,
												$pt_instance->get('element_code')
											)
										);
									}
								}
							}
							if ($locale) { // settings with locale (those can't repeat)
								$settings_list[$setting_name][$locale] = $setting_value;
							} else {
								// some settings allow multiple values under the same key, for instance restrict_to_types.
								// in those cases $va_settings[$vs_setting_name] becomes an array of values
								if (isset($settings_list[$setting_name]) && (!isset($settings_info[$setting_name]) || ($settings_info[$setting_name]['multiple']))) {
									if (!is_array($settings_list[$setting_name])) {
										$settings_list[$setting_name] = array($settings_list[$setting_name]);
									}
									$settings_list[$setting_name][] = $setting_value;
								} else {
									$settings_list[$setting_name] = $setting_value;
								}
							}
						}
					}

					if (is_object($pt_instance)) {
						foreach($settings_list as $setting_name => $setting_value) {
							$pt_instance->setSetting($setting_name, $setting_value);
						}
					}
				}
			}
		}

		return $settings_list;
	}
	# --------------------------------------------------
	private function _convertACLStringToConstant($ps_name) {
		switch($ps_name) {
			case 'edit':
				return __CA_BUNDLE_ACCESS_EDIT__;
			case 'read':
				return __CA_BUNDLE_ACCESS_READONLY__;
			case 'none':
			default:
				return __CA_BUNDLE_ACCESS_NONE__;
		}
	}
	# --------------------------------------------------
	private function _convertUserGroupAccessStringToInt($ps_name) {
		switch($ps_name) {
			case 'read':
				return 1;
			case 'edit':
				return 2;
			default:
				return 0;
		}
	}
	# --------------------------------------------------
	/**
	 * @return bool
	 */
	protected function loggingStatus() {
		return $this->logging_status;
	}
	# --------------------------------------------------
	protected function logStatus($ps_msg) {
		if($this->loggingStatus()) {
			$this->log->logInfo($ps_msg);
		}
	}
	# --------------------------------------------------
}
