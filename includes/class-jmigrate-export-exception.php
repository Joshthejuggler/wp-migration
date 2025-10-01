<?php
/**
 * Custom exception for JMigrate export errors.
 */

if ( class_exists( 'JMigrate_Export_Exception' ) ) {
    return;
}

class JMigrate_Export_Exception extends \RuntimeException {}
