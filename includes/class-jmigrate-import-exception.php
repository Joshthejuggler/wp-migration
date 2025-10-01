<?php
/**
 * Custom exception for JMigrate import errors.
 */

if ( class_exists( 'JMigrate_Import_Exception' ) ) {
    return;
}

class JMigrate_Import_Exception extends \RuntimeException {}
