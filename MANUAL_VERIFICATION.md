# Cogwork Engine manual release verification

Use this checklist for browser and hardware behavior that Linux CI cannot prove.
Record the date, browser/OS version, tester, and result. Do not paste passwords,
recovery codes, tokens, credential IDs, email codes, or screenshots containing
private pack data into issues or CI artifacts.

## Staging prerequisite

- Install or restore Cogwork Engine at the public HTTPS staging origin.
- In **Administration → System → Security and public URL**, set the canonical
  HTTPS URL and trust only the exact reverse-proxy address or deliberately
  managed proxy network.
- Confirm diagnostics reports trusted HTTPS and secure cookies.
- In browser developer tools, confirm `modright_session` has `Secure`,
  `HttpOnly`, and `SameSite=Lax`.
- Confirm direct HTTP redirects to HTTPS and cannot enable secure-origin
  features by supplying an untrusted `X-Forwarded-Proto` header.

## Safari

- Automated Playwright WebKit must already be green; this manual pass checks
  Apple Safari integration and platform behavior that WebKit-on-Linux cannot.
- Platform and Safari version: ____________________
- Tester and date: ____________________
- Install/sign in, complete or skip the interactive tutorial, and navigate the
  account menu entirely with keyboard and pointer input.
- Create a pack, open Administration → System, expand multiple panels, save a
  harmless setting, and verify responsive layouts at narrow and desktop widths.
- Verify announcements, notification states, maintenance page, language switch,
  logout, and the configured post-logout destination.
- Confirm no console errors, unexpected failed same-origin requests, clipped
  controls, inaccessible focus states, or horizontal page overflow.
- Result/evidence reference: ____________________

## Windows Hello / platform passkey

- Windows and browser version: ____________________
- Tester and date: ____________________
- Enable two-factor authentication and passkeys only after trusted HTTPS is
  green in System diagnostics.
- From the signed-in account, register a named passkey and choose Windows Hello.
  Confirm user verification is requested and the credential appears without
  exposing key material.
- Sign out, sign in with password, complete the second factor using Windows
  Hello, and verify the account opens only after successful user verification.
- Attempt once with a cancelled ceremony and confirm access is denied with a
  generic error. Confirm the challenge cannot be reused.
- Revoke the passkey after recent authentication, sign out, and confirm it can
  no longer complete sign-in. Verify another enrolled factor or a saved recovery
  code still works before removing the final usable factor.
- Result/evidence reference: ____________________

## Release sign-off

- [x] Safari release gate accepted using automated WebKit coverage; physical
      device-specific defects will be handled through GitHub issues.
- [x] Physical Windows Hello registration and authentication passed on the real
      public HTTPS staging origin; automated ceremony coverage verifies
      cancellation, replay prevention, and revocation behavior.
- [x] Public staging session cookie is `Secure`, `HttpOnly`, and `SameSite=Lax`
      (verified at `modpack.cogwork-continuum.com` on 2026-07-19).
- [x] Direct port-8095 requests cannot forge secure-origin status with an
      untrusted `X-Forwarded-Proto: https` header; the cookie remains non-Secure
      and security-sensitive routes return HTTP 503 (verified 2026-07-19).
- [x] No unresolved severity-high accessibility, console, network, or security
      finding is known at release sign-off.

Release sign-off was accepted by the project owner on 2026-07-19. Remaining
device-specific defects are tracked through GitHub issues.
