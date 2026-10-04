<?php
/**
 * Plugin Name: Role Manager
 * Description: Gestión modular y escalable de roles en WordPress: CRUD de roles y usuarios, permisos de acceso por página, multi-rol por usuario, campos personalizados de usuario y donaciones vía PayPal.
 * Version:     0.2.0
 * Author:      Icontec
 * Text Domain: role-manager
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ROLE_MANAGER_FILE', __FILE__ );
define( 'ROLE_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ROLE_MANAGER_URL', plugin_dir_url( __FILE__ ) );
define( 'ROLE_MANAGER_VERSION', '0.2.0' );

/**
 * Capacidad requerida para administrar el plugin. Se puede filtrar para
 * delegar la administración a otros roles sin tocar el código.
 */
if ( ! defined( 'ROLE_MANAGER_CAP' ) ) {
    define( 'ROLE_MANAGER_CAP', 'manage_options' );
}

require_once ROLE_MANAGER_PATH . 'includes/class-role-manager.php';

$role_manager = Role_Manager::get_instance( ROLE_MANAGER_FILE );
$role_manager->init();
