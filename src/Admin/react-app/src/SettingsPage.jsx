import EmailSettings from './EmailSettings';
import FormSettings from './FormSettings';
import ProUpsell from './ProUpsell';

export default function SettingsPage() {
	return (
		<div className="sb-settings-page">
			<EmailSettings />
			<FormSettings />
			<ProUpsell />
		</div>
	);
}
