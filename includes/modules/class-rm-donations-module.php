<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Módulo: Donaciones "☕ Invítame un café".
 *
 * Integra la PayPal REST API (v2 Checkout Orders):
 *  1. Se crea una orden en el servidor (create order) y se redirige al
 *     usuario a PayPal para aprobarla.
 *  2. Al volver, se captura el pago (capture order) y se muestra el
 *     agradecimiento.
 *
 * Credenciales (Client ID / Secret) y entorno (sandbox/live) se configuran
 * en la misma pantalla. Docs: https://developer.paypal.com/api/rest/
 */
class RM_Donations_Module extends RM_Module {

    const OPTION_SETTINGS = 'rm_donations_settings';

    /** Importes sugeridos (en la divisa configurada). */
    const PRESETS = array( 3, 5, 10 );

    public function get_slug() {
        return 'rm-donations';
    }

    public function get_menu_title() {
        return __( 'Donaciones ☕', 'role-manager' );
    }

    public function get_page_title() {
        return __( '☕ Invítame un café', 'role-manager' );
    }

    public function register_hooks() {
        add_action( 'admin_post_rm_donation_settings', array( $this, 'handle_settings' ) );
        add_action( 'admin_post_rm_donate', array( $this, 'handle_donate' ) );
        add_action( 'admin_init', array( $this, 'maybe_capture_order' ) );
    }

    /* ---------------------------------------------------------------------
     * Configuración
     * ------------------------------------------------------------------- */

    /**
     * @return array{client_id:string,client_secret:string,mode:string,currency:string}
     */
    public static function get_settings() {
        return wp_parse_args(
            (array) get_option( self::OPTION_SETTINGS, array() ),
            array(
                'client_id'     => '',
                'client_secret' => '',
                'mode'          => 'sandbox',
                'currency'      => 'USD',
            )
        );
    }

    private static function api_base( $settings ) {
        return 'live' === $settings['mode']
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function handle_settings() {
        $this->check_permissions();
        check_admin_referer( 'rm_donation_settings' );

        $settings = self::get_settings();

        $settings['client_id'] = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $settings['mode']      = ( isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ) ? 'live' : 'sandbox';

        $currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : 'USD';
        $settings['currency'] = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';

        // El secret solo se sobreescribe si se envía uno nuevo (no se re-muestra).
        $secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
        if ( '' !== $secret ) {
            $settings['client_secret'] = $secret;
        }

        update_option( self::OPTION_SETTINGS, $settings, false );

        $this->redirect_back( array( 'rm_status' => 'success', 'rm_message' => 'settings_saved' ) );
    }

    /* ---------------------------------------------------------------------
     * PayPal REST API
     * ------------------------------------------------------------------- */

    /**
     * Obtiene un access token OAuth2 (client_credentials).
     *
     * @return string|WP_Error
     */
    private function get_access_token() {
        $settings = self::get_settings();

        if ( '' === $settings['client_id'] || '' === $settings['client_secret'] ) {
            return new WP_Error( 'rm_no_credentials', __( 'Faltan credenciales de PayPal.', 'role-manager' ) );
        }

        $response = wp_remote_post(
            self::api_base( $settings ) . '/v1/oauth2/token',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => 'grant_type=client_credentials',
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            return new WP_Error( 'rm_token_error', __( 'PayPal no devolvió un token de acceso.', 'role-manager' ) );
        }

        return (string) $body['access_token'];
    }

    /**
     * Crea la orden y redirige al usuario a PayPal para aprobarla.
     */
    public function handle_donate() {
        $this->check_permissions();
        check_admin_referer( 'rm_donate' );

        $amount = isset( $_POST['amount'] ) ? round( (float) wp_unslash( $_POST['amount'] ), 2 ) : 0;
        if ( $amount < 1 ) {
            $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'bad_amount' ) );
        }

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'credentials' ) );
        }

        $settings   = self::get_settings();
        $return_url = add_query_arg(
            array( 'page' => $this->get_slug(), 'rm_pp' => 'return' ),
            admin_url( 'admin.php' )
        );
        $cancel_url = add_query_arg(
            array( 'page' => $this->get_slug(), 'rm_status' => 'error', 'rm_message' => 'cancelled' ),
            admin_url( 'admin.php' )
        );

        $response = wp_remote_post(
            self::api_base( $settings ) . '/v2/checkout/orders',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'intent'         => 'CAPTURE',
                        'purchase_units' => array(
                            array(
                                'description' => __( 'Café para el desarrollador de Role Manager', 'role-manager' ),
                                'amount'      => array(
                                    'currency_code' => $settings['currency'],
                                    'value'         => number_format( $amount, 2, '.', '' ),
                                ),
                            ),
                        ),
                        'application_context' => array(
                            'brand_name'   => get_bloginfo( 'name' ),
                            'user_action'  => 'PAY_NOW',
                            'return_url'   => $return_url,
                            'cancel_url'   => $cancel_url,
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'api_error' ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // Busca el enlace de aprobación para redirigir al usuario a PayPal.
        if ( ! empty( $body['links'] ) ) {
            foreach ( (array) $body['links'] as $link ) {
                if ( isset( $link['rel'], $link['href'] ) && 'approve' === $link['rel'] ) {
                    wp_redirect( esc_url_raw( $link['href'] ) ); // Dominio externo: no usar wp_safe_redirect.
                    exit;
                }
            }
        }

        $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'api_error' ) );
    }

    /**
     * Al volver de PayPal (?rm_pp=return&token=ORDER_ID) captura el pago.
     */
    public function maybe_capture_order() {
        if ( ! isset( $_GET['page'], $_GET['rm_pp'], $_GET['token'] )
            || $this->get_slug() !== $_GET['page']
            || 'return' !== $_GET['rm_pp']
            || ! current_user_can( $this->get_capability() )
        ) {
            return;
        }

        $order_id = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        $token    = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'credentials' ) );
        }

        $settings = self::get_settings();
        $response = wp_remote_post(
            self::api_base( $settings ) . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
            )
        );

        $body   = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
        $status = isset( $body['status'] ) ? $body['status'] : '';

        if ( 'COMPLETED' === $status ) {
            $this->redirect_back( array( 'rm_status' => 'success', 'rm_message' => 'thanks' ) );
        }

        $this->redirect_back( array( 'rm_status' => 'error', 'rm_message' => 'capture_failed' ) );
    }

    private function redirect_back( $args ) {
        $url = add_query_arg(
            array_merge( array( 'page' => $this->get_slug() ), $args ),
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

        $settings   = self::get_settings();
        $configured = '' !== $settings['client_id'] && '' !== $settings['client_secret'];

        echo '<div class="wrap rm-wrap rm-donations">';
        echo '<h1>' . esc_html( $this->get_page_title() ) . '</h1>';

        $this->maybe_render_notice();

        // --- Tarjeta de donación ---
        echo '<div class="rm-card rm-donate-card">';
        echo '<div class="rm-coffee-emoji" aria-hidden="true">☕</div>';
        echo '<h2>' . esc_html__( '¿Te resulta útil Role Manager?', 'role-manager' ) . '</h2>';
        echo '<p>' . esc_html__( 'Invítame un café y ayúdame a seguir manteniendo y mejorando este plugin. ¡Gracias!', 'role-manager' ) . '</p>';

        if ( $configured ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-donate-form">';
            wp_nonce_field( 'rm_donate' );
            echo '<input type="hidden" name="action" value="rm_donate" />';

            echo '<div class="rm-amounts">';
            foreach ( self::PRESETS as $i => $preset ) {
                printf(
                    '<label class="rm-amount"><input type="radio" name="amount" value="%1$s"%2$s /><span>%3$s %1$s</span></label>',
                    esc_attr( $preset ),
                    checked( 1 === $i, true, false ),
                    esc_html( $settings['currency'] )
                );
            }
            echo '<label class="rm-amount rm-amount-custom">';
            echo '<input type="radio" name="amount" value="" id="rm-amount-other" />';
            echo '<span><input type="number" min="1" step="0.5" placeholder="' . esc_attr__( 'Otro', 'role-manager' ) . '" id="rm-amount-other-value" aria-label="' . esc_attr__( 'Otra cantidad', 'role-manager' ) . '" /></span>';
            echo '</label>';
            echo '</div>';

            echo '<button type="submit" class="rm-donate-button">';
            echo '<span class="rm-donate-button-coffee" aria-hidden="true">☕</span> ';
            echo esc_html__( 'Donar con PayPal', 'role-manager' );
            echo '</button>';

            if ( 'sandbox' === $settings['mode'] ) {
                echo '<p class="description">' . esc_html__( 'Modo sandbox activo: los pagos son de prueba.', 'role-manager' ) . '</p>';
            }

            echo '</form>';
        } else {
            echo '<p><em>' . esc_html__( 'Configura las credenciales de PayPal más abajo para activar las donaciones.', 'role-manager' ) . '</em></p>';
        }

        echo '</div>';

        // --- Configuración de PayPal ---
        $this->render_settings_form( $settings );

        echo '</div>';
    }

    private function render_settings_form( $settings ) {
        echo '<h2>' . esc_html__( 'Configuración de PayPal', 'role-manager' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Crea una app REST en developer.paypal.com y pega aquí sus credenciales.', 'role-manager' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rm-card">';
        wp_nonce_field( 'rm_donation_settings' );
        echo '<input type="hidden" name="action" value="rm_donation_settings" />';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="rm-pp-client-id">' . esc_html__( 'Client ID', 'role-manager' ) . '</label></th>';
        echo '<td><input name="client_id" id="rm-pp-client-id" type="text" class="large-text" value="' . esc_attr( $settings['client_id'] ) . '" autocomplete="off" /></td></tr>';

        echo '<tr><th scope="row"><label for="rm-pp-secret">' . esc_html__( 'Client Secret', 'role-manager' ) . '</label></th>';
        echo '<td><input name="client_secret" id="rm-pp-secret" type="password" class="large-text" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $settings['client_secret'] ? __( 'Guardado — escribe uno nuevo para cambiarlo', 'role-manager' ) : '' ) . '" /></td></tr>';

        echo '<tr><th scope="row">' . esc_html__( 'Entorno', 'role-manager' ) . '</th><td>';
        echo '<label><input type="radio" name="mode" value="sandbox"' . checked( $settings['mode'], 'sandbox', false ) . ' /> ' . esc_html__( 'Sandbox (pruebas)', 'role-manager' ) . '</label><br />';
        echo '<label><input type="radio" name="mode" value="live"' . checked( $settings['mode'], 'live', false ) . ' /> ' . esc_html__( 'Live (pagos reales)', 'role-manager' ) . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="rm-pp-currency">' . esc_html__( 'Divisa (ISO 4217)', 'role-manager' ) . '</label></th>';
        echo '<td><input name="currency" id="rm-pp-currency" type="text" class="small-text" maxlength="3" value="' . esc_attr( $settings['currency'] ) . '" /> <span class="description">USD, EUR, MXN…</span></td></tr>';

        echo '</tbody></table>';

        submit_button( __( 'Guardar configuración', 'role-manager' ) );
        echo '</form>';
    }

    private function maybe_render_notice() {
        if ( empty( $_GET['rm_status'] ) || empty( $_GET['rm_message'] ) ) {
            return;
        }
        $status   = sanitize_key( wp_unslash( $_GET['rm_status'] ) );
        $code     = sanitize_key( wp_unslash( $_GET['rm_message'] ) );
        $messages = array(
            'settings_saved' => __( 'Configuración de PayPal guardada.', 'role-manager' ),
            'thanks'         => __( '¡Muchísimas gracias por el café! ☕💛 Tu donación se completó correctamente.', 'role-manager' ),
            'bad_amount'     => __( 'Indica una cantidad válida (mínimo 1).', 'role-manager' ),
            'credentials'    => __( 'No se pudo autenticar con PayPal. Revisa las credenciales.', 'role-manager' ),
            'api_error'      => __( 'PayPal no aceptó la solicitud. Inténtalo de nuevo.', 'role-manager' ),
            'cancelled'      => __( 'Donación cancelada. ¡No pasa nada, gracias igualmente!', 'role-manager' ),
            'capture_failed' => __( 'No se pudo completar el pago en PayPal.', 'role-manager' ),
        );
        $text = isset( $messages[ $code ] ) ? $messages[ $code ] : '';
        if ( $text ) {
            $this->notice( $text, 'error' === $status ? 'error' : 'success' );
        }
    }
}
