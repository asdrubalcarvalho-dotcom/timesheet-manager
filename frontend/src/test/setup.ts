import '@testing-library/jest-dom/vitest';
import { beforeAll } from 'vitest';
import i18n from '../i18n';

beforeAll(async () => {
	await i18n.changeLanguage('en-US');
});
