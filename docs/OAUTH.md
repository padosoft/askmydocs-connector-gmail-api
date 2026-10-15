# Google OAuth

The package requests only `https://www.googleapis.com/auth/gmail.readonly` and `openid`. Readonly supplies full messages/attachments without mark-read, modify or send. OpenID supplies a stable mailbox `sub`: the ID token is verified against Google JWKS with RS256 signature, issuer, audience, expiration and flow nonce. Email address from Gmail profile is presentation only.

Use a dedicated Google OAuth web client. Enable Gmail API; configure consent audience/testing users and exact redirect URI. Authorization code uses a tenant/installation-bound, expiring, single-use state, nonce and PKCE S256. Offline access is requested. Missing refresh token prompts reconnection with consent; subsequent refreshes preserve the previous refresh token and every extra key. A changed subject clears the old cursor and uses another storage scope. Concurrent refresh is serialized per installation.

Google web redirect restrictions may prevent registering `https://askmydocsdev.test/...`; confirm the console accepts the exact URI. If it does not, use an approved HTTPS callback domain/proxy routed to the existing host callback. Do not change Herd APP_URL or start artisan serve. No real credentials are needed for fixture tests.

A restricted Gmail scope can require Google verification and additional security assessment depending on deployment, audience and use of user data. Consent/testing status and organizational policies can prevent access independently of package correctness. Disconnect intentionally deletes local credentials/state only; remote grant revocation can also remove scopes used by other products under the same client.

Official references: [Google OAuth web-server flow](https://developers.google.com/identity/protocols/oauth2/web-server), [OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes).
