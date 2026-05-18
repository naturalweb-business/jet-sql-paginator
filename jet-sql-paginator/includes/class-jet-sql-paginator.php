<?php
/**
 * Jet SQL Paginator — Clase principal
 *
 * Paginación backend real para JetEngine Query Builder SQL Custom Mode.
 *
 * Cómo funciona:
 *
 * 1. El usuario añade {{PAGINATE:N}} al final de su SQL en JE Query Builder,
 *    donde N es el número de items por página.
 *
 * 2. Este plugin intercepta la query via 'jet-engine/query-builder/query/after-query-setup':
 *    a) Lee el número de página actual desde JSF (pagenum en URL o _page en AJAX)
 *    b) Calcula el total real de resultados via COUNT(*) antes del LIMIT
 *    c) Reemplaza {{PAGINATE:N}} por LIMIT N OFFSET M en el SQL
 *
 * 3. Via 'jet-engine/query-builder/set-props' inyecta found_posts y max_num_pages
 *    correctos para que el widget de paginación de JSF renderice las páginas.
 *
 * Requisito en Bricks/Elementor/Gutenberg:
 *   - El CSS ID del Listing Grid debe coincidir con el Query ID configurado en JSF.
 *   - Ejemplo: CSS ID del grid = "102", Query ID en widget JSF = "102".
 *   - Sin esto, JSF no encuentra el grid y el AJAX no funciona.
 *
 * @package JetSQLPaginator
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Jet_SQL_Paginator {

    /**
     * Placeholder que el usuario escribe en su SQL.
     * Acepta {{PAGINATE:12}} o {{PAGINATE: 12}}
     */
    const PLACEHOLDER_PATTERN = '/\{\{PAGINATE:\s*(\d+)\s*\}\}/i';

    /**
     * Namespace del endpoint REST.
     */
    const REST_NAMESPACE = 'jet-sql-paginator/v1';

    /**
     * Cache de per_page por query_id.
     * Se llena en intercept_je_sql y se lee en inject_pagination_props.
     *
     * @var array<int, int>
     */
    private array $per_page_cache = [];

    /**
     * Cache del total real de filas por query_id.
     * Se llena en intercept_je_sql (COUNT antes del LIMIT).
     * Se lee en inject_pagination_props.
     *
     * @var array<int, int>
     */
    private array $found_posts_cache = [];

    public function __construct() {

        // Endpoint REST opcional para integraciones externas
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );

        // Priority 0: leer página de JSF y escribirla en final_query['_page']
        // ANTES de que intercept_je_sql (priority 1) calcule el OFFSET.
        add_action(
            'jet-engine/query-builder/query/after-query-setup',
            [ $this, 'sync_jsf_page_to_query' ],
            0
        );

        // Priority 1: interceptar SQL, calcular total, inyectar LIMIT/OFFSET.
        add_action(
            'jet-engine/query-builder/query/after-query-setup',
            [ $this, 'intercept_je_sql' ],
            1
        );

        // Inyectar found_posts y max_num_pages correctos para el widget JSF.
        add_filter(
            'jet-engine/query-builder/set-props',
            [ $this, 'inject_pagination_props' ],
            10,
            2
        );
    }

    // =========================================================================
    // HOOK: sync_jsf_page_to_query — priority 0
    // =========================================================================

    /**
     * Traduce el número de página de JSF a final_query['_page'].
     *
     * JSF envía la página de dos formas:
     *   - Page Reload: ?pagenum=N en la URL
     *   - AJAX: _page o paged en el POST body
     *
     * intercept_je_sql (priority 1) leerá final_query['_page'] para
     * calcular el OFFSET correcto.
     */
    public function sync_jsf_page_to_query( $query ): void {

        if ( empty( $query->query['advanced_mode'] ) ) {
            return;
        }

        $page = 0;

        if ( ! empty( $_GET['pagenum'] ) ) {
            $page = absint( $_GET['pagenum'] );
        }

        if ( ! $page && ! empty( $_POST['_page'] ) ) {
            $page = absint( $_POST['_page'] );
        }

        if ( ! $page && ! empty( $_POST['paged'] ) ) {
            $page = absint( $_POST['paged'] );
        }

        if ( $page > 1 ) {
            $query->final_query['_page'] = $page;
        }
    }

    // =========================================================================
    // HOOK: intercept_je_sql — priority 1
    // =========================================================================

    /**
     * Intercepta el SQL de Advanced Mode y aplica paginación real.
     *
     * Solo actúa en queries que contienen {{PAGINATE:N}}.
     * Queries sin el placeholder pasan sin modificación.
     */
    public function intercept_je_sql( $query ): void {

        if ( empty( $query->query['advanced_mode'] ) ) {
            return;
        }

        // Asegurar que final_query tiene el SQL
        if ( empty( $query->final_query['manual_query'] ) ) {
            if ( ! empty( $query->query['manual_query'] ) ) {
                $query->final_query['manual_query'] = $query->query['manual_query'];
            } else {
                return;
            }
        }

        $sql = $query->final_query['manual_query'];

        if ( ! preg_match( self::PLACEHOLDER_PATTERN, $sql, $matches ) ) {
            return;
        }

        $per_page = (int) $matches[1];
        $query_id = (int) ( $query->id ?? 0 );

        if ( $query_id ) {
            $this->per_page_cache[ $query_id ] = $per_page;
        }

        // Página actual — escrita por sync_jsf_page_to_query en priority 0
        $current_page = ! empty( $query->final_query['_page'] )
            ? absint( $query->final_query['_page'] )
            : 1;

        $offset = ( $current_page - 1 ) * $per_page;

        // ── Total real ANTES del LIMIT ────────────────────────────────────────
        global $wpdb;

        $sql_count = preg_replace( self::PLACEHOLDER_PATTERN, '', $sql );
        $sql_count = preg_replace( '/--[^\n]*/', '', $sql_count );
        $sql_count = preg_replace( '/\s+ORDER\s+BY\s+.+$/is', '', trim( $sql_count ) );
        $sql_count = rtrim( trim( $sql_count ), ';' );
        $sql_count = str_replace( '{prefix}', $wpdb->prefix, $sql_count );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$sql_count}) AS _jspag_total" );

        // Fallback para queries con GROUP BY a nivel raíz donde COUNT wrapper falla
        if ( $wpdb->last_error || $total <= 0 ) {
            $wpdb->last_error = '';
            $sql_all  = str_replace( '{prefix}', $wpdb->prefix,
                rtrim( trim( preg_replace( self::PLACEHOLDER_PATTERN, '', $sql ) ), ';' )
            );
            $rows  = $wpdb->get_results( $sql_all );
            $total = is_array( $rows ) ? count( $rows ) : 0;
        }

        if ( $query_id && $total > 0 ) {
            $this->found_posts_cache[ $query_id ] = $total;
        }

        // ── Inyectar LIMIT/OFFSET real ────────────────────────────────────────
        $limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
        $sql       = preg_replace( self::PLACEHOLDER_PATTERN, $limit_sql, $sql );
        $sql       = rtrim( trim( $sql ), ';' );

        $query->final_query['manual_query'] = $sql;

        if ( isset( $query->query['manual_query'] ) ) {
            $query->query['manual_query'] = $sql;
        }
    }

    // =========================================================================
    // HOOK: inject_pagination_props
    // =========================================================================

    /**
     * Corrige found_posts y max_num_pages en los props de JE.
     *
     * JE devuelve max_num_pages=1 para SQL Custom Mode porque tras aplicar
     * el LIMIT solo ve N filas. Aquí lo corregimos con el total real que
     * calculamos antes del LIMIT en intercept_je_sql.
     */
    public function inject_pagination_props( array $props, $query ): array {

        if ( empty( $props['query_id'] ) ) {
            return $props;
        }

        $query_id = (int) $props['query_id'];

        if ( empty( $this->per_page_cache[ $query_id ] ) ) {
            return $props;
        }

        $per_page = $this->per_page_cache[ $query_id ];
        $total    = $this->found_posts_cache[ $query_id ] ?? 0;

        if ( $total <= 0 || $per_page <= 0 ) {
            return $props;
        }

        $props['found_posts']   = $total;
        $props['max_num_pages'] = (int) ceil( $total / $per_page );

        return $props;
    }

    // =========================================================================
    // ENDPOINT REST (opcional)
    // =========================================================================

    /**
     * GET /wp-json/jet-sql-paginator/v1/query?query_id=ID&page=N
     *
     * Devuelve items paginados en JSON. Útil para integraciones externas
     * o para debug sin pasar por el Listing Grid de JE.
     */
    public function register_rest_route(): void {
        register_rest_route( self::REST_NAMESPACE, '/query', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_rest_request' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'query_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    public function handle_rest_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {

        if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
            return new WP_Error( 'jetengine_missing', 'JetEngine no está activo.', [ 'status' => 500 ] );
        }

        $query_id = $request->get_param( 'query_id' );
        $page     = max( 1, $request->get_param( 'page' ) );
        $je_query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );

        if ( ! $je_query ) {
            return new WP_Error( 'query_not_found', "Query {$query_id} no encontrada.", [ 'status' => 404 ] );
        }

        $raw_sql = $this->get_raw_sql( $je_query );

        if ( ! $raw_sql ) {
            return new WP_Error( 'not_sql_query', "La query {$query_id} no es SQL Custom Mode.", [ 'status' => 400 ] );
        }

        if ( ! preg_match( self::PLACEHOLDER_PATTERN, $raw_sql, $matches ) ) {
            return new WP_Error( 'missing_placeholder', 'El SQL no contiene {{PAGINATE:N}}.', [ 'status' => 400 ] );
        }

        $per_page      = (int) $matches[1];
        $offset        = ( $page - 1 ) * $per_page;
        $processed_sql = $this->process_je_macros( $raw_sql, $je_query );
        $items         = $this->run_data_query( $processed_sql, $per_page, $offset );

        if ( is_wp_error( $items ) ) {
            return $items;
        }

        $total       = $this->run_count_query( $processed_sql );
        $total       = is_wp_error( $total ) ? count( $items ) : $total;
        $total_pages = (int) ceil( $total / $per_page );

        return new WP_REST_Response( [
            'success'    => true,
            'items'      => $items,
            'pagination' => [
                'total'       => $total,
                'total_pages' => $total_pages,
                'current'     => $page,
                'per_page'    => $per_page,
                'has_prev'    => $page > 1,
                'has_next'    => $page < $total_pages,
            ],
        ], 200 );
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    private function get_raw_sql( $je_query ): string|false {

        if (
            ! empty( $je_query->query['advanced_mode'] ) &&
            ! empty( $je_query->query['manual_query'] ) &&
            is_string( $je_query->query['manual_query'] )
        ) {
            return $je_query->query['manual_query'];
        }

        foreach ( [ 'custom_sql', 'sql_query', 'raw_query' ] as $key ) {
            if ( ! empty( $je_query->query[ $key ] ) && is_string( $je_query->query[ $key ] ) ) {
                return $je_query->query[ $key ];
            }
        }

        return false;
    }

    private function process_je_macros( string $sql, $je_query ): string {

        if ( function_exists( 'jet_engine' ) ) {
            $macros = jet_engine()->listings->macros ?? null;
            if ( $macros && method_exists( $macros, 'do_macros' ) ) {
                $sql = $macros->do_macros( $sql );
            }
        }

        global $wpdb;
        return str_replace( '{prefix}', $wpdb->prefix, $sql );
    }

    private function run_data_query( string $sql, int $per_page, int $offset ): array|WP_Error {
        global $wpdb;

        $sql = preg_replace(
            self::PLACEHOLDER_PATTERN,
            $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset ),
            $sql
        );
        $sql = preg_replace( '/--[^\n]*/', '', $sql );
        $sql = rtrim( trim( $sql ), ';' );

        $results = $wpdb->get_results( $sql );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', $wpdb->last_error, [ 'status' => 500 ] );
        }

        return $results ?? [];
    }

    private function run_count_query( string $sql ): int|WP_Error {
        global $wpdb;

        $sql = preg_replace( self::PLACEHOLDER_PATTERN, '', $sql );
        $sql = preg_replace( '/--[^\n]*/', '', $sql );
        $sql = preg_replace( '/\s+ORDER\s+BY\s+.+$/is', '', trim( $sql ) );
        $sql = rtrim( trim( $sql ), ';' );

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM ({$sql}) AS _jet_paginator_count" );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'count_error', $wpdb->last_error );
        }

        return (int) $total;
    }
}
