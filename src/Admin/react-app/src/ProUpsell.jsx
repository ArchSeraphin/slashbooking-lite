import { Button, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const PRO_URL = 'https://slashbooking.fr/#tarifs';

/**
 * Tasteful, dismiss-free promotion of the paid edition. A single, clearly
 * labelled section — never a locked feature, never a nag on every screen
 * (WordPress.org plugin guideline 11).
 */
export default function ProUpsell() {
	return (
		<Card
			className="sb-pro-card"
			style={ { borderLeft: '4px solid #f97316' } }
		>
			<CardBody>
				<h2 style={ { marginTop: 0 } }>
					{ '✨ ' }
					{ __( 'Passer à SlashBooking Pro', 'slashbooking' ) }
				</h2>
				<p style={ { marginTop: 0, color: '#475569' } }>
					{ __(
						'La version Pro débloque les fonctionnalités avancées :',
						'slashbooking'
					) }
				</p>
				<ul
					style={ {
						margin: '0 0 16px',
						paddingLeft: '20px',
						lineHeight: 1.8,
					} }
				>
					<li>
						{ __(
							'Synchronisation Google Agenda bidirectionnelle',
							'slashbooking'
						) }
					</li>
					<li>
						{ __(
							'E-mails entièrement personnalisables',
							'slashbooking'
						) }
					</li>
					<li>
						{ __( 'Rappels automatiques J-1', 'slashbooking' ) }
					</li>
				</ul>
				<Button
					variant="primary"
					href={ PRO_URL }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Découvrir la version Pro', 'slashbooking' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
