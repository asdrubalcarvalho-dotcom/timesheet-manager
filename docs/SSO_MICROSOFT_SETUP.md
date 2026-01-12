# Microsoft SSO (Azure / Entra ID) Setup

## Azure App Registration

- **Supported account types**: Accounts in any organizational directory **and** personal Microsoft accounts
- **Redirect URI (Web)**:
  - Local (Docker): `http://api.localhost/auth/microsoft/callback`
  - Production: `https://<backend-host>/auth/microsoft/callback`
- **Tenant**: Use `common` when you want to allow both org and personal accounts.

## Backend environment variables

Set these in the backend environment (do not commit real secrets):

- `MICROSOFT_CLIENT_ID=`
- `MICROSOFT_CLIENT_SECRET=`
- `MICROSOFT_TENANT=common`
- `MICROSOFT_REDIRECT_URI=http://api.localhost/auth/microsoft/callback`
