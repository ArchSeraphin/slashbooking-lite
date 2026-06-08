import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import UpgradeButton from './UpgradeButton';

/**
 * Wraps a Paid-only section. When `locked`, shows an upsell notice and renders
 * the children visually disabled (dimmed + non-interactive). Otherwise renders
 * the children unchanged.
 * @param {Object}                    root0          Props.
 * @param {boolean}                   root0.locked   Verrouille la section (licence non valide).
 * @param {string}                    root0.message  Message d'upsell (défaut : encart générique).
 * @param {import('react').ReactNode} root0.children Contenu de la section.
 */
export default function PaidLock( { locked, message, children } ) {
	if ( ! locked ) {
		return children;
	}
	return (
		<div className="sb-paidlock">
			<Notice status="warning" isDismissible={ false }>
				<p style={ { margin: '0 0 10px' } }>
					{ '🔒 ' }
					{ message ||
						__(
							'Disponible dans la version payante de SlashBooking.',
							'slashbooking'
						) }
				</p>
				<UpgradeButton variant="primary" small />
			</Notice>
			<div className="sb-paidlock__content" aria-disabled="true">
				{ children }
			</div>
		</div>
	);
}
