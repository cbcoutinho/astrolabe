# Astrolabe behind authentik — working example

A complete, runnable stack where **Nextcloud is an OIDC _client_ of authentik**
rather than being the identity provider itself — the deployment from
[astrolabe#324](https://github.com/cbcoutinho/astrolabe/issues/324).

Everything is declared in an authentik **blueprint**, so the stack comes up
fully configured: no clicking through the authentik admin UI. Two setup steps,
both one-liners.

See [`../external-idp-authelia`](../external-idp-authelia) for the same stack
with Authelia. The Nextcloud and MCP-server **auth** configuration is identical
between the two — only the provider differs. (The Authelia example additionally
has to trust a private CA, because Authelia will not serve OIDC over http; that
is TLS plumbing, not auth.)

```
browser ──► Nextcloud ──► authentik ──► back to Nextcloud
                │                          (session established)
                ▼
            Astrolabe ──asks user_oidc for the user's token──► MCP server
                                                                   │
                                                    verifies it against authentik's
                                                    JWKS, then searches Qdrant
```

Astrolabe does **not** mint its own tokens and is **not** an OAuth client: no
secret, no redirect URI. It asks `user_oidc` for the token authentik already
issued to the signed-in user. That path needs Astrolabe **≥ 0.42.2** and MCP
server **≥ 0.176.5**.

---

## Run it

**1. Generate the two process secrets** (once):

```bash
./generate-secrets.sh
```

Writes a git-ignored `.env` with `AUTHENTIK_SECRET_KEY` (signs authentik's
sessions) and `TOKEN_ENCRYPTION_KEY` (encrypts the credentials the MCP server
stores at rest). Both are real key material, so they are generated rather than
committed for you to copy into something real. Unlike the Authelia example
there are no certificates to make: authentik serves OIDC over plain http and
generates its own token-signing keypair internally.

**2. Add this to `/etc/hosts`** (Windows:
`C:\Windows\System32\drivers\etc\hosts`), also once:

```
127.0.0.1  nc.astrolabe.test auth.astrolabe.test
```

**3.**

```bash
docker compose up -d
```

authentik takes a couple of minutes to migrate its database and apply the
blueprint on first start. Nextcloud's provisioning hook polls for the discovery
document before registering the provider, so it waits that out rather than
failing — see [the blueprint race](#the-blueprint-is-applied-asynchronously). Then open <http://nc.astrolabe.test:8080>, click
**Log in with authentik**, and sign in as:

```
alice / password
```

Open **Astrolabe** in the app bar and search. For actual results rather than an
empty list, first enable indexing: **Settings → Astrolabe → Enable background
indexing**, add a note or two, and wait for the indexer.

authentik's own admin UI is at <http://auth.astrolabe.test:9000/if/admin/>
(`akadmin` / `akadmin-example-password`) if you want to look at the generated
provider.

Tear down with `docker compose down -v`.

---

## What the blueprint declares

`authentik/astrolabe-blueprint.yaml` creates an OAuth2 provider, an application,
and the demo user. Three lines in it matter more than the rest:

### `signing_key` — without it, nothing works

```yaml
signing_key: !Find [authentik_crypto.certificatekeypair, [name, authentik Self-signed Certificate]]
```

This is what makes authentik sign tokens with an **asymmetric** key (RS256) and
publish the public half in its JWKS. Leave it unset and the provider falls back
to symmetric (HS256) signing: the JWKS endpoint is served but **empty**, and the
MCP server cannot verify any token:

```
Token rejected (jwt/no_signing_keys, our fault) ... The JWK Set did not contain any keys
```

In the authentik **UI** this is the provider's *Signing Key* field. It is the
single most common cause of this failure, and it is easy to miss because
everything else looks correctly configured.

Check it with `curl http://auth.astrolabe.test:9000/application/o/astrolabe/jwks/` —
if you get `{"keys":[]}`, that is the whole problem.

> Reported as `jwt/unknown` on MCP server < 0.176.5, which told you nothing
> ([mcp#1331](https://github.com/cbcoutinho/nextcloud-mcp-server/pull/1331)).

### `sub_mode: user_username` — or you get zero results

```yaml
sub_mode: user_username
```

The MCP server identifies the caller by the token's `sub` claim and uses it
**directly as the Nextcloud user id**. authentik's default `sub_mode` is an
opaque hash, so unless the Nextcloud account id equals that hash, search returns
**HTTP 200 with zero results** — documents are indexed under the Nextcloud id
while the query filters on `sub`. No error, just nothing.

`user_username` puts the username in `sub`, so the Nextcloud account is simply
`alice`. Verified in this stack: `occ user:list` shows `alice`, and the MCP
server logs `user=alice` for the same request.

> The underlying assumption is a bug —
> [mcp#1326](https://github.com/cbcoutinho/nextcloud-mcp-server/issues/1326).
> `sub_mode` is the clean way to sidestep it on authentik.
>
> Both examples configure Nextcloud identically here — `--mapping-uid=sub
> --unique-uid=0`, so the account id follows whatever the IdP puts in `sub`.
> The difference is entirely on the *provider* side: authentik can be told to
> put the username there, whereas Authelia always issues an opaque UUID, so that
> example ends up with UUID account ids.
>
> **Do not** change the uid mapping on an existing Nextcloud — it rewrites
> account ids and orphans files, shares and indexed data.

### `client_id` must match `ALLOWED_MGMT_CLIENT`

Astrolabe calls the MCP server's management API presenting the user's authentik
token. That API only accepts tokens whose issuing client is on the
`ALLOWED_MGMT_CLIENT` allowlist, and it **fails closed** — unset means every
call is rejected with a bare `401`, with the reason only in the server log.

authentik generates random client ids like
`E2x6qDM9bEkQuBPX0v6umpupmNYEG0YNH3JfdUKo` when you create a provider in the UI;
this blueprint pins a readable one instead.

> Needs MCP server **≥ 0.176.3**. Before that the allowlist read only the
> `client_id` claim, while authentik (and Keycloak, and Authelia) stamp `azp` —
> so every external-IdP token was refused however you configured it
> ([mcp#1325](https://github.com/cbcoutinho/nextcloud-mcp-server/pull/1325)).

---

## Every setting, and why

### MCP server

| Setting | Why |
|---|---|
| `NEXTCLOUD_HOST` | Where Nextcloud is, from inside the network. |
| `NEXTCLOUD_PUBLIC_URL` | Where the **browser** reaches Nextcloud (Login Flow v2). |
| `MCP_DEPLOYMENT_MODE=login_flow` | Selects the auth flow. |
| `OIDC_DISCOVERY_URL` | The provider. Issuer, JWKS, userinfo and introspection all come from this one document. |
| `NEXTCLOUD_OIDC_CLIENT_ID` / `_SECRET` | Client credentials, used for introspection. |
| `ALLOWED_MGMT_CLIENT` | See above. Mandatory in practice. |
| `NEXTCLOUD_MCP_SERVER_URL` | The MCP server's own browser-facing URL, used by the same Login Flow v2 leg. |
| `ENABLE_SEMANTIC_SEARCH`, `QDRANT_URL`, `TOKEN_*` | Search + token storage; not auth. |
| `VECTOR_SYNC_SCAN_INTERVAL`, `VECTOR_SYNC_USER_POLL_INTERVAL` | Both lowered to 10s so a demo indexes promptly; raise them for anything real. |

Deliberately **not** set: `OIDC_ISSUER`, `OIDC_JWKS_URI`,
`NEXTCLOUD_PUBLIC_ISSUER_URL`, `NEXTCLOUD_RESOURCE_URI`. Discovery supplies them
and this example works without any of them.

### Nextcloud

| Setting | Why |
|---|---|
| `overwrite.cli.url` | The redirect URI is built from this; it must be the URL your **browser** uses or authentik rejects the callback. |
| `user_oidc` `store_login_token=1` | **Required.** Without it `user_oidc` discards the IdP token at login and Astrolabe has nothing to present. |
| `user_oidc` `oidc_provider_bearer_validation=true` | The MCP server calls back into Nextcloud with the user's token. |
| `user_oidc` `default_token_endpoint_auth_method=client_secret_post` | How authentik expects client authentication. |
| `astrolabe_client_id` | The IdP client the MCP server accepts tokens for — **not** a client registered inside Nextcloud. |
| `allow_insecure_http` | Only because this example runs on plain http. Drop it behind https. |

---

## Other traps

### The browser and the servers must agree on the IdP's URL

authentik derives the issuer from the **request host**, so fetching discovery via
`localhost:9000` and via `auth.astrolabe.test:9000` yields *different* issuers.
That is why this example uses a hosts entry and publishes authentik on the
**same port it listens on** — one URL that means the same thing everywhere.

`*.localhost` would avoid the hosts edit, but libcurl short-circuits any
`*.localhost` name to 127.0.0.1 (RFC 6761), so Nextcloud's PHP client could
never reach the authentik container by such a name.

### The blueprint is applied asynchronously

The authentik **server** reports healthy as soon as its web process is up, but
the provider and application are created by the authentik **worker**, which has
no healthcheck to wait on. Until it finishes, the discovery URL for the
application slug 404s.

So `nextcloud-hooks/post-installation/10-configure.sh` polls that URL before
calling `occ user_oidc:provider`. Without the poll the hook fails under `set -e`
and takes the Nextcloud container down on first run — and the failure reads like
a configuration error rather than a race, on a stack that is otherwise correct.

The Authelia example has no equivalent problem: its configuration is a static
file read at startup, with no separate worker applying it.

### Match your Qdrant to the server

An old Qdrant answers `400 Format error in JSON body: data did not match any
variant of untagged enum QueryInterface`. This pins `v1.19.0`, the version the
MCP server is tested against. Qdrant also refuses to start on storage written by
a newer version — `docker compose down -v` if you change it.

### No TLS needed here

Unlike Authelia — which refuses to serve OpenID Connect over http at all —
authentik is happy on plain http, so this example ships no certificates. That
makes it the simpler of the two to run locally.

---

## Verified

Run end to end: browser login through authentik, then a search from the
Astrolabe UI returning `HTTP 200 {"success":true,…}`, with `occ user:list`
showing `alice` and the MCP server resolving the same request to `user=alice`.

Results are empty until you enable background indexing and add content — the
expected state on a fresh stack, not a failure.
