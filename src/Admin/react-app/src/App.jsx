import { TabPanel } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import ServicesPage from './ServicesPage';
import GooglePage from './GooglePage';
import TemplatesPage from './TemplatesPage';
import SettingsPage from './SettingsPage';
import Logo from './Logo';

export default function App() {
	const readTab = () =>
		window.location.hash.replace( '#/', '' ) || 'bookings';
	const [ active, setActive ] = useState( readTab );
	useEffect( () => {
		const onHashChange = () => setActive( readTab() );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );
	const version =
		( window.SlashBooking && window.SlashBooking.version ) || '';

	return (
		<div className="sb-admin">
			<header className="sb-app-header">
				<div className="sb-app-header__brand">
					<div className="sb-app-header__logo">
						<Logo size={ 40 } />
					</div>
					<div>
						<h1 className="sb-app-header__title">SlashBooking</h1>
						<p className="sb-app-header__subtitle">
							{ __(
								'Réservations en ligne, synchronisées avec Google Calendar',
								'slashbooking'
							) }
						</p>
					</div>
				</div>
				{ version && (
					<span className="sb-app-header__version">v{ version }</span>
				) }
			</header>

			<TabPanel
				key={ active }
				className="sb-tabs"
				tabs={ [
					{
						name: 'bookings',
						title: __( 'Réservations', 'slashbooking' ),
					},
					{
						name: 'services',
						title: __( 'Services', 'slashbooking' ),
					},
					{ name: 'google', title: __( 'Google', 'slashbooking' ) },
					{
						name: 'templates',
						title: __( 'Templates', 'slashbooking' ),
					},
					{
						name: 'settings',
						title: __( 'Réglages', 'slashbooking' ),
					},
				] }
				initialTabName={ active }
				onSelect={ ( name ) => {
					window.history.replaceState( null, '', `#/${ name }` );
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'services' ) {
						return <ServicesPage />;
					}
					if ( tab.name === 'google' ) {
						return <GooglePage />;
					}
					if ( tab.name === 'templates' ) {
						return <TemplatesPage />;
					}
					if ( tab.name === 'settings' ) {
						return <SettingsPage />;
					}
					return <BookingsPage />;
				} }
			</TabPanel>
		</div>
	);
}
