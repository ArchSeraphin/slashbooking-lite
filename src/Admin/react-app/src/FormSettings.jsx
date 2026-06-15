import { useEffect, useId, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, saveSettings } from './api';

export default function FormSettings() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ savedMsg, setSavedMsg ] = useState( '' );
	const [ disclaimer, setDisclaimer ] = useState( '' );
	const [ primaryColor, setPrimaryColor ] = useState( '' );
	const [ accentColor, setAccentColor ] = useState( '' );

	const DEFAULT_PRIMARY = '#2563eb';
	const DEFAULT_ACCENT = '#10b981';

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const s = await fetchSettings();
			setDisclaimer( s.form_disclaimer ?? '' );
			setPrimaryColor( s.form_primary_color ?? '' );
			setAccentColor( s.form_accent_color ?? '' );
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
			const payload = {
				formDisclaimer: disclaimer,
				formPrimaryColor: primaryColor,
				formAccentColor: accentColor,
			};
			await saveSettings( payload );
			setSavedMsg( __( 'Paramètres enregistrés.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};


	return (
		<Card>
			<CardHeader>
				<h2>
					{ __( 'Paramètres du formulaire public', 'slashbooking' ) }
				</h2>
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
						<TextareaControl
							label={ __(
								'Mention en bas du formulaire',
								'slashbooking'
							) }
							help={ __(
								'Affichée juste au-dessus du bouton « Confirmer la demande ». Laissez vide pour ne rien afficher.',
								'slashbooking'
							) }
							value={ disclaimer }
							onChange={ setDisclaimer }
							rows={ 3 }
							placeholder={ __(
								"Ex : Notre équipe devra approuver la date et l'heure proposées afin de confirmer votre rendez-vous.",
								'slashbooking'
							) }
							__nextHasNoMarginBottom
						/>

						<hr
							style={ {
								margin: '24px 0',
								border: 'none',
								borderTop: '1px solid #e5e7eb',
							} }
						/>

						<h3
							style={ {
								margin: '0 0 4px',
								fontSize: 14,
								fontWeight: 600,
							} }
						>
							{ __(
								"Couleurs d'accent du formulaire",
								'slashbooking'
							) }
						</h3>
						<p
							style={ {
								margin: '0 0 16px',
								fontSize: 13,
								color: '#6b7280',
							} }
						>
							{ __(
								'Personnalisez les couleurs du bouton de confirmation, des créneaux sélectionnés et des accents de calendrier. Laissez vide pour utiliser les couleurs par défaut SlashBooking.',
								'slashbooking'
							) }
						</p>

						<ColorRow
							label={ __(
								'Couleur principale (boutons, sélection)',
								'slashbooking'
							) }
							value={ primaryColor }
							onChange={ setPrimaryColor }
							placeholder={ DEFAULT_PRIMARY }
						/>

						<div style={ { height: 12 } } />

						<ColorRow
							label={ __(
								"Couleur d'accent (états disponibles, indicateurs)",
								'slashbooking'
							) }
							value={ accentColor }
							onChange={ setAccentColor }
							placeholder={ DEFAULT_ACCENT }
						/>


						<div
							style={ {
								marginTop: 20,
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
								{ __( 'Enregistrer', 'slashbooking' ) }
							</Button>
							{ savedMsg && (
								<span
									style={ { color: '#15803d', fontSize: 13 } }
								>
									{ savedMsg }
								</span>
							) }
						</div>

					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * A compact color input pair: native <input type="color"> swatch + hex text
 * input + reset button. Two-way bound — typing in either control updates
 * both. Empty value = restore plugin default.
 * @param {Object}              root0             Props.
 * @param {string}              root0.label       Libellé affiché au-dessus du couple d'inputs.
 * @param {string}              root0.value       Hex courant ('' = défaut du plugin).
 * @param {(v: string) => void} root0.onChange    Reçoit le nouvel hex (ou '').
 * @param {string}              root0.placeholder Hex par défaut, affiché en placeholder.
 */
function ColorRow( { label, value, onChange, placeholder } ) {
	const id = useId();
	const safe = /^#[0-9a-fA-F]{6}$/.test( value ) ? value : placeholder;
	return (
		<div>
			<label
				htmlFor={ id }
				style={ {
					fontSize: 11,
					fontWeight: 500,
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: '#1e1e1e',
				} }
			>
				{ label }
			</label>
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: 10,
					marginTop: 4,
				} }
			>
				<input
					id={ id }
					type="color"
					value={ safe }
					onChange={ ( e ) => onChange( e.target.value ) }
					style={ {
						width: 42,
						height: 32,
						padding: 2,
						border: '1px solid #c3c4c7',
						borderRadius: 4,
						background: '#fff',
						cursor: 'pointer',
					} }
				/>
				<input
					type="text"
					value={ value }
					onChange={ ( e ) => onChange( e.target.value ) }
					placeholder={ placeholder }
					style={ {
						width: 110,
						padding: '6px 10px',
						border: '1px solid #c3c4c7',
						borderRadius: 4,
						fontFamily:
							'ui-monospace, SFMono-Regular, Menlo, monospace',
						fontSize: 13,
					} }
				/>
				{ value !== '' && (
					<button
						type="button"
						onClick={ () => onChange( '' ) }
						style={ {
							background: 'none',
							border: 'none',
							color: '#6b7280',
							fontSize: 12,
							cursor: 'pointer',
							textDecoration: 'underline',
						} }
					>
						{ __( 'Réinitialiser', 'slashbooking' ) }
					</button>
				) }
			</div>
		</div>
	);
}
