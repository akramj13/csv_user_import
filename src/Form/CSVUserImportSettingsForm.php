<?php

namespace Drupal\csv_user_import\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for CSV User Import module.
 */
class CSVUserImportSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['csv_user_import.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_user_import_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('csv_user_import.settings');

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure settings for the CSV User Import module.') . '</p>',
    ];

    $form['default_role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Role'),
      '#description' => $this->t('Default role machine name to assign to users when no role is specified in the CSV. Leave empty to use "authenticated".'),
      '#default_value' => $config->get('default_role') ?: 'authenticated',
    ];

    $form['max_import_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Import Size'),
      '#description' => $this->t('Maximum number of users to import in a single batch. Higher numbers may cause timeouts.'),
      '#default_value' => $config->get('max_import_size') ?: 1000,
      '#min' => 1,
      '#max' => 10000,
    ];

    $form['log_imports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Import Operations'),
      '#description' => $this->t('Log detailed information about import operations to the Drupal log.'),
      '#default_value' => $config->get('log_imports') !== FALSE,
    ];

    $form['allow_duplicate_emails'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Duplicate Email Addresses'),
      '#description' => $this->t('If checked, users with duplicate email addresses will be created with modified email addresses. If unchecked, duplicate emails will be skipped.'),
      '#default_value' => $config->get('allow_duplicate_emails') ?: FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('csv_user_import.settings')
      ->set('default_role', $form_state->getValue('default_role'))
      ->set('max_import_size', $form_state->getValue('max_import_size'))
      ->set('log_imports', $form_state->getValue('log_imports'))
      ->set('allow_duplicate_emails', $form_state->getValue('allow_duplicate_emails'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
