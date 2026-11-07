<?php

defined( 'NUKE_EVO' ) || exit;

$ed_lang = array(
	'uninstall_title'        => 'Uninstall Donations',
	'uninstall_intro'        => 'Permanently remove this module and its donation widget from this site.',
	// @translator Keep the filename config.php and the brand name PayPal unchanged.
	'uninstall_warning'      => 'Warning: this will permanently delete donation history, donation settings, the Donations module folder (including config.php) and the Donations block folder. This cannot be undone. Existing PayPal payments will not be refunded.',
	'uninstall_confirm'      => 'I understand this cannot be undone and confirm that I want to uninstall Donations and delete its data and files.',
	'uninstall_button'       => 'Uninstall and delete permanently',
	'uninstall_cancel'       => 'Cancel',
	'uninstall_complete'     => 'Donations uninstalled',
	'uninstall_done'         => 'Donation data, settings and registrations have been removed. File cleanup has been attempted.',
	'uninstall_cleanup'      => 'Some files could not be deleted. Remove the following remaining paths manually:',
	'uninstall_failed'       => 'Uninstall could not complete. Confirm the checkbox and ensure your account has permission to delete the donation files and database records. If deletion already started, restore your backup or remove any remaining files manually.',
	'admin_home'             => 'Return to administration',
	'install_not_configured' => 'Donations have not been configured properly. Please contact the site administrator.',
	'install_title'          => 'Install Donations',
	'install_description'    => 'The donation database is not ready. Run the installer to create the transaction table and donation settings option using your site database prefix.',
	// @translator Keep Evolution and GBP unchanged. GBP is the currency code for British pounds; sandbox mode means test payments.
	'install_defaults'       => 'New installations start with donations disabled, sandbox mode and GBP. Existing settings are copied into a single Evolution option. Donation records are preserved.',
	'install_button'         => 'Run installer',
	// @translator Keep the brand name PayPal unchanged.
	'install_success'        => 'Donations installed successfully. Configure PayPal and your donation preferences before enabling payments.',
	// @translator Keep CREATE unchanged; it names the database permission required to create tables.
	'install_failed'         => 'Installation could not be completed. Check database connectivity and CREATE permissions, then retry. Any tables already created are preserved.',
	'install_incompatible'   => 'Existing donation tables are missing required columns. A database migration is needed before the dashboard can be used. Existing tables have not been altered.',
	'view_module'            => 'View donation page',
	'no_transactions'        => 'No transactions have been recorded yet.',
	'title'                  => 'Support our community',
	'intro'                  => 'Your donation helps keep the community running. Thank you for your support.',
	'amount'                 => 'Donation amount',
	// @translator Keep the brand name PayPal unchanged.
	'donate'                 => 'Continue to PayPal',
	'anonymous_label'        => 'Hide my username from the public donor list',
	'anonymous'              => 'Anonymous',
	'guest'                  => 'Guest supporter',
	// @translator Describes money collected towards the donation goal, for example "£50 raised".
	'raised'                 => 'raised',
	'goal'                   => 'Donation goal',
	'progress'               => 'Donation goal progress',
	'recent'                 => 'Recent supporters',
	'none'                   => 'No completed donations yet.',
	'disabled'               => 'Donations are temporarily unavailable.',
	// @translator Sandbox means the payment testing environment; no real payments are made.
	'sandbox'                => 'Sandbox mode: test payments only.',
	'error'                  => 'We could not process this request. Please try again or contact the administrator.',
	'complete'               => 'Thank you! Your donation has been confirmed.',
	'pending'                => 'Your payment is not confirmed yet. Please check again later or contact the administrator.',
	'cancel'                 => 'Checkout cancelled. If you approved a payment, contact the administrator to check its status before trying again.',
	'confirm'                => 'Confirm your donation',
	// @translator Keep the brand name PayPal unchanged.
	'confirm_help'           => 'Complete the payment you approved at PayPal.',
	'back'                   => 'Return to donations',
	// @translator Keep the brand name PayPal unchanged.
	'privacy'                => 'Public donor lists show your site username and donation amount unless you choose anonymity. Administrators can still see the transaction record. Payment details are handled by PayPal.',
	'admin'                  => 'Donations',
	'settings'               => 'Settings',
	'save'                   => 'Save settings',
	'saved'                  => 'Settings saved.',
	'enabled'                => 'Enable donations',
	// @translator Keep PayPal unchanged. Environment means the live or sandbox payment environment.
	'environment'            => 'PayPal environment',
	'currency'               => 'Currency',
	'minimum'                => 'Minimum donation',
	'maximum'                => 'Maximum donation',
	// @translator Keep UTC unchanged; it means Coordinated Universal Time.
	'goal_start'             => 'Goal start date (UTC)',
	// @translator Preserve the number 0; entering zero disables the donation goal.
	'goal_amount'            => 'Goal amount (0 disables goal)',
	'guests'                 => 'Allow guest donations',
	'transactions'           => 'Transactions',
	// @translator Keep the brand name PayPal unchanged.
	'sync'                   => 'Check PayPal status',
	'previous'               => 'Previous',
	'next'                   => 'Next',
	// @translator API means application programming interface.
	'configuration'          => 'API configuration',
	// @translator Credentials means the authentication details used to connect to PayPal.
	'configured'             => 'Credentials configured',
	// @translator Credentials means the authentication details used to connect to PayPal.
	'not_configured'         => 'Credentials missing or incomplete',
	// @translator A webhook URL is the endpoint that receives payment notifications from PayPal.
	'webhook'                => 'Webhook URL',
	// @translator Keep PayPal unchanged. References means donation and payment identifiers.
	'id'                     => 'Donation / PayPal references',
	'supporter'              => 'Supporter',
	'status'                 => 'Status',
	// @translator Keep UTC unchanged; it means Coordinated Universal Time.
	'date'                   => 'Created (UTC)',
	// @translator Environment means the live or sandbox payment environment.
	'mode'                   => 'Environment',
	'login'                  => 'Please sign in to donate.',
	// @translator Labels the permitted donation amount range, from the minimum to the maximum.
	'limits'                 => 'Allowed amount',
	// @translator Keep the brand name PayPal unchanged.
	'sync_done'              => 'PayPal status updated.',
	// @translator Keep the brand name PayPal unchanged.
	'review'                 => 'Partially refunded donations are excluded from public totals. Review refunds in PayPal.',
);