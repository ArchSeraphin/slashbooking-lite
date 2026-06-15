import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, saveSettings } from './api';

export default function EmailSettings() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ savedMsg, setSavedMsg ] = useState( '' );
	const [ notifEmail, setNotifEmail ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const [ logo, setLogo ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const s = await fetchSettings();
			setNotifEmail( s.notification_email ?? '' );
			setFallback( s.admin_email_fallback ?? '' );
			setLogo( s.company_logo ?? '' );
			setPhone( s.company_phone ?? '' );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load();
	}, [] );

	const save = async () => {
		setSaving( true );
		setSavedMsg( '' );
		setError( null );
		try {
			await saveSettings( {
				notificationEmail: notifEmail,
				companyLogo: logo,
				companyPhone: phone,
			} );
			setSavedMsg( __( 'Settings saved.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const effectiveTarget = notifEmail.trim() !== '' ? notifEmail : fallback;

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Email settings', 'slashbooking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! loading && (
					<>
						<TextControl
							label={ __(
								'Notification recipient address',
								'slashbooking'
							) }
							help={
								notifEmail.trim() === ''
									? __(
											"If left empty, notifications go to the WP admin email (",
											'slashbooking'
									  ) +
									  fallback +
									  ').'
									: __(
											"New-request notifications will go here, regardless of the WP admin email.",
											'slashbooking'
									  )
							}
							type="email"
							value={ notifEmail }
							onChange={ setNotifEmail }
							placeholder={ fallback }
							__nextHasNoMarginBottom
						/>

						<div style={ { height: 12 } } />

						<TextControl
							label={ __(
								'Company logo URL (used by {company_logo})',
								'slashbooking'
							) }
							type="url"
							value={ logo }
							onChange={ setLogo }
							placeholder={ __(
								'Public URL of your logo (PNG or JPG)',
								'slashbooking'
							) }
							__nextHasNoMarginBottom
						/>

						<div style={ { height: 12 } } />

						<TextControl
							label={ __(
								'Company phone (used by {company_phone})',
								'slashbooking'
							) }
							value={ phone }
							onChange={ setPhone }
							placeholder="+33 1 23 45 67 89"
							__nextHasNoMarginBottom
						/>

						<div
							style={ {
								marginTop: 16,
								display: 'flex',
								gap: 8,
								alignItems: 'center',
							} }
						>
							<Button
								variant="primary"
								onClick={ save }
								disabled={ saving }
							>
								{ __( 'Save', 'slashbooking' ) }
							</Button>
							{ savedMsg && (
								<span
									style={ { color: '#15803d', fontSize: 13 } }
								>
									{ savedMsg }
								</span>
							) }
						</div>

						<p
							style={ {
								marginTop: 16,
								fontSize: 12,
								color: '#6b7280',
							} }
						>
							{ __(
								'“New request” notifications will go to: ',
								'slashbooking'
							) }
							<strong>
								{ effectiveTarget ||
									__(
										'(no address set)',
										'slashbooking'
									) }
							</strong>
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}
