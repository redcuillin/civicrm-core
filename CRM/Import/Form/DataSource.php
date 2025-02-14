<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

use Civi\Api4\Utils\CoreUtil;

/**
 * Base class for upload-only import forms (all but Contact import).
 */
abstract class CRM_Import_Form_DataSource extends CRM_Import_Forms {

  /**
   * Set variables up before form is built.
   */
  public function preProcess(): void {
    $this->pushUrlToUserContext();
    // check for post max size
    CRM_Utils_Number::formatUnitSize(ini_get('post_max_size'), TRUE);
    $this->assign('importEntity', $this->getTranslatedEntity());
    $this->assign('importEntities', $this->getTranslatedEntities());
  }

  /**
   * Get the import entity (translated).
   *
   * Used for template layer text.
   *
   * @return string
   */
  protected function getTranslatedEntity(): string {
    return (string) CoreUtil::getInfoItem($this->getBaseEntity(), 'title');
  }

  /**
   * Get the import entity plural (translated).
   *
   * Used for template layer text.
   *
   * @return string
   */
  protected function getTranslatedEntities(): string {
    return (string) CoreUtil::getInfoItem($this->getBaseEntity(), 'title_plural');
  }

  /**
   * Common form elements.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm() {
    $this->assign('errorMessage', $this->getErrorMessage());

    $this->assign('urlPath', 'civicrm/import/datasource');
    $this->assign('urlPathVar', 'snippet=4&user_job_id=' . $this->get('user_job_id'));
    if ($this->isImportDataUploaded()) {
      $this->add('checkbox', 'use_existing_upload', ts('Use data already uploaded'), NULL, FALSE, [
        'onChange' => "
          CRM.$('.crm-import-datasource-form-block-dataSource').toggle();
          CRM.$('#data-source-form-block').toggle()",
      ]);
    }

    $this->add('select', 'dataSource', ts('Data Source'), $this->getDataSources(), TRUE,
      ['onchange' => 'buildDataSourceFormBlock(this.value);']
    );

    $mappingArray = CRM_Core_BAO_Mapping::getCreateMappingValues('Import ' . $this->getBaseEntity());
    $this->add('select', 'savedMapping', ts('Saved Field Mapping'), ['' => ts('- select -')] + $mappingArray);

    if ($loadedMapping = $this->get('loadedMapping')) {
      $this->setDefaults(['savedMapping' => $loadedMapping]);
    }

    //build date formats
    CRM_Core_Form_Date::buildAllowedDateFormats($this);
    // When we call buildDataSourceFields we add them to the form both for purposes of
    // initial display, but also so they are available during `postProcess`. Hence
    // we need to add them to the form when first displaying it, or when a csv has been
    // uploaded or csv described but NOT when the existing file is used. We have
    // to check `_POST` for this because we want them to be not-added BEFORE validation
    // as `buildDataSourceFields` also adds rules, which will run before `use_existing_upload`
    // is treated as submitted.
    if (empty($_POST['use_existing_upload'])) {
      $this->buildDataSourceFields();
    }
    $this->addButtons([
        [
          'type' => 'upload',
          'name' => ts('Continue'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE,
        ],
        [
          'type' => 'cancel',
          'name' => ts('Cancel'),
        ],
    ]);
  }

  public function setDefaultValues() {
    return array_merge($this->dataSourceDefaults, [
      'dataSource' => $this->getDefaultDataSource(),
      'onDuplicate' => CRM_Import_Parser::DUPLICATE_SKIP,
    ]);

  }

  /**
   * Get an error message to assign to the template.
   *
   * @return string
   */
  protected function getErrorMessage(): string {
    return '';
  }

  /**
   * A long-winded way to add one radio element to the form.
   */
  protected function addContactTypeSelector() {
    //contact types option
    $contactTypeOptions = [];
    if (CRM_Contact_BAO_ContactType::isActive('Individual')) {
      $contactTypeOptions['Individual'] = ts('Individual');
    }
    if (CRM_Contact_BAO_ContactType::isActive('Household')) {
      $contactTypeOptions['Household'] = ts('Household');
    }
    if (CRM_Contact_BAO_ContactType::isActive('Organization')) {
      $contactTypeOptions['Organization'] = ts('Organization');
    }
    $this->addRadio('contactType', ts('Contact Type'), $contactTypeOptions);

    $this->setDefaults([
      'contactType' => 'Individual',
    ]);
  }

  /**
   * Store form values.
   *
   * @param array $names
   */
  protected function storeFormValues($names) {
    foreach ($names as $name) {
      $this->set($name, $this->controller->exportValue($this->_name, $name));
    }
  }

  /**
   * Common postProcessing.
   */
  public function postProcess() {
    $this->processDatasource();
    $this->controller->resetPage('MapField');
    parent::postProcess();
  }

  /**
   * Return a descriptive name for the page, used in wizard header.
   *
   * @return string
   */
  public function getTitle() {
    return ts('Upload Data');
  }

  /**
   * Process the datasource submission - setting up the job and data source.
   *
   * @throws \CRM_Core_Exception
   */
  protected function processDatasource(): void {
    try {
      if (!$this->getUserJobID()) {
        $this->createUserJob();
        $this->instantiateDataSource();
      }
      else {
        $submittedValues = $this->getSubmittedValues();
        $fieldsToCopyOver = array_keys(array_diff_key($submittedValues, $this->submittableFields));
        if ($submittedValues['use_existing_upload']) {
          // Use the already saved value.
          $fieldsToCopyOver[] = 'dataSource';
          foreach ($fieldsToCopyOver as $field) {
            $submittedValues[$field] = $this->getUserJobSubmittedValues()[$field];
          }
          $this->updateUserJobMetadata('submitted_values', $submittedValues);
        }
        else {
          $this->flushDataSource();
          $this->updateUserJobMetadata('submitted_values', $submittedValues);
          $this->instantiateDataSource();
        }
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::statusBounce($e->getUserMessage());
    }
  }

  /**
   * Instantiate the datasource.
   *
   * This gives the datasource a chance to do any table creation etc.
   *
   * @throws \CRM_Core_Exception
   */
  private function instantiateDataSource(): void {
    $this->getDataSourceObject()->initialize();
  }

  /**
   * Default values for datasource fields.
   *
   * @var array
   */
  protected $dataSourceDefaults = [];

  /**
   * Set dataSource default values.
   *
   * @param array $dataSourceDefaults
   *
   * @return self
   */
  public function setDataSourceDefaults(array $dataSourceDefaults): self {
    $this->dataSourceDefaults = $dataSourceDefaults;
    return $this;
  }

}
