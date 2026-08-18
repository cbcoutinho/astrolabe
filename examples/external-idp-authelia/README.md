# Astrolabe behind an external identity provider — working example

A complete, runnable stack where **Nextcloud is an OIDC _client_ of a separate
identity provider** (Authelia here), rather than being the identity provider
itself. This is the deployment shape from
[astrolabe#324](https://github.com/cbcoutinho/astrolabe/issues/324), and the one
that is easy to get subtly wrong.

Everything is pre-configured. Two setup steps, both one-liners.

See [`../external-idp-authentik`](../external-idp-authentik) for the same stack
with authentik. The Nextcloud and MCP-server configuration is **identical**
between the two — only the provider differs.

```
browser ──► Nextcloud ──► Authelia ──► back to Nextcloud
                │                          (session established)
                ▼
            Astrolabe ──asks user_oidc for the user's token──► MCP server
                                                                   │
                                                    verifies it against Authelia's
                                                    JWKS, then searches Qdrant
```

Astrolabe does **not** mint its own tokens here and is **not** an OAuth client:
no secret, no redirect URI. It asks `user_oidc` for the token the identity
provider already issued to the signed-in user. That path needs Astrolabe
**≥ 0.42.2** and MCP server **≥ 0.176.5**.

---

## Run it

**1. Generate the key material** (once):

```bash
./generate-secrets.sh
```

Needs **OpenSSL 3.x** — macOS ships LibreSSL as `/usr/bin/openssl`, which lacks
`req -section`; the script checks and tells you. `brew install openssl` and put
it first on `PATH`.

Nothing cryptographic is committed to this repository — a private key in a
public repo is not a secret, and shipping one would teach the wrong habit. The
script writes three throwaway, local-only things, all git-ignored:

| File | What it is |
|---|---|
| `authelia/oidc.issuer.key` | The RSA key Authelia signs OIDC tokens with, and whose public half it publishes at `/jwks.json`. **Must** be asymmetric — see [the empty-JWKS trap](#an-empty-jwks-means-a-symmetric-signing-key). |
| `certs/ca.pem` + `ca.key` | A local certificate authority. |
| `certs/authelia.crt` + `.key` | The TLS certificate it signs for `auth.astrolabe.test`, because Authelia [refuses to serve OIDC over http](#why-authelia-is-https-when-nothing-else-is). |
| `.env` | Random secrets compose passes in: Authelia's session / storage / HMAC keys, and the MCP server's `TOKEN_ENCRYPTION_KEY`, which encrypts stored credentials. |

Re-run with `--force` to replace them. Everything else in this example — the
OAuth client secret, the demo password — is deliberately committed in plaintext,
because those are configuration you need to *read* to understand the setup. The
dividing line is simple: anything that signs or encrypts is generated; anything
you would otherwise have to guess at is committed.

**2. Add this to `/etc/hosts`** (Windows:
`C:\Windows\System32\drivers\etc\hosts`), also once:

```
127.0.0.1  nc.astrolabe.test auth.astrolabe.test
```

**3.**

```bash
docker compose up -d
```

First start pulls images and installs apps — give it a few minutes. Then open
<http://nc.astrolabe.test:8080>, click **Log in with Authelia**, and sign in as:

```
alice / password
```

Your browser will warn once about Authelia's self-signed certificate — accept it
(see [TLS](#why-authelia-is-https-when-nothing-else-is) below).

Then open **Astrolabe** in the app bar and search. To get actual results rather
than an empty list, first enable indexing: **Settings → Astrolabe → Enable
background indexing**, add a note or two in the Notes app, and wait for the
indexer (it polls every 10s in this example).

To tear down completely: `docker compose down -v`.

---

## Every setting, and why it is there

The complaint that prompted this example was that the required settings are
undocumented and that it is unclear which are actually needed. So: this is the
complete list. Anything not mentioned here is left at its default.

### On the MCP server

| Setting | Why |
|---|---|
| `NEXTCLOUD_HOST` | Where Nextcloud is, from inside the network. |
| `NEXTCLOUD_PUBLIC_URL` | Where the **browser** reaches Nextcloud. Used by the Login Flow v2 leg that provisions background indexing. |
| `MCP_DEPLOYMENT_MODE=login_flow` | Selects the auth flow (ADR-022). |
| `OIDC_DISCOVERY_URL` | The identity provider. Issuer, JWKS, userinfo and introspection endpoints are all read from this one document — you do **not** configure them separately. |
| `NEXTCLOUD_OIDC_CLIENT_ID` / `_SECRET` | The client credentials, used for introspection. |
| `ALLOWED_MGMT_CLIENT` | **The one that bites.** See below. |
| `NEXTCLOUD_MCP_SERVER_URL` | The MCP server's own browser-facing URL, used by the same Login Flow v2 leg. |
| `ENABLE_SEMANTIC_SEARCH`, `QDRANT_URL`, `TOKEN_ENCRYPTION_KEY`, `TOKEN_STORAGE_DB` | Semantic search + token storage. Not auth-related. |
| `VECTOR_SYNC_SCAN_INTERVAL`, `VECTOR_SYNC_USER_POLL_INTERVAL` | Both lowered to 10s so a demo indexes promptly. Nothing to do with auth; raise them for anything real. |

Deliberately **not** set, because discovery supplies them:
`OIDC_ISSUER`, `OIDC_JWKS_URI`, `NEXTCLOUD_PUBLIC_ISSUER_URL`,
`NEXTCLOUD_RESOURCE_URI`. This example works without any of them.

#### `ALLOWED_MGMT_CLIENT` is mandatory in practice

Astrolabe calls the MCP server's management API presenting the user's IdP
token. That API only accepts tokens whose issuing client is on this allowlist,
and it **fails closed**: leave it unset and every call is rejected with a bare
`401`, with the reason only in the server log.

Set it to the IdP client id that obtained the token — `nextcloud` here, i.e.
the same client `user_oidc` logs people in with.

> Needs MCP server **≥ 0.176.3**. Before that the allowlist only read the
> `client_id` claim, while Authelia, Authentik and Keycloak all stamp `azp`
> instead — so every external-IdP token was refused no matter how you
> configured it ([mcp#1325](https://github.com/cbcoutinho/nextcloud-mcp-server/pull/1325)).

### On Nextcloud

| Setting | Why |
|---|---|
| `overwrite.cli.url` | Nextcloud builds the `user_oidc` redirect URI from this. It must be the URL your **browser** uses, or the IdP will reject the callback. |
| `user_oidc` `store_login_token=1` | **Required.** Without it `user_oidc` discards the IdP's token at login, and Astrolabe has nothing to hand to the MCP server. |
| `user_oidc` `oidc_provider_bearer_validation=true` | The MCP server calls back into Nextcloud with the user's token; Nextcloud must accept bearer tokens. |
| `user_oidc` provider `--mapping-uid=sub --unique-uid=0` | See [the `sub` trap](#the-sub-trap) below. |
| `astrolabe_client_id` | The IdP client the MCP server accepts tokens for. **Not** a client registered inside Nextcloud — that is what it means only in the `oidc`-app deployment. |
| `httpclient.allowselfsigned`, `user_oidc allow_insecure_http` | Only because this example uses a self-signed cert and plain http for Nextcloud. Drop both in any real deployment. |

### On the identity provider

One client. Not one per component.

| Setting | Why |
|---|---|
| redirect URI `…/apps/user_oidc/code` | `user_oidc`'s callback. |
| scopes `openid profile email groups` | `groups` is required: `user_oidc` requests a groups claim, and Authelia rejects the whole authorization request if the claim is requested without the scope. |
| `access_token_signed_response_alg: RS256` | Issues JWT access tokens, so the MCP server can verify them against the JWKS locally. |
| an **asymmetric** signing key | Non-negotiable. See below. |

---

## The traps

Each of these cost real debugging time. They apply to Authentik and Keycloak
just as much as Authelia.

### An empty JWKS means a symmetric signing key

If your provider signs tokens with a symmetric key (HS256), its JWKS endpoint is
served but **empty**, and the MCP server cannot verify anything:

```
Token rejected (jwt/no_signing_keys, our fault) ... The JWK Set did not contain any keys
```

Fix it at the provider: select an RS256 certificate as the signing key. Check
with `curl <jwks_uri>` — if you see `{"keys":[]}`, that is the whole problem.

> On MCP server < 0.176.5 this was reported as `jwt/unknown`, which told you
> nothing ([mcp#1331](https://github.com/cbcoutinho/nextcloud-mcp-server/pull/1331)).

### The `sub` trap

The MCP server identifies the caller by the token's `sub` claim and uses it
directly as the Nextcloud user id. Every IdP here issues an opaque `sub` (a
UUID), so unless the Nextcloud account id equals it, **search returns HTTP 200
with zero results** — your documents are indexed under the Nextcloud id while
the query filters on `sub`. No error, just nothing.

This example sidesteps it with `--mapping-uid=sub --unique-uid=0`, which is safe
on a **fresh** install.

> **Do not** apply that to an existing Nextcloud: changing the uid mapping
> rewrites account ids and orphans files, shares and indexed data. Tracked as
> [mcp#1326](https://github.com/cbcoutinho/nextcloud-mcp-server/issues/1326) —
> the fix belongs in the server, not in your config.

### The browser and the servers must agree on the IdP's URL

The OIDC issuer has to be byte-identical everywhere. That is why this example
uses a hosts entry and publishes Authelia on the **same port it listens on**:
`https://auth.astrolabe.test:9091` means the same thing in your browser and
inside the container network.

`*.localhost` would avoid the hosts edit — browsers resolve it natively — but
**libcurl short-circuits any `*.localhost` name to 127.0.0.1** (RFC 6761), so
Nextcloud's PHP client can never reach an IdP container by such a name. If you
would rather not edit `/etc/hosts`, `*.localtest.me` resolves publicly to
127.0.0.1 and works, at the cost of a DNS dependency.

### Why Authelia is https when nothing else is

Authelia derives the OIDC issuer from the request scheme and **refuses anything
but https**, with no override. So this example uses a local self-signed CA,
generated by `./generate-secrets.sh`.

(`certs/openssl.cnf` defines the names on that certificate;
`./generate-secrets.sh` builds it.)

That is also why the MCP service wraps its entrypoint: the CA has to be
**appended** to the system trust store, not substituted for it. Pointing
`SSL_CERT_FILE` straight at `ca.pem` breaks every *public* TLS call — including
the one-off model download the search backend does on its first query, which
surfaces as a confusing `500` long after authentication has succeeded.

With a publicly-trusted certificate, delete the wrapper, the two CA variables
and the two Nextcloud `allowselfsigned`/`allow_insecure_http` settings.

### Match your Qdrant to the server

An old Qdrant answers `400 Format error in JSON body: data did not match any
variant of untagged enum QueryInterface`. This example pins `v1.19.0`, the
version the MCP server itself is tested against. Qdrant will also refuse to
start on storage written by a newer version — `docker compose down -v` if you
change it.

---

## Adapting this to Authentik or Keycloak

The Nextcloud and MCP configuration is unchanged. Only the provider differs:

- **Client**: one confidential client, redirect URI `…/apps/user_oidc/code`,
  scopes `openid profile email groups`.
- **Signing key**: must be asymmetric (RS256). In Authentik this is the
  provider's *Signing Key* field — leaving it symmetric is the single most
  common cause of the empty-JWKS failure above.
- **`ALLOWED_MGMT_CLIENT`**: set to that client's id. Authentik generates a
  random one like `E2x6qDM9bEkQuBPX0v6umpupmNYEG0YNH3JfdUKo`.
- **`OIDC_DISCOVERY_URL`**: the provider's discovery document.

---

## Verified

This stack was run end to end: browser login through Authelia, then a search
from the Astrolabe UI returning `HTTP 200 {"success":true,…}`, with the MCP
server resolving the caller to the same id as the Nextcloud account.

Results are empty until you enable background indexing and add content — that
is the expected state on a fresh stack, not a failure.
