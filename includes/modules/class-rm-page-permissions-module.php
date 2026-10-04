<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: permisos de acceso por página.
 *
 * Permite definir qué roles pueden acceder a cada página de WordPress.
 * Si una página no tiene roles asignados, se considera pública. El acceso
 * se restringe en el front mediante el hook `template_redirect`.
 */
class RM_Page_Permissions_Module extends RM_Module {

    /** Opción: map [ page_id => [ 'role_slug', ... ] ]. */
    const OPTION_PERMISSIONS = 'rm_page_permissions';

    public function get_slug() {
        return 'role-manager-permissions';
    }

    public function get_menu_title() {
        return __( 'Permisos por página', 'role-manager' );
    }

    public function get_page_title() {
        return __( 'Permisos de acceso por página', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_save_permissions', array( $this, 'handle_save' ) );

        // Aplicación del control de acceso en el front.
        add_action( 'template_redirect', array( $this, 'enforce_access' ) );
    }

    /* ---------------------------------------------------------------------
     * Repositorio de datos
     * ------------------------------------------------------------------- */

    /**
     * @return array<int,string[]>
     */
    public static function get_permissions() {
        $perms = get_option( self::OPTION_PERMISSIONS, array() );
        return is_array( $perms ) ? $perms : array();
    }

    /**
     * Roles permitidos para una página concreta.
     *
     * @param int $page_id
     * @return string[]
     */
    public static function get_page_roles( $page_id ) {
        $perms = self::get_permissions();
        return isset( $perms[ $page_id ] ) ? (array) $perms[ $page_id ] : array();
    }

    /* ---------------------------------------------------------------------
     * Enforcement (front)
     * ------------------------------------------------------------------- */

    public function enforce_access() {
        if ( is_admin() || ! is_page() ) {
            return;
        }

        $page_id = get_queried_object_id();
        if ( ! $page_id ) {
            return;
        }

        $allowed_roles = self::get_page_roles( $page_id );

        // Sin roles definidos => página pública.
        if ( empty( $allowed_roles ) ) {
            return;
        }

        // Los administradores del plugin siempre tienen acceso.
        if ( current_user_can( ROLE_MANAGER_CAP ) ) {
            return;
        }

        $has_access = false;
        if ( is_user_logged_in() ) {
            $user_roles = (array) wp_get_current_user()->roles;
            $has_access = (bool) array_intersect( $user_roles, $allowed_roles );
        }

        /**
         * Permite anular la decisión de acceso desde código.
         *
         * @param bool  $has_access
         * @param int   $page_id
         * @param array $allowed_roles
         */
        $has_access = apply_filters( 'role_manager_page_access', $has_access, $page_id, $allowed_roles );

        if ( $has_access ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            // Redirige al login conservando el destino.
            wp_safe_redirect( wp_login_url( get_permalink( $page_id ) ) );
            exit;
        }

        // Usuario autenticado sin el rol requerido.
        wp_die(
            esc_html__( 'No tienes permisos para acceder a esta página.', 'role-manager' ),
            esc_html__( 'Acceso restringido', 'role-manager' ),
            array( 'response' => 403 )
        );
    }

    /* ---------------------------------------------------------------------
     * Handler
     * ------------------------------------------------------------------- */

    public function handle_save() {
        $this->check_permissions();
        check_admin_referer( 'rm_save_permissions' );

        $raw   = isset( $_POST['permissions'] ) ? (array) wp_unslash( $_POST['permissions'] ) : array();
        $roles = array_keys( wp_roles()->roles );
        $clean = array();

        foreach ( $raw as $page_id => $role_slugs ) {
            $page_id = absint( $page_id );
            if ( ! $page_id ) {
                continue;
            }
            $valid = array();
            foreach ( (array) $role_slugs as $slug ) {
                $slug = sanitize_key( $slug );
                if ( in_array( $slug, $roles, true ) ) {
                    $valid[] = $slug;
                }
            }
            if ( ! empty( $valid ) ) {
                $clean[ $page_id ] = array_values( array_unique( $valid ) );
            }
        }

        update_option( self::OPTION_PERMISSIONS, $clean );

        wp_safe_redirect(
            add_query_arg(
                array( 'page' => $this->get_slug(), 'rm_status' => 'success', 'rm_message' => 'saved' ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Vista
     * ------------------------------------------------------------------- */

    public function render() {
        $this->check_permissions();

        echo '<div class="wrap rm-wrap">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Marca los roles que pueden acceder a cada página. Una página sin roles marcados es pública.', 'role-manager' ) . '</p>';

        if ( ! empty( $_GET['rm_message'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['rm_message'] ) ) ) {
            $this->notice( __( 'Permisos guardados correctamente.', 'role-manager' ) );
        }

        $pages = get_pages( array( 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish,private,draft' ) );

        if ( empty( $pages ) ) {
            echo '<p>' . esc_html__( 'No se encontraron páginas.', 'role-manager' ) . '</p></div>';
            return;
        }

        $roles = wp_roles()->roles;
        $perms = self::get_permissions();

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'rm_save_permissions' );
        echo '<input type="hidden" name="action" value="rm_save_permissions" />';

        echo '<table class="wp-list-table widefat fixed striped rm-permissions-table">';
        echo '<thead><tr>';
        echo '<th class="rm-col-page">' . esc_html__( 'Página', 'role-manager' ) . '</th>';
        foreach ( $roles as $slug => $role ) {
            echo '<th class="rm-col-role">' . esc_html( translate_user_role( $role['name'] ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $pages as $page ) {
            $page_roles = isset( $perms[ $page->ID ] ) ? (array) $perms[ $page->ID ] : array();
            $is_public  = empty( $page_roles );

            echo '<tr>';
            echo '<td class="rm-col-page"><strong>' . esc_html( $page->post_title ) . '</strong>';
            echo ' <span class="rm-badge ' . ( $is_public ? 'rm-badge-public' : 'rm-badge-restricted' ) . '">';
            echo esc_html( $is_public ? __( 'Pública', 'role-manager' ) : __( 'Restringida', 'role-manager' ) );
            echo '</span>';
            echo '<br /><a href="' . esc_url( get_permalink( $page->ID ) ) . '" target="_blank" rel="noopener" class="rm-page-link">' . esc_html( get_page_uri( $page ) ) . '</a></td>';

            foreach ( $roles as $slug => $role ) {
                $checked = in_array( $slug, $page_roles, true ) ? ' checked' : '';
                printf(
                    '<td class="rm-col-role"><input type="checkbox" name="permissions[%1$d][]" value="%2$s"%3$s /></td>',
                    (int) $page->ID,
                    esc_attr( $slug ),
                    $checked // phpcs:ignore
                );
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        submit_button( __( 'Guardar permisos', 'role-manager' ) );
        echo '</form>';
        echo '</div>';
    }
}
