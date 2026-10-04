# Role Manager

Plugin modular y escalable para la gestión de roles en WordPress. Agrupa seis
funcionalidades, cada una en su propio submenú bajo el menú **Role Manager**:

1. **Roles (CRUD)** — crear, listar, editar y eliminar roles con sus capacidades.
2. **Usuarios (CRUD)** — crear, listar, editar y eliminar usuarios con datos
   básicos, multi-rol y campos personalizados (metadatos).
3. **Permisos por página** — definir qué roles pueden acceder a cada página.
4. **Multi-rol por usuario** — asignar varios roles a un mismo usuario.
5. **Campos personalizados** — definir campos adicionales de usuario (texto,
   número, select, radio) que se guardan como metadatos.
6. **Donaciones ☕** — sección "Invítame un café" con PayPal REST API.

## Arquitectura

El plugin está construido sobre una infraestructura de **módulos** que lo hace
fácil de extender sin tocar el núcleo.

```
roleManager/
├── role-manager.php                      # Bootstrap: constantes + arranque
├── includes/
│   ├── class-role-manager.php            # Núcleo (singleton). Carga y menú.
│   ├── class-rm-module.php               # Clase base abstracta de un módulo
│   ├── class-rm-module-registry.php      # Registro de módulos + submenús
│   └── modules/
│       ├── class-rm-roles-module.php             # CRUD de roles
│       ├── class-rm-users-module.php             # CRUD de usuarios + metadatos
│       ├── class-rm-page-permissions-module.php  # Permisos por página
│       ├── class-rm-user-roles-module.php        # Multi-rol por usuario
│       ├── class-rm-custom-fields-module.php     # Campos personalizados
│       └── class-rm-donations-module.php         # Donaciones (PayPal REST)
├── admin/
│   └── assets/
│       ├── css/admin.css
│       └── js/admin.js
└── README.md
```

### ¿Cómo añadir una nueva funcionalidad?

Cada funcionalidad es un módulo que extiende `RM_Module`. Para añadir uno nuevo:

1. Crea una clase que extienda `RM_Module` e implementa:
   `get_slug()`, `get_menu_title()`, `get_page_title()` y `render()`.
   Opcionalmente `register_hooks()` (para `admin_post_*`, filtros, etc.) y
   `get_capability()`.
2. Regístralo. Dos opciones:
   - Añádelo al array de `Role_Manager::register_modules()`, **o**
   - Sin tocar el plugin, engánchalo desde otro plugin/tema:

```php
add_filter( 'role_manager_modules', function ( $modules ) {
    require_once __DIR__ . '/class-mi-modulo.php';
    $modules[] = new Mi_Modulo();
    return $modules;
} );
```

El registro se encarga automáticamente de arrancar sus hooks y de crear su
submenú bajo **Role Manager**.

## Funcionalidades en detalle

### 1. Roles (CRUD)

- Crea roles indicando nombre, slug (opcional, se autogenera) y capacidades
  agrupadas por área (Entradas, Páginas, Usuarios, Sistema…).
- Edita nombre y capacidades de cualquier rol (nativo o personalizado).
- Elimina **solo** roles creados por el plugin; los usuarios afectados se
  reasignan automáticamente a *Suscriptor*.
- Los roles nativos de WordPress nunca se pueden eliminar.

### 2. Permisos de acceso por página

- Tabla con todas las páginas y una columna por rol.
- Marca los roles autorizados para cada página.
- Una página **sin roles marcados es pública**.
- El control se aplica en el front (`template_redirect`):
  - Visitante no autenticado → redirige al login conservando el destino.
  - Usuario sin el rol requerido → error 403.
  - Los administradores del plugin siempre tienen acceso.
- Se puede anular por código con el filtro `role_manager_page_access`.

### 3. Asignación de múltiples roles por usuario

- Selecciona un usuario y marca **todos** los roles que debe tener.
- Aprovecha el soporte nativo de WordPress para múltiples roles
  (`add_role` / `remove_role`).
- Se garantiza que el usuario conserve al menos un rol.

### 4. CRUD de usuarios

- Crea usuarios con los datos básicos de WordPress: nombre de usuario, email
  y contraseña (más nombre y apellidos opcionales).
- Asigna uno o varios roles en el mismo formulario.
- Rellena los **campos personalizados** definidos en el módulo de campos;
  se guardan como *user meta* con prefijo `rm_cf_`.
- El listado muestra roles y valores de los campos personalizados.
- No permite eliminar el propio usuario conectado.

### 5. Campos personalizados de usuario

- Define campos de tipo **texto, número, select o radio**.
- Los select/radio admiten una lista de opciones (una por línea).
- Cada campo puede marcarse como obligatorio al crear usuarios.
- Al eliminar un campo se borran sus metadatos en todos los usuarios.

### 6. Donaciones ☕ "Invítame un café"

- Integración directa con la [PayPal REST API](https://developer.paypal.com/api/rest/)
  (v2 Checkout Orders): se crea la orden en el servidor, el usuario aprueba
  el pago en PayPal y al volver se captura automáticamente.
- Importes sugeridos + cantidad personalizada, con animaciones ligeras.
- Configuración de credenciales (Client ID / Secret), entorno
  (sandbox / live) y divisa desde la propia pantalla.
- El *Client Secret* nunca se vuelve a mostrar una vez guardado.

## Seguridad

- Todas las acciones de escritura usan `admin-post.php` con verificación de
  *nonce* (`check_admin_referer`) y de capacidad (`current_user_can`).
- Todas las entradas se sanean (`sanitize_key`, `absint`,
  `sanitize_text_field`) y las salidas se escapan.

## Configuración

La capacidad requerida para administrar el plugin es `manage_options` por
defecto. Se puede cambiar definiendo la constante antes de cargar el plugin:

```php
define( 'ROLE_MANAGER_CAP', 'edit_users' );
```

## Requisitos

- WordPress 5.0+
- PHP 7.2+
