<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: asignación de múltiples roles por usuario.
 *
 * WordPress soporta de forma nativa que un usuario tenga varios roles a la
 * vez (add_role / remove_role). Este módulo expone una interfaz para
 * seleccionar el usuario y marcar todos los roles que debe tener.
 */
class RM_User_Roles_Module extends RM_Module {

    public function get_slug() {
        return 'role-manager-users';
    }

    public function get_menu_title() {
        return __( 'Multi-rol por usuario', 'role-manager' );
    }

    public function get_page_title() {
        return __( 'Asignación de múltiples roles', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_save_user_roles', array( $this, 'handle_save' ) );
    }

    // Hereda ROLE_MANAGER_CAP (manage_options): solo administradores.

    /* ---------------------------------------------------------------------
     * Handler
     * ------------------------------------------------------------------- */

    public function handle_save() {
        $this->check_permissions();
        check_admin_referer( 'rm_save_user_roles' );

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $user    = $user_id ? get_user_by( 'id', $user_id ) : false;

        if ( ! $user ) {
            $this->redirect_back( $user_id, 'error', 'notfound' );
        }

        $valid_roles = array_keys( wp_roles()->roles );
        $selected    = array();
        foreach ( isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array() as $slug ) {
            $slug = sanitize_key( $slug );
            if ( in_array( $slug, $valid_roles, true ) ) {
                $selected[] = $slug;
            }
        }
        $selected = array_values( array_unique( $selected ) );

        // Debe quedar al menos un rol.
        if ( empty( $selected ) ) {
            $this->redirect_back( $user_id, 'error', 'norole' );
        }

        // Reemplaza el conjunto de roles: quita los actuales y añade los nuevos.
        foreach ( (array) $user->roles as $current ) {
            $user->remove_role( $current );
        }
        foreach ( $selected as $slug ) {
            $user->add_role( $slug );
        }

        $this->redirect_back( $user_id, 'success', 'saved' );
    }

    private function redirect_back( $user_id, $status, $code ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => $this->get_slug(),
                    'user'       => (int) $user_id,
                    'rm_status'  => $status,
                    'rm_message' => $code,
                ),
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

        $selected_user = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

        echo '<div class="wrap rm-wrap">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';

        $this->maybe_render_notice();

        // 1) Selector de usuario.
        $this->render_user_selector( $selected_user );

        // 2) Editor de roles del usuario seleccionado.
        if ( $selected_user ) {
            $user = get_user_by( 'id', $selected_user );
            if ( $user ) {
                $this->render_role_editor( $user );
            } else {
                $this->notice( __( 'El usuario seleccionado no existe.', 'role-manager' ), 'error' );
            }
        }

        echo '</div>';
    }

    private function maybe_render_notice() {
        if ( empty( $_GET['rm_message'] ) ) {
            return;
        }
        $code   = sanitize_key( wp_unslash( $_GET['rm_message'] ) );
        $status = isset( $_GET['rm_status'] ) ? sanitize_key( wp_unslash( $_GET['rm_status'] ) ) : 'success';
        $map    = array(
            'saved'    => __( 'Roles del usuario actualizados correctamente.', 'role-manager' ),
            'notfound' => __( 'El usuario indicado no existe.', 'role-manager' ),
            'norole'   => __( 'Debes asignar al menos un rol al usuario.', 'role-manager' ),
        );
        if ( isset( $map[ $code ] ) ) {
            $this->notice( $map[ $code ], 'error' === $status ? 'error' : 'success' );
        }
    }

    private function render_user_selector( $selected_user ) {
        $users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ), 'number' => 500 ) );

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="rm-card">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $this->get_slug() ) . '" />';
        echo '<label for="rm-user-select"><strong>' . esc_html__( 'Selecciona un usuario', 'role-manager' ) . '</strong></label><br />';
        echo '<select name="user" id="rm-user-select" class="regular-text">';
        echo '<option value="">' . esc_html__( '— Elegir usuario —', 'role-manager' ) . '</option>';
        foreach ( $users as $u ) {
            printf(
                '<option value="%1$d"%2$s>%3$s (%4$s)</option>',
                (int) $u->ID,
                selected( $selected_user, $u->ID, false ),
                esc_html( $u->display_name ),
                esc_html( $u->user_login )
            );
        }
        echo '</select> ';
        submit_button( __( 'Ver roles', 'role-manager' ), 'secondary', '', false );
        echo '</form>';
    }

    private function render_role_editor( WP_User $user ) {
        $current_roles = (array) $user->roles;

        echo '<h2>' . sprintf(
            /* translators: %s: user display name. */
            esc_html__( 'Roles de %s', 'role-manager' ),
            esc_html( $user->display_name )
        ) . '</h2>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-card">';
        wp_nonce_field( 'rm_save_user_roles' );
        echo '<input type="hidden" name="action" value="rm_save_user_roles" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';

        echo '<div class="rm-roles-grid">';
        foreach ( wp_roles()->roles as $slug => $role ) {
            $checked = in_array( $slug, $current_roles, true ) ? ' checked' : '';
            printf(
                '<label class="rm-role-item"><input type="checkbox" name="roles[]" value="%1$s"%2$s /> <span>%3$s</span> <code>%1$s</code></label>',
                esc_attr( $slug ),
                $checked, // phpcs:ignore
                esc_html( translate_user_role( $role['name'] ) )
            );
        }
        echo '</div>';

        submit_button( __( 'Guardar roles', 'role-manager' ) );
        echo '</form>';
    }
}
