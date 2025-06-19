<?php

/*
 * Plugin Name: YAML Content Uploader
 * Description: A WP-CLI extension that enables uploading and updating WordPress content from <a href="https://yaml.org" target="_blank">YAML text files</a>.
 * Version: 0.0.1
 * Author: Ataraxia Development
 */

if( ! defined( 'ABSPATH' ) ) {
    wp_die( "No ABSPATH." );
}

if( class_exists( 'WP_CLI' ) ) {
    require_once( __DIR__ . '/vendor/autoload.php' );
    require_once( __DIR__ . '/twig-helpers.php' );
    require_once( __DIR__ . '/command.php' );
    WP_CLI::add_command(
        'yaml', '\YAML_Content_Uploader\YAML_Content_Uploader'
    );
}
