<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Núcleo del plugin.
 *
 * Actúa como contenedor: carga la infraestructura modular, registra los
 * módulos incluidos y delega en ellos el registro de submenús y hooks.
 * Para añadir una nueva funcionalidad basta con crear una clase que extienda
 * RM_Module y registrarla en register_modules() (o vía el filtro
 * `role_manager_modules`), sin tocar el resto del plugin.
 */
class Role_Manager {

    /** @var Role_Manager|null */
    private static $instance = null;

    /** @var string Ruta al archivo principal del plugin. */
    private $file;

    /** @var RM_Module_Registry */
    private $registry;

    public static function get_instance( $file = '' ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $file );
        }
        return self::$instance;
    }

    private function __construct( $file ) {
        $this->file = $file;
        $this->includes();
        $this->registry = new RM_Module_Registry();
    }

    /**
     * Carga la infraestructura y los módulos.
     */
    private function includes() {
        // Infraestructura.
        require_once ROLE_MANAGER_PATH . 'includes/class-rm-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/class-rm-module-registry.php';

        // Módulos (cada uno es autocontenido y define su propio submenú).
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-roles-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-page-permissions-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-user-roles-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-custom-fields-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-users-module.php';
        require_once ROLE_MANAGER_PATH . 'includes/modules/class-rm-donations-module.php';
    }

    public function init() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        $this->register_modules();

        // Registro de hooks de cada módulo.
        $this->registry->boot();

        // Menú de administración.
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        }
    }

    /**
     * Instancia y registra los módulos del plugin.
     *
     * El filtro `role_manager_modules` permite a terceros añadir o quitar
     * módulos, lo que mantiene el plugin escalable.
     */
    private function register_modules() {
        $modules = array(
            new RM_Roles_Module(),
            new RM_Users_Module(),
            new RM_Page_Permissions_Module(),
            new RM_User_Roles_Module(),
            new RM_Custom_Fields_Module(),
            new RM_Donations_Module(),
        );

        /**
         * Filtra la lista de módulos activos.
         *
         * @param RM_Module[] $modules Instancias de módulos.
         */
        $modules = apply_filters( 'role_manager_modules', $modules );

        foreach ( $modules as $module ) {
            if ( $module instanceof RM_Module ) {
                $this->registry->add( $module );
            }
        }
    }

    /**
     * Menú principal + un submenú por módulo.
     */
    public function register_menu() {
        add_menu_page(
            __( 'Role Manager', 'role-manager' ),
            __( 'Role Manager', 'role-manager' ),
            ROLE_MANAGER_CAP,
            'role-manager',
            array( $this->registry, 'render_first_module' ),
            'dashicons-groups',
            71
        );

        $this->registry->register_submenus( 'role-manager' );
    }

    public function enqueue_assets( $hook ) {
        // Solo cargar en las páginas del plugin.
        if ( false === strpos( (string) $hook, 'role-manager' ) ) {
            return;
        }

        wp_enqueue_style(
            'role-manager-admin',
            ROLE_MANAGER_URL . 'admin/assets/css/admin.css',
            array(),
            ROLE_MANAGER_VERSION
        );

        wp_enqueue_script(
            'role-manager-admin',
            ROLE_MANAGER_URL . 'admin/assets/js/admin.js',
            array( 'jquery' ),
            ROLE_MANAGER_VERSION,
            true
        );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'role-manager',
            false,
            dirname( plugin_basename( $this->file ) ) . '/languages'
        );
    }
}
