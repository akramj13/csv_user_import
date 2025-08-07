<?php

namespace Drupal\csv_user_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\csv_user_import\Service\CSVUserImportService;

/**
 * Form for importing users from CSV.
 */
class CSVUserImportForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The CSV user import service.
   *
   * @var \Drupal\csv_user_import\Service\CSVUserImportService
   */
  protected $csvUserImportService;

  /**
   * Constructs a new CSVUserImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\csv_user_import\Service\CSVUserImportService $csv_user_import_service
   *   The CSV user import service.
   */
  public function __construct(MessengerInterface $messenger, CSVUserImportService $csv_user_import_service) {
    $this->messenger = $messenger;
    $this->csvUserImportService = $csv_user_import_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('csv_user_import.service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_user_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes'] = [
      'enctype' => 'multipart/form-data',
      'class' => ['csv-user-import-form'],
    ];

    // Attach the CSS library
    $form['#attached']['library'][] = 'csv_user_import/csv_user_import_styles';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<div class="csv-user-import-form-header"><h2>👥 Import User Accounts</h2><p>Upload a CSV file containing user accounts (username, email, role).</p></div>',
    ];

    // Display results if available
    $results = $form_state->get('import_results');
    if (!empty($results)) {
      $form['results'] = [
        '#type' => 'markup',
        '#markup' => $this->buildResultsMarkup($results),
        '#weight' => -10,
      ];
    }

    $form['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV File'),
      '#description' => $this->t('Upload a CSV file with user data. Expected format: username, email, role (one user per row).'),
      '#required' => TRUE,
    ];

    $form['delimiter'] = [
      '#type' => 'select',
      '#title' => $this->t('Delimiter'),
      '#options' => [
        ',' => $this->t('Comma (,)'),
        ';' => $this->t('Semicolon (;)'),
        '\t' => $this->t('Tab'),
        '|' => $this->t('Pipe (|)'),
      ],
      '#default_value' => ',',
      '#description' => $this->t('Select the delimiter used in your CSV file.'),
    ];

    $form['has_header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('File has header row'),
      '#default_value' => TRUE,
      '#description' => $this->t('Check if the first row contains column headers.'),
    ];

    $form['activate_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate users immediately'),
      '#default_value' => TRUE,
      '#description' => $this->t('If checked, imported users will be activated and can log in immediately. If unchecked, users will be created but remain inactive until manually activated.'),
    ];

    $form['send_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send welcome email notifications'),
      '#default_value' => FALSE,
      '#description' => $this->t('If checked, imported users will receive welcome email notifications. Note: This requires proper email configuration.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Users'),
      '#button_type' => 'primary',
    ];

    $form['actions']['help'] = [
      '#type' => 'link',
      '#title' => $this->t('Help & Instructions'),
      '#url' => \Drupal\Core\Url::fromRoute('csv_user_import.help'),
      '#attributes' => ['class' => ['button'], 'target' => '_blank'],
    ];

    $form['actions']['template'] = [
      '#type' => 'link',
      '#title' => $this->t('Download Template'),
      '#url' => \Drupal\Core\Url::fromRoute('csv_user_import.download_template'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $file = file_get_contents($_FILES['files']['tmp_name']['csv_file']);
    if (empty($file)) {
      $form_state->setErrorByName('csv_file', $this->t('The uploaded file is empty or could not be read.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $validators = [
      'file_validate_extensions' => ['csv txt'],
      'file_validate_size' => [25600000], // 25MB
    ];

    $file = file_save_upload('csv_file', $validators, 'temporary://', 0);
    
    if ($file) {
      $delimiter = $form_state->getValue('delimiter');
      $has_header = $form_state->getValue('has_header');
      $activate_users = $form_state->getValue('activate_users');
      $send_notifications = $form_state->getValue('send_notifications');
      
      $result = $this->csvUserImportService->importUsers(
        $file, 
        $delimiter, 
        $has_header, 
        $activate_users, 
        $send_notifications
      );
      
      if ($result['success']) {
        $message = $this->t('Successfully processed @total users: @created created, @skipped skipped, @errors errors.', [
          '@total' => $result['total_processed'],
          '@created' => count($result['created_users']),
          '@skipped' => count($result['skipped_users']),
          '@errors' => count($result['errors']),
        ]);
        
        $this->messenger->addMessage($message);
        
        // Add warnings for skipped users
        if (!empty($result['skipped_users'])) {
          $skipped_message = $this->t('@count users were skipped (duplicates): @usernames', [
            '@count' => count($result['skipped_users']),
            '@usernames' => implode(', ', array_slice($result['skipped_users'], 0, 10)) . (count($result['skipped_users']) > 10 ? '...' : ''),
          ]);
          $this->messenger->addWarning($skipped_message);
        }
        
        // Add errors if any
        if (!empty($result['errors'])) {
          $error_message = $this->t('@count errors occurred during import. Check the detailed results below.', [
            '@count' => count($result['errors']),
          ]);
          $this->messenger->addError($error_message);
        }
        
        // Store results to display in the form
        $form_state->set('import_results', $result);
        $form_state->setRebuild(TRUE);
      } else {
        $this->messenger->addError($this->t('Import failed: @error', ['@error' => $result['error']]));
      }
      
      // Clean up temporary file
      $file->delete();
    } else {
      $this->messenger->addError($this->t('File upload failed.'));
    }
  }

  /**
   * Build markup for displaying import results.
   *
   * @param array $results
   *   The import results array.
   *
   * @return string
   *   HTML markup for the results.
   */
  private function buildResultsMarkup(array $results) {
    $markup = '<div class="csv-user-import-results">';
    $markup .= '<h3>Import Results</h3>';
    
    // Summary
    $markup .= '<div class="import-summary">';
    $markup .= '<p><strong>Total processed:</strong> ' . $results['total_processed'] . '</p>';
    $markup .= '<p><strong>Successfully created:</strong> ' . count($results['created_users']) . '</p>';
    $markup .= '<p><strong>Skipped (duplicates):</strong> ' . count($results['skipped_users']) . '</p>';
    $markup .= '<p><strong>Errors:</strong> ' . count($results['errors']) . '</p>';
    $markup .= '</div>';
    
    // Created users
    if (!empty($results['created_users'])) {
      $markup .= '<h4>✅ Successfully Created Users:</h4>';
      $markup .= '<ul>';
      $display_limit = 20;
      $users_to_display = array_slice($results['created_users'], 0, $display_limit);
      foreach ($users_to_display as $user_info) {
        $markup .= '<li>' . htmlspecialchars($user_info['username']) . ' (' . htmlspecialchars($user_info['email']) . ') - Role: ' . htmlspecialchars($user_info['role']) . '</li>';
      }
      if (count($results['created_users']) > $display_limit) {
        $remaining = count($results['created_users']) - $display_limit;
        $markup .= '<li><em>... and ' . $remaining . ' more users created</em></li>';
      }
      $markup .= '</ul>';
    }
    
    // Skipped users
    if (!empty($results['skipped_users'])) {
      $markup .= '<h4>⚠️ Skipped Users (Already Exist):</h4>';
      $markup .= '<ul>';
      foreach (array_slice($results['skipped_users'], 0, 20) as $skipped_user) {
        $markup .= '<li>' . htmlspecialchars($skipped_user) . '</li>';
      }
      if (count($results['skipped_users']) > 20) {
        $remaining = count($results['skipped_users']) - 20;
        $markup .= '<li><em>... and ' . $remaining . ' more users skipped</em></li>';
      }
      $markup .= '</ul>';
    }
    
    // Errors
    if (!empty($results['errors'])) {
      $markup .= '<h4>❌ Errors:</h4>';
      $markup .= '<ul class="error-list">';
      foreach ($results['errors'] as $error) {
        $markup .= '<li style="color: red;">' . htmlspecialchars($error) . '</li>';
      }
      $markup .= '</ul>';
    }
    
    $markup .= '</div>';
    return $markup;
  }

}
