# Astrolabe Internal URL

The Astrolabe Nextcloud app may need to make internal HTTP requests to the local web server (e.g., for OAuth token refresh). By default, it uses `http://localhost` which works for standard Docker containers where PHP and Apache run together.

| Variable | Description | Default |
|----------|-------------|---------|
| `astrolabe_internal_url` | Internal URL for server-to-server requests within container | `http://localhost` |

## When to Configure

- Custom container setups where the internal web server is not on `localhost:80`
- Kubernetes deployments with service discovery
- Multi-container setups with separate web server containers

## Example (Nextcloud config.php)

```php
'astrolabe_internal_url' => 'http://web-server.internal:8080',
```

**Note:** This is for internal PHP-to-Apache requests, NOT for external client URLs. The default (`http://localhost`) works for standard Docker containers where PHP and Apache run together.
