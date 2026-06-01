import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Wraps a Paid-only section. When `locked`, shows an upsell notice and renders
 * the children visually disabled (dimmed + non-interactive). Otherwise renders
 * the children unchanged.
 */
export default function PaidLock( { locked, message, children } ) {
	if ( ! locked ) {
		return children;
	}
	return (
		<div className="sb-paidlock">
			<Notice status="warning" isDismissible={ false }>
				{ '🔒 ' }
				{ message ||
					__(
						'Disponible dans la version payante de SlashBooking.',
						'slashbooking'
					) }
			</Notice>
			<div className="sb-paidlock__content" aria-disabled="true">
				{ children }
			</div>
		</div>
	);
}
