<?php

namespace Drupal\csv_user_import\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\Component\Transliteration\TransliterationInterface;
/**
 * Service for handling CSV user import operations.
 */
class CSVUserImportService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new CSVUserImportService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory, 
    EntityTypeManagerInterface $entity_type_manager, 
    TransliterationInterface $transliteration, 
    ConfigFactoryInterface $config_factory
  ) {
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->transliteration = $transliteration;
    $this->configFactory = $config_factory;
  }

  /**
   * Import users from CSV file.
   *
   * @param \Drupal\file\Entity\File $file
   *   The uploaded CSV file.
   * @param string $delimiter
   *   The CSV delimiter.
   * @param bool $has_header
   *   Whether the file has a header row.
   * @param bool $activate_users
   *   Whether to activate users immediately.
   * @param bool $send_notifications
   *   Whether to send welcome email notifications.
   *
   * @return array
   *   Result array with success status and processing details.
   */
  public function importUsers(File $file, $delimiter = ',', $has_header = TRUE, $activate_users = TRUE, $send_notifications = FALSE) {
    $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    $handle = fopen($file_path, 'r');
    
    if (!$handle) {
      return ['success' => FALSE, 'error' => 'Could not open file'];
    }

    $config = $this->configFactory->get('csv_user_import.settings');
    $max_import_size = $config->get('max_import_size') ?: 1000;
    $default_role = $config->get('default_role') ?: 'authenticated';
    $log_imports = $config->get('log_imports') !== FALSE;
    $allow_duplicate_emails = $config->get('allow_duplicate_emails') ?: FALSE;

    $total_processed = 0;
    $created_users = [];
    $skipped_users = [];
    $errors = [];
    $row_number = 0;
    
    // Skip header row if present
    if ($has_header) {
      $headers = fgetcsv($handle, 0, $delimiter);
      $row_number++;
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE && $total_processed < $max_import_size) {
      $row_number++;
      $total_processed++;
      
      try {
        $user_data = $this->parseUserData($data, $row_number, $default_role);
        if (!$user_data) {
          continue; // Skip invalid rows
        }

        // Check if user already exists
        if ($this->userExists($user_data['username'], $user_data['email'])) {
          $skipped_users[] = $user_data['username'];
          if ($log_imports) {
            $this->loggerFactory->get('csv_user_import')->info('Skipped existing user: @username (@email)', [
              '@username' => $user_data['username'],
              '@email' => $user_data['email'],
            ]);
          }
          continue;
        }

        // Handle duplicate emails if allowed
        if ($allow_duplicate_emails && $this->emailExists($user_data['email'])) {
          $user_data['email'] = $this->generateUniqueEmail($user_data['email']);
        }

        // Create the user
        $user = $this->createUser($user_data, $activate_users);
        if ($user) {
          $created_users[] = [
            'username' => $user_data['username'],
            'email' => $user_data['email'],
            'role' => $user_data['role'],
            'uid' => $user->id(),
          ];

          // Send notification if requested
          if ($send_notifications && $activate_users) {
            $this->sendWelcomeNotification($user);
          }

          if ($log_imports) {
            $this->loggerFactory->get('csv_user_import')->info('Created user: @username (@email) with role @role', [
              '@username' => $user_data['username'],
              '@email' => $user_data['email'],
              '@role' => $user_data['role'],
            ]);
          }
        }
      } catch (\Exception $e) {
        $errors[] = "Row {$row_number}: " . $e->getMessage();
        $this->loggerFactory->get('csv_user_import')->error('Error processing user at row @row: @message', [
          '@row' => $row_number,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    fclose($handle);
    
    // Log summary
    if ($log_imports) {
      $this->loggerFactory->get('csv_user_import')->info('Import completed: @created created, @skipped skipped, @errors errors', [
        '@created' => count($created_users),
        '@skipped' => count($skipped_users),
        '@errors' => count($errors),
      ]);
    }

    return [
      'success' => TRUE,
      'total_processed' => $total_processed,
      'created_users' => $created_users,
      'skipped_users' => $skipped_users,
      'errors' => $errors,
    ];
  }

  /**
   * Parse user data from CSV row.
   *
   * @param array $data
   *   The CSV row data.
   * @param int $row_number
   *   The row number for error reporting.
   * @param string $default_role
   *   The default role to assign.
   *
   * @return array|null
   *   The parsed user data or NULL if invalid.
   *
   * @throws \Exception
   *   If there's an error parsing the data.
   */
  private function parseUserData(array $data, $row_number, $default_role) {
    // Expecting 3 columns: username, email, role
    if (empty($data) || count($data) < 2) {
      throw new \Exception("Insufficient data in row (expected at least username and email)");
    }

    $username = trim($data[0]);
    $email = trim($data[1]);
    $role = isset($data[2]) ? trim($data[2]) : $default_role;

    // Validate required fields
    if (empty($username)) {
      throw new \Exception("Username is empty");
    }

    if (empty($email)) {
      throw new \Exception("Email is empty");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \Exception("Invalid email format: {$email}");
    }

    // Validate username format (Drupal requirements)
    if (!preg_match('/^[a-zA-Z0-9@._-]+$/', $username)) {
      throw new \Exception("Invalid username format: {$username} (only letters, numbers, @, ., _, - allowed)");
    }

    // Validate role exists
    if (!$this->roleExists($role)) {
      throw new \Exception("Invalid role: {$role}");
    }

    return [
      'username' => $username,
      'email' => $email,
      'role' => $role,
    ];
  }

  /**
   * Check if a user already exists with the given username or email.
   *
   * @param string $username
   *   The username to check.
   * @param string $email
   *   The email to check.
   *
   * @return bool
   *   TRUE if user exists, FALSE otherwise.
   */
  private function userExists($username, $email) {
    $existing_by_name = user_load_by_name($username);
    $existing_by_email = user_load_by_mail($email);
    
    return ($existing_by_name !== FALSE || $existing_by_email !== FALSE);
  }

  /**
   * Check if an email already exists.
   *
   * @param string $email
   *   The email to check.
   *
   * @return bool
   *   TRUE if email exists, FALSE otherwise.
   */
  private function emailExists($email) {
    return user_load_by_mail($email) !== FALSE;
  }

  /**
   * Generate a unique email address.
   *
   * @param string $original_email
   *   The original email address.
   *
   * @return string
   *   A unique email address.
   */
  private function generateUniqueEmail($original_email) {
    $parts = explode('@', $original_email);
    $local_part = $parts[0];
    $domain_part = $parts[1];
    
    $counter = 1;
    do {
      $new_email = $local_part . '+' . $counter . '@' . $domain_part;
      $counter++;
    } while ($this->emailExists($new_email) && $counter < 1000);

    return $new_email;
  }

  /**
   * Check if a role exists.
   *
   * @param string $role_id
   *   The role machine name.
   *
   * @return bool
   *   TRUE if role exists, FALSE otherwise.
   */
  private function roleExists($role_id) {
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $role = $role_storage->load($role_id);
    return $role !== NULL;
  }

  /**
   * Create a new user account.
   *
   * @param array $user_data
   *   The user data array.
   * @param bool $activate
   *   Whether to activate the user.
   *
   * @return \Drupal\user\Entity\User|null
   *   The created user entity or NULL on failure.
   */
  private function createUser(array $user_data, $activate = TRUE) {
    try {
      // Create user entity
      $user = User::create([
        'name' => $user_data['username'],
        'mail' => $user_data['email'],
        'status' => $activate ? 1 : 0,
        'init' => $user_data['email'],
      ]);

      // Set language preferences
      $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
      $user->set('langcode', $language);
      $user->set('preferred_langcode', $language);
      $user->set('preferred_admin_langcode', $language);

      // Add role (authenticated is added automatically)
      if ($user_data['role'] !== 'authenticated') {
        $user->addRole($user_data['role']);
      }

      // The genpass module will automatically generate a password
      // when the user is saved without a password
      $user->enforceIsNew();
      
      if ($activate) {
        $user->activate();
      }

      // Save the user
      $user->save();

      return $user;
    } catch (\Exception $e) {
      throw new \Exception("Failed to create user {$user_data['username']}: " . $e->getMessage());
    }
  }

  /**
   * Send welcome notification to user.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   */
  private function sendWelcomeNotification(User $user) {
    try {
      // Send admin-created user notification
      _user_mail_notify('register_admin_created', $user);
    } catch (\Exception $e) {
      $this->loggerFactory->get('csv_user_import')->warning('Failed to send welcome notification to @username: @message', [
        '@username' => $user->getAccountName(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Validate CSV file structure.
   *
   * @param \Drupal\file\Entity\File $file
   *   The uploaded CSV file.
   * @param string $delimiter
   *   The CSV delimiter.
   *
   * @return array
   *   Validation result with success status and error message.
   */
  public function validateCSVFile(File $file, $delimiter = ',') {
    $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    $handle = fopen($file_path, 'r');
    
    if (!$handle) {
      return ['success' => FALSE, 'error' => 'Could not open file'];
    }

    // Check first few rows
    $row_count = 0;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE && $row_count < 5) {
      $row_count++;
      if (empty($data) || count($data) < 2) {
        fclose($handle);
        return [
          'success' => FALSE, 
          'error' => "Row {$row_count} is empty or has insufficient data (expected at least username and email)"
        ];
      }
    }

    fclose($handle);
    return ['success' => TRUE];
  }

}
