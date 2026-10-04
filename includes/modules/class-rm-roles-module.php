<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: CRUD de roles.
 *
 * Permite crear, listar, editar y eliminar roles de WordPress con sus
 * capacidades. Los roles creados desde el plugin se registran en una opción
 * para poder distinguirlos de los roles nativos (que no se eliminan).
 */
class RM_Roles_Module extends RM_Module {

    /** Opción donde se guardan los slugs de roles creados por el plugin. */
    const OPTION_CUSTOM_ROLES = 'rm_custom_roles';

    public function get_slug() {
        return 'role-manager';
    }

    public function get_menu_title() {
        return __( 'Roles (CRUD)', 'role-manager' );
    }

    public function get_page_title() {
        return __( 'Gestión de Roles', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_create_role', array( $this, 'handle_create' ) );
        add_action( 'admin_post_rm_update_role', array( $this, 'handle_update' ) );
        add_action( 'admin_post_rm_delete_role', array( $this, 'handle_delete' ) );
    }

    /* ---------------------------------------------------------------------
     * Repositorio de datos
     * ------------------------------------------------------------------- */

    /**
     * Slugs de roles creados por el plugin.
     *
     * @return string[]
     */
    public static function get_custom_role_slugs() {
        return (array) get_option( self::OPTION_CUSTOM_ROLES, array() );
    }

    private static function mark_as_custom( $slug ) {
        $slugs = self::get_custom_role_slugs();
        if ( ! in_array( $slug, $slugs, true ) ) {
            $slugs[] = $slug;
            update_option( self::OPTION_CUSTOM_ROLES, $slugs );
        }
    }

    private static function unmark_custom( $slug ) {
        $slugs = array_values( array_diff( self::get_custom_role_slugs(), array( $slug ) ) );
        update_option( self::OPTION_CUSTOM_ROLES, $slugs );
    }

    /**
     * Lista de capacidades disponibles, agrupadas para la UI.
     * Combina un set curado con todas las capacidades ya presentes en roles.
     *
     * @return array<string,string[]> Grupo => lista de capacidades.
     */
    public static function get_capability_groups() {
        $groups = array(
            'General'    => array( 'read', 'level_0' ),
            'Entradas'   => array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'edit_published_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts' ),
            'Páginas'    => array( 'edit_pages', 'edit_others_pages', 'publish_pages', 'edit_published_pages', 'delete_pages', 'delete_others_pages' ),
            'Archivos'   => array( 'upload_files' ),
            'Temas'      => array( 'edit_theme_options', 'switch_themes' ),
            'Plugins'    => array( 'activate_plugins', 'install_plugins', 'update_plugins' ),
            'Usuarios'   => array( 'list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users' ),
            'Sistema'    => array( 'manage_options', 'manage_categories', 'moderate_comments', 'import', 'export' ),
        );

        // Añade capacidades presentes en roles existentes que no estén listadas.
        $known = array();
        foreach ( $groups as $caps ) {
            $known = array_merge( $known, $caps );
        }

        $extra = array();
        foreach ( wp_roles()->roles as $role ) {
            foreach ( array_keys( (array) $role['capabilities'] ) as $cap ) {
                if ( ! in_array( $cap, $known, true ) && ! in_array( $cap, $extra, true ) ) {
                    $extra[] = $cap;
                }
            }
        }
        if ( ! empty( $extra ) ) {
            sort( $extra );
            $groups['Otras'] = $extra;
        }

        return $groups;
    }

    /* ---------------------------------------------------------------------
     * Handlers (admin_post)
     * ------------------------------------------------------------------- */

    public function handle_create() {
        $this->check_permissions();
        check_admin_referer( 'rm_create_role' );

        $display = isset( $_POST['role_name'] ) ? sanitize_text_field( wp_unslash( $_POST['role_name'] ) ) : '';
        $slug    = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';

        // Si no se especifica slug, se deriva del nombre.
        if ( '' === $slug && '' !== $display ) {
            $slug = sanitize_key( str_replace( array( ' ', '-' ), '_', $display ) );
        }

        if ( '' === $display || '' === $slug ) {
            $this->redirect_back( 'error', 'missing' );
        }

        if ( get_role( $slug ) ) {
            $this->redirect_back( 'error', 'exists' );
        }

        $caps = $this->sanitize_caps( isset( $_POST['capabilities'] ) ? (array) wp_unslash( $_POST['capabilities'] ) : array() );
        $caps['read'] = true; // Todo rol debe poder leer.

        add_role( $slug, $display, $caps );
        self::mark_as_custom( $slug );

        $this->redirect_back( 'success', 'created' );
    }

    public function handle_update() {
        $this->check_permissions();
        check_admin_referer( 'rm_update_role' );

        $slug = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';
        $role = get_role( $slug );

        if ( ! $role ) {
            $this->redirect_back( 'error', 'notfound' );
        }

        // Actualiza capacidades: primero quita todas las actuales, luego añade las marcadas.
        $selected = $this->sanitize_caps( isset( $_POST['capabilities'] ) ? (array) wp_unslash( $_POST['capabilities'] ) : array() );
        $selected['read'] = true;

        foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
            $role->remove_cap( $cap );
        }
        foreach ( $selected as $cap => $grant ) {
            $role->add_cap( $cap, (bool) $grant );
        }

        // Actualiza el nombre visible (WP no expone setter, se hace vía wp_roles).
        $display = isset( $_POST['role_name'] ) ? sanitize_text_field( wp_unslash( $_POST['role_name'] ) ) : '';
        if ( '' !== $display ) {
            wp_roles()->roles[ $slug ]['name'] = $display;
            wp_roles()->role_names[ $slug ]    = $display;
            update_option( wp_roles()->role_key, wp_roles()->roles );
        }

        $this->redirect_back( 'success', 'updated' );
    }

    public function handle_delete() {
        $this->check_permissions();
        check_admin_referer( 'rm_delete_role' );

        $slug = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';

        // Solo se eliminan roles creados por el plugin (nunca nativos).
        if ( ! in_array( $slug, self::get_custom_role_slugs(), true ) ) {
            $this->redirect_back( 'error', 'protected' );
        }

        // Reasigna usuarios de este rol al rol por defecto de suscriptor.
        $users = get_users( array( 'role' => $slug, 'fields' => array( 'ID' ) ) );
        foreach ( $users as $u ) {
            $user = get_user_by( 'id', $u->ID );
            if ( $user ) {
                $user->remove_role( $slug );
                if ( empty( $user->roles ) ) {
                    $user->add_role( 'subscriber' );
                }
            }
        }

        remove_role( $slug );
        self::unmark_custom( $slug );

        $this->redirect_back( 'success', 'deleted' );
    }

    /**
     * @param array $raw
     * @return array<string,true>
     */
    private function sanitize_caps( $raw ) {
        $caps = array();
        foreach ( $raw as $cap ) {
            $cap = sanitize_key( $cap );
            if ( '' !== $cap ) {
                $caps[ $cap ] = true;
            }
        }
        return $caps;
    }

    private function redirect_back( $status, $code ) {
        $url = add_query_arg(
            array(
                'page'       => $this->get_slug(),
                'rm_status'  => $status,
                'rm_message' => $code,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Vista
     * ------------------------------------------------------------------- */

    public function render() {
        $this->check_permissions();

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
        $edit_slug = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';

        echo '<div class="wrap rm-wrap">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';

        $this->maybe_render_notice();

        if ( 'edit' === $action && $edit_slug && get_role( $edit_slug ) ) {
            $this->render_form( $edit_slug );
        } else {
            $this->render_form();
            $this->render_list();
        }

        echo '</div>';
    }

    private function maybe_render_notice() {
        if ( empty( $_GET['rm_status'] ) || empty( $_GET['rm_message'] ) ) {
            return;
        }
        $status  = sanitize_key( wp_unslash( $_GET['rm_status'] ) );
        $code    = sanitize_key( wp_unslash( $_GET['rm_message'] ) );
        $messages = array(
            'created'   => __( 'Rol creado correctamente.', 'role-manager' ),
            'updated'   => __( 'Rol actualizado correctamente.', 'role-manager' ),
            'deleted'   => __( 'Rol eliminado correctamente.', 'role-manager' ),
            'missing'   => __( 'Faltan datos obligatorios (nombre del rol).', 'role-manager' ),
            'exists'    => __( 'Ya existe un rol con ese identificador.', 'role-manager' ),
            'notfound'  => __( 'El rol indicado no existe.', 'role-manager' ),
            'protected' => __( 'No se puede eliminar un rol nativo de WordPress.', 'role-manager' ),
        );
        $text = isset( $messages[ $code ] ) ? $messages[ $code ] : '';
        if ( $text ) {
            $this->notice( $text, 'error' === $status ? 'error' : 'success' );
        }
    }

    /**
     * Formulario de creación o edición.
     *
     * @param string $slug Slug del rol a editar; vacío para crear.
     */
    private function render_form( $slug = '' ) {
        $editing  = '' !== $slug;
        $role     = $editing ? get_role( $slug ) : null;
        $name     = $editing && isset( wp_roles()->role_names[ $slug ] ) ? wp_roles()->role_names[ $slug ] : '';
        $role_caps = $editing ? array_keys( array_filter( (array) $role->capabilities ) ) : array( 'read' );

        echo '<h2>' . ( $editing ? esc_html__( 'Editar rol', 'role-manager' ) : esc_html__( 'Crear nuevo rol', 'role-manager' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-card">';

        wp_nonce_field( $editing ? 'rm_update_role' : 'rm_create_role' );
        printf( '<input type="hidden" name="action" value="%s" />', $editing ? 'rm_update_role' : 'rm_create_role' );

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="rm-role-name">' . esc_html__( 'Nombre del rol', 'role-manager' ) . '</label></th>';
        echo '<td><input name="role_name" id="rm-role-name" type="text" class="regular-text" value="' . esc_attr( $name ) . '" required /></td></tr>';

        echo '<tr><th scope="row"><label for="rm-role-slug">' . esc_html__( 'Identificador (slug)', 'role-manager' ) . '</label></th>';
        if ( $editing ) {
            echo '<td><code>' . esc_html( $slug ) . '</code>';
            echo '<input type="hidden" name="role_slug" value="' . esc_attr( $slug ) . '" /></td></tr>';
        } else {
            echo '<td><input name="role_slug" id="rm-role-slug" type="text" class="regular-text" placeholder="' . esc_attr__( 'Opcional, se genera desde el nombre', 'role-manager' ) . '" /></td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__( 'Capacidades', 'role-manager' ) . '</th><td>';
        $this->render_capabilities_checkboxes( $role_caps );
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button( $editing ? __( 'Actualizar rol', 'role-manager' ) : __( 'Crear rol', 'role-manager' ) );

        if ( $editing ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->get_slug() ) ) . '" class="button">' . esc_html__( 'Cancelar', 'role-manager' ) . '</a>';
        }

        echo '</form>';
    }

    private function render_capabilities_checkboxes( $selected ) {
        echo '<div class="rm-caps-grid">';
        foreach ( self::get_capability_groups() as $group => $caps ) {
            echo '<fieldset class="rm-caps-group"><legend>' . esc_html( $group ) . '</legend>';
            foreach ( $caps as $cap ) {
                $checked = in_array( $cap, $selected, true ) ? ' checked' : '';
                printf(
                    '<label class="rm-cap"><input type="checkbox" name="capabilities[]" value="%1$s"%2$s /> <span>%1$s</span></label>',
                    esc_attr( $cap ),
                    $checked // phpcs:ignore
                );
            }
            echo '</fieldset>';
        }
        echo '</div>';
    }

    private function render_list() {
        $custom = self::get_custom_role_slugs();

        echo '<h2>' . esc_html__( 'Roles existentes', 'role-manager' ) . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Nombre', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Slug', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Nº capacidades', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Nº usuarios', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Origen', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Acciones', 'role-manager' ) . '</th>';
        echo '</tr></thead><tbody>';

        $counts = count_users();

        foreach ( wp_roles()->roles as $slug => $role ) {
            $is_custom = in_array( $slug, $custom, true );
            $num_caps  = count( array_filter( (array) $role['capabilities'] ) );
            $num_users = isset( $counts['avail_roles'][ $slug ] ) ? (int) $counts['avail_roles'][ $slug ] : 0;

            $edit_url = add_query_arg(
                array( 'page' => $this->get_slug(), 'action' => 'edit', 'role' => $slug ),
                admin_url( 'admin.php' )
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html( translate_user_role( $role['name'] ) ) . '</strong></td>';
            echo '<td><code>' . esc_html( $slug ) . '</code></td>';
            echo '<td>' . esc_html( $num_caps ) . '</td>';
            echo '<td>' . esc_html( $num_users ) . '</td>';
            echo '<td>' . ( $is_custom ? esc_html__( 'Personalizado', 'role-manager' ) : esc_html__( 'Nativo', 'role-manager' ) ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Editar', 'role-manager' ) . '</a> ';

            if ( $is_custom ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( '¿Eliminar este rol? Los usuarios afectados pasarán a Suscriptor.', 'role-manager' ) ) . '\');">';
                wp_nonce_field( 'rm_delete_role' );
                echo '<input type="hidden" name="action" value="rm_delete_role" />';
                echo '<input type="hidden" name="role_slug" value="' . esc_attr( $slug ) . '" />';
                echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Eliminar', 'role-manager' ) . '</button>';
                echo '</form>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }
}
