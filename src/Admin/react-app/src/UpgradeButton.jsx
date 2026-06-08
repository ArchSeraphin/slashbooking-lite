import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const PRICING_URL =
	( window.SlashBooking && window.SlashBooking.pricingUrl ) ||
	'https://slashbooking.fr/#tarifs';

/**
 * "Passer à Pro" call-to-action. Opens the pricing page in a new tab so the
 * admin session is preserved. Reused everywhere a paid feature is locked.
 * @param {Object}  root0         Props.
 * @param {string}  root0.variant Button variant (default 'primary').
 * @param {boolean} root0.small   Render a compact button.
 * @param {string}  root0.label   Override the label.
 */
export default function UpgradeButton( {
	variant = 'primary',
	small = false,
	label,
} ) {
	return (
		<Button
			variant={ variant }
			size={ small ? 'small' : 'default' }
			href={ PRICING_URL }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ label || __( 'Passer à Pro', 'slashbooking' ) }
		</Button>
	);
}
