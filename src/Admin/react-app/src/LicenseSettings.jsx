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
import { fetchLicenseStatus, saveLicense } from './api';
import UpgradeButton from './UpgradeButton';

export default function LicenseSettings() {
	const [ settings, setSettings ] = useState( null );
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ licenseMsg, setLicenseMsg ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			setSettings( await fetchLicenseStatus() );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load();
	}, [] );

	const onSaveLicense = async () => {
		setLicenseMsg( '' );
		try {
			const res = await saveLicense( licenseKey );
			setLicenseKey( '' );
			setSettings( res );
			setLicenseMsg(
				res.license_status === 'valid'
					? __( 'Licence valide ✓', 'slashbooking' )
					: __( 'Licence invalide ou expirée.', 'slashbooking' )
			);
		} catch ( e ) {
			setLicenseMsg(
				__( 'Erreur : ', 'slashbooking' ) + ( e.message ?? String( e ) )
			);
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Licence SlashBooking', 'slashbooking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ settings && (
					<>
						<p style={ { marginTop: 0, color: '#475569' } }>
							{ __(
								'Votre clé de licence débloque la version Pro : connexion Google Calendar en 1 clic (sans projet Google Cloud), e-mails personnalisables et rappels automatiques.',
								'slashbooking'
							) }
						</p>
						<p>
							<strong>
								{ __( 'Statut : ', 'slashbooking' ) }
							</strong>
							{ settings.license_status === 'valid' &&
								__( 'Licence valide ✓', 'slashbooking' ) }
							{ settings.license_status === 'invalid' &&
								__(
									'Licence invalide ou expirée',
									'slashbooking'
								) }
							{ settings.license_status === 'absent' &&
								__( 'Aucune licence', 'slashbooking' ) }
							{ settings.license_status === 'unknown' &&
								__( 'Licence non vérifiée', 'slashbooking' ) }
							{ settings.plan && ` — ${ settings.plan }` }
						</p>
						{ settings.license_status !== 'valid' && (
							<div
								style={ {
									margin: '0 0 18px',
									padding: '14px 16px',
									background: '#ecfdf5',
									border: '1px solid #a7f3d0',
									borderRadius: '10px',
								} }
							>
								<p
									style={ {
										margin: '0 0 10px',
										color: '#065f46',
									} }
								>
									{ __(
										'Débloquez la synchronisation Google Agenda, les e-mails personnalisables et les rappels automatiques.',
										'slashbooking'
									) }
								</p>
								<UpgradeButton variant="primary" />
							</div>
						) }
						<TextControl
							label={
								settings.has_license
									? __(
											'Clé de licence (saisir pour remplacer)',
											'slashbooking'
									  )
									: __( 'Clé de licence', 'slashbooking' )
							}
							value={ licenseKey }
							onChange={ setLicenseKey }
						/>
						<Button
							variant="primary"
							onClick={ onSaveLicense }
							disabled={ ! licenseKey }
						>
							{ __( 'Enregistrer la licence', 'slashbooking' ) }
						</Button>
						{ licenseMsg && (
							<Notice
								status={
									licenseMsg.startsWith( 'Erreur' ) ||
									settings.license_status === 'invalid'
										? 'error'
										: 'success'
								}
								isDismissible={ false }
								style={ { marginTop: '12px' } }
							>
								{ licenseMsg }
							</Notice>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}
