import { __ } from '@wordpress/i18n';
import UpgradeButton from './UpgradeButton';

/**
 * Persistent Free→Pro upsell bar shown above every tab while the site is not
 * licensed. Aggressive but dismiss-free: a single slim, on-brand banner.
 */
export default function UpgradeBanner() {
	return (
		<div className="sb-upsell-bar">
			<div className="sb-upsell-bar__msg">
				<span className="sb-upsell-bar__title">
					{ '✨ ' }
					{ __(
						'Vous utilisez la version gratuite',
						'slashbooking'
					) }
				</span>
				<span className="sb-upsell-bar__sub">
					{ __(
						'Passez à Pro pour débloquer la synchro Google Agenda, les e-mails personnalisables et les rappels automatiques.',
						'slashbooking'
					) }
				</span>
			</div>
			<UpgradeButton variant="primary" />
		</div>
	);
}
