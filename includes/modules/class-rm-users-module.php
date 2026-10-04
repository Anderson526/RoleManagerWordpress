<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: CRUD de usuarios.
 *
 * Crea, lista, edita y elimina usuarios de WordPress con los datos básicos
 * (usuario, email, contraseña, roles) y los campos personalizados definidos
 * en el módulo de campos (guardados como user meta).
 */
class RM_Users_Module extends RM_Module {

    public function get_slug() {
        return 'rm-users';
    }

    public function get_menu_title() {
        return __( 'Usuarios (CRUD)', 'role-manager' );
    }

    public function get_page_title() {
        return __( 'Gestión de Usuarios', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_create_user', array( $this, 'handle_create' ) );
        add_action( 'admin_post_rm_update_user', array( $this, 'handle_update' ) );
        add_action( 'admin_post_rm_delete_user', array( $this, 'handle_delete' ) );
    }

    /* ---------------------------------------------------------------------
     * Handlers (admin_post)
     * ------------------------------------------------------------------- */

    public function handle_create() {
        $this->check_permissions();
        check_admin_referer( 'rm_create_user' );

        $login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ) ) : '';
        $email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
        $pass  = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';

        if ( '' === $login || '' === $email || '' === $pass ) {
            $this->redirect_back( 'error', 'missing' );
        }

        if ( username_exists( $login ) || email_exists( $email ) ) {
            $this->redirect_back( 'error', 'exists' );
        }

        $roles = $this->sanitize_roles( isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array() );
        if ( empty( $roles ) ) {
            $roles = array( get_option( 'default_role', 'subscriber' ) );
        }

        $user_id = wp_insert_user(
            array(
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => $pass,
                'first_name'   => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
                'last_name'    => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
                'role'         => $roles[0],
            )
        );

        if ( is_wp_error( $user_id ) ) {
            $this->redirect_back( 'error', 'wp_error' );
        }

        $this->assign_roles( $user_id, $roles );

        if ( ! $this->save_custom_fields( $user_id, true ) ) {
            // Usuario creado pero con campos obligatorios vacíos: se avisa.
            $this->redirect_back( 'error', 'required_meta', $user_id );
        }

        $this->redirect_back( 'success', 'created' );
    }

    public function handle_update() {
        $this->check_permissions();
        check_admin_referer( 'rm_update_user' );

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $user    = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            $this->redirect_back( 'error', 'notfound' );
        }

        $data = array(
            'ID'         => $user_id,
            'user_email' => isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : $user->user_email,
            'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
            'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
        );

        // La contraseña solo se cambia si se escribe una nueva.
        $pass = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
        if ( '' !== $pass ) {
            $data['user_pass'] = $pass;
        }

        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            $this->redirect_back( 'error', 'wp_error', $user_id );
        }

        $roles = $this->sanitize_roles( isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array() );
        if ( ! empty( $roles ) ) {
            $this->assign_roles( $user_id, $roles );
        }

        $this->save_custom_fields( $user_id, false );

        $this->redirect_back( 'success', 'updated' );
    }

    public function handle_delete() {
        $this->check_permissions();
        check_admin_referer( 'rm_delete_user' );

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( ! get_user_by( 'id', $user_id ) ) {
            $this->redirect_back( 'error', 'notfound' );
        }

        if ( get_current_user_id() === $user_id ) {
            $this->redirect_back( 'error', 'self_delete' );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user_id );

        $this->redirect_back( 'success', 'deleted' );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * Filtra la lista de roles a solo los existentes.
     *
     * @param array $raw
     * @return string[]
     */
    private function sanitize_roles( $raw ) {
        $roles = array();
        foreach ( $raw as $slug ) {
            $slug = sanitize_key( $slug );
            if ( '' !== $slug && get_role( $slug ) ) {
                $roles[] = $slug;
            }
        }
        return array_values( array_unique( $roles ) );
    }

    /**
     * Reemplaza los roles del usuario por los indicados (soporte multi-rol).
     *
     * @param int      $user_id
     * @param string[] $roles
     */
    private function assign_roles( $user_id, $roles ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user || empty( $roles ) ) {
            return;
        }
        $user->set_role( $roles[0] );
        foreach ( array_slice( $roles, 1 ) as $role ) {
            $user->add_role( $role );
        }
    }

    /**
     * Guarda los campos personalizados enviados en el formulario.
     *
     * @param int  $user_id
     * @param bool $enforce_required Si true, devuelve false cuando falta un obligatorio.
     * @return bool
     */
    private function save_custom_fields( $user_id, $enforce_required ) {
        $ok     = true;
        $posted = isset( $_POST['rm_cf'] ) ? (array) wp_unslash( $_POST['rm_cf'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        foreach ( RM_Custom_Fields_Module::get_fields() as $field ) {
            $raw   = isset( $posted[ $field['key'] ] ) ? $posted[ $field['key'] ] : '';
            $value = RM_Custom_Fields_Module::sanitize_value( $field, $raw );

            if ( '' === $value && ! empty( $field['required'] ) && $enforce_required ) {
                $ok = false;
            }

            if ( '' === $value ) {
                delete_user_meta( $user_id, RM_Custom_Fields_Module::meta_key( $field['key'] ) );
            } else {
                update_user_meta( $user_id, RM_Custom_Fields_Module::meta_key( $field['key'] ), $value );
            }
        }

        return $ok;
    }

    private function redirect_back( $status, $code, $user_id = 0 ) {
        $args = array(
            'page'       => $this->get_slug(),
            'rm_status'  => $status,
            'rm_message' => $code,
        );
        if ( $user_id ) {
            $args['action'] = 'edit';
            $args['user']   = $user_id;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Vista
     * ------------------------------------------------------------------- */

    public function render() {
        $this->check_permissions();

        $action    = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
        $edit_id   = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
        $edit_user = $edit_id ? get_user_by( 'id', $edit_id ) : null;

        echo '<div class="wrap rm-wrap">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';

        $this->maybe_render_notice();

        if ( 'edit' === $action && $edit_user ) {
            $this->render_form( $edit_user );
        } elseif ( 'new' === $action ) {
            $this->render_form();
        } else {
            $new_url = add_query_arg(
                array( 'page' => $this->get_slug(), 'action' => 'new' ),
                admin_url( 'admin.php' )
            );
            echo '<a href="' . esc_url( $new_url ) . '" class="page-title-action">' . esc_html__( 'Añadir usuario', 'role-manager' ) . '</a>';
            $this->render_list();
        }

        echo '</div>';
    }

    private function maybe_render_notice() {
        if ( empty( $_GET['rm_status'] ) || empty( $_GET['rm_message'] ) ) {
            return;
        }
        $status   = sanitize_key( wp_unslash( $_GET['rm_status'] ) );
        $code     = sanitize_key( wp_unslash( $_GET['rm_message'] ) );
        $messages = array(
            'created'       => __( 'Usuario creado correctamente.', 'role-manager' ),
            'updated'       => __( 'Usuario actualizado correctamente.', 'role-manager' ),
            'deleted'       => __( 'Usuario eliminado correctamente.', 'role-manager' ),
            'missing'       => __( 'Faltan datos obligatorios (usuario, email y contraseña).', 'role-manager' ),
            'exists'        => __( 'Ya existe un usuario con ese nombre o email.', 'role-manager' ),
            'notfound'      => __( 'El usuario indicado no existe.', 'role-manager' ),
            'wp_error'      => __( 'WordPress rechazó la operación. Revisa los datos.', 'role-manager' ),
            'self_delete'   => __( 'No puedes eliminar tu propio usuario.', 'role-manager' ),
            'required_meta' => __( 'Usuario creado, pero faltan campos personalizados obligatorios. Complétalos aquí.', 'role-manager' ),
        );
        $text = isset( $messages[ $code ] ) ? $messages[ $code ] : '';
        if ( $text ) {
            $this->notice( $text, 'error' === $status ? 'error' : 'success' );
        }
    }

    /**
     * Formulario de creación o edición de usuario.
     *
     * @param WP_User|null $user Usuario a editar; null para crear.
     */
    private function render_form( $user = null ) {
        $editing = null !== $user;

        echo '<h2>' . ( $editing ? esc_html__( 'Editar usuario', 'role-manager' ) : esc_html__( 'Crear nuevo usuario', 'role-manager' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-card">';
        wp_nonce_field( $editing ? 'rm_update_user' : 'rm_create_user' );
        printf( '<input type="hidden" name="action" value="%s" />', $editing ? 'rm_update_user' : 'rm_create_user' );
        if ( $editing ) {
            echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
        }

        echo '<table class="form-table" role="presentation"><tbody>';

        // --- Datos básicos de WordPress ---
        echo '<tr><th scope="row"><label for="rm-user-login">' . esc_html__( 'Nombre de usuario', 'role-manager' ) . '</label></th>';
        if ( $editing ) {
            echo '<td><code>' . esc_html( $user->user_login ) . '</code></td></tr>';
        } else {
            echo '<td><input name="user_login" id="rm-user-login" type="text" class="regular-text" required /></td></tr>';
        }

        echo '<tr><th scope="row"><label for="rm-user-email">' . esc_html__( 'Email', 'role-manager' ) . '</label></th>';
        echo '<td><input name="user_email" id="rm-user-email" type="email" class="regular-text" value="' . esc_attr( $editing ? $user->user_email : '' ) . '" required /></td></tr>';

        echo '<tr><th scope="row"><label for="rm-user-pass">' . esc_html__( 'Contraseña', 'role-manager' ) . '</label></th>';
        echo '<td><input name="user_pass" id="rm-user-pass" type="password" class="regular-text" autocomplete="new-password"' . ( $editing ? '' : ' required' ) . ' />';
        if ( $editing ) {
            echo '<p class="description">' . esc_html__( 'Déjala vacía para mantener la contraseña actual.', 'role-manager' ) . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="rm-user-first">' . esc_html__( 'Nombre', 'role-manager' ) . '</label></th>';
        echo '<td><input name="first_name" id="rm-user-first" type="text" class="regular-text" value="' . esc_attr( $editing ? $user->first_name : '' ) . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="rm-user-last">' . esc_html__( 'Apellidos', 'role-manager' ) . '</label></th>';
        echo '<td><input name="last_name" id="rm-user-last" type="text" class="regular-text" value="' . esc_attr( $editing ? $user->last_name : '' ) . '" /></td></tr>';

        // --- Roles (multi-rol) ---
        echo '<tr><th scope="row">' . esc_html__( 'Roles', 'role-manager' ) . '</th><td>';
        $current_roles = $editing ? (array) $user->roles : array( get_option( 'default_role', 'subscriber' ) );
        echo '<div class="rm-roles-grid">';
        foreach ( wp_roles()->roles as $slug => $role ) {
            printf(
                '<label class="rm-role-item"><input type="checkbox" name="roles[]" value="%1$s"%2$s /> %3$s <code>%1$s</code></label>',
                esc_attr( $slug ),
                checked( in_array( $slug, $current_roles, true ), true, false ),
                esc_html( translate_user_role( $role['name'] ) )
            );
        }
        echo '</div></td></tr>';

        // --- Campos personalizados ---
        $fields = RM_Custom_Fields_Module::get_fields();
        if ( ! empty( $fields ) ) {
            echo '<tr><th scope="row" colspan="2"><h3 class="rm-section-title">' . esc_html__( 'Campos personalizados', 'role-manager' ) . '</h3></th></tr>';
            foreach ( $fields as $field ) {
                $value = $editing ? (string) get_user_meta( $user->ID, RM_Custom_Fields_Module::meta_key( $field['key'] ), true ) : '';
                $this->render_custom_field_input( $field, $value );
            }
        }

        echo '</tbody></table>';

        submit_button( $editing ? __( 'Actualizar usuario', 'role-manager' ) : __( 'Crear usuario', 'role-manager' ) );
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->get_slug() ) ) . '" class="button">' . esc_html__( 'Volver al listado', 'role-manager' ) . '</a>';

        echo '</form>';
    }

    /**
     * Pinta el input adecuado según el tipo del campo personalizado.
     *
     * @param array  $field Definición del campo.
     * @param string $value Valor actual.
     */
    private function render_custom_field_input( $field, $value ) {
        $name     = 'rm_cf[' . $field['key'] . ']';
        $id       = 'rm-cf-' . $field['key'];
        $required = ! empty( $field['required'] );
        $mark     = $required ? ' <span class="rm-required">*</span>' : '';

        echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $mark . '</label></th><td>'; // phpcs:ignore

        switch ( $field['type'] ) {
            case 'number':
                printf(
                    '<input type="number" step="any" name="%s" id="%s" class="regular-text" value="%s" />',
                    esc_attr( $name ),
                    esc_attr( $id ),
                    esc_attr( $value )
                );
                break;

            case 'select':
                echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
                echo '<option value="">' . esc_html__( '— Seleccionar —', 'role-manager' ) . '</option>';
                foreach ( (array) $field['options'] as $option ) {
                    printf(
                        '<option value="%1$s"%2$s>%1$s</option>',
                        esc_attr( $option ),
                        selected( $value, $option, false )
                    );
                }
                echo '</select>';
                break;

            case 'radio':
                echo '<fieldset>';
                foreach ( (array) $field['options'] as $option ) {
                    printf(
                        '<label class="rm-radio-option"><input type="radio" name="%1$s" value="%2$s"%3$s /> %2$s</label>',
                        esc_attr( $name ),
                        esc_attr( $option ),
                        checked( $value, $option, false )
                    );
                }
                echo '</fieldset>';
                break;

            default:
                printf(
                    '<input type="text" name="%s" id="%s" class="regular-text" value="%s" />',
                    esc_attr( $name ),
                    esc_attr( $id ),
                    esc_attr( $value )
                );
        }

        echo '</td></tr>';
    }

    private function render_list() {
        $fields = RM_Custom_Fields_Module::get_fields();
        $users  = get_users( array( 'orderby' => 'login' ) );

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Usuario', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Roles', 'role-manager' ) . '</th>';
        foreach ( $fields as $field ) {
            echo '<th>' . esc_html( $field['label'] ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Acciones', 'role-manager' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $users as $user ) {
            $edit_url = add_query_arg(
                array( 'page' => $this->get_slug(), 'action' => 'edit', 'user' => $user->ID ),
                admin_url( 'admin.php' )
            );

            $role_names = array();
            foreach ( (array) $user->roles as $slug ) {
                $role_names[] = isset( wp_roles()->role_names[ $slug ] )
                    ? translate_user_role( wp_roles()->role_names[ $slug ] )
                    : $slug;
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html( $user->user_login ) . '</strong></td>';
            echo '<td>' . esc_html( $user->user_email ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', $role_names ) ) . '</td>';
            foreach ( $fields as $field ) {
                $value = (string) get_user_meta( $user->ID, RM_Custom_Fields_Module::meta_key( $field['key'] ), true );
                echo '<td>' . esc_html( $value ) . '</td>';
            }
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Editar', 'role-manager' ) . '</a> ';

            if ( get_current_user_id() !== $user->ID ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( '¿Eliminar este usuario? Esta acción no se puede deshacer.', 'role-manager' ) ) . '\');">';
                wp_nonce_field( 'rm_delete_user' );
                echo '<input type="hidden" name="action" value="rm_delete_user" />';
                echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
                echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Eliminar', 'role-manager' ) . '</button>';
                echo '</form>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }
}
