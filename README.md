# CSV User Import Module

A Drupal module that allows administrators to bulk import user accounts from CSV files.

## Features

- **CSV File Upload**: Upload CSV files with user data (username, email, role)
- **Automatic Password Generation**: Integrates with the genpass module to automatically generate secure passwords
- **Role Assignment**: Assign user roles during the import process
- **Duplicate Detection**: Prevents creation of duplicate users based on username or email
- **Detailed Reporting**: Shows created users, skipped duplicates, and any errors
- **Configurable Settings**: Customize default roles, import limits, and duplicate handling
- **Comprehensive Logging**: Logs all import operations for audit purposes

## Requirements

- Drupal 10.4+ or Drupal 11+
- PHP 8.3+
- **genpass module** (for automatic password generation)

## Installation

1. Download or clone this module to `web/modules/custom/csv_user_import/`
2. Enable the genpass module first: `drush en genpass`
3. Enable this module: `drush en csv_user_import`
4. Configure permissions for appropriate user roles
5. Access the import form at `/admin/people/csv-user-import`

## CSV Format

Your CSV file should have exactly 3 columns:

```csv
username,email,role
john_doe,john@example.com,administrator
jane_smith,jane@example.com,editor
bob_wilson,bob@example.com,authenticated
```

### Column Requirements:

- **Column 1 (Username)**: Must be unique, only a-Z, 0-9, -, _, @ allowed
- **Column 2 (Email)**: Must be valid email format and unique
- **Column 3 (Role)**: Must be existing role machine name (e.g., administrator, editor, authenticated)

## Usage

### Option 1: Via People Administration Page
1. Navigate to **Administration > People** (`/admin/people`)
2. Click the **"CSV User Import"** button in the action bar
3. Upload your CSV file and configure settings
4. Click **Import Users** to process the file

### Option 2: Direct Navigation
1. Navigate to **Administration > People > CSV User Import** (`/admin/people/csv-user-import`)
2. Upload your CSV file
3. Configure delimiter settings (usually comma)
4. Choose whether users should be activated immediately
5. Optionally enable welcome email notifications
6. Click **Import Users** to process the file
7. Review the detailed results showing created users, skipped duplicates, and errors

## Configuration

Configure the module at **Administration > Configuration > People > CSV User Import Settings**:

- **Default Role**: Role to assign when no role is specified in CSV
- **Maximum Import Size**: Limit number of users imported in one batch
- **Log Import Operations**: Enable detailed logging of import operations
- **Allow Duplicate Emails**: Handle duplicate email addresses

## Integration with Genpass Module

This module is designed to work seamlessly with the genpass module:

- When users are created without explicit passwords, genpass automatically generates secure passwords
- Users can reset their passwords using Drupal's standard password reset functionality
- Administrators can manually set passwords after import if needed

## Permissions

- **import csv users**: Allows users to access the CSV import form
- **administer csv user import**: Allows users to configure module settings

## Error Handling

The module provides comprehensive error handling and reporting:

- **Invalid CSV format**: Reports specific row and column issues
- **Duplicate users**: Skips existing users and reports them separately
- **Invalid roles**: Reports users with non-existent role assignments
- **Email validation**: Checks for valid email formats
- **Username validation**: Ensures usernames meet Drupal requirements

## Logging

All import operations are logged to Drupal's admin logs:

- Successfully created users
- Skipped duplicate users
- Processing errors
- Import summaries

View logs at **Administration > Reports > Recent log messages**

## Template File

Download a CSV template file from the import page to ensure proper formatting.

## Troubleshooting

### Common Issues:

1. **File upload fails**: Check server upload limits in PHP configuration
2. **Users not created**: Verify CSV format matches requirements exactly
3. **Invalid roles**: Ensure role machine names are correct (check at `/admin/people/roles`)
4. **Permission denied**: Verify user has "import csv users" permission
5. **Genpass not working**: Ensure genpass module is enabled and configured

### Debug Tips:

- Enable detailed logging in module settings
- Check Drupal logs for specific error messages
- Validate CSV format using the template file
- Test with a small sample file first

## License

GPL-2.0-or-later

## Support

For issues and feature requests, please check the Drupal.org project page or create an issue in the project repository.
