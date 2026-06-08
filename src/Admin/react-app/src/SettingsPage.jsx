import EmailSettings from './EmailSettings';
import FormSettings from './FormSettings';

export default function SettingsPage() {
	return (
		<div className="sb-settings-page">
			<EmailSettings />
			<FormSettings />
		</div>
	);
}
