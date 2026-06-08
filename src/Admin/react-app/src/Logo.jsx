/**
 * SlashBooking logo — the calendar mark used across the site, favicon and
 * emails: a white calendar plaque with an emerald frame, a highlighted day,
 * and a confirmation check badge.
 *
 * Source of truth: slashbooking-site/assets/logo/slashbooking-icon.svg —
 * keep this JSX in sync if the site mark changes.
 * @param {Object} root0      Props.
 * @param {number} root0.size Largeur/hauteur rendue en px.
 */
export default function Logo( { size = 40 } ) {
	return (
		<svg
			viewBox="0 0 64 64"
			width={ size }
			height={ size }
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			role="img"
		>
			{ /* Calendar binding rings */ }
			<rect x="17" y="6" width="5" height="13" rx="2.5" fill="#059669" />
			<rect x="38" y="6" width="5" height="13" rx="2.5" fill="#059669" />
			{ /* Plaque + header rule */ }
			<rect
				x="6"
				y="12"
				width="46"
				height="46"
				rx="10"
				fill="#ffffff"
				stroke="#059669"
				strokeWidth="4"
			/>
			<path d="M6 26 H52" stroke="#059669" strokeWidth="4" />
			{ /* Day grid */ }
			<g fill="#059669" opacity="0.4">
				<circle cx="18" cy="33" r="3" />
				<circle cx="29" cy="33" r="3" />
				<circle cx="40" cy="33" r="3" />
				<circle cx="18" cy="42" r="3" />
				<circle cx="18" cy="51" r="3" />
				<circle cx="29" cy="51" r="3" />
			</g>
			{ /* Highlighted day */ }
			<circle cx="29" cy="42" r="3.6" fill="#f59e0b" />
			{ /* Confirmation check badge */ }
			<circle
				cx="52"
				cy="52"
				r="10"
				fill="#059669"
				stroke="#ffffff"
				strokeWidth="3"
			/>
			<path
				d="M47 52.3 L50.6 56 L57.8 48.3"
				fill="none"
				stroke="#ffffff"
				strokeWidth="3.3"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}
