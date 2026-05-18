<?php
/**
 * Plugin Name: Jet SQL Paginator
 * Plugin URI:  https://github.com/tu-usuario/jet-sql-paginator
 * Description: Paginación backend real para JetEngine Query Builder SQL Custom Mode usando {{PAGINATE:N}}.
 * Version:     1.9.0
 * Author:      NaturalWeb & Business
 * License:     GPL-2.0-or-later
 * Text Domain: jet-sql-paginator
 *
 * Requires Plugins: jet-engine
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'JET_SQL_PAGINATOR_VERSION', '1.9.0' );
define( 'JET_SQL_PAGINATOR_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'init', function () {

    if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Jet SQL Paginator</strong> requiere JetEngine activo.';
            echo '</p></div>';
        } );
        return;
    }

    require_once JET_SQL_PAGINATOR_PATH . 'includes/class-jet-sql-paginator.php';
    new Jet_SQL_Paginator();

}, 15 ); // priority 15 — después de que JE registre sus queries (priority 12)
