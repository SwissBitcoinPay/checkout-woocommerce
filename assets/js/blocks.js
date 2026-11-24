/**
 * sattle.me for WooCommerce: the payment method in WooCommerce's Cart and Checkout blocks.
 *
 * Registered with WooCommerce's payment method registry (wc.wcBlocksRegistry), its title and
 * description read from what the server gives it (wc.wcSettings, "sattleme_data"). No build:
 * plain JavaScript on the globals of the script handles it depends on (wc-blocks-registry,
 * wc-settings, wp-element, wp-html-entities). Placing the order goes through the gateway's
 * process_payment() on the server, then to the order's payment page.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	var registry = wc.wcBlocksRegistry;
	var settings = wc.wcSettings;
	var element = wp.element;
	var entities = wp.htmlEntities;

	if ( ! registry || ! settings || ! element || ! entities ) {
		return;
	}

	var data = settings.getSetting( 'sattleme_data', {} ) || {};
	var title = entities.decodeEntities( data.title || '' );
	var description = entities.decodeEntities( data.description || '' );

	function Content() {
		return element.createElement( 'div', { className: 'sattleme-blocks-description' }, description );
	}

	function Label( props ) {
		var children = [];
		if ( data.icon ) {
			children.push(
				element.createElement( 'img', {
					key: 'icon',
					src: data.icon,
					alt: '',
					width: 24,
					height: 24,
					style: { marginRight: '0.5em', verticalAlign: 'middle' }
				} )
			);
		}
		var PaymentMethodLabel = props && props.components && props.components.PaymentMethodLabel;
		children.push(
			PaymentMethodLabel
				? element.createElement( PaymentMethodLabel, { key: 'text', text: title } )
				: element.createElement( 'span', { key: 'text' }, title )
		);
		return element.createElement( 'span', { className: 'sattleme-blocks-label', style: { display: 'inline-flex', alignItems: 'center' } }, children );
	}

	registry.registerPaymentMethod( {
		name: 'sattleme',
		paymentMethodId: 'sattleme',
		label: element.createElement( Label ),
		ariaLabel: title,
		content: element.createElement( Content ),
		edit: element.createElement( Content ),
		canMakePayment: function () {
			// The server decides: WooCommerce offers the method only when the gateway is available.
			return true;
		},
		supports: {
			features: Array.isArray( data.supports ) ? data.supports : [ 'products' ]
		}
	} );
}() );
