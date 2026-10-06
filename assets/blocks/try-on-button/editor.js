/**
 * Editor side of the "Try On button" block. Plain ES5 (no build step): the block is
 * dynamic and rendered by PHP, so the editor only shows a preview and a product ID field.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;

	wp.blocks.registerBlockType( 'weargraph/try-on-button', {
		edit: function ( props ) {
			var id = props.attributes.productId || 0;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Product', 'weargraph' ) },
						el( TextControl, {
							label: __( 'Product ID (leave empty for the current product)', 'weargraph' ),
							type: 'number',
							value: id ? String( id ) : '',
							onChange: function ( value ) {
								props.setAttributes( { productId: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				el( 'span', { className: 'button weargraph-tryon-button', 'aria-disabled': 'true' }, __( 'Try On', 'weargraph' ) ),
				el( 'p', { style: { fontSize: '12px', opacity: 0.7, margin: '4px 0 0' } },
					__( 'Shown only for products switched on in WearGraph → Catalog.', 'weargraph' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
