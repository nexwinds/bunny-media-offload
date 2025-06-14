<?php
// Test if the constant is defined in WordPress
define('ABSPATH', true); // Mock WordPress define
define('BMO_API_KEY', 'bmapi_2FQfkpb6iZUdDoMbn86dPBtyvsRT2dUn');
define('BMO_API_REGION', 'us');

// Include settings
require_once 'includes/class-bunny-settings.php';

// Create settings instance
$settings = new Bunny_Settings();

// Check if API key is defined through settings
echo "BMO_API_KEY defined directly: " . (defined('BMO_API_KEY') ? "Yes" : "No") . "\n";
echo "BMO_API_KEY value: " . (defined('BMO_API_KEY') ? constant('BMO_API_KEY') : "Not defined") . "\n";

// Test the settings->get method
echo "Getting 'bmo_api_key' from settings: " . $settings->get('bmo_api_key') . "\n";
echo "Is constant defined according to settings: " . ($settings->is_constant_defined('bmo_api_key') ? "Yes" : "No") . "\n"; 