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
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class delegates to the chosen DataSource to grab the data to be imported.
 */
class CRM_Contact_Import_Form_DataSource extends CRM_Import_Form_DataSource {

  /**
   * Get the name of the type to be stored in civicrm_user_job.type_id.
   *
   * @return string
   */
  public function getUserJobType(): string {
    return 'contact_import';
  }

  /**
   * Build the form object.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    parent::buildQuickForm();

    // duplicate handling options
    $this->addRadio('onDuplicate', ts('For Duplicate Contacts'), [
      CRM_Import_Parser::DUPLICATE_SKIP => ts('Skip'),
      CRM_Import_Parser::DUPLICATE_UPDATE => ts('Update'),
      CRM_Import_Parser::DUPLICATE_FILL => ts('Fill'),
      CRM_Import_Parser::DUPLICATE_NOCHECK => ts('No Duplicate Checking'),
    ]);

    $js = ['onClick' => "buildSubTypes();buildDedupeRules();"];
    // contact types option
    $contactTypeOptions = $contactTypeAttributes = [];
    if (CRM_Contact_BAO_ContactType::isActive('Individual')) {
      $contactTypeOptions['Individual'] = ts('Individual');
      $contactTypeAttributes['Individual'] = $js;
    }
    if (CRM_Contact_BAO_ContactType::isActive('Household')) {
      $contactTypeOptions['Household'] = ts('Household');
      $contactTypeAttributes['Household'] = $js;
    }
    if (CRM_Contact_BAO_ContactType::isActive('Organization')) {
      $contactTypeOptions['Organization'] = ts('Organization');
      $contactTypeAttributes['Organization'] = $js;
    }
    $this->addRadio('contactType', ts('Contact Type'), $contactTypeOptions, [], NULL, FALSE, $contactTypeAttributes);

    $this->addElement('select', 'contactSubType', ts('Subtype'));
    $this->addElement('select', 'dedupe_rule_id', ts('Dedupe Rule'));

    if (CRM_Utils_GeocodeProvider::getUsableClassName()) {
      $this->addElement('checkbox', 'doGeocodeAddress', ts('Geocode addresses during import?'));
    }

    if (Civi::settings()->get('address_standardization_provider') === 'USPS') {
      $this->addElement('checkbox', 'disableUSPS', ts('Disable USPS address validation during import?'));
    }
  }

  /**
   * Set the default values of various form elements.
   *
   * @return array
   *   reference to the array of default values
   */
  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $defaults['contactType'] = 'Individual';
    $defaults['disableUSPS'] = TRUE;

    if ($this->get('loadedMapping')) {
      $defaults['savedMapping'] = $this->get('loadedMapping');
    }

    return $defaults;
  }

  /**
   * Call the DataSource's postProcess method.
   *
   * @throws \CRM_Core_Exception
   */
  public function postProcess() {
    $this->controller->resetPage('MapField');
    $this->processDatasource();
    // @todo - this params are being set here because they were / possibly still
    // are in some places being accessed by forms later in the flow
    // ie CRM_Contact_Import_Form_MapField, CRM_Contact_Import_Form_Preview
    // which was the old way of saving values submitted on this form such that
    // the other forms could access them. Now they should use
    // `getSubmittedValue` or simply not get them if the only
    // reason is to pass to the Parser which can itself
    // call 'getSubmittedValue'
    // Once the mentioned forms no longer call $this->get() all this 'setting'
    // is obsolete.
    $storeParams = [
      'dateFormats' => $this->getSubmittedValue('dateFormats'),
      'savedMapping' => $this->getSubmittedValue('savedMapping'),
    ];

    foreach ($storeParams as $storeName => $value) {
      $this->set($storeName, $value);
    }
    CRM_Core_Session::singleton()->set('dateTypes', $storeParams['dateFormats']);

  }

  /**
   * General function for handling invalid configuration.
   *
   * I was going to statusBounce them all but when I tested I was 'bouncing' to weird places
   * whereas throwing an exception gave no behaviour change. So, I decided to centralise
   * and we can 'flip the switch' later.
   *
   * @param $message
   *
   * @throws \CRM_Core_Exception
   */
  protected function invalidConfig($message) {
    throw new CRM_Core_Exception($message);
  }

  /**
   * Return a descriptive name for the page, used in wizard header
   *
   * @return string
   */
  public function getTitle(): string {
    return ts('Choose Data Source');
  }

}
