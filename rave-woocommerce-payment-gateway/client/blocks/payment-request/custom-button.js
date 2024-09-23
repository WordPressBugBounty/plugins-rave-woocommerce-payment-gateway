import { __ } from '@wordpress/i18n';
import { getBlocksConfiguration } from 'wcflutterwave/blocks/utils';

export const CustomButton = ( { onButtonClicked } ) => {
	const {
		theme = 'dark',
		height = '44',
		customLabel = __( 'Buy now', 'rave-woocommerce-payment-gateway' ),
	} = getBlocksConfiguration()?.button;
	return (
		<button
			type="button"
			id="wc-flutterwave-custom-button"
			className={ `button ${ theme } is-active` }
			style={ {
				height: height + 'px',
			} }
			onClick={ onButtonClicked }
		>
			{ customLabel }
		</button>
	);
};