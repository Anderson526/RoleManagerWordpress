<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase base para todos los módulos del plugin.
 *
 * Cada funcionalidad (CRUD de roles, permisos por página, multi-rol por
 * usuario) se implementa como un módulo que extiende esta clase. Esto
 * garantiza que el plugin sea modular y escalable: para añadir una nueva
 * funcionalidad basta con crear una subclase y registrarla.
 */
abstract class RM_Module {

    /**
     * Slug único del módulo. Se usa como slug del submenú.
     *
     * @return string
     */
    abstract public function get_slug();

    /**
     * Título que aparece en el submenú.
     *
     * @return string
     */
    abstract public function get_menu_title();

    /**
     * Título de la página (cabecera <h1>).
     *
     * @return string
     */
    abstract public function get_page_title();

    /**
     * Renderiza el contenido de la página del módulo.
     *
     * @return void
     */
    abstract public function render();

    /**
     * Capacidad requerida para ver/usar el módulo.
     * Se puede sobreescribir por módulo.
     *
     * @return string
     */
    public function get_capability() {
        return ROLE_MANAGER_CAP;
    }

    /**
     * Punto de enganche de hooks propios del módulo (acciones admin_post,
     * enforcement en el front, etc.). Se llama una sola vez en el arranque.
     *
     * Por defecto no hace nada; los módulos lo sobreescriben si lo necesitan.
     *
     * @return void
     */
    public function register_hooks() {}

    /**
     * Helper: verifica capacidad y corta la ejecución si no la tiene.
     *
     * @return void
     */
    protected function check_permissions() {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta sección.', 'role-manager' ) );
        }
    }

    /**
     * Helper: renderiza un aviso de administración.
     *
     * @param string $message Texto del aviso (ya traducido).
     * @param string $type    success | error | warning | info.
     * @return void
     */
    protected function notice( $message, $type = 'success' ) {
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }
}
