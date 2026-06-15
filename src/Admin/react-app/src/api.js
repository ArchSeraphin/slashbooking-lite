import apiFetch from '@wordpress/api-fetch';

const NAMESPACE = 'slashbooking/v1/';

export function setupApi() {
	if ( window.SlashBooking?.nonce ) {
		apiFetch.use(
			apiFetch.createNonceMiddleware( window.SlashBooking.nonce )
		);
	}
	// Prefix every path with our REST namespace so WordPress's default
	// rootURL middleware builds wp-json/slashbooking/v1/<path> correctly.
	apiFetch.use( ( options, next ) => {
		if ( typeof options.path === 'string' ) {
			const clean = options.path.replace( /^\//, '' );
			if ( ! clean.startsWith( NAMESPACE ) ) {
				return next( { ...options, path: NAMESPACE + clean } );
			}
		}
		return next( options );
	} );
}

export async function listBookings( params = {} ) {
	const qs = new URLSearchParams( params ).toString();
	return apiFetch( { path: 'admin/bookings' + ( qs ? '?' + qs : '' ) } );
}

export async function actBooking( id, action ) {
	return apiFetch( {
		path: `admin/bookings/${ id }/${ action }`,
		method: 'POST',
	} );
}

// --- Settings (form, email notifications, retention) ---

export async function fetchSettings() {
	return apiFetch( { path: 'admin/settings' } );
}

export async function saveSettings( {
	legalPageId,
	bookingRetentionDays,
	notificationEmail,
	companyLogo,
	companyPhone,
	formDisclaimer,
	formPrimaryColor,
	formAccentColor,
} = {} ) {
	const data = {};
	if ( legalPageId !== undefined ) {
		data.legal_page_id = legalPageId;
	}
	if ( bookingRetentionDays !== undefined ) {
		data.booking_retention_days = bookingRetentionDays;
	}
	if ( notificationEmail !== undefined ) {
		data.notification_email = notificationEmail;
	}
	if ( companyLogo !== undefined ) {
		data.company_logo = companyLogo;
	}
	if ( companyPhone !== undefined ) {
		data.company_phone = companyPhone;
	}
	if ( formDisclaimer !== undefined ) {
		data.form_disclaimer = formDisclaimer;
	}
	if ( formPrimaryColor !== undefined ) {
		data.form_primary_color = formPrimaryColor;
	}
	if ( formAccentColor !== undefined ) {
		data.form_accent_color = formAccentColor;
	}
	return apiFetch( {
		path: 'admin/settings',
		method: 'POST',
		data,
	} );
}

// --- Services CRUD ---

export async function listServices() {
	return apiFetch( { path: 'admin/services' } );
}

export async function fetchService( slug ) {
	return apiFetch( { path: `admin/services/${ slug }` } );
}

export async function saveService( slug, data ) {
	return apiFetch( {
		path: `admin/services/${ slug }`,
		method: 'POST',
		data,
	} );
}

export async function createService( { name, slug } ) {
	return apiFetch( {
		path: 'admin/services',
		method: 'POST',
		data: { name, slug },
	} );
}

export async function deleteService( slug ) {
	return apiFetch( {
		path: `admin/services/${ slug }`,
		method: 'DELETE',
	} );
}
