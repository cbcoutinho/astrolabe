# Astrolabe User Setup (Hybrid Mode)

When Astrolabe connects to an MCP server running in hybrid mode, users must complete a **two-step credential setup**:

## Step 1: OAuth Authorization (Search Access)

**Purpose**: Allows Astrolabe to call MCP server APIs on the user's behalf.

**Flow**:
1. User opens Astrolabe Personal Settings in Nextcloud
2. Clicks "Authorize" button
3. Redirected to Astrolabe's OAuth controller (`/apps/astrolabe/oauth/initiate`)
4. OAuth controller discovers IdP from MCP server's `/api/v1/status` endpoint
5. User authenticates with Identity Provider (Nextcloud OIDC or external IdP)
6. Tokens stored in Nextcloud user config (`McpTokenStorage`)
7. Astrolabe can now perform semantic searches via MCP API

**Technical Details**:
- Token audience: MCP server
- Token storage: Nextcloud app config (`oc_preferences`)
- Used for: `/api/v1/search`, `/api/v1/status` (authenticated endpoints)

## Step 2: App Password (Background Indexing)

**Purpose**: Allows MCP server to access Nextcloud content for background sync.

**Flow**:
1. User generates app password in Nextcloud Security settings
2. Enters app password in Astrolabe Personal Settings
3. App password validated against Nextcloud and stored (encrypted)
4. MCP server can now index user's content in the background

**Technical Details**:
- Credential type: Nextcloud app password
- Token storage: MCP server's refresh token database
- Used for: Background indexing, content sync to vector database

## Why Two Credentials?

| Direction | Auth Method | Purpose |
|-----------|-------------|---------|
| Astrolabe → MCP Server | OAuth Bearer Token | User searches, settings management |
| MCP Server → Nextcloud | BasicAuth (App Password) | Background content indexing |

The separation ensures:
- **Security**: Each credential has limited scope
- **Audit Trail**: OAuth tokens identify users; app passwords enable background ops
- **User Control**: Users explicitly grant each type of access

## See Also
- [MCP Server OAuth Architecture](https://github.com/cbcoutinho/nextcloud-mcp-server/blob/master/docs/oauth-architecture.md) - Progressive Consent (Flow 2) details
- [MCP Server Configuration](https://github.com/cbcoutinho/nextcloud-mcp-server/blob/master/docs/configuration.md#enable_offline_access) - Hybrid mode configuration
