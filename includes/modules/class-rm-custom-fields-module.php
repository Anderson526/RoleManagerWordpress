<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: Campos personalizados de usuario.
 *
 * Permite definir campos adicionales (texto, número, select, radio) que se
 * mostrarán en el CRUD de usuarios y se guardarán como metadatos. Las
 * definiciones se almacenan en una opción del sitio.
 */
class RM_Custom_Fields_Module extends RM_Module {

    /** Opción donde se guardan las definiciones de campos. */
    const OPTION_FIELDS = 'rm_user_fields';

    /** Prefijo de las claves de user meta generadas por el plugin. */
    const META_PREFIX = 'rm_cf_';

    public function get_slug() {
        return 'rm-custom-fields';
    }

    public function get_menu_title() {
        return __( 'Campos personalizados', 'role-manager' );
    }

    public function get_page_title() {
        return __( 'Campos personalizados de usuario', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_cf_save', array( $this, 'handle_save' ) );
        add_action( 'admin_post_rm_cf_delete', array( $this, 'handle_delete' ) );
    }

    /* ---------------------------------------------------------------------
     * Repositorio de datos
     * ------------------------------------------------------------------- */

    /**
     * Tipos de campo soportados.
     *
     * @return array<string,string> tipo => etiqueta.
     */
    public static function get_field_types() {
        return array(
            'text'   => __( 'Texto', 'role-manager' ),
            'number' => __( 'Número', 'role-manager' ),
            'select' => __( 'Select (lista desplegable)', 'role-manager' ),
            'radio'  => __( 'Radio buttons', 'role-manager' ),
        );
    }

    /**
     * Definiciones de campos, indexadas por clave.
     * La primera vez se siembran dos campos de ejemplo.
     *
     * @return array<string,array{key:string,label:string,type:string,options:string[],required:bool}>
     */
    public static function get_fields() {
        $fields = get_option( self::OPTION_FIELDS, null );

        if ( null === $fields ) {
            $fields = array(
                'identificacion' => array(
                    'key'      => 'identificacion',
                    'label'    => __( 'Identificación', 'role-manager' ),
                    'type'     => 'text',
                    'options'  => array(),
                    'required' => false,
                ),
                'nombres_claves' => array(
                    'key'      => 'nombres_claves',
                    'label'    => __( 'Nombres claves', 'role-manager' ),
                    'type'     => 'text',
                    'options'  => array(),
                    'required' => false,
                ),
            );
            update_option( self::OPTION_FIELDS, $fields );
        }

        return (array) $fields;
    }

    /**
     * Clave de user meta para una definición de campo.
     *
     * @param string $key Clave del campo.
     * @return string
     */
    public static function meta_key( $key ) {
        return self::META_PREFIX . $key;
    }

    /**
     * Sanea el valor enviado para un campo según su tipo.
     *
     * @param array $field Definición del campo.
     * @param mixed $raw   Valor recibido (sin slashes).
     * @return string Valor saneado ('' si no es válido).
     */
    public static function sanitize_value( $field, $raw ) {
        $raw = is_scalar( $raw ) ? (string) $raw : '';

        switch ( $field['type'] ) {
            case 'number':
                return is_numeric( $raw ) ? (string) ( 0 + $raw ) : '';
            case 'select':
            case 'radio':
                return in_array( $raw, (array) $field['options'], true ) ? $raw : '';
            default:
                return sanitize_text_field( $raw );
        }
    }

    /* ---------------------------------------------------------------------
     * Handlers (admin_post)
     * ------------------------------------------------------------------- */

    public function handle_save() {
        $this->check_permissions();
        check_admin_referer( 'rm_cf_save' );

        $label    = isset( $_POST['field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['field_label'] ) ) : '';
        $key      = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
        $type     = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text';
        $required = ! empty( $_POST['field_required'] );
        $editing  = ! empty( $_POST['editing'] );

        if ( '' === $key && '' !== $label ) {
            $key = sanitize_key( str_replace( array( ' ', '-' ), '_', remove_accents( $label ) ) );
        }

        if ( '' === $label || '' === $key ) {
            $this->redirect_back( 'error', 'missing' );
        }

        if ( ! array_key_exists( $type, self::get_field_types() ) ) {
            $type = 'text';
        }

        // Opciones (una por línea) solo para select/radio.
        $options = array();
        if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
            $raw_options = isset( $_POST['field_options'] ) ? wp_unslash( $_POST['field_options'] ) : '';
            foreach ( explode( "\n", (string) $raw_options ) as $line ) {
                $line = sanitize_text_field( $line );
                if ( '' !== $line && ! in_array( $line, $options, true ) ) {
                    $options[] = $line;
                }
            }
            if ( empty( $options ) ) {
                $this->redirect_back( 'error', 'no_options' );
            }
        }

        $fields = self::get_fields();

        if ( ! $editing && isset( $fields[ $key ] ) ) {
            $this->redirect_back( 'error', 'exists' );
        }

        $fields[ $key ] = array(
            'key'      => $key,
            'label'    => $label,
            'type'     => $type,
            'options'  => $options,
            'required' => $required,
        );

        update_option( self::OPTION_FIELDS, $fields );

        $this->redirect_back( 'success', $editing ? 'updated' : 'created' );
    }

    public function handle_delete() {
        $this->check_permissions();
        check_admin_referer( 'rm_cf_delete' );

        $key    = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
        $fields = self::get_fields();

        if ( ! isset( $fields[ $key ] ) ) {
            $this->redirect_back( 'error', 'notfound' );
        }

        unset( $fields[ $key ] );
        update_option( self::OPTION_FIELDS, $fields );

        // Limpia los metadatos asociados de todos los usuarios.
        delete_metadata( 'user', 0, self::meta_key( $key ), '', true );

        $this->redirect_back( 'success', 'deleted' );
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

        $action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
        $edit_key = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
        $fields   = self::get_fields();

        echo '<div class="wrap rm-wrap">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Define los campos adicionales que se pedirán al crear o editar usuarios. Se guardan como metadatos del usuario.', 'role-manager' ) . '</p>';

        $this->maybe_render_notice();

        if ( 'edit' === $action && isset( $fields[ $edit_key ] ) ) {
            $this->render_form( $fields[ $edit_key ] );
        } else {
            $this->render_form();
            $this->render_list( $fields );
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
            'created'    => __( 'Campo creado correctamente.', 'role-manager' ),
            'updated'    => __( 'Campo actualizado correctamente.', 'role-manager' ),
            'deleted'    => __( 'Campo eliminado correctamente (incluidos sus metadatos).', 'role-manager' ),
            'missing'    => __( 'Faltan datos obligatorios (etiqueta del campo).', 'role-manager' ),
            'exists'     => __( 'Ya existe un campo con esa clave.', 'role-manager' ),
            'notfound'   => __( 'El campo indicado no existe.', 'role-manager' ),
            'no_options' => __( 'Los campos select/radio necesitan al menos una opción.', 'role-manager' ),
        );
        $text = isset( $messages[ $code ] ) ? $messages[ $code ] : '';
        if ( $text ) {
            $this->notice( $text, 'error' === $status ? 'error' : 'success' );
        }
    }

    /**
     * Formulario de creación o edición de un campo.
     *
     * @param array|null $field Definición a editar; null para crear.
     */
    private function render_form( $field = null ) {
        $editing = null !== $field;
        $label   = $editing ? $field['label'] : '';
        $key     = $editing ? $field['key'] : '';
        $type    = $editing ? $field['type'] : 'text';
        $options = $editing ? implode( "\n", (array) $field['options'] ) : '';

        echo '<h2>' . ( $editing ? esc_html__( 'Editar campo', 'role-manager' ) : esc_html__( 'Añadir campo', 'role-manager' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-card">';
        wp_nonce_field( 'rm_cf_save' );
        echo '<input type="hidden" name="action" value="rm_cf_save" />';
        if ( $editing ) {
            echo '<input type="hidden" name="editing" value="1" />';
        }

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="rm-cf-label">' . esc_html__( 'Etiqueta', 'role-manager' ) . '</label></th>';
        echo '<td><input name="field_label" id="rm-cf-label" type="text" class="regular-text" value="' . esc_attr( $label ) . '" required /></td></tr>';

        echo '<tr><th scope="row"><label for="rm-cf-key">' . esc_html__( 'Clave (meta key)', 'role-manager' ) . '</label></th>';
        if ( $editing ) {
            echo '<td><code>' . esc_html( self::meta_key( $key ) ) . '</code>';
            echo '<input type="hidden" name="field_key" value="' . esc_attr( $key ) . '" /></td></tr>';
        } else {
            echo '<td><input name="field_key" id="rm-cf-key" type="text" class="regular-text" placeholder="' . esc_attr__( 'Opcional, se genera desde la etiqueta', 'role-manager' ) . '" /></td></tr>';
        }

        echo '<tr><th scope="row"><label for="rm-cf-type">' . esc_html__( 'Tipo', 'role-manager' ) . '</label></th><td>';
        echo '<select name="field_type" id="rm-cf-type">';
        foreach ( self::get_field_types() as $value => $text ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $type, $value, false ),
                esc_html( $text )
            );
        }
        echo '</select></td></tr>';

        echo '<tr class="rm-cf-options-row"><th scope="row"><label for="rm-cf-options">' . esc_html__( 'Opciones', 'role-manager' ) . '</label></th>';
        echo '<td><textarea name="field_options" id="rm-cf-options" rows="4" class="regular-text" placeholder="' . esc_attr__( 'Una opción por línea', 'role-manager' ) . '">' . esc_textarea( $options ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Solo para campos de tipo select o radio.', 'role-manager' ) . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__( 'Obligatorio', 'role-manager' ) . '</th>';
        echo '<td><label><input type="checkbox" name="field_required" value="1"' . checked( $editing && ! empty( $field['required'] ), true, false ) . ' /> ' . esc_html__( 'El campo es obligatorio al crear usuarios', 'role-manager' ) . '</label></td></tr>';

        echo '</tbody></table>';

        submit_button( $editing ? __( 'Actualizar campo', 'role-manager' ) : __( 'Añadir campo', 'role-manager' ) );

        if ( $editing ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->get_slug() ) ) . '" class="button">' . esc_html__( 'Cancelar', 'role-manager' ) . '</a>';
        }

        echo '</form>';
    }

    private function render_list( $fields ) {
        echo '<h2>' . esc_html__( 'Campos definidos', 'role-manager' ) . '</h2>';

        if ( empty( $fields ) ) {
            echo '<p>' . esc_html__( 'Todavía no hay campos personalizados.', 'role-manager' ) . '</p>';
            return;
        }

        $types = self::get_field_types();

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Etiqueta', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Meta key', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Tipo', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Opciones', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Obligatorio', 'role-manager' ) . '</th>';
        echo '<th>' . esc_html__( 'Acciones', 'role-manager' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $fields as $field ) {
            $edit_url = add_query_arg(
                array( 'page' => $this->get_slug(), 'action' => 'edit', 'field' => $field['key'] ),
                admin_url( 'admin.php' )
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html( $field['label'] ) . '</strong></td>';
            echo '<td><code>' . esc_html( self::meta_key( $field['key'] ) ) . '</code></td>';
            echo '<td>' . esc_html( isset( $types[ $field['type'] ] ) ? $types[ $field['type'] ] : $field['type'] ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', (array) $field['options'] ) ) . '</td>';
            echo '<td>' . ( ! empty( $field['required'] ) ? esc_html__( 'Sí', 'role-manager' ) : esc_html__( 'No', 'role-manager' ) ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Editar', 'role-manager' ) . '</a> ';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( '¿Eliminar este campo? Se borrarán también sus metadatos en todos los usuarios.', 'role-manager' ) ) . '\');">';
            wp_nonce_field( 'rm_cf_delete' );
            echo '<input type="hidden" name="action" value="rm_cf_delete" />';
            echo '<input type="hidden" name="field_key" value="' . esc_attr( $field['key'] ) . '" />';
            echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Eliminar', 'role-manager' ) . '</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }
}
