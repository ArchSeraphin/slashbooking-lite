import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	TextControl,
	ToggleControl,
	Notice,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchService, saveService } from './api';

const DAYS = [
	{ key: '1', label: __( 'Monday', 'slashbooking' ) },
	{ key: '2', label: __( 'Tuesday', 'slashbooking' ) },
	{ key: '3', label: __( 'Wednesday', 'slashbooking' ) },
	{ key: '4', label: __( 'Thursday', 'slashbooking' ) },
	{ key: '5', label: __( 'Friday', 'slashbooking' ) },
	{ key: '6', label: __( 'Saturday', 'slashbooking' ) },
	{ key: '7', label: __( 'Sunday', 'slashbooking' ) },
];

export default function ServiceEditor( { slug, onClose } ) {
	const [ service, setService ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ isDirty, setIsDirty ] = useState( false );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await fetchService( slug );
			setService( data );
			setIsDirty( false );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load();
	}, [ slug ] );

	const patch = ( fields ) => {
		setService( ( s ) => ( { ...s, ...fields } ) );
		setIsDirty( true );
	};

	const patchDay = ( dayKey, ranges ) => {
		setService( ( s ) => ( {
			...s,
			weekly_hours: { ...s.weekly_hours, [ dayKey ]: ranges },
		} ) );
		setIsDirty( true );
	};

	const addRange = ( dayKey ) => {
		const existing = service.weekly_hours[ dayKey ] || [];
		patchDay( dayKey, [ ...existing, { open: '09:00', close: '18:00' } ] );
	};

	const removeRange = ( dayKey, idx ) => {
		const next = ( service.weekly_hours[ dayKey ] || [] ).filter(
			( _, i ) => i !== idx
		);
		patchDay( dayKey, next );
	};

	const setRange = ( dayKey, idx, field, value ) => {
		const next = ( service.weekly_hours[ dayKey ] || [] ).map( ( r, i ) =>
			i === idx ? { ...r, [ field ]: value } : r
		);
		patchDay( dayKey, next );
	};

	const toggleDay = ( dayKey, enabled ) => {
		if ( enabled ) {
			patchDay( dayKey, [ { open: '09:00', close: '18:00' } ] );
		} else {
			patchDay( dayKey, [] );
		}
	};

	const save = async () => {
		setSaving( true );
		setError( null );
		setMessage( null );
		try {
			const payload = {
				name: service.name,
				duration_min: service.duration_min,
				buffer_before_min: service.buffer_before_min,
				buffer_after_min: service.buffer_after_min,
				min_lead_time_hours: service.min_lead_time_hours,
				max_horizon_days: service.max_horizon_days,
				color: service.color,
				active: service.active,
				weekly_hours: service.weekly_hours,
			};
			const updated = await saveService( slug, payload );
			setService( updated );
			setIsDirty( false );
			setMessage( __( 'Service saved.', 'slashbooking' ) );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const close = () => {
		if ( isDirty ) {
			// eslint-disable-next-line no-alert -- confirmation volontaire (perte de modifs)
			const ok = window.confirm(
				__(
					'Unsaved changes, leave?',
					'slashbooking'
				)
			);
			if ( ! ok ) {
				return;
			}
		}
		onClose();
	};

	if ( loading || ! service ) {
		return (
			<Card>
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	return (
		<Card>
			<CardHeader>
				<Flex>
					<FlexItem>
						<h2
							style={ {
								margin: 0,
								fontSize: 16,
								fontWeight: 600,
							} }
						>
							{ __( 'Editing: ', 'slashbooking' ) }
							<code>{ slug }</code>
						</h2>
					</FlexItem>
					<FlexItem>
						<Button variant="tertiary" onClick={ close }>
							← { __( 'Back to services', 'slashbooking' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				{ message && (
					<Notice
						status="success"
						onRemove={ () => setMessage( null ) }
					>
						{ message }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				<div className="sb-service-form">
					{ /* Bloc identité */ }
					<section className="sb-form-section">
						<h3 className="sb-form-section__title">
							{ __( 'Service identity', 'slashbooking' ) }
						</h3>
						<div className="sb-form-grid">
							<TextControl
								label={ __( 'Display name', 'slashbooking' ) }
								value={ service.name }
								onChange={ ( v ) => patch( { name: v } ) }
							/>
							<TextControl
								label={ __( 'Color (hex)', 'slashbooking' ) }
								value={ service.color }
								onChange={ ( v ) => patch( { color: v } ) }
								type="text"
							/>
							<ToggleControl
								label={ __(
									'Active service (visible on the public form)',
									'slashbooking'
								) }
								checked={ !! service.active }
								onChange={ ( v ) => patch( { active: v } ) }
							/>
						</div>
					</section>

					{ /* Bloc durée + buffers */ }
					<section className="sb-form-section">
						<h3 className="sb-form-section__title">
							{ __( 'Duration & rules', 'slashbooking' ) }
						</h3>
						<div className="sb-form-grid">
							<TextControl
								label={ __(
									'Appointment duration (minutes)',
									'slashbooking'
								) }
								type="number"
								value={ service.duration_min }
								onChange={ ( v ) =>
									patch( {
										duration_min: parseInt( v, 10 ) || 0,
									} )
								}
								min={ 5 }
								max={ 600 }
							/>
							<TextControl
								label={ __(
									'Buffer before (minutes)',
									'slashbooking'
								) }
								type="number"
								value={ service.buffer_before_min }
								onChange={ ( v ) =>
									patch( {
										buffer_before_min:
											parseInt( v, 10 ) || 0,
									} )
								}
								min={ 0 }
								max={ 240 }
							/>
							<TextControl
								label={ __(
									'Buffer after / travel (minutes)',
									'slashbooking'
								) }
								type="number"
								value={ service.buffer_after_min }
								onChange={ ( v ) =>
									patch( {
										buffer_after_min:
											parseInt( v, 10 ) || 0,
									} )
								}
								min={ 0 }
								max={ 240 }
							/>
							<TextControl
								label={ __(
									'Minimum lead time before appointment (hours)',
									'slashbooking'
								) }
								type="number"
								value={ service.min_lead_time_hours }
								onChange={ ( v ) =>
									patch( {
										min_lead_time_hours:
											parseInt( v, 10 ) || 0,
									} )
								}
								min={ 0 }
								max={ 720 }
							/>
							<TextControl
								label={ __(
									'Booking horizon (days)',
									'slashbooking'
								) }
								type="number"
								value={ service.max_horizon_days }
								onChange={ ( v ) =>
									patch( {
										max_horizon_days:
											parseInt( v, 10 ) || 0,
									} )
								}
								min={ 1 }
								max={ 365 }
							/>
						</div>
					</section>

					{ /* Bloc jours / horaires */ }
					<section className="sb-form-section">
						<h3 className="sb-form-section__title">
							{ __(
								'Working days & hours',
								'slashbooking'
							) }
						</h3>
						<p className="sb-form-section__hint">
							{ __(
								'Check the working days and set the time ranges. You can add several ranges per day (morning / afternoon).',
								'slashbooking'
							) }
						</p>

						<div className="sb-week">
							{ DAYS.map( ( d ) => {
								const ranges =
									service.weekly_hours[ d.key ] || [];
								const open = ranges.length > 0;
								return (
									<div
										key={ d.key }
										className={ `sb-day ${
											open ? 'is-open' : 'is-closed'
										}` }
									>
										<div className="sb-day__head">
											<ToggleControl
												label={ d.label }
												checked={ open }
												onChange={ ( v ) =>
													toggleDay( d.key, v )
												}
											/>
										</div>
										{ open && (
											<div className="sb-day__ranges">
												{ ranges.map( ( r, i ) => (
													<div
														key={ i }
														className="sb-range"
													>
														<input
															type="time"
															className="sb-time-input"
															value={ r.open }
															onChange={ ( e ) =>
																setRange(
																	d.key,
																	i,
																	'open',
																	e.target
																		.value
																)
															}
														/>
														<span className="sb-range__sep">
															→
														</span>
														<input
															type="time"
															className="sb-time-input"
															value={ r.close }
															onChange={ ( e ) =>
																setRange(
																	d.key,
																	i,
																	'close',
																	e.target
																		.value
																)
															}
														/>
														<Button
															variant="tertiary"
															isDestructive
															size="small"
															onClick={ () =>
																removeRange(
																	d.key,
																	i
																)
															}
														>
															×
														</Button>
													</div>
												) ) }
												<Button
													variant="link"
													size="small"
													onClick={ () =>
														addRange( d.key )
													}
												>
													+{ ' ' }
													{ __(
														'Add a time range',
														'slashbooking'
													) }
												</Button>
											</div>
										) }
									</div>
								);
							} ) }
						</div>
					</section>

					<Flex
						gap={ 2 }
						style={ {
							marginTop: 20,
							paddingTop: 16,
							borderTop: '1px solid #e2e8f0',
						} }
					>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ saving }
								disabled={ ! isDirty || saving }
							>
								{ __( 'Save', 'slashbooking' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="tertiary" onClick={ close }>
								{ __( 'Cancel', 'slashbooking' ) }
							</Button>
						</FlexItem>
					</Flex>
				</div>
			</CardBody>
		</Card>
	);
}
