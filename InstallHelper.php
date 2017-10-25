<?php

/**
 * @file
 * Contains \InstallHelper.
 *
 * Dependencies:
 * - Helper module.
 */

/**
 * Simplify repetitive install tasks.
 */
class InstallHelper {
  // Status indicators for logging.
  const INFO = 2;
  const GOOD = 1;
  const BAD = 0;

  /**
   * Status labels.
   *
   * @var array
   */
  private static $statusLabel = array(
    self::BAD => 'error',
    self::GOOD => 'status',
    self::INFO => 'warning',
  );

  /**
   * Disable one or more modules.
   *
   * @param mixed $modules
   *   A single module name or array of module names.
   *
   * @throws InstallHelperException
   *   If a module was not disabled.
   */
  public static function disableModules($modules) {
    $modules = (array) $modules;
    module_disable($modules, FALSE);

    foreach ($modules as $module) {
      if (!static::verifyDisabled($module)) {
        throw new InstallHelperException(t("Failed to disable !mod.", array('!mod' => $module)));
      }
    }

    static::log("Disabled modules: " . implode(', ', $modules));
  }

  /**
   * Uninstall one or more modules.
   *
   * @param mixed $modules
   *   A single module name or array of module names.
   *
   * @throws InstallHelperException
   *   If a module was not uninstalled.
   */
  public static function uninstallModules($modules) {
    $modules = (array) $modules;
    $args = array('!mods' => implode(', ', $modules));

    // Disable modules first.
    static::disableModules($modules);

    // Uninstall and verify.
    if (drupal_uninstall_modules($modules, FALSE)) {
      // Verify by checking the system table.
      foreach ($modules as $module) {
        if (!static::verifyUninstalled($module)) {
          throw new InstallHelperException(t("Failed to uninstall !mod.  Update failed.", array('!mod' => $module)));
        }
      }
    }
    else {
      throw new InstallHelperException(t("Failed to uninstall modules !mods. Update failed.", $args));
    }

    static::log("Uninstalled modules: " . $args['!mods']);
  }

  /**
   * Enable one or more modules.
   *
   * @param mixed $modules
   *   A single module name or array of module names.
   *
   * @throws \InstallHelperException
   *   If the modules could not be enabled.
   */
  public static function enableModules($modules) {
    $modules = (array) $modules;

    if (!module_enable($modules)) {
      throw new InstallHelperException(t("Failed to enable !mods.", array('!mods' => implode(', ', $modules))));
    }

    static::log("Enabled modules: " . implode(', ', $modules));
  }

  /**
   * Delete a field, it's instances, data and DB tables.
   *
   * @param mixed $field_names
   *   A single field name or an array of field names.
   *
   * @throws InstallHelperException
   *   If the field fails to delete.
   */
  public static function deleteFields($field_names) {
    foreach ((array) $field_names as $field_name) {
      if ($field = field_info_field($field_name)) {
        try {
          FieldHelper::deleteField($field, TRUE);
          static::log("Deleted field {$field_name}.");
        }
        catch (Exception $e) {
          throw new InstallHelperException($e->getMessage(), self::BAD);
        }
      }
      else {
        static::log("Unable to load field {$field_name} for deleting.", self::INFO);
      }
    }
  }

  /**
   * Delete a field instance.
   *
   * @param string $entity_type
   *   The type of the entity the instance is attached to.
   * @param string $bundle
   *   The bundle the instance is attached to.
   * @param string $field_name
   *   The machine name of this field.
   *
   * @throws InstallHelperException
   *   If the field instance fails to delete.
   */
  public static function deleteFieldInstance($entity_type, $bundle, $field_name) {
    $instance = field_info_instance($entity_type, $field_name, $bundle);
    if ($instance) {
      try {
        FieldHelper::deleteInstance($instance);
        static::log("Deleted field instance {$field_name} on {$entity_type} / {$bundle}.");
      }
      catch (Exception $e) {
        throw new InstallHelperException($e->getMessage(), self::BAD);
      }
    }
    else {
      static::log("Unable to load field instance {$field_name} on {$entity_type}:{$bundle} for deleting.", self::INFO);
    }
  }

  /**
   * Revert an entire feature module or just certain components.
   *
   * If $module is an array of modules and $components is not empty, then those
   * components will be reverted for all $modules listed.  To revert different
   * components in each module, call the method once per module.
   *
   * @param string|array $module
   *   Module(s) to revert.
   * @param string|array $components
   *   Optional component(s) of the module(s) to revert.
   */
  public static function revertFeatures($module, $components = NULL) {
    foreach ((array) $module as $_module) {
      if (empty($components)) {
        features_revert_module($_module);
        static::log("Reverted {$_module}.");
      }
      else {
        $components = (array) $components;
        features_revert(array($_module => $components));
        static::log("Reverted " . implode(', ', $components) . " in {$_module}.");
      }

    }
  }

  /**
   * Lock a whole feature or some of its components.
   *
   * @param string $module
   *   A feature module machine name.
   * @param string|array $components
   *   (optional) One or more components to lock.
   */
  public static function lockFeature($module, $components = NULL) {
    if ($components) {
      foreach ((array) $components as $component) {
        features_feature_lock($module, $component);
        static::log(t("Locked component @comp of feature @module.", array('@component' => $component, '@module' => $module)));
      }
    }
    else {
      features_feature_lock($module);
      static::log(t("Locked feature @module.", array('@module' => $module)));
    }
  }

  /**
   * Set the timing rule for a cron job.
   *
   * @param string $job_name
   *   The full cron job name.
   * @param string $rule
   *   The cron timing rule.
   */
  public static function setCronJobRule($job_name, $rule) {
    static::setCronRule($job_name, $rule);
  }

  /**
   * Set the timing rule for a cron queue.
   *
   * @param string $job_name
   *   The cron queue name, not including the prefix 'queue_'.
   * @param string $rule
   *   The cron timing rule.
   */
  public static function setCronQueueRule($job_name, $rule) {
    static::setCronRule('queue_' . $job_name, $rule);
  }

  /**
   * Helper function to set the elysia settings for a cron task.
   *
   * @param string $job_name
   *   The full cron job name.
   * @param string $rule
   *   The cron timing rule.
   */
  private static function setCronRule($job_name, $rule) {
    elysia_cron_set($job_name, FALSE, array('rule' => $rule));
    static::log("Set cron task {$job_name} to {$rule}.");
  }

  /**
   * Set a variable value.
   *
   * @param string $name
   *   The var name.
   * @param mixed $value
   *   The var value.
   */
  public static function setVariable($name, $value) {
    variable_set($name, $value);
    static::log("Set variable {$name} to {$value}.");
  }

  /**
   * Delete variables.
   *
   * @param string|array $names
   *   The var name or array of names.
   */
  public static function deleteVariables($names) {
    foreach ((array) $names as $name) {
      variable_del($name);
      static::log("Deleted variable {$name}.");
    }
  }

  /**
   * Disable a view.
   *
   * @param string $view_name
   *   The machine name of the view to disable.
   */
  public static function disableView($view_name) {
    $views = variable_get('views_defaults', array());
    $views[$view_name] = TRUE;
    variable_set('views_defaults', $views);
    static::log("Disabled view {$view_name}");
  }

  /**
   * Verify if a module or theme is uninstalled.
   *
   * @param string $module_name
   *   A module or theme name.
   *
   * @return bool|null
   *   TRUE if the module or theme is uninstalled, FALSE otherwise.  NULL if the
   *   module wasn't found.
   */
  protected static function verifyUninstalled($module_name) {
    $sql = "SELECT status, schema_version FROM {system} WHERE name = :name";
    $args = array(':name' => $module_name);

    // $res will be FALSE if there are no results.
    if ($res = db_query($sql, $args)->fetchAssoc()) {
      $status = (int) $res['status'];
      $schema_version = (int) $res['schema_version'];

      return (($status === DRUPAL_DISABLED) && ($schema_version === -1));
    }
  }

  /**
   * Verify that a module was successfully disabled.
   *
   * @param string $module_name
   *   A module or theme name.
   *
   * @return bool|null
   *   TRUE if disabled, FALSE otherwise.  NULL if the module was not found.
   */
  protected static function verifyDisabled($module_name) {
    $sql = "SELECT status FROM {system} WHERE name = :name";
    $args = array(':name' => $module_name);

    $status = db_query($sql, $args)->fetchField();
    if (is_numeric($status)) {
      $status = (int) $status;

      return ($status === DRUPAL_DISABLED);
    }
  }

  /**
   * Helper function to create a redirect.
   *
   * @param string $source
   *   The source URL.
   * @param string $destination
   *   The destination URL.
   * @param array $source_options
   *   (optional) The source options, defaults to an empty array.
   * @param array $destination_options
   *   (optional) The destination options, defaults to an empty array.
   * @param string $langcode
   *   (optional) The redirect language code. Defaults to LANGUAGE_NONE.
   *
   * @see redirect_object_prepare()
   */
  public static function createRedirect($source, $destination, array $source_options = array(), array $destination_options = array(), $langcode = LANGUAGE_NONE) {
    if (!module_exists('redirect')) {
      return;
    }

    $redirect = new stdClass();
    redirect_object_prepare(
      $redirect,
      array(
        'source' => $source,
        'source_options' => $source_options,
        'redirect' => $destination,
        'redirect_options' => $destination_options,
        'language' => $langcode,
      )
    );
    redirect_save($redirect);
    self::log(t('Created redirect from !source to !redirect', array('!source' => $source, '!redirect' => $destination)));
  }

  /**
   * Delete a flag from the system.
   *
   * @param string|array $flag_names
   *   The names of the flags to delete.
   *
   * @throws \InstallHelperException
   *   If the flag is not found.
   */
  public static function deleteFlags($flag_names) {
    if (!module_exists('flag')) {
      return;
    }

    foreach ((array) $flag_names as $flag_name) {
      if ($flag = flag_get_flag($flag_name)) {
        $flag->delete();
        $flag->disable();
        _flag_clear_cache();
        static::log("Deleted flag $flag_name.");
      }
      else {
        throw new InstallHelperException(t("Unable to locate flag @flag for deletion.", array('@flag' => $flag_name)));
      }
    }
  }

  /**
   * Remove records from the system table for dead modules.
   *
   * @param string|array $modules
   *   The name(s) of modules to wipe from the system table.
   */
  public static function systemWipe($modules) {
    db_delete('system')->condition('name', $modules)->execute();

    $names = implode(', ', (array) $modules);
    static::log("Wiped {$names} from system table.");
  }

  /**
   * Manually drop a database table.
   *
   * @param string|array $tables
   *   One or more table names.
   */
  public static function dropTables($tables) {
    foreach ((array) $tables as $table) {
      // Existence check happens in the DB object prior to drop.
      if (db_drop_table($table)) {
        static::log("Dropped table {$table}.");
      }
      else {
        static::log("Table {$table} was not dropped because it does not exist.", self::INFO);
      }
    }
  }

  /**
   * Place a block in a theme's region.
   *
   * @param string $module
   *   The module that owns the block.
   * @param string $delta
   *   The block delta.
   * @param string $theme
   *   The theme in which to place the block.
   * @param string $region
   *   The region in the theme.
   * @param array $settings
   *   Additional settings, such as cache value or weight.
   *
   * @throws \InstallHelperException
   */
  public static function placeBlock($module, $delta, $theme, $region, array $settings = array()) {
    // Define the required fields to set and necessary default values.
    // SQL cols that don't have default values must be set here in  case the
    // merge results in an insert.
    $fields = array(
      'region' => $region,
      'status' => 1,
      'pages' => '',
    );

    // ESI adds cols to the block table.
    if (db_field_exists('block', 'esi_ttl')) {
      $fields['esi_ttl'] = 0;
    }

    // Add any additional settings to the query.
    foreach ($settings as $col => $val) {
      $fields[$col] = $val;
    }

    try {
      // Upsert the record.
      $res = db_merge('block')
        ->key(array(
          'theme' => $theme,
          'module' => $module,
          'delta' => $delta,
        ))
        ->fields($fields)
        ->execute();
    }
    catch (Exception $e) {
      // Cutoff-exception to relay through our custom handler.
      throw new InstallHelperException($e->getMessage(), $e->getCode());
    }

    // Define and set the message.
    $t_args = array(
      '@block' => $delta,
      '@theme' => $theme,
      '@region' => $region,
    );

    // If the block record was successfully updated, it should return that at
    // least one row was affected.
    if ($res > 0) {
      static::log(t("Block @block was placed in the @theme @region.", $t_args));
    }
    else {
      throw new InstallHelperException(t("Failed to place block @block in the @theme @region.", $t_args));
    }
  }

  public static function createTerm($term, $vocabulary) {
    if (is_string($vocabulary)) {
      $vocabulary = taxonomy_vocabulary_machine_name_load($vocabulary);
    }
    elseif (!is_object($vocabulary)) {
      static::log("Invalid vocabulary name!", static::BAD);
      return;
    }

    // Allow terms to be passed in as string (term names) or arrays containing
    // more term data, such as weight.
    if (is_array($term)) {
      $term = (object) $term;
    }
    else {
      $_term = new stdClass();
      $_term->name = $term;
      $term = $_term;
    }

    // Set the vocabulary.
    $term->vid = $vocabulary->vid;

    $t_args = array(
      '@name' => $term->name,
      '@vocab' => $vocabulary->name,
    );

    switch (taxonomy_term_save($term)) {
      case SAVED_NEW:
        static::log(t("Added term '@name' to '@vocab'.", $t_args));
        break;

      case SAVED_UPDATED:
        static::log(t("Updated term '@name' in '@vocab'.", $t_args), static::INFO);
        break;

      default:
        static::log(t("Unable to create term '@name' in '@vocab'.", $t_args), static::BAD);
        break;
    }

    return $term;
  }

  /**
   * Add new terms to a taxonomy vocabulary.
   *
   * @param string|array $terms
   *   A single term name or array of term names.
   * @param string $vocabulary_name
   *   The machine name of the vocabulary to add to.
   *
   * @return array
   *   An array of the created terms.
   */
  public static function createTerms($terms, $vocabulary_name) {
    $vocabulary = taxonomy_vocabulary_machine_name_load($vocabulary_name);

    foreach ((array) $terms as &$term) {
      $term = static::createTerm($term, $vocabulary);
    }

    return $terms;
  }

  /**
   * Toggle the reporting of apachesolr's ReadOnly warnings.
   *
   * Requires patch from https://www.drupal.org/node/2863134.
   *
   * @param bool $toggle
   *   TRUE to enable logging, FALSE to suppress it.
   */
  public static function apachesolrReadOnlyWarnings($toggle) {
    variable_set('apachesolr_report_readonly_to_watchdog', $toggle);

    if ($toggle === FALSE) {
      static::log("Suppressing apachesolr readonly warnings.", self::INFO);
    }
    else {
      static::log("Enabling apachesolr readonly warnings.", self::INFO);
    }
  }

  /**
   * Log a message through the proper method during the update process.
   *
   * @param string $msg
   *   The message to log.
   * @param int $status
   *   The status constant for the message.
   */
  public static function log($msg, $status = self::GOOD) {
    if (drupal_is_cli()) {
      $display_status = static::getIcon($status);
      print "{$display_status} {$msg}\n";
    }
    else {
      $display_status = self::$statusLabel[$status];
      drupal_set_message($msg, $display_status);
    }
  }

  /**
   * Get a color-coded icon.
   *
   * @param int $status
   *   A log message status constant.
   *
   * @return string
   *   The colorized icon.
   */
  private static function getIcon($status) {
    switch ($status) {
      case self::BAD:
        return static::formatRed('✗');

      case self::GOOD:
        return static::formatGreen('✓');

      case self::INFO:
      default:
        return static::formatYellow('⚠︎︎');
    }
  }

  /**
   * Make a string green.
   *
   * @param string $string
   *   A string.
   *
   * @return string
   *   The string formatted to appear green.
   */
  public static function formatGreen($string) {
    $green = "\033[1;32;40m\033[1m%s\033[0m";
    $colored = sprintf($green, $string);

    return $colored;
  }

  /**
   * Make a string red.
   *
   * @param string $string
   *   A string.
   *
   * @return string
   *   The string formatted to appear red.
   */
  public static function formatRed($string) {
    $red = "\033[31;40m\033[1m%s\033[0m";
    $colored = sprintf($red, $string);

    return $colored;
  }

  /**
   * Make a string yello.
   *
   * @param string $string
   *   A string.
   *
   * @return string
   *   The string formatted to appear yellow.
   */
  public static function formatYellow($string) {
    $yellow = "\033[1;33;40m\033[1m%s\033[0m";
    $colored = sprintf($yellow, $string);

    return $colored;
  }

}

/**
 * Handle logging of exceptions to the terminal.
 */
class InstallHelperException extends DrupalUpdateException {

  /**
   * {@inheritdoc}
   */
  public function __construct($message = "", $code = 0, \Exception $previous = NULL) {
    // Log the custom error message ourselves.
    InstallHelper::log($message, InstallHelper::BAD);

    // Override the exception message as we already logged the custom message.
    $function = $this->getUpdateFunction();
    $message = InstallHelper::formatRed("Update $function failed!");

    parent::__construct($message, $code, $previous);
  }

  /**
   * Get the function name of the update hook.
   *
   * @return string|null
   *   The function name if found.  NULL otherwise.
   */
  private function getUpdateFunction() {
    foreach ($this->getTrace() as $item) {
      if (preg_match('/_\d{4}$/', $item['function'])) {
        return $item['function'];
      }
    }
  }

}

/**
 * Class BatchUpdateHelper.
 *
 * Facilitate simpler batch processing in update hooks.
 */
class BatchUpdateHelper {

  /**
   * The sandbox array from the update hook.
   *
   * @var array
   */
  private $sandbox;

  /**
   * Flag to determine whether the batch process has been initialized yet.
   *
   * @var bool
   */
  private $initialized = FALSE;

  /**
   * The original ids to process.
   *
   * @var array
   */
  private $ids = array();

  /**
   * The total number of items to process.
   *
   * @var int
   */
  private $totalCount = 0;

  /**
   * The number of items that have been processed.
   *
   * @var int
   */
  private $processed = 0;

  /**
   * The number of items to process on each run.
   *
   * @var int
   */
  private $perRun = 100;

  /**
   * Singleton loader to fetch the existing batch process.
   *
   * @param array $sandbox
   *   The sandbox array from the update hook.
   * @param string $key
   *   A unique identifier for a batch process.  Usually __FUNCTION__ is used.
   *
   * @return static
   *   This batch object.
   */
  public static function load(array &$sandbox, $key) {
    static $instance;

    if (!isset($instance[$key])) {
      $instance[$key] = new static($sandbox);
    }

    return $instance[$key];
  }

  /**
   * BatchUpdateHelper constructor.
   *
   * @param array $sandbox
   *   The sandbox array from the update hook.
   */
  public function __construct(array &$sandbox) {
    $this->sandbox = &$sandbox;
  }

  /**
   * Conditional wrapper to setup a batch process.
   *
   * @param string $title
   *   An optional title to print when starting a batch process.
   *
   * @return bool
   *   TRUE if the process needs to be initialized.
   */
  public function initialize($title = '') {
    if ($this->initialized) {
      return FALSE;
    }
    else {
      InstallHelper::log(t("Starting Batch Process: @title", array('@title' => $title)), InstallHelper::INFO);
      timer_start('update_hook_batch_helper');

      $this->initialized = TRUE;
      return TRUE;
    }
  }

  /**
   * Store an array of identifying info.
   *
   * @param array $ids
   *   Any array of identifying information.
   *
   * @return $this
   *   Chainable object reference.
   */
  public function setIds(array $ids) {
    $this->ids = $ids;
    $this->totalCount = count($ids);
    return $this;
  }

  /**
   * Set the number of items to process on each batch run.
   *
   * @param int $per_run
   *   The number of items to process on each batch run.
   *
   * @return $this
   *   Chainable object reference.
   */
  public function processPerRun($per_run = 100) {
    $this->perRun = $per_run;
    return $this;
  }

  /**
   * Process a single item.
   *
   * @param callable $function
   *   A callback function to do the processing.
   */
  public function run(callable $function) {
    // Process the batch.
    for ($i = 0; !empty($this->ids) && $i < $this->perRun; $i++) {
      $value = array_shift($this->ids);

      $function($value);

      $this->processed++;
    }

    // Log the process.
    $args = array(
      '!done' => $this->processed,
      '!total' => $this->totalCount,
      '!pct' => @round(($this->processed / $this->totalCount) * 100, 2),
    );
    InstallHelper::log(t('Completed !done/!total (!pct%)', $args));

    // Mark the process as completed or not.
    $this->sandbox['#finished'] = $this->isFinished();

    // If finished, print the timer.
    if ($this->isFinished()) {
      $timer = timer_stop('update_hook_batch_helper');
      InstallHelper::log(t("Batch completed in !time.", array('!time' => format_interval($timer['time'] / 1000, 3))));
    }
  }

  /**
   * Determine if the batch is finished.
   *
   * @return bool
   *   TRUE if all items have been processed.
   */
  public function isFinished() {
    return empty($this->ids);
  }

}
