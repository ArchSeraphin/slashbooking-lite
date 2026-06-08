import LicenseSettings from './LicenseSettings';
import EmailSettings from './EmailSettings';
import FormSettings from './FormSettings';

export default function SettingsPage() {
	return (
		<div className="sb-settings-page">
			<LicenseSettings />
			<EmailSettings />
			<FormSettings />
		</div>
	);
}
