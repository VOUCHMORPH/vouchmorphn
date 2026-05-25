<?php
/**
 * Simple configuration loader for non-namespace files
 * This file returns the configuration array directly
 */

// Load the namespace-based config
require_once __DIR__ . '/LoadCountry.php';

// Return the config array
return \Core\Config\LoadCountry::getConfig();
