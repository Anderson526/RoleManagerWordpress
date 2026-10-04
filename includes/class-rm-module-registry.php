<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registro de módulos.
 *
 * Mantiene la colección de módulos activos y se encarga de:
 *  - arrancar sus hooks,
 *  - registrar un submenú por módulo bajo el menú principal,
 *  - resolver qué módulo renderiza la página principal.
 */
class RM_Module_Registry {

    /** @var RM_Module[] Indexados por slug. */
    private $modules = array();

    /**
     * Añade un módulo al registro.
     *
     * @param RM_Module $module
     * @return void
     */
    public function add( RM_Module $module ) {
        $this->modules[ $module->get_slug() ] = $module;
    }

    /**
     * @return RM_Module[]
     */
    public function all() {
        return $this->modules;
    }

    /**
     * Arranca los hooks de cada módulo.
     *
     * @return void
     */
    public function boot() {
        foreach ( $this->modules as $module ) {
            $module->register_hooks();
        }
    }

    /**
     * Registra un submenú por cada módulo bajo el menú principal.
     *
     * @param string $parent_slug Slug del menú principal.
     * @return void
     */
    public function register_submenus( $parent_slug ) {
        foreach ( $this->modules as $module ) {
            add_submenu_page(
                $parent_slug,
                $module->get_page_title(),
                $module->get_menu_title(),
                $module->get_capability(),
                $module->get_slug(),
                array( $module, 'render' )
            );
        }

        // Evita el submenú duplicado que WordPress crea con el slug del menú
        // principal, reemplazándolo por el título del primer módulo.
        global $submenu;
        if ( isset( $submenu[ $parent_slug ][0] ) ) {
            $first = $this->get_first_module();
            if ( $first ) {
                $submenu[ $parent_slug ][0][0] = $first->get_menu_title();
                $submenu[ $parent_slug ][0][2] = $first->get_slug();
            }
        }
    }

    /**
     * Devuelve el primer módulo registrado.
     *
     * @return RM_Module|null
     */
    public function get_first_module() {
        $values = array_values( $this->modules );
        return isset( $values[0] ) ? $values[0] : null;
    }

    /**
     * Callback del menú principal: renderiza el primer módulo.
     *
     * @return void
     */
    public function render_first_module() {
        $first = $this->get_first_module();
        if ( $first ) {
            $first->render();
        }
    }
}
