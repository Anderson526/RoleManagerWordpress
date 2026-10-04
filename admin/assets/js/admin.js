/* Role Manager - scripts del panel de administración */
( function ( $ ) {
    'use strict';

    $( function () {
        // Genera el slug automáticamente a partir del nombre del rol (solo al crear).
        var $name = $( '#rm-role-name' );
        var $slug = $( '#rm-role-slug' );

        if ( $name.length && $slug.length ) {
            var touched = false;
            $slug.on( 'input', function () {
                touched = true;
            } );
            $name.on( 'input', function () {
                if ( touched ) {
                    return;
                }
                var value = $( this ).val()
                    .toLowerCase()
                    .replace( /[áàä]/g, 'a' )
                    .replace( /[éèë]/g, 'e' )
                    .replace( /[íìï]/g, 'i' )
                    .replace( /[óòö]/g, 'o' )
                    .replace( /[úùü]/g, 'u' )
                    .replace( /ñ/g, 'n' )
                    .replace( /[^a-z0-9]+/g, '_' )
                    .replace( /^_+|_+$/g, '' );
                $slug.val( value );
            } );
        }

        // Campos personalizados: solo mostrar "Opciones" para select/radio.
        var $type = $( '#rm-cf-type' );
        if ( $type.length ) {
            var toggleOptions = function () {
                var show = 'select' === $type.val() || 'radio' === $type.val();
                $( '.rm-cf-options-row' ).toggle( show );
            };
            $type.on( 'change', toggleOptions );
            toggleOptions();
        }

        // Donaciones: la cantidad personalizada activa su radio y viceversa.
        var $other = $( '#rm-amount-other' );
        var $otherValue = $( '#rm-amount-other-value' );
        if ( $other.length && $otherValue.length ) {
            $otherValue.on( 'focus input', function () {
                $other.prop( 'checked', true ).val( $( this ).val() );
            } );
            $( '.rm-donate-form input[name="amount"]' ).not( $other ).on( 'change', function () {
                $otherValue.val( '' );
                $other.val( '' );
            } );
        }
    } );
} )( jQuery );
