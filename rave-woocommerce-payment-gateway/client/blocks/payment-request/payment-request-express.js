import { __ } from '@wordpress/i18n';
import { FlutterWaveButton, closePaymentModal } from 'flutterwave-react-v3';
import {
	usePaymentRequest,
	useProcessPaymentHandler,
	useOnClickHandler,
	useCancelHandler,
} from './hooks';
import { getBlocksConfiguration } from 'wcflutterwave/blocks/utils';


export const PaymentRequestExpress = ({ payment_details } ) => {

	const fwConfig = {
		...payment_details,
		text: __('Pay with Flutterwave!', 'rave-woocommerce-payment-gateway'),
		callback: (response) => {
			console.log(response);
			closePaymentModal()
		},
		onClose: () => {},
	}
	return (
	<div>
		<FlutterWaveButton {...fwConfig} />
	</div>
	);
};