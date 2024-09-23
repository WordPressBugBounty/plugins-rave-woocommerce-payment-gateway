import { getSetting } from '@woocommerce/settings';
import { PAYMENT_METHOD_NAME } from './constants';
import { PaymentRequestExpress } from './payment-request-express';
import { applePayImage } from './apple-pay-preview';
import {
	getBlocksConfiguration,
} from 'wcflutterwave/blocks/utils';

const ApplePayPreview = () => <img src={ applePayImage } alt="" />;

const public_key = getBlocksConfiguration()?.public_key ?? null;

const paymentRequestPaymentMethod = {
	name: PAYMENT_METHOD_NAME,
	content: <PaymentRequestExpress flutterwave={ { public_key } } />,
	edit: <ApplePayPreview />,
	canMakePayment: ( cartData ) => {
		return true;
	},
	paymentMethodId: PAYMENT_METHOD_NAME,
	supports: {
		features: getBlocksConfiguration()?.supports ?? [],
	},
};

export default paymentRequestPaymentMethod;