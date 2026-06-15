import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listServices } from './api';

export default function ShortcodeMemo() {
	const [ services, setServices ] = useState( null );
	const [ copied, setCopied ] = useState( '' );

	useEffect( () => {
		listServices()
			.then( ( res ) =>
				setServices( Array.isArray( res ) ? res : res?.services ?? [] )
			)
			.catch( () => setServices( [] ) );
	}, [] );

	const copy = async ( code ) => {
		try {
			await window.navigator.clipboard.writeText( code );
			setCopied( code );
			setTimeout( () => setCopied( '' ), 1500 );
		} catch ( e ) {
			// Clipboard access denied — silent fail, user can copy manually.
		}
	};

	const active = ( services ?? [] ).filter( ( s ) => s.active !== false );

	const lines = [];
	if ( active.length > 1 ) {
		lines.push( {
			code: '[slashbooking]',
			label: __(
				'Project selector (all active services)',
				'slashbooking'
			),
		} );
	}
	active.forEach( ( s ) => {
		lines.push( {
			code: `[slashbooking service="${ s.slug }"]`,
			label:
				s.name +
				( s.duration_minutes ? ` · ${ s.duration_minutes } min` : '' ),
		} );
	} );

	return (
		<Card>
			<CardHeader>
				<h2>
					{ __(
						'Shortcodes — paste into your WordPress pages',
						'slashbooking'
					) }
				</h2>
			</CardHeader>
			<CardBody>
				{ services === null && <Spinner /> }

				{ services !== null && lines.length === 0 && (
					<p>
						{ __(
							"No active service. Add a service in the Services tab to generate a shortcode.",
							'slashbooking'
						) }
					</p>
				) }

				{ services !== null && lines.length > 0 && (
					<table className="sb-shortcode-memo">
						<tbody>
							{ lines.map( ( { code, label } ) => (
								<tr key={ code }>
									<td className="sb-shortcode-memo__code">
										<code>{ code }</code>
									</td>
									<td className="sb-shortcode-memo__label">
										{ label }
									</td>
									<td className="sb-shortcode-memo__action">
										<Button
											variant="tertiary"
											onClick={ () => copy( code ) }
										>
											{ copied === code
												? __(
														'✓ Copied',
														'slashbooking'
												  )
												: __(
														'Copy',
														'slashbooking'
												  ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}
